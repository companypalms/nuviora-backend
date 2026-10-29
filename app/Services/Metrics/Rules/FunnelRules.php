<?php

namespace App\Services\Metrics\Rules;

use App\Constants\OrderStatus;
use App\Services\Metrics\Period;

/**
 * Embudo del panel del dueño (diccionario de métricas, §3, §4 y §8). PHP puro: recibe el recorrido de cada pedido
 * (OrderFactsReader::funnel) y cuenta cuántos llegaron a cada paso. Cada número tiene aquí mismo su explicación.
 *
 * Las reglas:
 * - Cuenta los pedidos CREADOS en el período (sin cambios ni devoluciones), aunque avancen días después (§1, §3).
 * - Un pedido "llegó" a un paso si alguna vez estuvo en uno de sus estados, o si llegó a un paso posterior: un pedido
 *   entregado pasó por todos, aunque alguien se haya saltado un estado. Por eso cada paso nunca es mayor que el anterior.
 * - Entregados = los que hoy están entregados, el mismo número de Ventas.
 * - "Tiempo normal" = la mediana: la mitad de los pedidos tardó menos que eso. Un pedido que tardó un mes no la infla
 *   como inflaría un promedio.
 */
final class FunnelRules
{
    /** Los pasos, en orden, con su explicación. */
    public const STEPS = [
        'created' => [
            'label' => 'Creados',
            'help' => 'Pedidos que entraron en el período, con los filtros de arriba. No cuenta cambios ni devoluciones.',
        ],
        'assigned' => [
            'label' => 'Llegaron a una vendedora',
            'help' => 'Pedidos que alguna vez estuvieron en la lista de una vendedora ("Asignado a vendedor" o "Reprogramado para hoy"). Los que faltan nunca los atendió nadie: se quedaron en "Nuevo" o se cancelaron antes.',
        ],
        'confirmed' => [
            'label' => 'Confirmados',
            'help' => 'La vendedora los confirmó y pasaron a reparto: a una agencia, a un repartidor propio, o esperando cupo en una agencia.',
        ],
        'on_route' => [
            'label' => 'Salieron a entregar',
            'help' => 'Llegaron a "En ruta": el repartidor salió con ellos.',
        ],
        'delivered' => [
            'label' => 'Entregados',
            'help' => 'Llegaron al cliente. Es el mismo número de "Entregados" de Ventas.',
        ],
    ];

    /** Qué estados cuentan como haber llegado a cada paso (los demás pasos se miden distinto: ver arriba). */
    public const STATUSES = [
        'assigned' => [OrderStatus::ASIGNADO_VENDEDOR, OrderStatus::REPROGRAMADO_HOY],
        'confirmed' => ['Confirmado', OrderStatus::PENDIENTE_AGENCIA, OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR],
        'on_route' => [OrderStatus::EN_RUTA],
    ];

    public const METRICS = [
        'confirmation_rate' => [
            'label' => 'Tasa de confirmación',
            'format' => 'percent',
            'matures' => true,
            'help' => 'De los pedidos creados en este período, qué porcentaje confirmaron las vendedoras y pasó a reparto (confirmados ÷ creados). Mide el trabajo de ventas.',
        ],
        'delivery_rate' => [
            'label' => 'Entrega de lo confirmado',
            'format' => 'percent',
            'matures' => true,
            'help' => 'De los pedidos confirmados, qué porcentaje ya llegó al cliente (entregados ÷ confirmados). Mide a las agencias y a los repartidores. Puede subir en los próximos días, a medida que se entreguen los que siguen en camino.',
        ],
        'time_to_deliver' => [
            'label' => 'Tiempo hasta entregar',
            'format' => 'hours',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Lo que tarda normalmente un pedido desde que entra hasta que se entrega: la mitad de los pedidos entregados tardó menos que esto (la mediana). Más bajo es mejor.',
        ],
    ];

    public const HELP = 'De los pedidos creados en el período, cuántos llegaron a cada paso (alguna vez) y qué parte pasó del paso anterior. Entre paso y paso, los que se quedaron ahí y qué les pasó.';

    private const ORDER = ['created', 'assigned', 'confirmed', 'on_route', 'delivered'];

    /** De qué momento se mide el tiempo de cada paso: desde el paso anterior que tenga hora. */
    private const SINCE = ['assigned' => ['created_at'], 'confirmed' => ['assigned_at', 'created_at'], 'on_route' => ['confirmed_at'], 'delivered' => ['route_at']];

    private const AT = ['created' => 'created_at', 'assigned' => 'assigned_at', 'confirmed' => 'confirmed_at', 'on_route' => 'route_at', 'delivered' => 'delivered_at'];

