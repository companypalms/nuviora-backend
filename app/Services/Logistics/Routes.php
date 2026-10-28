<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\LogisticsRoute;
use App\Models\Order;
use App\Models\RiderProfile;
use App\Models\User;
use App\Services\Delivery\GoogleRoutesException;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rutas de varias paradas (§9.3, §10, §24.1). Mientras el rider todavía no salió del almacén, el sistema le propone al
 * operador sumarle una orden que está buscando rider en el mismo centro; si el operador aprueba, la orden es del rider sin
 * pedirle otra aceptación (§9.3, §28). No se retiene a nadie esperando órdenes: solo se miran las que ya existen.
 * - Se simulan todos los órdenes de paradas (centro→A→B y centro→B→A; con 3, los 6) con el tiempo de servicio por parada
 *   del centro (§10.1). Para cada entrega: SLA ya consumido + ETA del rider al centro + picking + los tramos hasta ella
 *   + el servicio de las paradas anteriores, como en §7.1. Ninguna puede pasar el límite (55 min, §10.1).
 * - Se propone el orden que mejor respeta el SLA y después el tiempo total (§10.2): primero el que deja la peor entrega
 *   más temprano, así una orden cerca del límite no se sacrifica por ahorrar km (§10.1).
 * - Máximo normal 2 paradas; 3 solo como excepción que el operador aprueba expresamente (§10, §23). El documento no fija
 *   qué es "extremadamente cercanos": la propuesta muestra los tramos y lo decide el operador.
 * - "Confirmar salida" bloquea la ruta (§11, Departures). Después no se suma nada (§11, §30).
 * Los tramos entre clientes salen de la matriz de Google con tráfico, como el resto de los ETA (§33), y se guardan 15 min.
 */
class Routes
{
    /** Las paradas que todavía están en el centro con su rider (§9.3: "mientras no haya retirado los paquetes"). */
    public const BEFORE_DEPARTURE = [LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING, LogisticsStatus::READY];

    /** Paradas que ya tienen resultado (§20.2 FINALIZADA: "todas las paradas tienen resultado"). */
    public const WITH_RESULT = [LogisticsStatus::DELIVERED, LogisticsStatus::CLOSED, LogisticsStatus::RETURN_PENDING, LogisticsStatus::IN_REVIEW];

    private const LEG_CACHE_SECONDS = 900;

    public function __construct(private RiderEta $eta, private LogisticsSettings $settings, private RouteMatrixClient $matrix,
        private LogisticsOrders $orders)
    {
    }

    /**
     * §24.1: las rutas que el sistema propone ahora en el centro. Una por orden que busca rider: la mejor entre los riders
     * que todavía no salieron.
     *
     * @return array{proposals: array<int, array>, warning: ?string}
     */
    public function proposals(LogisticsCenter $center, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $settings = $this->settings->for($center, $at);
        $maxStops = max((int) $settings['max_stops_normal'], (int) $settings['max_stops_exceptional']);
        if ($maxStops < 2) {
            return ['proposals' => [], 'warning' => null];
        }

        $candidates = LogisticsOrder::with('order:id,name,location_lat,location_lng')->where('center_id', $center->id)
            ->where('status', LogisticsStatus::SEARCHING)->whereNull('rider_id')->whereNull('pickup_rider_id')->whereNull('departed_at')
            ->whereNotNull('sla_started_at')->orderBy('sla_started_at')->get()
            ->filter(fn (LogisticsOrder $r) => $r->order?->location_lat !== null && $r->order?->location_lng !== null)->values();
        if ($candidates->isEmpty()) {
            return ['proposals' => [], 'warning' => null];
        }
        $groups = $this->undeparted($center)->filter(fn (Collection $stops) => $stops->count() < $maxStops);
        if ($groups->isEmpty()) {
            return ['proposals' => [], 'warning' => null];
        }

        $profiles = RiderProfile::with('user:id,names')->whereIn('user_id', $groups->keys())->get()->keyBy('user_id');
        $warning = $this->eta->refreshToCenter($profiles->values(), $center->loadMissing('warehouse'));

        $best = [];
        foreach ($candidates as $candidate) {
            foreach ($groups as $riderId => $stops) {
                $profile = $profiles[$riderId] ?? null;
                if (!$profile) {
                    continue;
                }
                $profile->refresh();
                try {
                    $plan = $this->plan($stops->push($candidate)->values(), $profile, $settings, $at);
                } catch (GoogleRoutesException $e) {
                    $warning ??= $e->getMessage();
                    continue;
                } finally {
                    $stops->pop();
                }
                if (!$plan) {
                    continue;
                }
                $proposal = $plan + [
                    'add_logistics_order_id' => $candidate->id,
                    'add_order_name' => $candidate->order?->name,
                    'rider' => ['id' => (int) $riderId, 'names' => $profile->user?->names],
                    'route_id' => $stops->first()->route_id,
                    'exceptional' => count($plan['stops']) > (int) $settings['max_stops_normal'],
                ];
                $current = $best[$candidate->id] ?? null;
                if (!$current || [$proposal['worst_minutes'], $proposal['total_minutes']] < [$current['worst_minutes'], $current['total_minutes']]) {
                    $best[$candidate->id] = $proposal;
                }
            }
        }

        return ['proposals' => array_values($best), 'warning' => $warning];
    }

