<?php

namespace App\Services\Storefront;

use App\Models\Inventory;
use App\Models\InventoryVariant;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `.172` (dueño, 2026-11-21: "no debería ofrecer si no hay stock"): cuánto puede entregar hoy cada ciudad de un producto
 * del formulario. Una orden la entrega una sola agencia, así que cuenta el almacén que más tiene de esa ciudad (sus agencias
 * activas y su logística propia). Es el stock útil: sin lo apartado, lo dañado ni lo bloqueado; lo que viaja cuenta al
 * llegar. La página lo pide cada minuto como mucho; el pedido lo revisa sin caché.
 */
class StorefrontStock
{
    public const CACHE_SECONDS = 60;

    /**
     * @param list<int> $cityIds
     * @return array<int, array{all: int, sizes: array<int, int>}> city_id => lo que puede entregar
     */
    public function byCity(int $productId, array $cityIds, bool $fresh = false): array
    {
        $cityIds = array_values(array_unique(array_map('intval', $cityIds)));
        if ($cityIds === []) {
            return [];
        }
        $calc = fn () => $this->calc($productId, $cityIds);

        return $fresh ? $calc() : Cache::remember("storefront:stock:{$productId}:" . implode(',', $cityIds), self::CACHE_SECONDS, $calc);
    }

    private function calc(int $productId, array $cityIds): array
    {
        $byCity = array_fill_keys($cityIds, []);
        foreach (DB::table('city_agency')->join('users', 'users.id', '=', 'city_agency.agency_id')->join('warehouses', 'warehouses.user_id', '=', 'city_agency.agency_id')
            ->where('city_agency.is_active', true)->whereNull('users.deactivated_at')->where('warehouses.is_active', true)->whereIn('city_agency.city_id', $cityIds)
            ->get(['city_agency.city_id', 'warehouses.id']) as $r) {
            $byCity[(int) $r->city_id][] = (int) $r->id;
        }
        foreach (DB::table('logistics_centers')->where('is_active', true)->whereNotNull('warehouse_id')->whereIn('city_id', $cityIds)->get(['city_id', 'warehouse_id']) as $r) {
            $byCity[(int) $r->city_id][] = (int) $r->warehouse_id;
        }
        $warehouses = array_values(array_unique(array_merge([], ...array_values($byCity))));
        $productStock = [];
        foreach (Inventory::whereIn('warehouse_id', $warehouses ?: [0])->where('product_id', $productId)->get() as $i) {
            $productStock[(int) $i->warehouse_id] = ($productStock[(int) $i->warehouse_id] ?? 0) + $i->useful_stock;
        }
        $variantIds = ProductVariant::where('product_id', $productId)->where('is_active', true)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $variantStock = [];
        foreach (InventoryVariant::whereIn('warehouse_id', $warehouses ?: [0])->whereIn('variant_id', $variantIds ?: [0])->get() as $i) {
            $variantStock[(int) $i->warehouse_id][(int) $i->variant_id] = ($variantStock[(int) $i->warehouse_id][(int) $i->variant_id] ?? 0) + $i->useful_stock;
        }

        return StorefrontRules::stockByCity($byCity, $productStock, $variantStock, $variantIds);
    }
}
