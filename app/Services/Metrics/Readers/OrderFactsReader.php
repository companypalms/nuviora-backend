<?php

namespace App\Services\Metrics\Readers;

use App\Models\StatusChangeLog;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Lee, sin cambiar nada, los hechos de cada pedido para el panel del dueño. Salen de order_financials, la economía de
 * cada orden que ya mantiene Finanzas (la recalcula `finance:rebuild` al minuto de cualquier cambio), unida a la orden
 * para la hora exacta de creación y la tienda. Las reglas que convierten esto en números están en Metrics\Rules.
 */
class OrderFactsReader
{
    /**
     * Un hecho por pedido creado en el período: hora de creación, tipo (normal, cambio o devolución), qué pasó con él,
     * lo que dejó en dólares si se entregó y lo que costó (como lo agrupa Finanzas › Rentabilidad: el producto con su
     * traslado; las comisiones de vendedora, upsell y líder; el envío con la comisión del repartidor propio).
     *
     * @return LazyCollection<int, object>
     */
    public function facts(Period $period, Filters $filters): LazyCollection
    {
        return $this->base($period, $filters)
            ->whereNotNull('f.computed_at')
            ->select('o.created_at', 'o.shop_id', 'f.delivered_date', 'f.kind', 'f.outcome', 'f.product_id', 'f.city_id', 'f.cogs_source',
                'f.cogs_missing_lines', 'f.delivery_trips', 'f.delivery_final_trips', 'f.delivery_missing_trips')
            ->selectRaw('(f.revenue_main + f.revenue_upsell - f.revenue_refunded) AS revenue')
            ->selectRaw('(f.cogs_main + f.cogs_upsell + f.logistics_cost) AS product_cost')
            ->selectRaw('(f.commission_seller + f.commission_upsell + f.commission_leader) AS commissions')
            ->selectRaw('(f.delivery_cost + f.commission_deliverer) AS shipping')
            ->cursor();
    }

    /**
     * El recorrido de cada pedido creado en el período, para el embudo: su estado de hoy y la primera vez que llegó a los
     * estados de cada paso, del historial de cambios de estado (StatusChangeLog: order_status_logs hasta su CUTOFF,
     * order_tracking_comprehensive_logs desde ahí). El historial se lee solo de los pedidos del período.
     *
     * @param array<string, string[]> $steps paso => descripciones de sus estados (FunnelRules::STATUSES)
     * @return LazyCollection<int, object>
     */
    public function funnel(Period $period, Filters $filters, array $steps): LazyCollection
    {
        $ids = DB::table('statuses')->whereIn('description', array_merge(...array_values($steps)))->pluck('id', 'description');
        $idsOf = fn (string $step) => collect($steps[$step] ?? [])->map(fn ($d) => $ids[$d] ?? null)->filter()->values()->all() ?: [0];
        $all = $ids->values()->all() ?: [0];
        $orders = DB::table('order_financials')->whereBetween('created_date', [$period->fromDate(), $period->toDate()])->select('order_id');

        $columns = ['order_id', 'to_status_id', 'created_at'];
        $old = DB::table('order_status_logs')->select($columns)->where('created_at', '<', StatusChangeLog::CUTOFF)
            ->whereIn('order_id', $orders)->whereIn('to_status_id', $all);
        $new = DB::table('order_tracking_comprehensive_logs')->select($columns)->where('created_at', '>=', StatusChangeLog::CUTOFF)
            ->where(fn ($q) => $q->whereNull('from_status_id')->orWhereColumn('from_status_id', '!=', 'to_status_id'))
            ->whereIn('order_id', (clone $orders))->whereIn('to_status_id', $all);
        $first = fn (string $step) => 'MIN(CASE WHEN l.to_status_id IN (' . implode(',', array_map('intval', $idsOf($step))) . ') THEN l.created_at END)';
        $journey = DB::query()->fromSub($old->unionAll($new), 'l')->groupBy('l.order_id')
            ->selectRaw("l.order_id, {$first('assigned')} AS assigned_at, {$first('confirmed')} AS confirmed_at, {$first('on_route')} AS route_at");

        return $this->base($period, $filters)
            ->whereNotNull('f.computed_at')
            ->join('statuses as s', 's.id', '=', 'o.status_id')
            ->leftJoinSub($journey, 'j', 'j.order_id', '=', 'o.id')
            ->select('o.created_at', 'o.delivered_at', 'f.kind', 'f.outcome', 's.description as status', 'j.assigned_at', 'j.confirmed_at', 'j.route_at')
            ->cursor();
    }

    /**
     * Pedidos normales creados cada día por producto y ciudad, de todas las tiendas. Con el filtro de tienda, la
     * publicidad de cada producto y ciudad se reparte según cuántos de esos pedidos son de la tienda.
     *
     * @return array<int, array{date: string, product_id: ?int, city_id: ?int, orders: int}>
     */
    public function ordersByCell(Period $period): array
    {
        return DB::table('order_financials as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->whereBetween('f.created_date', [$period->fromDate(), $period->toDate()])
            ->whereBetween('o.created_at', [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')])
            ->whereNotNull('f.computed_at')->where('f.kind', 'normal')
            ->groupBy('f.created_date', 'f.product_id', 'f.city_id')
            ->selectRaw('f.created_date, f.product_id, f.city_id, COUNT(*) AS orders')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->created_date, 0, 10),
                'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                'city_id' => $r->city_id !== null ? (int) $r->city_id : null,
                'orders' => (int) $r->orders,
            ])->all();
    }

    /**
     * Pedidos del período que el sistema todavía está poniendo al día (un pedido nuevo o recién cambiado tarda hasta un
     * minuto). Sin el filtro de producto: el producto principal se conoce recién al calcularlo.
     */
    public function pending(Period $period, Filters $filters): int
    {
        return DB::table('order_financials as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->whereBetween('o.created_at', [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')])
            ->where(fn ($q) => $q->whereNotNull('f.stale_at')->orWhereNull('f.computed_at'))
            ->when($filters->shopId, fn ($q, $id) => $q->where('o.shop_id', $id))
            ->when($filters->cityId, fn ($q, $id) => $q->where('o.city_id', $id))
            ->count();
    }

    private function base(Period $period, Filters $filters): Builder
    {
        return DB::table('order_financials as f')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            // La fecha primero (tiene índice) y después la hora exacta: "hoy" llega hasta este momento
            ->whereBetween('f.created_date', [$period->fromDate(), $period->toDate()])
            ->whereBetween('o.created_at', [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')])
            ->when($filters->shopId, fn ($q, $id) => $q->where('o.shop_id', $id))
            ->when($filters->cityId, fn ($q, $id) => $q->where('f.city_id', $id))
            ->when($filters->productId, fn ($q, $id) => $q->where('f.product_id', $id));
    }
}