    /**
     * El operador aprueba la ruta propuesta (§24.1 "IF operator approves: update route; rider does not re-accept"). El
     * orden de las paradas es el que mandó (el propuesto o el que eligió él); se vuelve a calcular con datos de ahora y no
     * pasa si alguna entrega queda sobre el límite. Con más paradas que el máximo normal, tiene que aprobar la excepción.
     */
    public function approve(LogisticsOrder $add, array $stopOrder, User $by, bool $exceptional): array
    {
        $center = $add->center()->with('warehouse')->first();
        $settings = $this->settings->for($center);
        $riderId = (int) LogisticsOrder::whereIn('id', $stopOrder)->where('id', '!=', $add->id)->value('rider_id');
        $stops = $riderId ? $this->undeparted($center)->get($riderId) : null;
        if (!$stops) {
            return ['ok' => false, 'message' => 'Ese rider ya salió o ya no tiene esa orden: la ruta no se puede armar.', 'code' => 409];
        }
        $ids = $stops->pluck('id')->push($add->id)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $order = array_map('intval', $stopOrder);
        if ($ids !== collect($order)->sort()->values()->all()) {
            return ['ok' => false, 'message' => 'Las paradas no coinciden con lo que tiene el rider ahora. Vuelve a cargar la torre.', 'code' => 409];
        }
        $count = count($order);
        if ($count > max((int) $settings['max_stops_normal'], (int) $settings['max_stops_exceptional'])) {
            return ['ok' => false, 'message' => "Máximo {$settings['max_stops_exceptional']} paradas por ruta (§10).", 'code' => 422];
        }
        if ($count > (int) $settings['max_stops_normal'] && !$exceptional) {
            return ['ok' => false, 'message' => "Una ruta de {$count} paradas es excepcional: apruébala solo si los destinos están muy cerca (§10).", 'code' => 422];
        }
        $profile = RiderProfile::where('user_id', $riderId)->first();
        $this->eta->refreshToCenter(collect([$profile]), $center);
        $profile->refresh();
        $rows = $stops->push($add)->keyBy('id');
        try {
            $plan = $this->plan(collect($order)->map(fn ($id) => $rows[$id])->values(), $profile, $settings, now(), true);
        } catch (GoogleRoutesException $e) {
            return ['ok' => false, 'message' => 'Google no respondió los tiempos entre clientes: intenta de nuevo.', 'code' => 503];
        }
        if (!$plan) {
            return ['ok' => false, 'message' => "Con ese orden alguna entrega pasa de {$settings['sla_limit_minutes']} min (§10.1).", 'code' => 409];
        }

        $out = DB::transaction(function () use ($add, $order, $riderId, $by, $plan, $count, $settings) {
            $locked = LogisticsOrder::whereIn('id', $order)->lockForUpdate()->get()->keyBy('id');
            $new = $locked[$add->id];
            if ($new->status !== LogisticsStatus::SEARCHING || $new->rider_id || $new->pickup_rider_id) {
                return ['ok' => false, 'message' => 'La orden ya no está buscando rider (' . LogisticsStatus::label($new->status) . ').', 'code' => 409];
            }
            foreach ($locked as $row) {
                if ($row->id !== $new->id && ((int) $row->rider_id !== $riderId || !in_array($row->status, self::BEFORE_DEPARTURE, true) || $row->departed_at)) {
                    return ['ok' => false, 'message' => 'El rider ya salió del almacén: la ruta quedó cerrada (§11).', 'code' => 409];
                }
            }
            $routeId = $locked->firstWhere('route_id', '!=', null)?->route_id;
            $route = $routeId ? LogisticsRoute::whereKey($routeId)->lockForUpdate()->first() : null;
            $attributes = [
                'exceptional' => $count > (int) $settings['max_stops_normal'],
                'plan' => $plan,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ];
            $route = $route ? tap($route)->update($attributes) : LogisticsRoute::create($attributes + [
                'center_id' => $new->center_id,
                'rider_id' => $riderId,
                'status' => LogisticsRoute::OPEN,
            ]);
            app(Dispatcher::class)->assignToRoute($new, $riderId, $by, $route->id);
            $this->applyPlan($route, $plan, $locked, $by->id, 'ruta_armada');

            return ['ok' => true, 'message' => "Ruta de {$count} paradas armada: el rider las recoge juntas.", 'code' => 200, 'route' => $route];
        });
        if ($out['ok']) {
            app(Dispatcher::class)->notifyRouteAssignment($add->fresh(), $riderId);
            unset($out['route']);
        }

        return $out;
    }

