<?php

namespace App\Services\Metrics\Rules;

use App\Services\Metrics\Buckets;
use App\Services\Metrics\Period;

/**
 * Ventas y pedidos del panel del dueño (diccionario de métricas, §1, §3 y §8). PHP puro: recibe los hechos de cada
 * pedido (los trae App\Services\Metrics\Readers\OrderFactsReader) y calcula los números. Cada número tiene aquí mismo
 * la explicación que ve el dueño al pasar el cursor, para que la explicación y el cálculo nunca digan cosas distintas.
 *
 * Un hecho es un pedido: ['created_at' => 'Y-m-d H:i:s', 'kind' => normal|exchange|return,
 * 'outcome' => delivered|not_delivered|open, 'revenue' => USD neto (solo si se entregó), 'delivered_date' => ?Y-m-d].
 *
 * Las reglas:
 * - Todo cuenta por el día en que se CREÓ el pedido. Si se entrega días después, suma a su día de creación (§1).
 * - Venta = pedido entregado. Los cambios y las devoluciones no son ventas: se cuentan aparte (§1).
 * - Efectividad general = entregados ÷ creados (§3), igual que Finanzas › Cohortes.
 */
final class SalesRules
{
    public const NORMAL = 'normal';
    public const DELIVERED = 'delivered';
    public const NOT_DELIVERED = 'not_delivered';
    public const OPEN = 'open';

    /**
     * Las tarjetas. "matures": el número de un período reciente todavía puede subir (los pedidos se siguen entregando),
     * así que compararlo con un período ya terminado no es justo; el panel lo muestra sin rojo ni verde.
     */
    public const METRICS = [
        'created' => [
            'label' => 'Pedidos creados',
            'format' => 'count',
            'matures' => false,
            'help' => 'Pedidos que entraron en el período elegido, con los filtros de arriba. No cuenta cambios ni devoluciones: esos se ven aparte.',
        ],
        'delivered' => [
            'label' => 'Entregados',
            'format' => 'count',
            'matures' => true,
            'help' => 'De los pedidos creados en este período, los que ya fueron entregados, aunque se hayan entregado días después. No cuenta devoluciones ni cambios.',
        ],
        'revenue' => [
            'label' => 'Monto vendido',
            'format' => 'money',
            'matures' => true,
            'help' => 'Lo que suman en dólares los pedidos entregados de este período, con sus upsells. Si se le devolvió dinero a un cliente, eso se resta.',
        ],
        'effectiveness' => [
            'label' => 'Efectividad general',
            'format' => 'percent',
            'matures' => true,
            'help' => 'De los pedidos creados en este período, qué porcentaje ya se entregó (entregados ÷ creados). Puede subir en los próximos días, a medida que se entreguen los que siguen en camino.',
        ],
        'ticket' => [
            'label' => 'Ticket promedio',
            'format' => 'money',
            'matures' => false,
            'help' => 'Cuánto deja en promedio cada pedido entregado: monto vendido ÷ entregados.',
        ],
    ];

    /** Qué pasó con los pedidos creados en el período (la barra de colores). */
    public const STATUS = [
        self::DELIVERED => [
            'label' => 'Entregados',
            'help' => 'Ya llegaron al cliente. Cuentan como venta.',
        ],
        self::OPEN => [
            'label' => 'En camino',
            'help' => 'Todavía no se entregan ni se cancelan: siguen con la vendedora, la agencia o el repartidor. Todavía pueden convertirse en venta.',
        ],
        self::NOT_DELIVERED => [
            'label' => 'No entregados',
            'help' => 'Se cancelaron o el cliente los rechazó. Ya no van a ser venta.',
        ],
    ];

    public const SERIES_HELP = 'Cada barra son los pedidos creados ese día, pintados según lo que pasó con ellos hasta ahora. '
        . 'Por eso las barras de los últimos días tienen más "en camino": todavía se están entregando.';

    public const EXCLUDED_HELP = 'Los cambios de producto y las devoluciones también son pedidos, pero no son ventas nuevas: '
        . 'no se cuentan en los números de arriba.';

