<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;

/** Pérdidas del Panel del dueño: cancelaciones y sus motivos, rechazos en puerta, devoluciones y cambios. */
class LossesTool extends PanelTool
{
    public function name(): string
    {
        return 'perdidas';
    }

    public function description(): string
    {
        return 'Pérdidas de un período: pedidos cancelados (por motivo de la lista, en qué momento y quién canceló), rechazados en la puerta, devoluciones, cambios, envíos fallidos y reembolsos. Úsala para "cancelaciones", "por qué se cancela", "rechazos".';
    }

    public function parameters(): array
    {
        return self::object(PeriodArgs::schema() + self::filtersSchema() + self::chartSchema('los motivos de cancelación'));
    }

    public function working(array $args): string
    {
        return 'Revisando las cancelaciones…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $d = $this->panel->losses($period, $filters);
        $rows = fn (array $list, string $what) => array_map(fn ($r) => [$what => $r['label'], 'pedidos' => $r['count']], array_slice($list, 0, 15));

        $data = self::base($period, $args, $d) + [
            'tarjetas' => Present::cards($d['cards']),
            'cancelados_por_motivo' => $rows($d['reasons']['rows'], 'motivo'),
            'cancelados_por_momento' => $rows($d['stages']['rows'], 'momento'),
            'cancelados_por_quien' => $rows($d['who']['rows'], 'quien'),
            'rechazos_por_ultima_novedad' => $rows($d['novelties']['rows'], 'novedad'),
        ];

        $display = [Present::cardsBlock(self::title('Pérdidas', $period), $d['period']['compare_label'], $d['cards'])];
        $reasons = array_values(array_filter($d['reasons']['rows'], fn ($r) => $r['count'] > 0));
        if (!empty($args['grafico']) && $reasons !== []) {
            $display[] = Present::chart('hbar', 'Cancelados por motivo', 'count', [['key' => 'count', 'label' => 'Pedidos']],
                array_map(fn ($r) => ['label' => $r['label'], 'count' => $r['count']], $reasons));
        }

        return new ToolResult($data, $display);
    }
}
