<?php

namespace App\Services\Metrics\Readers;

use App\Constants\LogisticsStatus;
use App\Constants\OrderStatus;
use App\Models\AgencyClosureSetting;
use App\Models\AgencyWeeklyClosure;
use App\Models\DispatchOffer;
use App\Models\LogisticsCenter;
use App\Models\RiderCashMovement;
use App\Models\StatusChangeLog;
use App\Services\Logistics\LogisticsSettings;
use App\Services\Logistics\RiderCash;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\PersonName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Lee, sin cambiar nada, lo que hace falta para la sección Equipo del panel del dueño: el recorrido de cada pedido entre
 * vendedoras, agencias y repartidores, y lo que hizo cada operadora en la torre de control. Las reglas que lo
 * convierten en KPIs están en Metrics\Team.
 *
 * Quién tenía el pedido en cada momento:
 * - la vendedora: el historial de estados (order_tracking_comprehensive_logs guarda la vendedora de cada cambio; en el
 *   historial viejo, de antes del 12/02/2026, se usa la vendedora de hoy);
 * - la agencia y el repartidor: los cambios registrados en order_activity_logs ("Cambió la agencia de … a …").
 * Para no recorrer todo el historial, se leen los pedidos que entraron a ventas o a reparto en el período o hasta
 * LOOKBACK_DAYS antes (un pedido que se pasa de una vendedora o agencia a otra lo hace en esos días).
 */
class TeamReader
{
    public const LOOKBACK_DAYS = 30;

    /** Cuántos pedidos se leen por consulta. */
    private const CHUNK = 1000;

    public function __construct(private MoneyReader $money, private LogisticsSettings $logistics, private RiderCash $riderCash)
    {
    }

    /**
     * Las personas de esos roles, con lo que se muestra debajo de su nombre: el grupo de venta de la vendedora, el
     * nombre de la agencia del repartidor (o "Logística propia") y el centro de la operadora.
     *
     * @return array<int, array{name: string, group: ?string}>
     */
    public function people(array $roles): array
    {
        $users = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->whereIn('r.description', $roles)
            ->leftJoin('users as ag', 'ag.id', '=', 'u.agency_id')
            ->get(['u.id', 'u.names', 'u.surnames', 'r.description as role', 'ag.names as agency', 'ag.surnames as agency_surnames']);
        $ids = $users->pluck('id');
        $groups = DB::table('sales_group_members as m')->join('sales_groups as g', 'g.id', '=', 'm.sales_group_id')
            ->whereIn('m.user_id', $ids)->whereNull('m.ended_at')->pluck('g.name', 'm.user_id');
        $riders = DB::table('rider_profiles as p')->leftJoin('logistics_centers as c', 'c.id', '=', 'p.center_id')
            ->whereIn('p.user_id', $ids)->pluck('c.name', 'p.user_id');
        $centers = DB::table('logistics_center_operators as o')->join('logistics_centers as c', 'c.id', '=', 'o.center_id')
            ->whereIn('o.user_id', $ids)->get(['o.user_id', 'c.name'])->groupBy('user_id');

        $out = [];
        foreach ($users as $u) {
            $group = match ($u->role) {
                'Vendedor' => $groups[$u->id] ?? null,
                'Repartidor' => $u->agency ? PersonName::of($u->agency, $u->agency_surnames) : ($riders->has($u->id) ? 'Logística propia' : null),
                'Operador logístico' => isset($centers[$u->id]) ? $centers[$u->id]->pluck('name')->implode(', ') : null,
                default => null,
            };
            $out[(int) $u->id] = ['name' => PersonName::of($u->names, $u->surnames), 'group' => $group];
        }

        return $out;
    }

