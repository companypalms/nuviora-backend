<?php

namespace App\Services\Assistant\Tools;

/** Las alertas del Panel del dueño: quién o qué está por debajo de las metas y los límites que puso el dueño. */
class AlertsTool extends PanelTool
{
    private const LEVELS = ['red' => 'rojo: ya no llega', 'yellow' => 'amarillo: cerca del límite', 'pending' => 'gris: todavía puede llegar'];

    public function name(): string
    {
        return 'alertas';
    }

    public function description(): string
    {
        return 'Las alertas de un período según las metas y los límites que puso el dueño: vendedoras y agencias por debajo de su efectividad mínima, agencias lentas o con muchos rechazos, dinero sin entregar de agencias y repartidores, costo por venta y cancelaciones. Úsala para "qué está mal", "qué debo revisar", "alertas".';
    }

    public function parameters(): array
    {
        return self::object(PeriodArgs::schema() + self::filtersSchema());
    }

    public function working(array $args): string
    {
        return 'Revisando las alertas…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $d = $this->panel->alerts($period, $filters);
        if (!$d['configured']) {
            return new ToolResult(['alertas_configuradas' => false, 'explicacion' => 'Todavía no pusiste metas ni límites: las alertas se encienden en el Panel del dueño › Metas y límites.'],
                [['type' => 'link', 'label' => 'Poner metas y límites', 'to' => '/panel-dueno?metas=1']]);
        }

        $items = [];
        $data = [];
        foreach ($d['alerts'] as $a) {
            $data[] = [
                'alerta' => $a['title'],
                'nivel' => self::LEVELS[$a['level']] ?? $a['level'],
                'detalle' => array_map(fn ($i) => array_filter([
                    'quien' => $i['name'] ?? null,
                    'como_va' => $i['text'],
                    'nivel' => self::LEVELS[$i['level']] ?? $i['level'],
                ]), array_slice($a['items'], 0, 15)),
            ];
            foreach ($a['items'] as $i) {
                $items[] = ['text' => ($i['name'] ?? $a['title']) . ': ' . $i['text'], 'level' => $i['level']];
            }
        }

        return new ToolResult(
            self::base($period, $args, []) + ['alertas' => $data ?: 'No hay alertas: todo dentro de tus metas y límites.'],
            $items !== [] ? [['type' => 'list', 'title' => "Alertas · {$period->label}", 'items' => array_slice($items, 0, 30)]] : [],
        );
    }
}