    /** §10.2: "Si el operador cambia el orden antes de la salida, debe quedar auditado". */
    public function reorder(LogisticsRoute $route, array $stopOrder, User $by, ?string $reason): array
    {
        $route->load('stops');
        $order = array_map('intval', $stopOrder);
        if ($route->status !== LogisticsRoute::OPEN || $route->locked_at) {
            return ['ok' => false, 'message' => 'La ruta ya salió del almacén: el orden de las paradas queda fijo (§11).', 'code' => 409];
        }
        if ($route->stops->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() !== collect($order)->sort()->values()->all()) {
            return ['ok' => false, 'message' => 'Las paradas no coinciden con la ruta. Vuelve a cargar la torre.', 'code' => 409];
        }
        $center = $route->center()->with('warehouse')->first();
        $settings = $this->settings->for($center);
        $profile = RiderProfile::where('user_id', $route->rider_id)->first();
        $this->eta->refreshToCenter(collect([$profile]), $center);
        $profile->refresh();
        $rows = $route->stops->keyBy('id');
        try {
            $plan = $this->plan(collect($order)->map(fn ($id) => $rows[$id])->values(), $profile, $settings, now(), true);
        } catch (GoogleRoutesException $e) {
            return ['ok' => false, 'message' => 'Google no respondió los tiempos entre clientes: intenta de nuevo.', 'code' => 503];
        }
        if (!$plan) {
            return ['ok' => false, 'message' => "Con ese orden alguna entrega pasa de {$settings['sla_limit_minutes']} min (§10.1).", 'code' => 409];
        }
        $before = $route->stops->pluck('id')->all();

        return DB::transaction(function () use ($route, $plan, $by, $reason, $before, $order) {
            $route = LogisticsRoute::whereKey($route->id)->lockForUpdate()->first();
            if ($route->status !== LogisticsRoute::OPEN) {
                return ['ok' => false, 'message' => 'La ruta ya salió del almacén.', 'code' => 409];
            }
            $route->update(['plan' => $plan]);
            $locked = LogisticsOrder::whereIn('id', $order)->lockForUpdate()->get()->keyBy('id');
            $this->applyPlan($route, $plan, $locked, $by->id, 'ruta_reordenada', ['antes' => $before, 'despues' => $order, 'motivo' => $reason], $reason);

            return ['ok' => true, 'message' => 'Orden de las paradas cambiado.', 'code' => 200];
        });
    }

    /**
     * Pone la ruta al día después de cada cambio de una parada (LogisticsOrders::transition):
     * - antes de salir, una parada que dejó de ser del rider (cancelada, devuelta a la vendedora…) sale de la ruta; si
     *   queda una sola, la ruta se deshace y esa orden sale sola;
     * - en la calle, cuando todas tienen resultado, queda finalizada (§20.2).
     */
    public function sync(int $routeId): void
    {
        $route = LogisticsRoute::with('stops')->find($routeId);
        if (!$route || in_array($route->status, [LogisticsRoute::FINISHED, LogisticsRoute::UNDONE], true)) {
            return;
        }
        if ($route->status === LogisticsRoute::OPEN) {
            [$keep, $drop] = $route->stops->partition(fn (LogisticsOrder $s) => (int) $s->rider_id === (int) $route->rider_id
                && (in_array($s->status, self::BEFORE_DEPARTURE, true) || $s->departed_at));
            foreach ($drop as $stop) {
                $this->leave($stop, 'sale_de_ruta', "Salió de la ruta #{$route->id}: quedó en \"" . LogisticsStatus::label($stop->status) . '".');
            }
            if ($drop->isEmpty()) {
                return;
            }
            if ($keep->count() <= 1) {
                foreach ($keep as $stop) {
                    $this->leave($stop, 'ruta_deshecha', "La ruta #{$route->id} quedó con una sola parada: sale sola.");
                }
                $route->update(['status' => LogisticsRoute::UNDONE, 'finished_at' => now()]);

                return;
            }
            foreach ($keep->values() as $i => $stop) {
                $first = $i === 0;
                $stop->forceFill(['route_stop' => $i + 1] + ($first ? ['leg_seconds' => $stop->center_client_seconds, 'leg_meters' => $stop->center_client_meters] : []))->save();
            }

            return;
        }
        $pending = $route->stops->contains(fn (LogisticsOrder $s) => !in_array($s->status, self::WITH_RESULT, true));
        if (!$pending) {
            $route->update(['status' => $route->status === LogisticsRoute::INTERRUPTED ? LogisticsRoute::INTERRUPTED : LogisticsRoute::FINISHED, 'finished_at' => now()]);
        }
    }