    /** Cuántas personas tiene cada rol del sistema (para los botones), y todos los roles. */
    public function roleCounts(): array
    {
        $counts = DB::table('roles as r')->leftJoin('users as u', 'u.role_id', '=', 'r.id')->groupBy('r.description')
            ->selectRaw('r.description, COUNT(u.id) AS people')->pluck('people', 'description');

        return $counts->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Los grupos de venta con las vendedoras que estuvieron en ellos en algún momento del período, y su líder.
     *
     * @return array<int, array{name: string, leader_id: ?int, leader: ?string, members: int[]}>
     */
    public function salesGroups(Period $period): array
    {
        $rows = DB::table('sales_group_members as m')->join('sales_groups as g', 'g.id', '=', 'm.sales_group_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where(fn ($q) => $q->whereNull('m.started_at')->orWhere('m.started_at', '<=', $period->end->format('Y-m-d H:i:s')))
            ->where(fn ($q) => $q->whereNull('m.ended_at')->orWhere('m.ended_at', '>=', $period->start->format('Y-m-d H:i:s')))
            ->orderBy('g.name')
            ->get(['g.id', 'g.name', 'm.user_id', 'm.role', 'u.names', 'u.surnames']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] ??= ['name' => $r->name, 'leader_id' => null, 'leader' => null, 'members' => []];
            $out[(int) $r->id]['members'][] = (int) $r->user_id;
            if ($r->role === 'leader') {
                $out[(int) $r->id]['leader_id'] = (int) $r->user_id;
                $out[(int) $r->id]['leader'] = PersonName::of($r->names, $r->surnames);
            }
        }
        foreach ($out as &$g) {
            $g['members'] = array_values(array_unique($g['members']));
        }

        return $out;
    }

    /**
     * Un recorrido por pedido normal (sin cambios ni devoluciones) que entró a ventas en el período o hasta
     * LOOKBACK_DAYS antes: su vendedora y su estado de hoy, lo que dejó, la comisión de esa vendedora y de la líder, el
     * motivo de su última cancelación y todo su historial de estados con la vendedora de cada cambio.
     */
    public function sellerJourneys(Period $period, Filters $filters, array $entry, array $stage): LazyCollection
    {
        return $this->chunked($this->reached($period, $entry), fn (array $ids) => $this->sellerChunk($ids, $filters));
    }

    private function sellerChunk(array $ids, Filters $filters): LazyCollection
    {
        $logs = DB::table('order_tracking_comprehensive_logs')->where('created_at', '>=', StatusChangeLog::CUTOFF)
            ->whereIn('order_id', $ids)
            ->selectRaw('order_id, created_at AS at, to_status_id, seller_id AS seller, 0 AS old, id AS seq')
            ->selectRaw('(from_status_id IS NULL OR from_status_id <> to_status_id) AS changed')
            // Se lo pasaron de otra vendedora, o se lo dieron a ella sin vendedora antes (no la creación del pedido)
            ->selectRaw('(was_reassigned = 1 OR (previous_seller_id IS NULL AND seller_id IS NOT NULL AND from_status_id IS NOT NULL)) AS reassigned')
            ->unionAll(DB::table('order_status_logs')->where('created_at', '<', StatusChangeLog::CUTOFF)->whereIn('order_id', $ids)
                ->selectRaw('order_id, created_at AS at, to_status_id, NULL AS seller, 1 AS old, id AS seq')
                ->selectRaw('(from_status_id IS NULL OR from_status_id <> to_status_id) AS changed, 0 AS reassigned'));

        $rows = $this->orders($filters)->whereIn('o.id', $ids)
            ->joinSub($logs, 'l', 'l.order_id', '=', 'o.id')
            ->join('statuses as ls', 'ls.id', '=', 'l.to_status_id')
            ->select('o.id as order_id', 'o.agent_id', 's.description as status', 'l.at', 'ls.description as log_status', 'l.changed', 'l.reassigned')
            ->selectRaw('CASE WHEN l.old = 1 THEN o.agent_id ELSE l.seller END AS seller')
            ->selectRaw('(f.revenue_main + f.revenue_upsell - f.revenue_refunded) AS revenue')
            ->selectRaw('EXISTS (SELECT 1 FROM order_products op WHERE op.order_id = o.id AND op.is_upsell = 1) AS has_upsell')
            ->selectRaw('(SELECT COUNT(*) FROM orders ch WHERE ch.parent_order_id = o.id) AS returns')
            ->selectRaw("COALESCE((SELECT SUM(e.amount_usd) FROM earnings e WHERE e.order_id = o.id AND e.user_id = o.agent_id AND e.role_type IN ('vendedor', 'upsell')), 0) AS commission")
            ->selectRaw('COALESCE((SELECT SUM(lc.amount_usd) FROM leader_commissions lc WHERE lc.order_id = o.id), 0) AS leader_commission')
            ->selectRaw("(SELECT COALESCE(c.category, 'sin_lista') FROM order_cancellations c WHERE c.order_id = o.id AND c.status = 'approved' ORDER BY c.id DESC LIMIT 1) AS reason_key")
            ->orderBy('o.id')->orderBy('l.at')->orderBy('l.old', 'desc')->orderBy('l.seq')
            ->cursor();

        return self::group($rows,
            fn ($r) => ['order_id' => (int) $r->order_id, 'agent_id' => $r->agent_id, 'status' => $r->status, 'revenue' => (float) $r->revenue,
                'has_upsell' => (bool) $r->has_upsell, 'returns' => (int) $r->returns, 'commission' => (float) $r->commission,
                'leader_commission' => (float) $r->leader_commission, 'reason_key' => $r->reason_key, 'logs' => []],
            fn ($r) => ['at' => (string) $r->at, 'status' => $r->log_status, 'seller' => $r->seller !== null ? (int) $r->seller : null,
                'changed' => (bool) $r->changed, 'reassigned' => (bool) $r->reassigned],
            'logs');
    }

    /**
     * Un recorrido por pedido normal que pasó por reparto en el período o hasta LOOKBACK_DAYS antes: su agencia, su estado
     * y la ciudad de hoy, cuándo se entregó, lo pagado en carreras a cada agencia y, en orden, sus cambios de estado y de
     * agencia (los cambios de agencia van primero si fueron al mismo tiempo que un cambio de estado).
     */
    public function agencyJourneys(Period $period, Filters $filters, array $stage): LazyCollection
    {
        return $this->chunked($this->reached($period, [OrderStatus::ASIGNAR_A_AGENCIA]), fn (array $ids) => $this->agencyChunk($ids, $filters));
    }

    private function agencyChunk(array $ids, Filters $filters): LazyCollection
    {
        $status = DB::table('order_tracking_comprehensive_logs')->where('created_at', '>=', StatusChangeLog::CUTOFF)
            ->whereIn('order_id', $ids)->where(fn ($q) => $q->whereNull('from_status_id')->orWhereColumn('from_status_id', '<>', 'to_status_id'))
            ->selectRaw("order_id, created_at AS at, 1 AS kind_order, 'status' AS kind, to_status_id, NULL AS new_value, NULL AS old_value, id AS seq")
            ->unionAll(DB::table('order_status_logs')->where('created_at', '<', StatusChangeLog::CUTOFF)->whereIn('order_id', $ids)
                ->whereColumn('from_status_id', '<>', 'to_status_id')
                ->selectRaw("order_id, created_at AS at, 1 AS kind_order, 'status' AS kind, to_status_id, NULL AS new_value, NULL AS old_value, id AS seq"))
            ->unionAll($this->changes('agency_id', $ids));

        $rows = $this->orders($filters)->whereIn('o.id', $ids)
            ->joinSub($status, 'ev', 'ev.order_id', '=', 'o.id')
            ->leftJoin('statuses as es', 'es.id', '=', 'ev.to_status_id')
            ->leftJoin('cities as ci', 'ci.id', '=', 'f.city_id')
            ->select('o.id as order_id', 'o.agency_id', 's.description as status', 'o.delivered_at', 'ci.name as city',
                'ev.at', 'ev.kind', 'es.description as event_status', 'ev.new_value', 'ev.old_value')
            ->selectRaw("(SELECT GROUP_CONCAT(CONCAT(t.agency_id, ':', t.price_usd)) FROM agency_trips t WHERE t.order_id = o.id AND t.voided_at IS NULL) AS trips")
            ->orderBy('o.id')->orderBy('ev.at')->orderBy('ev.kind_order')->orderBy('ev.seq')
            ->cursor();

        return self::group($rows,
            function ($r) {
                $trips = [];
                foreach (array_filter(explode(',', (string) $r->trips)) as $pair) {
                    [$agency, $usd] = array_pad(explode(':', $pair, 2), 2, 0);
                    $trips[(int) $agency] = ($trips[(int) $agency] ?? 0) + (float) $usd;
                }

                return ['order_id' => (int) $r->order_id, 'agency_id' => $r->agency_id !== null ? (int) $r->agency_id : null, 'status' => $r->status,
                    'delivered_at' => $r->delivered_at ? (string) $r->delivered_at : null, 'city' => $r->city ?? 'Sin ciudad', 'trips' => $trips, 'events' => []];
            },
            fn ($r) => $r->kind === 'agency'
                ? ['at' => (string) $r->at, 'kind' => 'agency', 'status' => null, 'changed' => true, 'agency' => self::id($r->new_value), 'old' => self::id($r->old_value)]
                : ['at' => (string) $r->at, 'kind' => 'status', 'status' => $r->event_status, 'changed' => true, 'agency' => null, 'old' => null],
            'events');
    }

    /**
     * Un recorrido por pedido normal que pasó por reparto en el período o hasta LOOKBACK_DAYS antes: su repartidor y su
     * estado de hoy, cuándo se entregó, lo que ganó el repartidor de hoy con él (comisión y carreras que no se
     * entregaron) y, en orden, los cambios de repartidor.
     */
    public function riderJourneys(Period $period, Filters $filters): LazyCollection
    {
        $ids = $this->reached($period, [OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::EN_RUTA]);

        return $this->chunked($ids, fn (array $chunk) => $this->riderChunk($chunk, $filters));
    }

    private function riderChunk(array $ids, Filters $filters): LazyCollection
    {
        $rows = $this->orders($filters)->whereIn('o.id', $ids)
            ->joinSub($this->changes('deliverer_id', $ids), 'ev', 'ev.order_id', '=', 'o.id')
            ->select('o.id as order_id', 'o.deliverer_id', 's.description as status', 'o.delivered_at', 'ev.at', 'ev.new_value')
            ->selectRaw("COALESCE((SELECT SUM(e.amount_usd) FROM earnings e WHERE e.order_id = o.id AND e.user_id = o.deliverer_id AND e.role_type = 'repartidor'), 0)
                + COALESCE((SELECT SUM(t.amount_usd) FROM rider_trips t WHERE t.order_id = o.id AND t.rider_id = o.deliverer_id), 0) AS earning")
            ->orderBy('o.id')->orderBy('ev.at')->orderBy('ev.seq')
            ->cursor();

        return self::group($rows,
            fn ($r) => ['order_id' => (int) $r->order_id, 'deliverer_id' => $r->deliverer_id !== null ? (int) $r->deliverer_id : null, 'status' => $r->status,
                'delivered_at' => $r->delivered_at ? (string) $r->delivered_at : null, 'earning' => (float) $r->earning, 'events' => []],
            fn ($r) => ['at' => (string) $r->at, 'deliverer' => self::id($r->new_value)],
            'events');
    }

    /**
     * Lo que cada repartidor de la logística propia confirmó de lo que la app le ofreció en el período. Solo las ofertas
     * que dependían de él: no cuentan las que tomó otro rider primero ni las de pedidos que se cancelaron.
     */
    public function riderOffers(Period $period): array
    {
        return DB::table('dispatch_offers')->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')])
            ->whereIn('response', [DispatchOffer::CONFIRMED, DispatchOffer::UNCONFIRMED])
            ->groupBy('rider_id')
            ->selectRaw('rider_id, COUNT(*) AS sent, SUM(response = ?) AS confirmed', [DispatchOffer::CONFIRMED])
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->rider_id => ['sent' => (int) $r->sent, 'confirmed' => (int) $r->confirmed]])
            ->all();
    }

