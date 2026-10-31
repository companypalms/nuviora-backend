<?php

namespace App\Services\Finance;

use App\Models\InventoryLot;
use Illuminate\Support\Facades\DB;

/**
 * El costo de un producto sale solo del historial del inventario (regla del dueño, 2026-10-31): "el sistema solo debe saber
 * cuánto cuesta una unidad cuando se registra la compra en el inventario; de ninguna otra manera". El costo de la ficha
 * del producto (products.cost_usd) ya no se usa.
 *
 * - Un lote de una compra registrada (o completado a mano en Finanzas › Inventario, que queda en el historial) vale lo
 *   suyo; la pieza recuperada en un cambio, $0 (su costo ya se contó en la orden).
 * - Todo lo demás (el stock del corte, un ajuste de conteo, algo que entró sin compra, o lo que se vendió sin stock) toma
 *   el costo de la última compra de ese producto hasta ese día; si es de antes de la primera compra, el de la primera.
 *   Así un producto que se quedó sin stock no aparece "sin costo".
 * - Un producto que nunca tuvo una compra registrada no tiene costo: "todavía no tiene costo", y el CPA no lo calcula.
 */
final class PurchaseCost
{
    /** Los lotes cuyo costo vale tal cual. */
    public const OWN = ['purchase', 'manual', 'recovered'];

    /** De dónde sale el costo de referencia de un producto: sus compras registradas y lo completado a mano. */
    public const REFERENCE = ['purchase', 'manual'];

    /** El costo que el sistema le pone a un lote que no es de una compra: el de la última compra. */
    public const DERIVED = 'last_purchase';

    /** @var array<int, list<array{0: string, 1: float}>> producto => [fecha, costo] de la más vieja a la más nueva */
    private array $history = [];

    /** @param iterable<int>|null $productIds null = todos */
    public static function for(?iterable $productIds = null): self
    {
        $self = new self();
        $ids = $productIds === null ? null : array_values(array_unique(array_map('intval', is_array($productIds) ? $productIds : iterator_to_array($productIds))));
        if ($ids === []) {
            return $self;
        }
        $rows = DB::table('inventory_lots')->whereIn('cost_source', self::REFERENCE)->where('unit_cost_usd', '>', 0)
            ->when($ids !== null, fn ($q) => $q->whereIn('product_id', $ids))
            ->orderBy('received_at')->orderBy('id')->get(['product_id', 'received_at', 'unit_cost_usd']);
        foreach ($rows as $r) {
            $self->history[(int) $r->product_id][] = [substr((string) $r->received_at, 0, 19), round((float) $r->unit_cost_usd, 4)];
        }

        return $self;
    }

    /** El costo de una unidad de ese producto en esa fecha (null = hoy), o null si nunca tuvo una compra. */
    public function at(int $productId, ?string $date = null): ?float
    {
        return self::pick($this->history[$productId] ?? [], $date);
    }

    public function has(int $productId): bool
    {
        return ($this->history[$productId] ?? []) !== [];
    }

    /**
     * PHP puro: la última compra hasta esa fecha (fecha sola = hasta el final de ese día); si todas son después, la primera.
     *
     * @param list<array{0: string, 1: float}> $list de la más vieja a la más nueva
     */
    public static function pick(array $list, ?string $date): ?float
    {
        if ($list === []) {
            return null;
        }
        if ($date === null) {
            return end($list)[1];
        }
        $limit = strlen($date) <= 10 ? "{$date} 23:59:59" : $date;
        $found = null;
        foreach ($list as [$at, $cost]) {
            if ($at > $limit) {
                break;
            }
            $found = $cost;
        }

        return $found ?? $list[0][1];
    }

    /**
     * Les pone a los lotes que no son de una compra el costo de la última compra de su producto (hasta el día del lote), o
     * los deja sin costo si el producto nunca tuvo una compra. Cada lote que cambia marca sus órdenes para recalcular
     * (FinanceTriggers). Lo corren la sincronización de cada noche y cada compra nueva o corregida.
     *
     * @param list<int>|null $productIds null = todos
     * @return int lotes que cambiaron
     */
    public static function recost(?array $productIds = null): int
    {
        $lots = InventoryLot::where(fn ($q) => $q->whereNull('cost_source')->orWhereNotIn('cost_source', self::OWN))
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->get(['id', 'product_id', 'received_at', 'unit_cost_usd', 'cost_source']);
        if ($lots->isEmpty()) {
            return 0;
        }
        $ref = self::for($lots->pluck('product_id')->unique()->all());
        $changed = 0;
        foreach ($lots as $lot) {
            $cost = $ref->at((int) $lot->product_id, $lot->received_at?->format('Y-m-d H:i:s'));
            $source = $cost === null ? null : self::DERIVED;
            $old = $lot->unit_cost_usd !== null ? round((float) $lot->unit_cost_usd, 4) : null;
            if ($old === $cost && $lot->cost_source === $source) {
                continue;
            }
            $lot->forceFill(['unit_cost_usd' => $cost, 'cost_source' => $source])->save();
            $changed++;
        }

        return $changed;
    }
}
