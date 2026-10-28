<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\DispatchOffer;
use App\Models\Earning;
use App\Models\LogisticsCenter;
use App\Models\LogisticsIncident;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderEvent;
use App\Models\LogisticsRoute;
use App\Models\RiderCashMovement;
use App\Models\RiderPayout;
use App\Models\RiderProfile;
use App\Models\RiderTrip;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Las métricas del §27, "que deben poder calcularse desde el día 1", para un centro y un rango de días. Salen de lo que
 * ya queda guardado (§26): la fila logística de cada orden con sus tiempos, las rondas y ofertas, las novedades, las
 * incidencias, el efectivo, las carreras y los pagos. Una orden cuenta en el día en que arrancó su SLA.
 * Canal de cada orden: "propia" si la llevó un rider, "agencia" si pasó a la agencia (§19); las que se cerraron antes
 * de tener canal (canceladas mientras buscaba rider) no cuentan como atendidas.
 */
class Metrics
{
    public const OVERFLOW = [
        'minuto_5' => 'Minuto 5 sin rider propio (§8, R4)',
        'forzada_por_operador' => 'El operador la pasó antes del minuto 5 (§19.1)',
    ];

    public function __construct(private LogisticsSettings $settings, private RiderCash $cash)
    {
    }

    public function for(LogisticsCenter $center, CarbonInterface $from, CarbonInterface $to): array
    {
        $settings = $this->settings->for($center);
        $target = (int) $settings['sla_target_minutes'];
        $limit = (int) $settings['sla_limit_minutes'];
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $rows = LogisticsOrder::where('center_id', $center->id)->whereBetween('sla_started_at', [$start, $end])->get();
        $ids = $rows->pluck('id');
        $channel = fn (LogisticsOrder $r) => match (true) {
            $r->agency_id !== null || $r->status === LogisticsStatus::AGENCY => 'agencia',
            $r->rider_id !== null || $r->departed_at !== null || $r->rider_confirmed_at !== null => 'propia',
            default => null,
        };
        $byChannel = $rows->groupBy(fn ($r) => $channel($r) ?? 'sin_canal');
        $served = ($byChannel['propia'] ?? collect())->count() + ($byChannel['agencia'] ?? collect())->count();
        $delivered = $rows->filter(fn ($r) => $r->status === LogisticsStatus::DELIVERED && $r->delivered_at);
        $minutes = fn (LogisticsOrder $r, string $field) => $r->{$field} ? round($r->sla_started_at->diffInSeconds($r->{$field}) / 60, 1) : null;
        $confirmed = DispatchOffer::with('round:id,number')->whereIn('logistics_order_id', $ids)->where('response', DispatchOffer::CONFIRMED)
            ->orderBy('id')->get()->groupBy('logistics_order_id')->map->first();
        $names = User::whereIn('id', $rows->pluck('rider_id')->merge(RiderProfile::where('center_id', $center->id)->pluck('user_id'))->filter()->unique())
            ->pluck('names', 'id');

        return [
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'sla' => ['target' => $target, 'limit' => $limit],
            'orders' => $rows->count(),
            // 1. "% de pedidos elegibles atendidos por flota propia vs agencia"
            'served' => [
                'total' => $served,
                'own' => ($byChannel['propia'] ?? collect())->count(),
                'agency' => ($byChannel['agencia'] ?? collect())->count(),
                'own_pct' => self::pct(($byChannel['propia'] ?? collect())->count(), $served),
                'agency_pct' => self::pct(($byChannel['agencia'] ?? collect())->count(), $served),
                'without_channel' => ($byChannel['sin_canal'] ?? collect())->count(),
            ],
            // 2. "% entregados ≤45 min y ≤55 min por canal" y 3. tiempos de asignación y entrega
            'delivery' => collect(['propia', 'agencia'])->mapWithKeys(function ($c) use ($delivered, $channel, $minutes, $target, $limit) {
                $list = $delivered->filter(fn ($r) => $channel($r) === $c)->map(fn ($r) => $minutes($r, 'delivered_at'))->filter(fn ($m) => $m !== null)->values();

                return [$c => [
                    'delivered' => $list->count(),
                    'within_target_pct' => self::pct($list->filter(fn ($m) => $m <= $target)->count(), $list->count()),
                    'within_limit_pct' => self::pct($list->filter(fn ($m) => $m <= $limit)->count(), $list->count()),
                    'minutes' => self::stats($list),
                ]];
            })->all(),
            'assignment_minutes' => self::stats(($byChannel['propia'] ?? collect())->map(fn ($r) => $minutes($r, 'rider_confirmed_at'))->filter(fn ($m) => $m !== null)->values()),
            // 4. "% de pedidos resueltos en R1, R2, llamada manual y agencia"
            'resolved_in' => $this->resolvedIn($rows, $channel, $confirmed, $served),
            // 5. "Tasa de confirmación por rider en R1/R2 y tiempo de respuesta"
            'riders_rounds' => $this->riderRounds($ids, $names),
            // 6. "Motivo de overflow a agencia"
            'overflow' => ($byChannel['agencia'] ?? collect())->groupBy(fn ($r) => $r->overflow_reason ?? 'sin_motivo')
                ->map(fn ($list, $reason) => ['reason' => $reason, 'label' => self::OVERFLOW[$reason] ?? $reason, 'count' => $list->count()])->values()->all(),
            // 7. "Rutas simples, dobles y excepcionales de 3 paradas"
            'routes' => $this->routes($center, $rows, $start, $end),
            // 8. "ETA previsto vs tiempo real"
            'eta' => $this->eta($delivered->filter(fn ($r) => $channel($r) === 'propia'), $confirmed, $minutes),
            // 9. y 10. novedades por tipo, canal, rider y operador; resueltas por el operador o escaladas a ventas
            'novelties' => $this->novelties($ids, $rows, $channel, $names),
            // 11. "Retornos pendientes y tiempo hasta devolución"
            'returns' => $this->returns($center, $rows),
            // 12. "Efectivo en custodia, diferencias y tiempo hasta conciliación"
            'cash' => $this->cashMetrics($center, $start, $end, $names),
            // 13. "Carreras/pagos por rider"
            'riders_pay' => $this->ridersPay($center, $rows, $start, $end, $names),
            // 14. "Incidencias graves y reasignaciones de ruta"
            'incidents' => $this->incidents($center, $start, $end),
            // Informe del 27/10, sección 06 punto 6 (Fran: "que se aplique todo"): las señales raras, por rider y por operador
            'signals' => $this->signals($center, $rows, $start, $end, $names),
            // 15. "Delivery rate propia vs agencia por centro"
            'delivery_rate' => collect(['propia', 'agencia'])->mapWithKeys(function ($c) use ($rows, $channel) {
                $list = $rows->filter(fn ($r) => $channel($r) === $c);
                $ok = $list->where('status', LogisticsStatus::DELIVERED)->count();
                // No entregada: el producto volvió (retorno) o se cerró después de salir
                $failed = $list->filter(fn ($r) => $r->return_pending_at || ($r->status === LogisticsStatus::CLOSED && $r->departed_at))->count();

                return [$c => ['delivered' => $ok, 'not_delivered' => $failed, 'rate_pct' => self::pct($ok, $ok + $failed)]];
            })->all(),
        ];
    }

