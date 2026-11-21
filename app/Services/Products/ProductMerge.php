<?php

namespace App\Services\Products;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Status;
use App\Services\Finance\OrderEconomics;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * `.167` (dueño, 2026-11-21: "en el inventario aparecen dos Bunnycare. El primer Bunnycare es en donde puse stock. Sin embargo,
 * desde ahí no se están jalando las órdenes, sino desde el otro… coloca solo un Bunnycare que sea el correcto, el que viene de
 * Shopify… y además le vas a añadir el stock que tiene el otro"). Shopify busca el producto de cada línea por su nombre exacto
 * y después por su número de Shopify (Product::forShopifyLine); el BuniCare que creó el asistente no tenía ninguno de los dos,
 * así que Shopify creó otro y los 51 pedidos quedaron en "Sin Stock".
 *
 * Une el repetido que creó Shopify con el nuestro, el que tiene la ficha, el stock y las campañas: al nuestro le pasan el
 * número de Shopify (y el precio, la foto y el SKU si no los tenía) y todo lo que colgaba del repetido (sus pedidos); el
 * repetido, ya vacío, se borra. Todo en una transacción: el ensayo hace exactamente lo mismo y lo deshace. Después, sus
 * pedidos se recalculan y salen de "Sin Stock" como cuando entra stock (InventoryService::recoverFromSinStock).
 */
final class ProductMerge
{
    /** @param \Closure(int): bool|null $hasStock si al pedido ya le alcanza el stock (en las pruebas, sin bodegas) */
    public function __construct(private ?InventoryService $inventory = null, private ?OrderEconomics $economics = null, private ?\Closure $hasStock = null)
    {
        $this->hasStock ??= fn (int $id) => (bool) Order::find($id)?->hasStock();
    }

    /**
     * @return array{keep: array{id: int, title: string}, dup: array{id: int, title: string}, product: array<string, mixed>,
     *     moved: array<string, int>, orders: list<int>, waiting: int, could_leave: int, applied: bool, rebuilt: ?int, recovered: ?int}
     */
    public function run(int $keepId, int $dupId, bool $apply): array
    {
        DB::beginTransaction();
        try {
            $report = $this->merge($keepId, $dupId);
            // Lo que saldría de "Sin Stock" ya con el stock del nuestro (sin tocar nada: solo se mira)
            $report['could_leave'] = count(array_filter($report['sin_stock'], $this->hasStock));
            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $report['applied'] = $apply;
        $report['rebuilt'] = null;
        $report['recovered'] = null;
        if ($apply) {
            $report['rebuilt'] = $report['orders'] ? ($this->economics ?? app(OrderEconomics::class))->rebuild($report['orders']) : 0;
            $recovered = 0;
            foreach ($report['sin_stock'] as $id) {
                if (($order = Order::find($id)) && ($this->inventory ?? app(InventoryService::class))->recoverFromSinStock($order)) {
                    $recovered++;
                }
            }
            $report['recovered'] = $recovered;
        }
        $report['waiting'] = count($report['sin_stock']);
        unset($report['sin_stock']);

        return $report;
    }

    /** Lo que se hace dentro de la transacción. */
    private function merge(int $keepId, int $dupId): array
    {
        $keep = DB::table('products')->where('id', $keepId)->lockForUpdate()->first();
        $dup = DB::table('products')->where('id', $dupId)->lockForUpdate()->first();
        if ($keepId === $dupId || !$keep || !$dup) {
            throw new RuntimeException('No encontré los dos productos (o son el mismo).');
        }
        if (!$dup->product_id) {
            throw new RuntimeException("El #{$dupId} no tiene número de Shopify: no es el repetido que creó Shopify.");
        }
        if ($keep->product_id && (string) $keep->product_id !== (string) $dup->product_id) {
            throw new RuntimeException("El #{$keepId} ya está unido a otro producto de Shopify ({$keep->product_id}).");
        }
        $orders = DB::table('order_products')->where('product_id', $dupId)->distinct()->orderBy('order_id')->pluck('order_id')->map(fn ($v) => (int) $v)->all();
        $sinStock = (int) Status::where('description', OrderStatus::SIN_STOCK)->value('id');
        $waiting = $orders && $sinStock ? DB::table('orders')->whereIn('id', $orders)->where('status_id', $sinStock)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all() : [];

        // 1. El número de Shopify (es único: primero se le quita al repetido), y el precio, la foto y el SKU si el nuestro no los tiene
        $product = ['product_id' => $dup->product_id, 'variant_id' => $dup->variant_id]
            + ((float) $keep->price <= 0 && (float) $dup->price > 0 ? ['price' => $dup->price] : [])
            + (!$keep->image && $dup->image ? ['image' => $dup->image] : [])
            + (!$keep->sku && $dup->sku ? ['sku' => $dup->sku] : []);
        DB::table('products')->where('id', $dupId)->update(['product_id' => null, 'variant_id' => null]);
        DB::table('products')->where('id', $keepId)->update($product + ['updated_at' => now()]);

        // 2. Todo lo que colgaba del repetido pasa al nuestro
        $moved = [];
        foreach (self::columns() as [$table, $column]) {
            if ($n = DB::table($table)->where($column, $dupId)->update([$column => $keepId])) {
                $moved["{$table}.{$column}"] = $n;
            }
        }

        // 3. El repetido, solo si ya no le queda nada
        foreach (self::columns() as [$table, $column]) {
            if (DB::table($table)->where($column, $dupId)->exists()) {
                throw new RuntimeException("Al #{$dupId} todavía le queda algo en {$table}: no se borra.");
            }
        }
        DB::table('products')->where('id', $dupId)->delete();

        return ['keep' => ['id' => $keepId, 'title' => (string) ($keep->showable_name ?: $keep->title)], 'dup' => ['id' => $dupId, 'title' => (string) $dup->title],
            'product' => $product, 'moved' => $moved, 'orders' => $orders, 'sin_stock' => $waiting];
    }

    /**
     * Las columnas que apuntan a un producto: las que terminan en "product_id", en todas las tablas de ESTA base (en el servidor
     * están también la de pruebas y otras), menos la de products (ahí es el número de Shopify).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function columns(): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $database = DB::getDatabaseName();
        $out = [];
        foreach (Schema::getTables() as $t) {
            if ($t['name'] === 'products' || (!$sqlite && ($t['schema'] ?? null) !== $database)) {
                continue;
            }
            foreach (Schema::getColumnListing($t['name']) as $column) {
                if (str_ends_with($column, 'product_id')) {
                    $out[] = [$t['name'], $column];
                }
            }
        }

        return $out;
    }
}
