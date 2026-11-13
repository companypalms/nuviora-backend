<?php

namespace App\Services\Assistant\Tools;

use App\Models\InventoryPurchase;
use App\Services\Assistant\Lookup;
use App\Services\Calendar\Rules\ExpenseRules;
use App\Services\Finance\PurchaseCost;

/**
 * `.142` (dueño, 2026-11-13: "no me deja ver a qué precio compré el releev la última vez ni tampoco a qué proveedor"): las
 * compras de inventario ya hechas (Inventario › Compras), de la más nueva a la más vieja: proveedor, unidades, costo por
 * unidad en dólares y el total en la moneda en que se pagó. Las que están por recibir también salen, marcadas; las
 * canceladas no. Solo lee.
 */
class PurchasesTool implements Tool
{
    private const COLUMNS = [
        ['key' => 'units', 'label' => 'Unidades', 'format' => 'count'],
        ['key' => 'unit_cost', 'label' => 'Costo por unidad', 'format' => 'money'],
        ['key' => 'total_usd', 'label' => 'Total en dólares', 'format' => 'money'],
    ];

    private const MAX_ROWS = 15;

    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'compras';
    }

    public function description(): string
    {
        return 'Las compras de inventario al proveedor (Inventario › Compras), de la más nueva a la más vieja: fecha, proveedor, unidades, costo por unidad en dólares, el total en la moneda en que se pagó (y la tasa si fue en bolívares), al contado o a crédito, almacén y si ya llegó o está por recibir. Sirve para "¿a qué precio compré X la última vez?", "¿a qué proveedor?" o "¿cuánto le he comprado a tal proveedor?". Dice también el costo por unidad que usa el sistema hoy para el C y el BE.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => ['string', 'null'], 'description' => 'El nombre del producto. null = todos los productos.'],
                'proveedor' => ['type' => ['string', 'null'], 'description' => 'El nombre del proveedor. null = todos.'],
            ],
            'required' => ['producto', 'proveedor'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando las compras…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->lookup->productId($args['producto'] ?? null);
        $supplierId = $this->lookup->supplierId($args['proveedor'] ?? null);
        $query = InventoryPurchase::query()->where('status', '!=', InventoryPurchase::CANCELLED)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId));
        $purchases = (clone $query)->with(['product:id,title,showable_name', 'supplier:id,name', 'warehouse:id,name'])
            ->orderByRaw('COALESCE(received_at, purchased_at) DESC')->orderByDesc('id')->limit(self::MAX_ROWS)->get();
        if ($purchases->isEmpty()) {
            return ToolResult::problem('No hay compras registradas' . ($productId ? ' de ese producto' : '') . ($supplierId ? ' a ese proveedor' : '') . '.');
        }

        $name = fn ($p) => $p ? ($p->showable_name ?: $p->title) : 'Producto';
        $usd = fn (?float $v) => $v !== null ? ExpenseRules::amount($v, 'USD') : null;
        $rows = $purchases->map(function (InventoryPurchase $p) use ($name, $usd) {
            $pending = $p->status === InventoryPurchase::PENDING;
            $total = $p->total_amount ?? $p->expected_total_amount;

            return array_filter([
                'fecha' => ($p->received_at ?? $p->purchased_at)?->format('d/m/Y'),
                'producto' => $name($p->product),
                'proveedor' => $p->supplier?->name,
                'unidades' => $p->quantity,
                'costo_por_unidad' => $p->unit_cost_usd !== null ? $usd($p->unit_cost_usd) . ($pending ? ' (estimado: todavía no llega)' : '') : null,
                'total' => $total !== null ? ExpenseRules::amount((float) $total, (string) ($p->currency ?: 'USD')) : null,
                'tasa' => $p->currency === 'VES' && $p->rate ? number_format((float) $p->rate, 2, ',', '.') . ' Bs por dólar' : null,
                'total_en_dolares' => $p->currency !== 'USD' ? $usd($p->total_usd) : null,
                'pago' => InventoryPurchase::PAYMENT_TYPES[$p->payment_type] ?? null,
                'almacen' => $p->warehouse?->name,
                'estado' => $pending ? 'Por recibir' . ($p->expected_at ? ', llega el ' . $p->expected_at->format('d/m/Y') : '') : 'Recibida',
            ], fn ($v) => $v !== null && $v !== '');
        });

        $last = $purchases->firstWhere('status', InventoryPurchase::RECEIVED);
        $received = (clone $query)->where('status', InventoryPurchase::RECEIVED);
        $data = [
            'ultima_compra_recibida' => $last ? $rows[$purchases->search(fn ($p) => $p->id === $last->id)] : null,
            'compras' => $rows->all(),
            'total_recibido' => ['compras' => (clone $received)->count(), 'unidades' => (int) (clone $received)->sum('quantity'),
                'dolares' => $usd(round((float) (clone $received)->sum('total_usd'), 2))],
        ];
        // El costo de una unidad que usa el sistema para el C y el BE (.46): sale de las compras, o de lo completado a mano
        if ($productId !== null && ($cost = PurchaseCost::for([$productId])->at($productId)) !== null) {
            $data['costo_que_usa_el_sistema'] = $usd($cost)
                . ($last && abs($cost - (float) $last->unit_cost_usd) >= 0.005 ? ' (distinto de la última compra: se completó a mano en Finanzas › Inventario)' : '');
        }

        $title = 'Compras' . ($productId ? ' de ' . $name($purchases->first()->product) : '') . ($supplierId ? ' a ' . $purchases->first()->supplier?->name : '');

        return new ToolResult(array_filter($data, fn ($v) => $v !== null), [['type' => 'table', 'title' => $title, 'columns' => self::COLUMNS,
            'rows' => $purchases->map(fn (InventoryPurchase $p, int $i) => [
                'name' => ($rows[$i]['fecha'] ?? '') . ' · ' . ($p->supplier?->name ?? 'Sin proveedor'),
                'group' => implode(' · ', array_filter([$productId ? null : $name($p->product),
                    $p->currency !== 'USD' && $p->total_amount !== null ? ExpenseRules::amount((float) $p->total_amount, (string) $p->currency) : null,
                    $p->status === InventoryPurchase::PENDING ? 'por recibir' : null])) ?: null,
                'values' => ['units' => $p->quantity, 'unit_cost' => $p->unit_cost_usd, 'total_usd' => $p->total_usd],
                'low_data' => false,
            ])->all()]]);
    }
}
