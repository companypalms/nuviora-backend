<?php

namespace App\Services\Assistant\Tools;

use App\Services\SCM\SupplyChainEngine;

/**
 * Lo que se está acabando: el mismo semáforo del aviso de Stock bajo del Dashboard (SupplyChainEngine). Rojo: alcanza
 * para menos días que lo que tarda el proveedor; naranja y amarillo, poco más.
 */
class LowStockTool implements Tool
{
    private const LEVELS = [
        'red' => 'rojo: se acaba antes de que llegue una compra',
        'orange' => 'naranja: alerta',
        'yellow' => 'amarillo: precaución',
    ];

    public function __construct(private SupplyChainEngine $engine)
    {
    }

    public function name(): string
    {
        return 'stock_bajo';
    }

    public function description(): string
    {
        return 'Los productos que se están acabando, con su semáforo (rojo, naranja, amarillo): por almacén, cuánto queda útil, para cuántos días alcanza según lo que se vende, lo que tarda el proveedor y cuánto se sugiere comprar. Es lo mismo del aviso de Stock bajo.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false];
    }

    public function working(array $args): string
    {
        return 'Revisando qué se está acabando…';
    }

    public function run(array $args): ToolResult
    {
        $low = array_values(array_filter($this->engine->analyze(), fn ($r) => isset(self::LEVELS[$r['priority']])));
        if ($low === []) {
            return new ToolResult(['resumen' => 'Nada se está acabando: todos los productos tienen cobertura suficiente.']);
        }
        $counts = array_count_values(array_column($low, 'priority'));

        return new ToolResult([
            'cuantos' => array_filter(['rojo' => $counts['red'] ?? null, 'naranja' => $counts['orange'] ?? null, 'amarillo' => $counts['yellow'] ?? null]),
            'productos' => array_map(fn ($r) => array_filter([
                'producto' => $r['product_name'],
                'almacen' => $r['warehouse_name'],
                'nivel' => self::LEVELS[$r['priority']],
                'stock_util' => $r['stock_useful'],
                'alcanza_para_dias' => $r['days_coverage'],
                'se_vende_por_dia' => $r['daily_demand'],
                'tarda_el_proveedor_dias' => $r['lead_time_days'],
                'compra_sugerida' => $r['purchase_suggested'] ?: null,
            ], fn ($v) => $v !== null), array_slice($low, 0, 40)),
        ], [
            ['type' => 'list', 'title' => 'Lo que se está acabando', 'items' => array_map(fn ($r) => [
                'text' => "{$r['product_name']} ({$r['warehouse_name']}): quedan {$r['stock_useful']}, alcanza para " . ($r['days_coverage'] ?? '—') . ' días'
                    . ($r['purchase_suggested'] ? "; comprar {$r['purchase_suggested']}" : ''),
                'level' => $r['priority'],
            ], array_slice($low, 0, 30))],
            ['type' => 'link', 'label' => 'Abrir reabastecimiento', 'to' => '/reabastecimiento'],
        ]);
    }
}