    /** El efectivo que tiene cada repartidor ahora mismo (USD y bolívares, en dólares). */
    public function riderCash(array $riderIds): array
    {
        return array_map(fn ($b) => (float) $b['total_usd'], $this->riderCash->balances($riderIds));
    }

    /** Lo que cada agencia tiene ahora mismo de pedidos entregados (Finanzas › Caja, el mismo "Por cobrar" de Dinero). */
    public function agencyCash(): array
    {
        $out = [];
        foreach ($this->money->receivables() as $r) {
            if ($r['agency_id'] !== null) {
                $out[(int) $r['agency_id']] = (float) $r['usd'];
            }
        }

        return $out;
    }

    /**
     * Los cierres semanales que vencían en el período (el aviso: el día y la hora de la configuración de la agencia,
     * en la semana siguiente), si se subieron antes de vencer y si tuvieron diferencias: carreras que no cuadraron, o
     * un total cobrado fuera de la tolerancia de la agencia.
     *
     * @return array<int, array{agency_id: int, on_time: bool, differences: int}>
     */
    public function agencyClosures(Period $period): array
    {
        $closures = AgencyWeeklyClosure::whereBetween('period_end', [$period->start->subDays(8)->toDateString(), $period->toDate()])
            ->withCount(['discrepancies'])->get();
        $settings = AgencyClosureSetting::whereIn('agency_id', $closures->pluck('agency_id'))->get()->keyBy('agency_id');
        $now = Carbon::now();
        $out = [];
        foreach ($closures as $c) {
            $setting = $settings[$c->agency_id] ?? AgencyClosureSetting::for((int) $c->agency_id);
            $deadline = $c->reminderAt($setting);
            if ($deadline->lt($period->start) || $deadline->gt($period->end) || $deadline->gt($now)) {
                continue;
            }
            $offTotal = $c->expected_total !== null && $c->reported_total !== null && !$setting->withinTolerance($c->expected_total, $c->reported_total);
            $out[] = [
                'agency_id' => (int) $c->agency_id,
                'on_time' => $c->uploaded_at !== null && $c->uploaded_at->lte($deadline),
                'differences' => (int) $c->discrepancies_count + ($offTotal ? 1 : 0),
            ];
        }

        return $out;
    }