    private function resolvedIn(Collection $rows, \Closure $channel, Collection $confirmed, int $served): array
    {
        $buckets = ['R1' => 0, 'R2' => 0, 'llamada_manual' => 0, 'ruta' => 0, 'agencia' => 0];
        foreach ($rows as $r) {
            $c = $channel($r);
            if ($c === 'agencia') {
                $buckets['agencia']++;
            } elseif ($c === 'propia') {
                $key = match ($r->assigned_via) {
                    'manual' => 'llamada_manual',
                    'ruta' => 'ruta',
                    default => ($confirmed->get($r->id)?->round?->number ?? 1) >= 2 ? 'R2' : 'R1',
                };
                $buckets[$key]++;
            }
        }
        $labels = ['R1' => 'Ronda 1', 'R2' => 'Ronda 2', 'llamada_manual' => 'Llamada manual (§8.4)', 'ruta' => 'Sumada a una ruta (§9.3)', 'agencia' => 'Agencia'];

        return collect($buckets)->map(fn ($n, $k) => ['key' => $k, 'label' => $labels[$k], 'count' => $n, 'pct' => self::pct($n, $served)])->values()->all();
    }

    private function riderRounds(Collection $ids, Collection $names): array
    {
        $offers = DispatchOffer::with('round:id,number')->whereIn('logistics_order_id', $ids)->get();

        return $offers->groupBy('rider_id')->map(function (Collection $list, $riderId) use ($names) {
            $round = fn (int $n) => $list->filter(fn ($o) => (int) $o->round?->number === $n);
            $line = ['rider_id' => (int) $riderId, 'name' => $names[$riderId] ?? "#{$riderId}"];
            foreach ([1, 2] as $n) {
                $sent = $round($n);
                $yes = $sent->where('response', DispatchOffer::CONFIRMED);
                $line["r{$n}_sent"] = $sent->count();
                $line["r{$n}_confirmed"] = $yes->count();
                $line["r{$n}_rate_pct"] = self::pct($yes->count(), $sent->count());
            }
            $times = $list->where('response', DispatchOffer::CONFIRMED)->filter(fn ($o) => $o->sent_at && $o->responded_at)
                ->map(fn ($o) => $o->sent_at->diffInSeconds($o->responded_at))->values();
            $line['response_seconds'] = self::stats($times);

            return $line;
        })->sortByDesc('r1_sent')->values()->all();
    }

