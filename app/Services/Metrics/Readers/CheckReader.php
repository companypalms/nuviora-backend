<?php

namespace App\Services\Metrics\Readers;

use App\Constants\OrderStatus;
use App\Models\AgencyClosureRow;
use App\Models\AgencyWeeklyClosure;
use App\Models\OrderDeliveryRoute;
use App\Services\Finance\PurchaseCost;
use App\Services\Finance\TripCostAverage;
use App\Services\Metrics\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El segundo camino de la revisión de los números (`.48a`): lee, sin cambiar nada, lo mismo que suma el panel pero desde
 * las tablas de origen (órdenes, comisiones, carreras, Meta), sin pasar por order_financials. Y los datos que pueden
 * hacer que un número salga mal. Las reglas que comparan están en Metrics\Rules\CheckRules.
 */
class CheckReader
{
    private ?array $statusIds = null;

    /** Los pedidos creados en el período (de todo tipo). */
    public function orderIds(Period $period): array
    {
        return $this->orders($period)->orderBy('o.id')->pluck('o.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Lo guardado de cada pedido (order_financials), por order_id, con sus marcas de recálculo. */
    public function stored(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('order_financials')->whereIn('order_id', $chunk)->get() as $r) {
                $out[(int) $r->order_id] = (array) $r;
            }
        }

        return $out;
    }