    /**
     * Un hecho es un pedido: ['created_at', 'kind', 'outcome', 'status' (el de hoy), 'assigned_at', 'confirmed_at',
     * 'route_at', 'delivered_at'] (las horas, la primera vez que llegó a ese paso; vacías si nunca llegó).
     *
     * @param iterable<array|object> $facts
     */
    public static function compute(iterable $facts, Period $period): array
    {
        $start = $period->start->format('Y-m-d H:i:s');
        $end = $period->end->format('Y-m-d H:i:s');
        $count = array_fill_keys(self::ORDER, 0);
        $stayed = array_fill_keys(self::ORDER, ['cancelled' => 0, 'rejected' => 0, 'open' => 0]);
        $hours = array_fill_keys(self::ORDER, []);
        $total = [];

        foreach ($facts as $fact) {
            $f = (array) $fact;
            if ((string) $f['created_at'] < $start || (string) $f['created_at'] > $end || $f['kind'] !== SalesRules::NORMAL) {
                continue;
            }
            $reached = self::reached($f);
            foreach ($reached as $step) {
                $count[$step]++;
            }
            $last = end($reached);
            if ($last !== 'delivered') {
                $kind = $f['outcome'] === SalesRules::NOT_DELIVERED ? ($f['status'] === OrderStatus::RECHAZADO ? 'rejected' : 'cancelled') : 'open';
                $stayed[$last][$kind]++;
            }
            foreach (self::SINCE as $step => $from) {
                $at = $step === 'delivered' && $f['outcome'] !== SalesRules::DELIVERED ? null : ($f[self::AT[$step]] ?? null);
                $since = null;
                foreach ($from as $k) {
                    if (!empty($f[$k])) {
                        $since = $f[$k];
                        break;
                    }
                }
                if ($at && $since && ($h = self::hoursBetween($since, $at)) !== null) {
                    $hours[$step][] = $h;
                }
            }
            if ($f['outcome'] === SalesRules::DELIVERED && !empty($f['delivered_at']) && ($h = self::hoursBetween($f['created_at'], $f['delivered_at'])) !== null) {
                $total[] = $h;
            }
        }

        $steps = [];
        $previous = null;
        foreach (self::ORDER as $step) {
            $steps[] = [
                'key' => $step,
                'label' => self::STEPS[$step]['label'],
                'help' => self::STEPS[$step]['help'],
                'count' => $count[$step],
                'of_created' => $count['created'] > 0 ? round($count[$step] / $count['created'] * 100, 1) : null,
                'of_previous' => $previous === null ? null : ($count[$previous] > 0 ? round($count[$step] / $count[$previous] * 100, 1) : null),
                'median_hours' => self::median($hours[$step]),
                'timed' => count($hours[$step]),
                'stayed' => $step === 'delivered' ? null : $stayed[$step] + ['total' => array_sum($stayed[$step])],
            ];
            $previous = $step;
        }

        return [
            'steps' => $steps,
            'totals' => [
                'created' => $count['created'],
                'confirmed' => $count['confirmed'],
                'delivered' => $count['delivered'],
                'confirmation_rate' => $count['created'] > 0 ? round($count['confirmed'] / $count['created'] * 100, 1) : null,
                'delivery_rate' => $count['confirmed'] > 0 ? round($count['delivered'] / $count['confirmed'] * 100, 1) : null,
                'time_to_deliver' => self::median($total),
                'timed_deliveries' => count($total),
            ],
        ];
    }

    public static function cards(array $now, array $before, bool $maturing): array
    {
        $details = [
            'confirmation_rate' => $now['created'] > 0 ? Card::plural($now['confirmed'], 'confirmado', 'confirmados') . ' ÷ ' . Card::plural($now['created'], 'creado', 'creados') : null,
            'delivery_rate' => $now['confirmed'] > 0 ? Card::plural($now['delivered'], 'entregado', 'entregados') . ' ÷ ' . Card::plural($now['confirmed'], 'confirmado', 'confirmados') : null,
            'time_to_deliver' => $now['timed_deliveries'] > 0 ? 'La mediana de ' . Card::plural($now['timed_deliveries'], 'pedido entregado', 'pedidos entregados') : null,
        ];
        $cards = [];
        foreach (self::METRICS as $key => $m) {
            $cards[] = Card::make($key, $m, $now[$key], $before[$key], $details[$key], $maturing);
        }

        return $cards;
    }

    /** "57 % confirmados × 62 % de lo confirmado entregado = 35 % de efectividad general". */
    public static function relation(array $t): ?string
    {
        if ($t['confirmation_rate'] === null || $t['delivery_rate'] === null || $t['created'] === 0) {
            return null;
        }
        $pct = fn (float $v) => number_format($v, 1, ',', '.') . ' %';

        return $pct($t['confirmation_rate']) . ' confirmados × ' . $pct($t['delivery_rate']) . ' de lo confirmado entregado = '
            . $pct(round($t['delivered'] / $t['created'] * 100, 1)) . ' de efectividad general. El número más bajo de los dos te dice dónde está el problema: en ventas o en el reparto.';
    }

    /** Los pasos a los que llegó un pedido, en orden (si llegó a uno, llegó a todos los de antes). */
    private static function reached(array $f): array
    {
        $last = match (true) {
            $f['outcome'] === SalesRules::DELIVERED => 4,
            !empty($f['route_at']) => 3,
            !empty($f['confirmed_at']) => 2,
            !empty($f['assigned_at']) => 1,
            default => 0,
        };

        return array_slice(self::ORDER, 0, $last + 1);
    }

    private static function hoursBetween(string $from, string $to): ?float
    {
        $h = (strtotime($to) - strtotime($from)) / 3600;

        return $h >= 0 ? $h : null;
    }

    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
