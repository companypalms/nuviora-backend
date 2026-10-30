<?php

namespace App\Services\Assistant\Tools;

use App\Models\Product;
use App\Services\Assistant\Present;
use App\Services\Meta\AngleStats;

/**
 * Los ángulos de un producto (Etapa 5, paso 1b), como en Configuración › Meta Ads › Ángulos: el código de los nombres de
 * los anuncios, el nombre y la hipótesis, si el dueño los confirmó y 30 días de gasto, compras y CPA.
 */
class MetaAnglesTool extends MetaTool
{
    private const STATUS = ['confirmed' => 'confirmado', 'proposed' => 'propuesto, sin confirmar', 'detected' => 'detectado, sin nombre'];

    private const COLUMNS = [
        ['key' => 'active_ads', 'label' => 'Anuncios activos', 'format' => 'count'],
        ['key' => 'spend', 'label' => 'Gasto 30 días', 'format' => 'money'],
        ['key' => 'purchases', 'label' => 'Compras', 'format' => 'count'],
        ['key' => 'cpa', 'label' => 'CPA', 'format' => 'money'],
    ];

    public function name(): string
    {
        return 'angulos';
    }

    public function description(): string
    {
        return 'Los ángulos de venta de un producto en Meta Ads (el código que va en el nombre de los anuncios, por ejemplo DOL o FAS): su nombre y su hipótesis, si el dueño los confirmó, los otros códigos que son lo mismo, cuántos anuncios y creativos tienen y el gasto, las compras y el CPA de 30 días contra el objetivo (C) y el break-even (BE). Úsala para "ángulos de X", "qué ángulo vende más", "qué ángulo probar".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'El nombre del producto.'],
            ],
            'required' => ['producto'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando los ángulos…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->productId($args['producto'] ?? null);
        $product = Product::find($productId, ['id', 'title', 'showable_name']);
        $name = $product->showable_name ?: $product->title;
        $angles = AngleStats::forProduct($productId);
        $link = ['type' => 'link', 'label' => 'Ver los ángulos', 'to' => '/admin/integraciones?seccion=meta&tab=angles'];
        if (!$angles) {
            return new ToolResult(['problema' => "Todavía no hay ángulos de {$name}: salen del código en el nombre de los anuncios de Meta (por ejemplo, \"C12 DOL B004\")."], [$link]);
        }
        $target = self::targets($productId);

        $data = array_filter([
            'producto' => $name,
        ] + self::targetWords($target) + [
            'angulos' => array_map(fn ($a) => array_filter([
                'codigo' => $a['code'],
                'nombre' => $a['name'],
                'hipotesis' => $a['hypothesis'],
                'estado' => (self::STATUS[$a['status']] ?? $a['status']) . ($a['status'] === 'proposed' && $a['proposed_by'] === 'ai' ? ' (lo propuso la IA)' : ''),
                'tambien_se_escribe' => array_column($a['aliases'], 'code') ?: null,
                'anuncios_activos' => $a['active_ads'],
                'anuncios_en_total' => $a['ads'],
                'creativos' => $a['creatives'],
                'ultimos_30_dias' => self::window($a['spend'], $a['purchases'], $target['c'], $target['be']),
                'ejemplo_de_texto' => $a['sample'] ? mb_strimwidth(Present::mask($a['sample']), 0, 160, '…') : null,
            ], fn ($v) => $v !== null), $angles),
        ], fn ($v) => $v !== null);

        return new ToolResult($data, [['type' => 'table', 'title' => "Ángulos de {$name}", 'columns' => self::COLUMNS, 'rows' => array_map(fn ($a) => [
            'name' => $a['code'] . ($a['name'] ? " · {$a['name']}" : ''),
            'group' => self::STATUS[$a['status']] ?? $a['status'],
            'values' => $a,
            'low_data' => false,
        ], $angles)], $link]);
    }
}