    private function routes(LogisticsCenter $center, Collection $rows, CarbonInterface $start, CarbonInterface $end): array
    {
        $routes = LogisticsRoute::withCount('stops')->where('center_id', $center->id)->whereBetween('departed_at', [$start, $end])->get();
        // Una ruta interrumpida suelta sus paradas pendientes: se cuenta con las paradas que llevaba al salir
        $stops = fn (LogisticsRoute $r) => max($r->stops_count, count($r->plan['stops'] ?? []));
        $simple = $rows->filter(fn ($r) => $r->departed_at && $r->rider_id && !$r->route_id && $r->assigned_via !== 'ruta')
            ->filter(fn ($r) => !LogisticsOrderEvent::where('logistics_order_id', $r->id)->whereIn('action', ['ruta_armada', 'ruta_de_recuperacion'])->exists())->count();

        return [
            'simple' => $simple,
            'double' => $routes->filter(fn ($r) => $stops($r) === 2)->count(),
            'triple' => $routes->filter(fn ($r) => $stops($r) >= 3)->count(),
            'exceptional' => $routes->where('exceptional', true)->count(),
            'interrupted' => $routes->whereNotNull('interrupted_at')->count(),
            'recovery' => $routes->filter(fn ($r) => (bool) ($r->plan['recuperacion'] ?? false))->count(),
        ];
    }

    private function eta(Collection $delivered, Collection $confirmed, \Closure $minutes): array
    {
        $pairs = $delivered->map(function (LogisticsOrder $r) use ($confirmed, $minutes) {
            $predicted = $r->predicted_minutes ?? $confirmed->get($r->id)?->projected_minutes;

            return $predicted !== null ? ['predicted' => (float) $predicted, 'real' => $minutes($r, 'delivered_at')] : null;
        })->filter()->values();
        $diff = $pairs->map(fn ($p) => round($p['real'] - $p['predicted'], 1));

        return [
            'count' => $pairs->count(),
            'predicted_avg' => $pairs->count() ? round($pairs->avg('predicted'), 1) : null,
            'real_avg' => $pairs->count() ? round($pairs->avg('real'), 1) : null,
            // Positivo: tardó más de lo previsto
            'diff_avg' => $diff->count() ? round($diff->avg(), 1) : null,
            'within_5_pct' => self::pct($diff->filter(fn ($d) => abs($d) <= 5)->count(), $diff->count()),
        ];
    }

    private function novelties(Collection $ids, Collection $rows, \Closure $channel, Collection $names): array
    {
        $list = LogisticsNovelty::whereIn('logistics_order_id', $ids)->get();
        $agency = LogisticsOrderEvent::whereIn('logistics_order_id', $rows->filter(fn ($r) => $channel($r) === 'agencia')->pluck('id'))
            ->where('action', 'novedad_con_ventas')->distinct('logistics_order_id')->count('logistics_order_id');
        $escalated = $list->whereNotNull('escalated_at')->count();
        $byOperator = $list->whereNull('escalated_at')->whereNotNull('resolved_at')->count();
        $operatorIds = $list->pluck('resolved_by')->merge($list->pluck('escalated_by'))->filter()->unique();
        $operators = User::whereIn('id', $operatorIds)->pluck('names', 'id');

        return [
            'total' => $list->count(),
            'by_type' => $list->groupBy('type')->map(fn ($g, $t) => ['type' => $t, 'label' => LogisticsNovelty::TYPES[$t]['label'] ?? $t, 'count' => $g->count()])
                ->sortByDesc('count')->values()->all(),
            'by_channel' => ['propia' => $list->count(), 'agencia' => $agency],
            'by_rider' => $list->groupBy('reported_by')->map(fn ($g, $id) => ['name' => $names[$id] ?? "#{$id}", 'count' => $g->count()])->sortByDesc('count')->values()->all(),
            'by_operator' => $list->filter(fn ($n) => $n->resolved_by || $n->escalated_by)->groupBy(fn ($n) => $n->escalated_by ?? $n->resolved_by)
                ->map(fn ($g, $id) => ['name' => $operators[$id] ?? "#{$id}", 'count' => $g->count()])->sortByDesc('count')->values()->all(),
            'resolved_by_operator' => $byOperator,
            'escalated_to_sales' => $escalated,
            'open' => $list->whereNull('resolved_at')->whereNull('escalated_at')->count(),
            'resolved_by_operator_pct' => self::pct($byOperator, $byOperator + $escalated),
            'escalated_pct' => self::pct($escalated, $byOperator + $escalated),
        ];
    }

