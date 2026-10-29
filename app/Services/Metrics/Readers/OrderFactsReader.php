<?php

namespace App\Services\Metrics\Readers;

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
