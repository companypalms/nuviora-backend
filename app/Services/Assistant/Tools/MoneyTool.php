<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;

/** Dinero del Panel del dueño: la ganancia neta (ya ganado y en camino), a dónde se fue el dinero, por producto y por cobrar. */
class MoneyTool extends PanelTool
{
    public function name(): string
    {
        return 'dinero';
    }

    public function description(): string
    {
        return 'Dinero de un período: ganancia neta ya ganada (vendido − producto − comisiones − envío − publicidad − gastos, por la fecha en que se crearon los pedidos), lo que todavía puede sumar lo que está en camino, costo por pedido y por venta, a dónde se fue el dinero, la ganancia por producto y lo que las agencias y repartidores todavía no han entregado (por cobrar, es de ahora mismo). Úsala para "ganancias", "cuánto gané", "rentabilidad", "publicidad", "por cobrar".';
    }

    public function parameters(): array
    {
        return self::object(PeriodArgs::schema() + self::filtersSchema() + self::chartSchema('la ganancia de cada día'));
    }

    public function working(array $args): string
    {
        return 'Revisando el dinero…';
    }

    public function run(array $args): ToolResult
    {
        [$period, $filters] = $this->scope($args);
        $d = $this->panel->money($period, $filters);
        $granularity = $d['series']['granularity'];

        $data = self::base($period, $args, $d) + [
            'tarjetas' => Present::cards($d['cards']),
            'a_donde_se_fue_el_dinero' => array_map(fn ($s) => ['concepto' => $s['label'], 'monto' => Present::value($s['amount'], 'money')], $d['waterfall']),
            'por_producto' => array_map(fn ($p) => [
                'producto' => $p['name'],
                'vendido' => Present::value($p['revenue'], 'money'),
                'ganancia_antes_de_gastos' => Present::value($p['profit'], 'money'),
                'entregados' => $p['delivered'],
            ], $d['products']['rows']),
            'por_cobrar_ahora' => [
                'total' => Present::value($d['receivables']['total'], 'money'),
                'quien_lo_tiene' => array_map(fn ($r) => ['quien' => $r['name'], 'monto' => Present::value($r['usd'], 'money')], array_slice($d['receivables']['rows'], 0, 15)),
            ],
        ];
        if ($d['warnings'] !== []) {
            $data['avisos'] = $d['warnings'];
        }

        $display = [Present::cardsBlock(self::title('Dinero', $period), $d['period']['compare_label'], $d['cards'])];
        if (!empty($args['grafico']) && count($d['series']['points']) > 1) {
            $display[] = Present::chart('bar', 'Ganancia ya ganada de cada día', 'money', [['key' => 'profit', 'label' => 'Ganancia']],
                array_map(fn ($p) => ['label' => Present::bucket($p['date'], $granularity), 'profit' => $p['profit']], $d['series']['points']));
        }

        return new ToolResult($data, $display);
    }
}