    /**
     * Lo que hicieron las operadoras en la torre de control. Con el filtro de ciudad, solo el centro de esa ciudad (los
     * de tienda y producto no se aplican: la operadora trabaja con todos los pedidos de su centro).
     */
    public function operatorFacts(Period $period, Filters $filters): array
    {
        $start = $period->start->format('Y-m-d H:i:s');
        $end = $period->end->format('Y-m-d H:i:s');
        $centers = LogisticsCenter::when($filters->cityId, fn ($q, $id) => $q->where('city_id', $id))->get();
        $ids = $centers->pluck('id')->all() ?: [0];
        $targets = $centers->mapWithKeys(fn ($c) => [$c->id => (int) $this->logistics->for($c)['sla_target_minutes']])->all();

        $events = DB::table('logistics_order_events as e')->join('logistics_orders as lo', 'lo.id', '=', 'e.logistics_order_id')
            ->whereIn('lo.center_id', $ids)->whereBetween('e.created_at', [$start, $end])
            ->whereIn('e.action', ['salida', 'entregada_a_agencia', 'listo_despacho', 'a_agencia', 'asignada_a_mano'])
            ->get(['e.actor_id', 'e.action', 'e.created_at', 'lo.center_id', 'lo.rider_confirmed_at', 'lo.sla_started_at', 'lo.status', 'lo.delivered_at'])
            ->map(fn ($e) => [
                'actor' => $e->actor_id !== null ? (int) $e->actor_id : null, 'action' => $e->action, 'at' => (string) $e->created_at,
                'center' => (int) $e->center_id, 'rider_confirmed_at' => $e->rider_confirmed_at, 'sla_started_at' => $e->sla_started_at,
                'delivered_at' => $e->status === LogisticsStatus::DELIVERED ? $e->delivered_at : null, 'target' => $targets[$e->center_id] ?? 45,
            ])->all();

        return [
            'shifts' => DB::table('logistics_shifts')->whereIn('center_id', $ids)->where('started_at', '<=', $end)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
                ->get(['user_id', 'center_id', 'started_at', 'ended_at', 'close_type'])
                ->map(fn ($s) => ['user' => (int) $s->user_id, 'center' => (int) $s->center_id, 'started_at' => (string) $s->started_at,
                    'ended_at' => $s->ended_at ? (string) $s->ended_at : null, 'close_type' => $s->close_type])->all(),
            'events' => $events,
            'released' => DB::table('logistics_orders')->whereIn('center_id', $ids)->whereBetween('sla_started_at', [$start, $end])
                ->get(['center_id', 'sla_started_at'])->map(fn ($r) => ['center' => (int) $r->center_id, 'at' => (string) $r->sla_started_at])->all(),
            'novelties' => DB::table('logistics_novelties as n')->join('logistics_orders as lo', 'lo.id', '=', 'n.logistics_order_id')
                ->whereIn('lo.center_id', $ids)->whereRaw('COALESCE(n.escalated_at, n.resolved_at) BETWEEN ? AND ?', [$start, $end])
                ->get(['n.resolved_by', 'n.escalated_by', 'n.opened_at', 'n.created_at', 'n.escalated_at', 'n.resolved_at'])
                ->map(fn ($n) => ['resolved_by' => $n->resolved_by, 'escalated_by' => $n->escalated_by,
                    'opened_at' => (string) ($n->opened_at ?? $n->created_at), 'done_at' => (string) ($n->escalated_at ?? $n->resolved_at)])->all(),
            'alerts' => DB::table('logistics_alerts')->whereIn('center_id', $ids)->whereNotNull('resolved_by')->whereBetween('resolved_at', [$start, $end])
                ->get(['resolved_by', 'created_at', 'resolved_at'])
                ->map(fn ($a) => ['resolved_by' => $a->resolved_by, 'created_at' => (string) $a->created_at, 'resolved_at' => (string) $a->resolved_at])->all(),
            'handovers' => DB::table('rider_cash_movements')->whereIn('center_id', $ids)->where('type', RiderCashMovement::HANDOVER)
                ->whereBetween('created_at', [$start, $end])->get(['actor_id', 'has_difference'])
                ->map(fn ($h) => ['actor' => $h->actor_id, 'has_difference' => (bool) $h->has_difference])->all(),
            'now' => Carbon::now()->format('Y-m-d H:i:s'),
        ];
    }

