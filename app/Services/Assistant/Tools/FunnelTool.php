<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;

/** Embudo del Panel del dueño: cuántos pedidos llegan a cada paso, dónde se quedan y cuánto tardan. */
class FunnelTool extends PanelTool
{
    public function name(): string
    {
        return 'embudo';
    }

    public function description(): string
    {
        return 'El embudo de los pedidos de un período: cuántos llegan a cada paso (creado, asignado, confirmado, en ruta, entregado), qué % de los creados, cuánto se pierde entre un paso y otro y cuánto tardan normalmente. Incluye la tasa de confirmación y la de entrega de los confirmados.';
    }

    public function parameters(): array
    {
        return self::object(PeriodArgs::schema() + self::filtersSchema() + self::chartSchema('los pasos del embudo'));
    }

    public function working(array $args): string
    {
        return 'Revisando el embudo…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $d = $this->panel->funnel($period, $filters);

        $data = self::base($period, $args, $d) + [
            'tarjetas' => Present::cards($d['cards']),
            'pasos' => array_map(fn ($s) => array_filter([
                'paso' => $s['label'],
                'pedidos' => $s['count'],
                'de_los_creados' => Present::value($s['of_created'], 'percent'),
                'del_paso_anterior' => $s['of_previous'] !== null ? Present::value($s['of_previous'], 'percent') : null,
                'tiempo_normal_hasta_aqui' => $s['median_hours'] !== null ? Present::value($s['median_hours'], 'hours') : null,
            ], fn ($v) => $v !== null), $d['steps']),
        ];

        $display = [Present::cardsBlock(self::title('Embudo', $period), $d['period']['compare_label'], $d['cards'])];
        if (!empty($args['grafico'])) {
            $display[] = Present::chart('hbar', 'Pedidos en cada paso', 'count', [['key' => 'count', 'label' => 'Pedidos']],
                array_map(fn ($s) => ['label' => $s['label'], 'count' => $s['count']], $d['steps']));
        }

        return new ToolResult($data, $display);
    }
}