    /** §12: "termina la primera parada y luego la segunda". La parada anterior que todavía está en camino, sin resultado. */
    public function blockingStop(LogisticsOrder $row): ?LogisticsOrder
    {
        if (!$row->route_id || !$row->route_stop) {
            return null;
        }

        return LogisticsOrder::with('order:id,name')->where('route_id', $row->route_id)->where('route_stop', '<', $row->route_stop)
            ->where('status', LogisticsStatus::EN_ROUTE)->orderBy('route_stop')->first();
    }

    /** §18.2: de dónde sale el tramo de esta parada. La anterior en el orden oficial, o null si sale del centro. */
    public function previousStop(LogisticsOrder $row): ?LogisticsOrder
    {
        if (!$row->route_id || ($row->route_stop ?? 1) <= 1) {
            return null;
        }

        return LogisticsOrder::with('order:id,location_lat,location_lng')->where('route_id', $row->route_id)
            ->where('route_stop', '<', $row->route_stop)->orderByDesc('route_stop')->first();
    }

    /** §18.2: el tramo que se le paga en esta parada (desde la anterior, o desde el centro). */
    public static function legMeters(LogisticsOrder $row): ?int
    {
        return $row->route_id && $row->leg_meters !== null ? (int) $row->leg_meters
            : ($row->center_client_meters !== null ? (int) $row->center_client_meters : null);
    }

    /**
     * Los riders que tienen órdenes en el centro y todavía no salieron, con esas órdenes en el orden de su ruta. Un
     * reemplazo que recoge donde está el otro rider no cuenta: su paquete no está en el centro (§14).
     *
     * @return Collection<int, Collection<int, LogisticsOrder>>
     */
    private function undeparted(LogisticsCenter $center): Collection
    {
        return LogisticsOrder::with('order:id,name,location_lat,location_lng')->where('center_id', $center->id)
            ->whereIn('status', self::BEFORE_DEPARTURE)->whereNotNull('rider_id')->whereNull('departed_at')->whereNull('pickup_rider_id')
            ->orderByRaw('route_stop is null')->orderBy('route_stop')->orderBy('id')->get()
            ->groupBy(fn (LogisticsOrder $r) => (int) $r->rider_id)
            ->filter(fn (Collection $stops) => $stops->every(fn (LogisticsOrder $s) => $s->order?->location_lat !== null));
    }

    /**
     * El mejor orden de esas paradas para ese rider, o null si ninguno deja todas las entregas dentro del límite. Con
     * $fixed, solo el orden dado.
     */
    private function plan(Collection $rows, RiderProfile $profile, array $settings, CarbonInterface $at, bool $fixed = false): ?array
    {
        if ($profile->eta_center_seconds === null) {
            return null;
        }
        $limit = (int) $settings['sla_limit_minutes'] * 60;
        $service = (int) $settings['service_minutes_per_stop'] * 60;
        $picking = (int) ($settings['picking_minutes'] ?? 0) * 60;
        $departIn = (int) $profile->eta_center_seconds + $picking;
        $legs = $this->legs($rows);

        $best = null;
        foreach ($fixed ? [$rows->all()] : self::permutations($rows->all()) as $perm) {
            $t = $departIn;
            $prev = null;
            $stops = [];
            foreach ($perm as $i => $row) {
                $leg = $prev ? ($legs['between'][$prev->id][$row->id] ?? null) : ($legs['center'][$row->id] ?? null);
                if (!$leg) {
                    continue 2;
                }
                $t += $leg['seconds'];
                $consumed = max(0, (int) $row->sla_started_at->diffInSeconds($at));
                $stops[] = [
                    'logistics_order_id' => $row->id,
                    'order_name' => $row->order?->name,
                    'stop' => $i + 1,
                    'leg_seconds' => $leg['seconds'],
                    'leg_meters' => $leg['meters'],
                    'consumed_minutes' => round($consumed / 60, 1),
                    'projected_minutes' => round(($consumed + $t) / 60, 1),
                ];
                $t += $service;
                $prev = $row;
            }
            $worst = max(array_column($stops, 'projected_minutes'));
            if ($worst * 60 > $limit) {
                continue;
            }
            $total = round(($t - $service) / 60, 1);
            if (!$best || [$worst, $total] < [$best['worst_minutes'], $best['total_minutes']]) {
                $best = [
                    'stops' => $stops,
                    'worst_minutes' => $worst,
                    'total_minutes' => $total,
                    'depart_in_minutes' => round($departIn / 60, 1),
                    'service_minutes' => (int) $settings['service_minutes_per_stop'],
                    'limit_minutes' => (int) $settings['sla_limit_minutes'],
                ];
            }
        }

        return $best;
    }