    /** Número y nombre de cada pedido, para los ejemplos. */
    public function orderNames(array $ids): array
    {
        return DB::table('orders')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n, $id) => $n ?: "id {$id}")->all();
    }

    /**
     * Los pedidos normales (sin cambios ni devoluciones) según su estado de hoy, y lo vendido de los entregados: lo de
     * Ventas (solo normales) y lo de Dinero (de todo tipo). Lo vendido es el total de la orden menos lo devuelto al
     * cliente (todo, si tiene una devolución entregada).
     *
     * @return array{created: int, delivered: int, not_delivered: int, open: int, revenue_normal: float, revenue_all: float}
     */
    public function sales(Period $period): array
    {
        $st = $this->statusIds();
        $normal = 'COALESCE(o.is_return, 0) = 0 AND COALESCE(o.is_exchange, 0) = 0';
        $total = 'ROUND(COALESCE(o.current_total_price, 0), 2)';
        $returned = 'EXISTS (SELECT 1 FROM orders r WHERE r.parent_order_id = o.id AND r.is_return = 1 AND r.status_id = ?)';
        $revenue = "({$total} - ROUND(LEAST({$total}, CASE WHEN {$returned} THEN {$total} ELSE COALESCE(o.refunded_usd, 0) END), 2))";
        $r = $this->orders($period)->selectRaw(
            "SUM({$normal}) AS created,
             SUM({$normal} AND o.status_id = ?) AS delivered,
             SUM({$normal} AND o.status_id IN (?, ?)) AS not_delivered,
             SUM(CASE WHEN o.status_id = ? AND {$normal} THEN {$revenue} ELSE 0 END) AS revenue_normal,
             SUM(CASE WHEN o.status_id = ? THEN {$revenue} ELSE 0 END) AS revenue_all",
            [$st['delivered'], $st['cancelled'], $st['rejected'], $st['delivered'], $st['delivered'], $st['delivered'], $st['delivered']]
        )->first();

        return [
            'created' => (int) $r->created,
            'delivered' => (int) $r->delivered,
            'not_delivered' => (int) $r->not_delivered,
            'open' => (int) $r->created - (int) $r->delivered - (int) $r->not_delivered,
            'revenue_normal' => round((float) $r->revenue_normal, 2),
            'revenue_all' => round((float) $r->revenue_all, 2),
        ];
    }

    /**
     * Las comisiones de los pedidos entregados: todas las de earnings menos el envío (repartidor y la agencia de antes),
     * más la parte de la líder. Por pedido y redondeadas al centavo, como se guardan.
     */
    public function commissions(Period $period): float
    {
        $delivered = $this->statusIds()['delivered'];
        $earnings = DB::query()->fromSub(
            DB::table('earnings as e')->join('orders as o', 'o.id', '=', 'e.order_id')
                ->whereBetween('o.created_at', $this->bounds($period))->where('o.status_id', $delivered)
                ->whereNotIn('e.role_type', ['repartidor', 'agencia'])
                ->groupBy('e.order_id', 'e.role_type')->selectRaw('ROUND(SUM(e.amount_usd), 2) AS amount'),
            'x'
        )->sum('amount');
        $leader = DB::query()->fromSub(
            DB::table('leader_commissions as l')->join('orders as o', 'o.id', '=', 'l.order_id')
                ->whereBetween('o.created_at', $this->bounds($period))->where('o.status_id', $delivered)
                ->groupBy('l.order_id')->selectRaw('ROUND(SUM(l.amount_usd), 2) AS amount'),
            'x'
        )->sum('amount');

        return round((float) $earnings + (float) $leader, 2);
    }

    /**
     * El envío de todos los pedidos creados en el período, entregados o no: cada carrera no anulada con su costo aprobado
     * en un cierre listo para pago o pagado; si no, el esperado de su ruta calculada; si no, $0. Antes de las carreras,
     * lo que se le pagaba a la agencia por la orden (si salió). Y la comisión del repartidor propio, si se entregó. Desde
     * la `.49`, cada carrera sin costo cuenta el promedio de su ciudad (el mismo que usa el panel).
     */
    public function shipping(Period $period): float
    {
        $delivered = $this->statusIds()['delivered'];
        $orders = $this->orders($period)->select('o.id');
        $approved = DB::table('agency_closure_rows as cr')->join('agency_weekly_closures as c', 'c.id', '=', 'cr.closure_id')
            ->where('cr.is_current', true)->whereIn('cr.match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])
            ->whereIn('c.status', [AgencyWeeklyClosure::READY, AgencyWeeklyClosure::PAID])->whereNotNull('cr.matched_trip_id')
            ->groupBy('cr.matched_trip_id')->selectRaw('cr.matched_trip_id, SUM(COALESCE(cr.approved_cost, 0)) AS cost');
        $trips = DB::table('agency_trips as t')->leftJoin('order_delivery_routes as r', 'r.id', '=', 't.delivery_route_id')
            ->leftJoinSub($approved, 'ap', 'ap.matched_trip_id', '=', 't.id')
            ->whereNull('t.voided_at')->whereIn('t.order_id', $orders)
            ->groupBy('t.order_id')
            ->selectRaw('t.order_id, SUM(CASE WHEN ap.matched_trip_id IS NOT NULL THEN ap.cost
                WHEN r.calculation_status = ? AND r.expected_cost IS NOT NULL THEN r.expected_cost ELSE 0 END) AS cost,
                SUM(ap.matched_trip_id IS NULL AND NOT (r.calculation_status <=> ? AND r.expected_cost IS NOT NULL)) AS missing',
                [OrderDeliveryRoute::CALCULATED, OrderDeliveryRoute::CALCULATED])
            ->get()->keyBy('order_id');
        $cities = DB::table('orders')->whereIn('id', $trips->keys())->pluck('city_id', 'id');
        $average = TripCostAverage::forPanel($period->toDate());
        $legacy = DB::table('earnings as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereIn('e.order_id', (clone $orders))->where('e.role_type', 'agencia')->where('o.was_shipped', true)
            ->whereNotExists(fn ($q) => $q->from('agency_trips as t2')->whereColumn('t2.order_id', 'e.order_id')
                ->whereColumn('t2.earning_id', 'e.id')->whereNull('t2.voided_at'))
            ->groupBy('e.order_id')->selectRaw('e.order_id, SUM(e.amount_usd) AS amount')->pluck('amount', 'order_id');
        $rider = DB::table('earnings as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereIn('e.order_id', (clone $orders))->where('e.role_type', 'repartidor')->where('o.status_id', $delivered)
            ->groupBy('e.order_id')->selectRaw('e.order_id, SUM(e.amount_usd) AS amount')->pluck('amount', 'order_id');

        $total = 0.0;
        foreach ($trips->keys()->merge($legacy->keys())->unique() as $id) {
            $total += round((float) ($trips[$id]->cost ?? 0) + round((float) ($legacy[$id] ?? 0), 2), 2);
            $city = $cities[$id] ?? null;
            $total += TripCostAverage::fill((int) ($trips[$id]->missing ?? 0), $city !== null ? (int) $city : null, $average);
        }
        foreach ($rider as $amount) {
            $total += round((float) $amount, 2);
        }

        return round($total, 2);
    }

    /** Todo lo gastado en Meta esos días, campaña por campaña, sin importar si está clasificada. */
    public function ads(Period $period): float
    {
        return round((float) DB::table('meta_insights_daily')->where('level', 'campaign')
            ->whereBetween('date', [$period->fromDate(), $period->toDate()])->sum('spend'), 2);
    }

    /**
     * Los datos que pueden hacer que un número salga mal, para las reglas: cada uno con cuántos casos, el monto si aplica
     * y algunos ejemplos.
     */
    public function problems(Period $period, array $stored): array
    {
        $st = $this->statusIds();
        $from = $period->fromDate();
        $to = $period->toDate();
        $delivered = array_filter($stored, fn ($r) => ($r['outcome'] ?? null) === 'delivered');
        $out = [];

        // Productos vendidos que nunca tuvieron una compra registrada (sin costo)
        $sold = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')
            ->whereBetween('o.created_at', $this->bounds($period))->where('o.status_id', $st['delivered'])
            ->whereRaw('COALESCE(o.is_return, 0) = 0')
            ->whereNotNull('op.product_id')->groupBy('op.product_id')->selectRaw('op.product_id, COUNT(DISTINCT op.order_id) AS orders')
            ->pluck('orders', 'product_id');
        $purchases = PurchaseCost::for($sold->keys()->all());
        $noCost = $sold->filter(fn ($n, $pid) => !$purchases->has((int) $pid));
        $names = DB::table('products')->whereIn('id', $noCost->keys())->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => $p->showable_name ?: $p->title]);
        $out['no_cost'] = [
            'count' => (int) $noCost->sum(),
            'examples' => $noCost->sortDesc()->map(fn ($n, $pid) => ($names[$pid] ?? "Producto {$pid}") . " ({$n})")->values()->all(),
        ];

        $out['old_catalog'] = ['count' => count(array_filter($delivered, fn ($r) => ($r['cogs_source'] ?? null) === 'catalog'))];
        $out['provisional_cost'] = ['count' => count(array_filter($delivered, fn ($r) => in_array($r['cogs_source'] ?? null, ['last', 'mixed'], true)))];
        $out['trips_estimated'] = ['count' => array_sum(array_map(fn ($r) => max(0, (int) $r['delivery_trips'] - (int) $r['delivery_final_trips'] - (int) $r['delivery_missing_trips']), $stored))];
        $out['trips_missing'] = ['count' => array_sum(array_map(fn ($r) => (int) $r['delivery_missing_trips'], $stored))];

        // Meta: lo que no tiene producto y ciudad, cuentas en otra moneda y días sin datos
        $insights = fn () => DB::table('meta_insights_daily as d')->where('d.level', 'campaign')->whereBetween('d.date', [$from, $to]);
        $unclassified = $insights()->leftJoin('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where(fn ($q) => $q->whereNull('c.product_id')->orWhereNull('c.city_id'))
            ->groupBy('d.campaign_meta_id')->selectRaw('d.campaign_meta_id, MAX(c.name) AS name, SUM(d.spend) AS spend')
            ->havingRaw('SUM(d.spend) <> 0')->orderByDesc('spend')->get();
        $out['unclassified_ads'] = [
            'count' => $unclassified->count(),
            'amount' => (float) $unclassified->sum('spend'),
            'examples' => $unclassified->map(fn ($c) => ($c->name ?: $c->campaign_meta_id) . ' ($' . number_format((float) $c->spend, 2, ',', '.') . ')')->all(),
        ];
        $foreign = $insights()->join('meta_ad_accounts as a', 'a.id', '=', 'd.meta_ad_account_id')
            ->whereNotNull('a.currency')->where('a.currency', '<>', 'USD')
            ->groupBy('a.id')->selectRaw('MAX(a.name) AS name, MAX(a.currency) AS currency, SUM(d.spend) AS spend')->havingRaw('SUM(d.spend) <> 0')->get();
        $out['foreign_currency'] = ['count' => $foreign->count(), 'amount' => (float) $foreign->sum('spend'),
            'examples' => $foreign->map(fn ($a) => "{$a->name} ({$a->currency})")->all()];
        $withData = array_flip($insights()->distinct()->pluck('d.date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $missing = $withData === [] ? [] : array_values(array_filter($period->dates(), fn ($d) => !isset($withData[$d]) && $d < now()->toDateString()));
        $out['meta_missing_days'] = ['count' => count($missing), 'examples' => $missing];

        // Comisiones guardadas de pedidos que hoy no están entregados (Ganancias globales y los pagos las cuentan). La
        // agencia de antes de las carreras no: se le pagaba por pedido que salía, se entregara o no
        $notDelivered = DB::table('earnings as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereBetween('o.created_at', $this->bounds($period))->where('o.status_id', '<>', $st['delivered'])
            ->where('e.role_type', '<>', 'agencia')->where('e.amount_usd', '<>', 0)
            ->selectRaw('e.role_type, COUNT(DISTINCT e.order_id) AS orders, SUM(e.amount_usd) AS amount')->groupBy('e.role_type')->get();
        $out['earnings_not_delivered'] = ['count' => (int) $notDelivered->sum('orders'), 'amount' => (float) $notDelivered->sum('amount'),
            'examples' => $notDelivered->map(fn ($r) => "{$r->role_type}: {$r->orders} ($" . number_format((float) $r->amount, 2, ',', '.') . ')')->all()];

        $noDate = $this->orders($period)->where('o.status_id', $st['delivered'])->whereNull('o.delivered_at')->pluck('o.name')->all();
        $out['delivered_without_date'] = ['count' => count($noDate), 'examples' => $noDate];
        // Los cambios valen $0 a propósito (no son venta): solo cuentan los pedidos normales
        $zero = $this->orders($period)->where('o.status_id', $st['delivered'])->whereRaw('COALESCE(o.is_return, 0) = 0 AND COALESCE(o.is_exchange, 0) = 0')
            ->whereRaw('COALESCE(o.current_total_price, 0) <= 0')->pluck('o.name')->all();
        $out['zero_price'] = ['count' => count($zero), 'examples' => $zero];

        return $out;
    }

    /** Los pedidos creados en el período, a la hora exacta (el panel corta "hoy" en este momento). */
    private function orders(Period $period): Builder
    {
        return DB::table('orders as o')->whereBetween('o.created_at', $this->bounds($period));
    }

    private function bounds(Period $period): array
    {
        return [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')];
    }

    /** @return array{delivered: int, cancelled: int, rejected: int} */
    private function statusIds(): array
    {
        if ($this->statusIds === null) {
            $ids = DB::table('statuses')->whereIn('description', [OrderStatus::ENTREGADO, OrderStatus::CANCELADO, OrderStatus::RECHAZADO])
                ->pluck('id', 'description');
            $this->statusIds = [
                'delivered' => (int) ($ids[OrderStatus::ENTREGADO] ?? -1),
                'cancelled' => (int) ($ids[OrderStatus::CANCELADO] ?? -2),
                'rejected' => (int) ($ids[OrderStatus::RECHAZADO] ?? -3),
            ];
        }

        return $this->statusIds;
    }
}
