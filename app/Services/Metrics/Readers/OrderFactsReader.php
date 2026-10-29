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
     * Un hecho por pedido creado en el período: hora de creación, tipo (normal, cambio o devolución), qué pasó con él
     * y lo que dejó en dólares si se entregó.
     *
     * @return LazyCollection<int, object>
     */
    public function facts(Period $period, Filters $filters): LazyCollection
    {
        return $this->base($period, $filters)
            ->whereNotNull('f.computed_at')
            ->select('o.created_at', 'f.delivered_date', 'f.kind', 'f.outcome')
            ->selectRaw('(f.revenue_main + f.revenue_upsell - f.revenue_refunded) AS revenue')
            ->cursor();
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