    private function returns(LogisticsCenter $center, Collection $rows): array
    {
        $returns = $rows->whereNotNull('return_pending_at');
        $hours = $returns->whereNotNull('returned_at')->map(fn ($r) => round($r->return_pending_at->diffInSeconds($r->returned_at) / 3600, 2))->values();

        return [
            'in_range' => $returns->count(),
            'received' => $hours->count(),
            'pending_now' => LogisticsOrder::where('center_id', $center->id)->where('status', LogisticsStatus::RETURN_PENDING)->count(),
            'hours_to_return' => self::stats($hours),
        ];
    }

    private function cashMetrics(LogisticsCenter $center, CarbonInterface $start, CarbonInterface $end, Collection $names): array
    {
        $riders = RiderProfile::where('center_id', $center->id)->pluck('user_id');
        $balances = collect($this->cash->balances($riders))->filter(fn ($b) => abs($b['total_usd']) > 0.004);
        $movements = RiderCashMovement::where('center_id', $center->id)->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get();
        $handovers = $movements->where('type', RiderCashMovement::HANDOVER);
        // Desde que cobró hasta la siguiente entrega de ese rider en el centro
        $hours = $movements->where('type', RiderCashMovement::COLLECTION)->map(function ($c) use ($handovers) {
            $next = $handovers->first(fn ($h) => (int) $h->rider_id === (int) $c->rider_id && $h->created_at->gte($c->created_at));

            return $next ? round($c->created_at->diffInSeconds($next->created_at) / 3600, 2) : null;
        })->filter(fn ($h) => $h !== null)->values();

        return [
            'in_custody_usd' => round($balances->sum('total_usd'), 2),
            'riders_with_cash' => $balances->map(fn ($b, $id) => ['name' => $names[$id] ?? "#{$id}", 'total_usd' => $b['total_usd']])->values()->all(),
            'collections' => $movements->where('type', RiderCashMovement::COLLECTION)->count(),
            'handovers' => $handovers->count(),
            'differences' => $handovers->where('has_difference', true)->count(),
            'hours_to_handover' => self::stats($hours),
        ];
    }

