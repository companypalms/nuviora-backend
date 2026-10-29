<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;

/** Ventas y pedidos del Panel del dueño: creados, entregados, vendido, efectividad, ticket y cada día. */
class SalesTool extends PanelTool
{
    public function name(): string
    {
        return 'ventas';
    }

    public function description(): string
    {
        return 'Ventas y pedidos de un período: pedidos creados, entregados (venta = entregado), lo vendido en USD, la efectividad general (entregados ÷ creados), el ticket promedio, qué pasó con los pedidos y los números de cada día (o semana). Compara con el período anterior. Se puede filtrar por tienda, ciudad o producto.';
    }

    public function parameters(): array
    {
        return self::object(PeriodArgs::schema() + self::filtersSchema() + self::chartSchema('los pedidos de cada día'));
    }

    public function working(array $args): string
    {
        return 'Revisando las ventas…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $d = $this->panel->sales($period, $filters);
        $granularity = $d['series']['granularity'];
        $points = $d['series']['points'];

        $data = self::base($period, $args, $d) + [
            'tarjetas' => Present::cards($d['cards']),
            'que_paso_con_los_pedidos' => array_map(fn ($s) => ['estado' => $s['label'], 'pedidos' => $s['count']], $d['status']),
            'por_' . ($granularity === 'day' ? 'dia' : ($granularity === 'week' ? 'semana' : 'mes')) => array_map(fn ($p) => [
                'fecha' => Present::bucket($p['date'], $granularity),
                'creados' => $p['created'],
                'entregados' => $p['delivered'],
                'vendido' => Present::value($p['revenue'], 'money'),
                'efectividad' => Present::value($p['effectiveness'], 'percent'),
            ], array_slice($points, -45)),
        ];

        $display = [Present::cardsBlock(self::title('Ventas', $period), $d['period']['compare_label'], $d['cards'])];
        if (!empty($args['grafico']) && count($points) > 1) {
            $display[] = Present::chart('bar', 'Pedidos creados y entregados', 'count',
                [['key' => 'created', 'label' => 'Creados'], ['key' => 'delivered', 'label' => 'Entregados']],
                array_map(fn ($p) => ['label' => Present::bucket($p['date'], $granularity), 'created' => $p['created'], 'delivered' => $p['delivered']], $points));
        }

        return new ToolResult($data, $display);
    }
}