    /** Los pedidos normales ya calculados por Finanzas (order_financials), con los filtros del panel y su estado de hoy. */
    private function orders(Filters $filters): Builder
    {
        return DB::table('orders as o')
            ->join('order_financials as f', 'f.order_id', '=', 'o.id')
            ->join('statuses as s', 's.id', '=', 'o.status_id')
            ->whereNotNull('f.computed_at')->where('f.kind', 'normal')
            ->when($filters->shopId, fn ($q, $id) => $q->where('o.shop_id', $id))
            ->when($filters->cityId, fn ($q, $id) => $q->where('f.city_id', $id))
            ->when($filters->productId, fn ($q, $id) => $q->where('f.product_id', $id));
    }

    /**
     * Los pedidos (de menor a mayor) que llegaron a alguno de esos estados en el período o hasta LOOKBACK_DAYS antes. Se
     * buscan por el estado (tiene índice) y después se lee el historial de cada uno por su número de pedido.
     *
     * @return int[]
     */
    private function reached(Period $period, array $statuses): array
    {
        $ids = $this->statusIds($statuses);
        $range = [$period->start->subDays(self::LOOKBACK_DAYS)->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')];
        $read = fn (string $table) => DB::table($table)->whereIn('to_status_id', $ids)->whereBetween('created_at', $range)->distinct()->pluck('order_id');

        return $read('order_tracking_comprehensive_logs')->merge($read('order_status_logs'))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** Lee los recorridos de a CHUNK pedidos (en orden), para que cada consulta busque por número de pedido. */
    private function chunked(array $ids, callable $read): LazyCollection
    {
        return LazyCollection::make(function () use ($ids, $read) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach ($read($chunk) as $journey) {
                    yield $journey;
                }
            }
        });
    }