    /**
     * Los tramos que hacen falta: centro → cada cliente (se guarda en la orden logística) y entre clientes (en caché).
     *
     * @return array{center: array<int, ?array>, between: array<int, array<int, ?array>>}
     */
    private function legs(Collection $rows): array
    {
        $center = [];
        foreach ($rows as $row) {
            $center[$row->id] = $this->eta->centerToClient($row);
        }
        $between = [];
        $missing = [];
        foreach ($rows as $a) {
            foreach ($rows as $b) {
                if ($a->id === $b->id) {
                    continue;
                }
                $cached = Cache::get($this->legKey($a, $b));
                if ($cached !== null) {
                    $between[$a->id][$b->id] = $cached ?: null;
                } else {
                    $missing[$a->id] = $a;
                    $missing[$b->id] = $b;
                }
            }
        }
        if ($missing) {
            $points = collect($missing)->map(fn (LogisticsOrder $r) => [(float) $r->order->location_lat, (float) $r->order->location_lng])->all();
            $matrix = $this->matrix->matrix($points, $points);
            foreach ($missing as $aId => $a) {
                foreach ($missing as $bId => $b) {
                    if ($aId === $bId) {
                        continue;
                    }
                    $cell = $matrix[$aId][$bId] ?? null;
                    // false = Google dijo que no hay ruta (se guarda igual, para no volver a preguntar en 15 min)
                    Cache::put($this->legKey($a, $b), $cell ?? false, self::LEG_CACHE_SECONDS);
                    $between[$aId][$bId] = $cell;
                }
            }
        }

        return ['center' => $center, 'between' => $between];
    }

    private function legKey(LogisticsOrder $a, LogisticsOrder $b): string
    {
        return "logistica:tramo:{$a->id}:{$b->id}";
    }

    /** Deja la ruta en las paradas y en el historial de cada una (§26: "orden de paradas, ETA predichos, distancias"). */
    private function applyPlan(LogisticsRoute $route, array $plan, Collection $rows, int $actorId, string $action, array $extra = [],
        ?string $reason = null): void
    {
        foreach ($plan['stops'] as $stop) {
            $row = $rows[$stop['logistics_order_id']];
            $row->forceFill([
                'route_id' => $route->id,
                'route_stop' => $stop['stop'],
                'leg_seconds' => $stop['leg_seconds'],
                'leg_meters' => $stop['leg_meters'],
                'predicted_minutes' => $stop['projected_minutes'],
            ])->save();
            $this->orders->event($row, $action, $row->status, $row->status, $actorId, $reason, $extra + [
                'ruta' => $route->id,
                'parada' => $stop['stop'],
                'de' => count($plan['stops']),
                'excepcional' => $route->exceptional,
                'sla_proyectado_min' => $stop['projected_minutes'],
                'tramo_m' => $stop['leg_meters'],
            ]);
        }
    }

    private function leave(LogisticsOrder $stop, string $action, string $reason): void
    {
        $stop->forceFill(['route_id' => null, 'route_stop' => null, 'leg_seconds' => null, 'leg_meters' => null, 'predicted_minutes' => null])->save();
        $this->orders->event($stop, $action, $stop->status, $stop->status, null, $reason);
    }

    /** @return array<int, array> */
    private static function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }
        $out = [];
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach (self::permutations(array_values($rest)) as $perm) {
                $out[] = [$item, ...$perm];
            }
        }

        return $out;
    }
}
