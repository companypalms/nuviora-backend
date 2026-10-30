<?php

namespace App\Services\Assistant\Tools;

use App\Models\AdAngle;
use App\Models\MetaCreative;

/**
 * Los creativos de un producto con el veredicto de las reglas del dueño (Etapa 5, paso 2a), como en Meta Ads: en cada
 * ciudad y entre ciudades, la etapa del embudo, lo que dice el video y sus 7 días.
 */
class MetaCreativesTool extends MetaTool
{
    /** Qué veredictos entran en cada filtro (AdRules::STATES). */
    private const FILTERS = [
        'ganadores' => ['proven', 'promising'],
        'intermedios' => ['intermediate'],
        'perdedores' => ['loser'],
        'fallando' => ['failing'],
        'en_prueba' => ['evaluation', 'no_data', 'no_target'],
    ];

    private const STATUS = ['testing' => 'Testing', 'winner' => 'Winner', 'fatigued' => 'Fatigued', 'loser' => 'Loser', 'archived' => 'Archived'];

    private const MAX_ROWS = 30;

    private const COLUMNS = [
        ['key' => 'spend', 'label' => 'Gasto 7 días', 'format' => 'money'],
        ['key' => 'purchases', 'label' => 'Compras', 'format' => 'count'],
        ['key' => 'cpa', 'label' => 'CPA', 'format' => 'money'],
    ];

    public function name(): string
    {
        return 'creativos';
    }

    public function description(): string
    {
        return 'Los creativos (videos o imágenes) de un producto en Meta Ads con el veredicto de las reglas del dueño: PROVEN, prometedor, intermedio, perdedor, ganador que empezó a fallar o en evaluación; cómo le va en cada ciudad y entre ciudades (ganador transversal, sensible a ciudad, perdedor en todas), la etapa del embudo, qué falla del video (hook, retención, CTR, landing) y qué iterar, su ángulo y batch, su estado (Testing, Winner, Fatigued, Loser) y el gasto, las compras y el CPA de 7 días. Úsala para "creativos de X", "qué creativos ganan", "qué video apago".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'El nombre del producto.'],
                'estado' => ['type' => ['string', 'null'], 'enum' => [...array_keys(self::FILTERS), 'todos', null],
                    'description' => 'Solo los de ese veredicto. null = los activos; todos = también los apagados.'],
            ],
            'required' => ['producto', 'estado'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando los creativos…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->productId($args['producto'] ?? null);
        $d = $this->decisionsFor($productId);
        $filter = $args['estado'] ?? null;
        $creatives = array_values(array_filter($d['creatives'], fn ($cr) => match (true) {
            $filter === 'todos' => true,
            $filter !== null => $cr['active'] && in_array($cr['verdict']['key'], self::FILTERS[$filter] ?? [], true),
            default => $cr['active'],
        }));
        usort($creatives, fn ($a, $b) => [$b['week']['spend'], $a['label']] <=> [$a['week']['spend'], $b['label']]);
        $product = $d['product']['name'];
        if (!$creatives) {
            return ToolResult::problem("{$product} no tiene creativos " . ($filter && $filter !== 'todos' ? str_replace('_', ' ', $filter) . ' activos' : 'activos') . ' ahora.');
        }

        $codes = MetaCreative::whereIn('id', array_column($creatives, 'id'))->get(['id', 'angle_code', 'batch_code'])->keyBy('id');
        $angles = AdAngle::where('product_id', $productId)->whereNotNull('name')->pluck('name', 'code');
        $rows = array_slice($creatives, 0, self::MAX_ROWS);
        $data = [
            'producto' => $product,
            'cuantos' => count($creatives) . (count($creatives) > self::MAX_ROWS ? ' (van los ' . self::MAX_ROWS . ' que más gastaron en 7 días)' : ''),
            'creativos' => array_map(function ($cr) use ($codes, $angles) {
                $code = $codes[$cr['id']] ?? null;

                return array_filter([
                    'creativo' => $cr['label'],
                    'angulo' => $code?->angle_code ? $code->angle_code . (isset($angles[$code->angle_code]) ? " ({$angles[$code->angle_code]})" : '') : null,
                    'batch' => $code?->batch_code,
                    'activo' => $cr['active'] ? null : 'apagado',
                    'veredicto' => $cr['verdict']['label'],
                    'entre_ciudades' => $cr['cross']['label'] ?? null,
                    'lectura' => $cr['verdict']['reading'],
                    'propuesta' => $cr['verdict']['proposal'],
                    'por_ciudad' => count($cr['cities']) > 1 ? array_map(fn ($c) => ($c['city'] ?? 'Campaña') . ": {$c['label']}" . ($c['data'] ? " ({$c['data']})" : ''), $cr['cities']) : null,
                    'embudo' => $cr['funnel']['label'] ?? null,
                    'video' => ($cr['video']['diagnosis'] ?? []) ?: null,
                    'ultimos_7_dias' => self::window($cr['week']['spend'], $cr['week']['purchases']),
                    'estado' => isset(self::STATUS[$cr['status']]) ? self::STATUS[$cr['status']] . ($cr['status_source'] === 'manual' ? ' (puesto a mano)' : '') : null,
                ], fn ($v) => $v !== null);
            }, $rows),
        ];

        $display = [['type' => 'table', 'title' => "Creativos de {$product}", 'columns' => self::COLUMNS, 'rows' => array_map(fn ($cr) => [
            'name' => $cr['label'],
            'group' => $cr['verdict']['label'] . (isset($cr['cross']['label']) ? " · {$cr['cross']['label']}" : '') . ($cr['active'] ? '' : ' · apagado'),
            'values' => $cr['week'],
            'low_data' => false,
        ], $rows)]];
        $display[] = count($creatives) === 1
            ? ['type' => 'link', 'label' => 'Abrir el creativo', 'to' => "/meta-ads/creativos/{$creatives[0]['id']}"]
            : ['type' => 'link', 'label' => 'Abrir Meta Ads', 'to' => '/meta-ads'];

        return new ToolResult($data, $display);
    }
}
