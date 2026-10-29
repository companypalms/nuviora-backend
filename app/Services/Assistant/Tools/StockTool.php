<?php

namespace App\Services\Assistant\Tools;

use App\Models\City;
use App\Services\Assistant\Lookup;
use App\Services\Inventory\StockStatus;

/** El stock de un producto (o de todos), por almacén: lo mismo de Inventario › Global. */
class StockTool implements Tool
{
    private const COLUMNS = [
        ['key' => 'available', 'label' => 'Disponible', 'format' => 'count'],
        ['key' => 'reserved', 'label' => 'Reservado', 'format' => 'count'],
        ['key' => 'review', 'label' => 'En revisión', 'format' => 'count'],
        ['key' => 'in_transit', 'label' => 'En tránsito', 'format' => 'count'],
        ['key' => 'incoming', 'label' => 'Por recibir', 'format' => 'count'],
    ];

    private const MAX_ROWS = 60;

    public function __construct(private StockStatus $stock, private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'stock';
    }

    public function description(): string
    {
        return 'Cuánto stock hay de un producto (o de todos), en total y por almacén: disponible para vender, reservado por órdenes que todavía no se entregan, en revisión (dañado o de cambios), en tránsito entre almacenes y por recibir de compras al proveedor (con la fecha más próxima). Se puede filtrar por ciudad. Es lo de ahora mismo.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => ['string', 'null'], 'description' => 'El nombre del producto. null = todos los productos (el total de cada uno).'],
                'ciudad' => ['type' => ['string', 'null'], 'description' => 'Solo los almacenes de esa ciudad. null = todos.'],
            ],
            'required' => ['producto', 'ciudad'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando el inventario…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->lookup->productId($args['producto'] ?? null);
        $cityId = $this->lookup->cityId($args['ciudad'] ?? null);
        $rows = $this->stock->rows(null, $productId);
        if ($cityId !== null) {
            $rows = $rows->where('city_id', $cityId)->values();
        }
        if ($rows->isEmpty()) {
            return ToolResult::problem('No hay stock registrado' . ($productId ? ' de ese producto' : '') . ($cityId ? ' en esa ciudad' : '') . '.');
        }
        $cities = City::whereIn('id', $rows->pluck('city_id')->filter()->unique())->pluck('name', 'id');
        $sum = fn ($items) => array_combine(array_column(self::COLUMNS, 'key'), array_map(fn ($c) => (int) $items->sum($c['key']), self::COLUMNS));
        $words = fn (array $v) => array_filter([
            'disponible' => $v['available'], 'reservado' => $v['reserved'] ?: null, 'en_revision' => $v['review'] ?: null,
            'en_transito' => $v['in_transit'] ?: null, 'por_recibir' => $v['incoming'] ?: null,
        ], fn ($x) => $x !== null);

        if ($productId === null) {
            // Todos los productos: el total de cada uno
            $byProduct = $rows->groupBy('product_id')->map(fn ($items) => ['name' => $items->first()['product']] + $sum($items))
                ->sortByDesc('available')->values();

            return new ToolResult(
                ['productos' => $byProduct->take(self::MAX_ROWS)->map(fn ($p) => ['producto' => $p['name']] + $words($p))->all(),
                    'ciudad' => $args['ciudad'] ?? null],
                [self::table('Stock por producto' . ($cityId ? " · {$cities[$cityId]}" : ''),
                    $byProduct->take(self::MAX_ROWS)->map(fn ($p) => ['name' => $p['name'], 'group' => null, 'values' => $p])->all())],
            );
        }

        $product = $rows->first()['product'];
        $data = [
            'producto' => $product,
            'total' => $words($sum($rows)),
            'por_almacen' => $rows->take(self::MAX_ROWS)->map(fn ($r) => array_filter([
                'almacen' => $r['warehouse'],
                'ciudad' => $cities[$r['city_id']] ?? null,
                'variante' => $r['variant'],
                'proxima_compra_llega' => $r['incoming_next_at'],
            ]) + $words($r))->all(),
        ];

        return new ToolResult($data, [self::table("Stock de {$product}", $rows->take(self::MAX_ROWS)->map(fn ($r) => [
            'name' => $r['warehouse'] . ($r['variant'] ? " · {$r['variant']}" : ''),
            'group' => $cities[$r['city_id']] ?? null,
            'values' => $r,
        ])->all())]);
    }

    private static function table(string $title, array $rows): array
    {
        return ['type' => 'table', 'title' => $title, 'columns' => self::COLUMNS,
            'rows' => array_map(fn ($r) => $r + ['low_data' => false], $rows)];
    }
}
