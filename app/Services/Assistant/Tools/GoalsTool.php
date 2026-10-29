<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;
use App\Services\Metrics\OwnerPanel;

/** Las metas del negocio del mes que corre, con cuánto lleva y a qué ritmo (las mismas de arriba del Dashboard). */
class GoalsTool implements Tool
{
    public function __construct(private OwnerPanel $panel)
    {
    }

    public function name(): string
    {
        return 'metas';
    }

    public function description(): string
    {
        return 'Las metas del negocio de este mes (ventas, ganancia neta y efectividad): cuánto lleva, cuánto falta y a qué ritmo va. Si todavía no las puso, sus números del mes anterior de referencia.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false];
    }

    public function working(array $args): string
    {
        return 'Revisando tus metas…';
    }

    public function run(array $args): ToolResult
    {
        $d = $this->panel->goalsSummary();
        if (!$d['configured']) {
            return new ToolResult([
                'mes' => $d['month'],
                'metas_puestas' => false,
                'referencia' => array_values(array_filter([$d['reference']['label'] ?? null, ...array_values($d['reference']['business'] ?? [])])),
            ], [['type' => 'link', 'label' => "Poner las metas de {$d['month']}", 'to' => '/panel-dueno?metas=1']]);
        }
        // Las tarjetas de la franja no traen la comparación: se completan para que se vean como en el panel
        $cards = array_map(fn ($c) => $c + ['previous' => null, 'change' => null, 'change_unit' => 'percent', 'better' => 'higher', 'compare' => false, 'note' => null], $d['cards']);

        return new ToolResult(
            ['mes' => $d['month'], 'metas_puestas' => true, 'calculado_hasta' => $d['updated_at'], 'tarjetas' => Present::cards($cards)],
            [Present::cardsBlock("Tus metas de {$d['month']}", '', $cards)],
        );
    }
}
