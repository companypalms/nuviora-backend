<?php

namespace App\Services\Assistant\Tools;

use App\Models\InventoryPurchase;
use App\Models\InventoryTransfer;
use App\Services\Calendar\Rules\ExpenseRules;

/** Lo que viene en camino: las compras al proveedor por recibir y los traslados entre almacenes (Inventario › En camino). */
class IncomingTool implements Tool
{
    public function name(): string
    {
        return 'en_camino';
    }

    public function description(): string
    {
        return 'Lo que viene en camino al inventario: las compras al proveedor que todavía no llegan (producto, cantidad, proveedor, almacén, cuándo debería llegar y cuánto costó) y los traslados entre almacenes que están en tránsito.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false];
    }

    public function working(array $args): string
    {
        return 'Revisando lo que viene en camino…';
    }

    public function run(array $args): ToolResult
    {
        $purchases = InventoryPurchase::with(['product:id,title,showable_name', 'supplier:id,name', 'warehouse:id,name'])
            ->where('status', InventoryPurchase::PENDING)->orderBy('expected_at')->limit(50)->get();
        $transfers = InventoryTransfer::with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'lines.product:id,title,showable_name'])
            ->where('status', InventoryTransfer::IN_TRANSIT)->orderBy('dispatched_at')->limit(50)->get();
        if ($purchases->isEmpty() && $transfers->isEmpty()) {
            return new ToolResult(['resumen' => 'No hay compras por recibir ni traslados en camino.']);
        }
        $name = fn ($p) => $p ? ($p->showable_name ?: $p->title) : 'Producto';

        $purchaseRows = $purchases->map(fn ($p) => array_filter([
            'producto' => $name($p->product),
            'cantidad' => $p->quantity,
            'proveedor' => $p->supplier?->name,
            'almacen' => $p->warehouse?->name,
            'llega' => $p->expected_at?->format('d/m/Y'),
            'atrasada' => $p->expected_at && $p->expected_at->lt(today()) ? true : null,
            'costo' => ($p->total_amount ?? $p->expected_total_amount) !== null
                ? ExpenseRules::amount((float) ($p->total_amount ?? $p->expected_total_amount), (string) ($p->currency ?: 'USD')) : null,
        ], fn ($v) => $v !== null))->all();
        $transferRows = $transfers->map(fn ($t) => [
            'desde' => $t->fromWarehouse?->name,
            'hacia' => $t->toWarehouse?->name,
            'salio' => $t->dispatched_at?->format('d/m/Y'),
            'productos' => $t->lines->map(fn ($l) => "{$l->quantity} × " . $name($l->product))->implode(', '),
        ])->all();

        $items = [];
        foreach ($purchaseRows as $p) {
            $items[] = ['text' => "Compra: {$p['cantidad']} × {$p['producto']}" . (isset($p['proveedor']) ? " de {$p['proveedor']}" : '')
                . (isset($p['llega']) ? ", llega el {$p['llega']}" : '') . (!empty($p['atrasada']) ? ' (atrasada)' : ''), 'level' => !empty($p['atrasada']) ? 'yellow' : null];
        }
        foreach ($transferRows as $t) {
            $items[] = ['text' => "Traslado de {$t['desde']} a {$t['hacia']}: {$t['productos']}"];
        }

        return new ToolResult(
            array_filter(['compras_por_recibir' => $purchaseRows, 'traslados_en_camino' => $transferRows]),
            [['type' => 'list', 'title' => 'En camino', 'items' => $items]],
        );
    }
}