    /**
     * Los números del período, en una sola pasada por los pedidos.
     *
     * @param iterable<array|object> $facts
     */
    public static function compute(iterable $facts, Period $period): array
    {
        $granularity = Buckets::granularity($period);
        $start = $period->start->format('Y-m-d H:i:s');
        $end = $period->end->format('Y-m-d H:i:s');

        $series = [];
        foreach (array_keys(Buckets::ranges($period, $granularity)) as $key) {
            $series[$key] = self::emptyPoint($key);
        }
        $totals = self::emptyPoint(null);
        $excluded = ['exchange' => 0, 'return' => 0];

        foreach ($facts as $fact) {
            $f = (array) $fact;
            $createdAt = (string) $f['created_at'];
            // §1: cuenta el día en que se creó, no el día en que se entregó
            if ($createdAt < $start || $createdAt > $end) {
                continue;
            }
            if ($f['kind'] !== self::NORMAL) {
                $excluded[$f['kind']] = ($excluded[$f['kind']] ?? 0) + 1;
                continue;
            }
            $outcome = in_array($f['outcome'], [self::DELIVERED, self::NOT_DELIVERED], true) ? $f['outcome'] : self::OPEN;
            $revenue = $outcome === self::DELIVERED ? (float) $f['revenue'] : 0.0;
            $key = Buckets::key(substr($createdAt, 0, 10), $granularity);
            $series[$key] ??= self::emptyPoint($key);
            self::add($series[$key], $outcome, $revenue);
            self::add($totals, $outcome, $revenue);
        }

        return [
            'totals' => self::finish($totals),
            'series' => ['granularity' => $granularity, 'points' => array_values(array_map([self::class, 'finish'], $series))],
            'excluded' => $excluded,
        ];
    }

    /** Las tarjetas con su comparación contra el período anterior (ver Card). */
    public static function cards(array $current, array $previous, bool $maturing): array
    {
        $cards = [];
        foreach (self::METRICS as $key => $m) {
            $cards[] = Card::make($key, $m, $current[$key], $previous[$key], self::detail($key, $current), $maturing);
        }

        return $cards;
    }

    /** La cuenta con los números del período, para que el dueño vea de dónde sale cada tarjeta. */
    private static function detail(string $key, array $t): ?string
    {
        $money = fn (float $v) => Card::money($v);
        $orders = fn (int $n, string $one, string $many) => Card::plural($n, $one, $many);

        return match ($key) {
            'delivered' => $orders($t['delivered'], 'entregado', 'entregados') . ' de ' . $orders($t['created'], 'pedido creado', 'pedidos creados'),
            'revenue' => 'Suma de ' . $orders($t['delivered'], 'pedido entregado', 'pedidos entregados'),
            'effectiveness' => $t['created'] > 0 ? $orders($t['delivered'], 'entregado', 'entregados') . ' ÷ ' . $orders($t['created'], 'creado', 'creados') : null,
            'ticket' => $t['delivered'] > 0 ? $money($t['revenue']) . ' ÷ ' . $orders($t['delivered'], 'entregado', 'entregados') : null,
            default => null,
        };
    }

    private static function emptyPoint(?string $date): array
    {
        return ($date !== null ? ['date' => $date] : []) + [
            'created' => 0, self::DELIVERED => 0, self::OPEN => 0, self::NOT_DELIVERED => 0, 'revenue' => 0.0,
        ];
    }

    private static function add(array &$row, string $outcome, float $revenue): void
    {
        $row['created']++;
        $row[$outcome]++;
        $row['revenue'] += $revenue;
    }

    private static function finish(array $row): array
    {
        $row['revenue'] = round($row['revenue'], 2);
        $row['effectiveness'] = $row['created'] > 0 ? round($row[self::DELIVERED] / $row['created'] * 100, 1) : null;
        $row['ticket'] = $row[self::DELIVERED] > 0 ? round($row['revenue'] / $row[self::DELIVERED], 2) : null;

        return $row;
    }
}