    private function ridersPay(LogisticsCenter $center, Collection $rows, CarbonInterface $start, CarbonInterface $end, Collection $names): array
    {
        $own = $rows->filter(fn ($r) => $r->status === LogisticsStatus::DELIVERED && $r->rider_id && $r->departed_at);
        $earnings = Earning::where('role_type', 'repartidor')->whereIn('order_id', $rows->pluck('order_id'))->get()->groupBy('user_id');
        $trips = RiderTrip::where('center_id', $center->id)->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('rider_id');
        $payouts = RiderPayout::where('center_id', $center->id)->whereBetween('pay_date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('rider_id');
        $riderIds = $own->pluck('rider_id')->merge($trips->keys())->merge($payouts->keys())->unique();

        return $riderIds->map(fn ($id) => [
            'rider_id' => (int) $id,
            'name' => $names[$id] ?? "#{$id}",
            'deliveries' => $own->where('rider_id', $id)->count(),
            'earned_usd' => round(($earnings[$id] ?? collect())->sum('amount_usd') + ($trips[$id] ?? collect())->sum('amount_usd'), 2),
            'failed_legs' => ($trips[$id] ?? collect())->count(),
            'paid_usd' => round(($payouts[$id] ?? collect())->sum('amount_usd'), 2),
        ])->sortByDesc('deliveries')->values()->all();
    }

    private function incidents(LogisticsCenter $center, CarbonInterface $start, CarbonInterface $end): array
    {
        $list = LogisticsIncident::where('center_id', $center->id)->whereBetween('reported_at', [$start, $end])->get();

        return [
            'total' => $list->count(),
            'by_type' => $list->groupBy('type')->map(fn ($g, $t) => ['type' => $t, 'label' => LogisticsIncident::TYPES[$t] ?? $t, 'count' => $g->count()])
                ->sortByDesc('count')->values()->all(),
            'kept_going' => $list->where('status', LogisticsIncident::CONTINUES)->count(),
            'interrupted' => $list->whereNotNull('interrupted_at')->count(),
            'reassigned' => $list->whereNotNull('replacement_rider_id')->count() + $list->where('pickup', 'almacen')->count(),
        ];
    }

    /**
     * "Un rider o una pareja rider-operador que se sale del promedio es la señal de complicidad". Por rider: "no responde",
     * entregas fallidas, entregas o "no responde" fuera del sitio, diferencias de caja, retornos vencidos y asignaciones a
     * mano. Por operador: asignaciones a mano, novedades que resolvió él y diferencias de caja que recibió. Se marca a quien
     * tiene el doble del promedio o más (y al menos 2 casos).
     */
    private function signals(LogisticsCenter $center, Collection $rows, CarbonInterface $start, CarbonInterface $end, Collection $names): array
    {
        $ids = $rows->pluck('id');
        $count = fn (Collection $values) => $values->filter()->countBy()->all();
        $rider = [
            'no_responde' => $count(LogisticsNovelty::whereIn('logistics_order_id', $ids)->where('type', 'cliente_no_responde')->pluck('reported_by')),
            'fallidas' => $count($rows->whereNotNull('return_pending_at')->pluck('rider_id')),
            'fuera_del_sitio' => $count(LogisticsOrderEvent::whereIn('logistics_order_id', $ids)->whereIn('action', ['entrega_en_sitio', 'novedad_en_sitio'])
                ->get()->filter(fn ($e) => $e->data['fuera'] ?? false)->map(fn ($e) => $rows->firstWhere('id', $e->logistics_order_id)?->rider_id)),
            'diferencias_caja' => $count(RiderCashMovement::where('center_id', $center->id)->where('type', RiderCashMovement::HANDOVER)->where('has_difference', true)
                ->whereBetween('created_at', [$start, $end])->pluck('rider_id')),
            'retornos_vencidos' => $count(\App\Models\LogisticsAlert::where('center_id', $center->id)->where('type', 'retorno_vencido')
                ->whereBetween('created_at', [$start, $end])->pluck('rider_id')),
            'a_mano' => $count($rows->where('assigned_via', 'manual')->pluck('rider_id')),
        ];
        $operator = [
            'a_mano' => $count($rows->where('assigned_via', 'manual')->pluck('assigned_by')),
            'novedades_resueltas' => $count(LogisticsNovelty::whereIn('logistics_order_id', $ids)->whereNull('escalated_at')->whereNotNull('resolved_at')->pluck('resolved_by')),
            'diferencias_caja' => $count(RiderCashMovement::where('center_id', $center->id)->where('type', RiderCashMovement::HANDOVER)->where('has_difference', true)
                ->whereBetween('created_at', [$start, $end])->pluck('actor_id')),
        ];
        $people = User::whereIn('id', collect($rider)->flatMap(fn ($c) => array_keys($c))->merge(collect($operator)->flatMap(fn ($c) => array_keys($c)))->unique())
            ->pluck('names', 'id');
        $table = function (array $columns) use ($people) {
            $ids = collect($columns)->flatMap(fn ($c) => array_keys($c))->unique();
            $averages = collect($columns)->map(fn ($c) => $ids->count() ? array_sum($c) / $ids->count() : 0);

            return [
                'averages' => $averages->map(fn ($a) => round($a, 1))->all(),
                'rows' => $ids->map(function ($id) use ($columns, $averages, $people) {
                    $line = ['id' => (int) $id, 'name' => $people[$id] ?? "#{$id}", 'flags' => []];
                    foreach ($columns as $key => $values) {
                        $n = (int) ($values[$id] ?? 0);
                        $line[$key] = $n;
                        if ($n >= 2 && $n >= 2 * $averages[$key]) {
                            $line['flags'][] = $key;
                        }
                    }
                    return $line;
                })->sortByDesc(fn ($l) => count($l['flags']))->values()->all(),
            ];
        };

        return ['riders' => $table($rider), 'operators' => $table($operator)];
    }

    private static function pct(int $part, int $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }

    /** Promedio, mediana y P90 (§27: "promedio/mediana/P90"). */
    private static function stats(Collection $values): array
    {
        $sorted = $values->map(fn ($v) => (float) $v)->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return ['count' => 0, 'avg' => null, 'median' => null, 'p90' => null];
        }
        $at = fn (float $q) => $sorted[(int) min($n - 1, max(0, (int) ceil($q * $n) - 1))];

        return [
            'count' => $n,
            'avg' => round($sorted->avg(), 1),
            'median' => $n % 2 ? $sorted[intdiv($n, 2)] : round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2, 1),
            'p90' => $at(0.9),
        ];
    }
}