    /** Los cambios de un campo de la orden (agency_id o deliverer_id) que registró order_activity_logs. */
    private function changes(string $field, array $orders): Builder
    {
        return DB::table('order_activity_logs')->where('action', 'updated')->whereIn('order_id', $orders)
            ->whereRaw("JSON_CONTAINS_PATH(properties, 'one', ?)", ["$.{$field}"])
            ->selectRaw("order_id, created_at AS at, 0 AS kind_order, 'agency' AS kind, NULL AS to_status_id")
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(properties, ?)) AS new_value', ["$.{$field}.new"])
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(properties, ?)) AS old_value', ["$.{$field}.old"])
            ->selectRaw('id AS seq');
    }

    private function statusIds(array $descriptions): array
    {
        return DB::table('statuses')->whereIn('description', $descriptions)->pluck('id')->all() ?: [0];
    }

    /** "12" → 12; null, "null" o "" → null (JSON_UNQUOTE de un null de JSON da "null"). */
    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Junta las filas seguidas de un mismo pedido (vienen ordenadas por pedido) en un recorrido: los datos del pedido
     * una vez y sus eventos en una lista.
     */
    private static function group(LazyCollection $rows, callable $head, callable $item, string $list): LazyCollection
    {
        return LazyCollection::make(function () use ($rows, $head, $item, $list) {
            $current = null;
            $id = null;
            foreach ($rows as $r) {
                if ($id !== (int) $r->order_id) {
                    if ($current !== null) {
                        yield $current;
                    }
                    $id = (int) $r->order_id;
                    $current = $head($r);
                }
                $current[$list][] = $item($r);
            }
            if ($current !== null) {
                yield $current;
            }
        });
    }
}
