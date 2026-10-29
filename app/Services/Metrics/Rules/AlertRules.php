<?php

namespace App\Services\Metrics\Rules;

/**
 * Las alertas del panel del dueño (diccionario de métricas, §6): el sistema avisa solo, arriba del panel, en amarillo o
 * rojo, con los límites que puso el dueño (GoalRules). PHP puro. Miran el período y los filtros que eligió el dueño arriba
 * (pedido del dueño, 2026-10-29), con los mismos números de Equipo, Dinero y Pérdidas:
 * - vendedoras y agencias: los pedidos que les entraron en el período. Solo quien tuvo al menos el mínimo de su rol (10
 *   pedidos). Para no avisar en falso, amarillo o rojo solo si ya no llega aunque se le entregue lo que tiene en camino;
 *   si todavía puede llegar, gris ("pending");
 * - el dinero sin entregar de agencias y repartidores: lo de ahora mismo (no depende de fechas);
 * - la publicidad: la del período, con lo que se espera entregar de lo que sigue en camino;
 * - las cancelaciones: de los pedidos del período, desde 10 pedidos.
 *
 * Cada alerta junta a todas las personas de un mismo tipo: {type, level (el peor), title, help, section, role, items}. Cada
 * item tiene su texto entero ("text", para el cursor) y uno corto ("short", para la línea de la alerta).
 */
final class AlertRules
{
    /** Las cancelaciones: desde cuántos pedidos del período. */
    public const MIN_ORDERS = 10;

    /** El orden de los colores: rojo, amarillo y gris (todavía puede llegar). */
    private const RANK = ['red' => 0, 'yellow' => 1, 'pending' => 2];

    /**
     * Las personas de un rol que no llegan a su meta (o se pasan de su máximo).
     *
     * @param array<int, array{name: string, group: ?string, values: array}> $people lo que calcula el rol (compute)
     * @param array{key: string, min: int, label: string} $base el mínimo de datos del rol
     */
    public static function team(string $key, array $goal, array $people, array $base, string $window): ?array
    {
        $def = GoalRules::TEAM[$key];
        if (!$goal['alert'] || $goal['value'] === null) {
            return null;
        }
        $items = [];
        foreach ($people as $id => $p) {
            if ((int) ($p['values'][$base['key']] ?? 0) < $base['min']) {
                continue;
            }
            $value = $p['values'][$def['metric']] ?? null;
            $limits = GoalRules::personLimits($goal, (int) $id);
            $level = GoalRules::certainLevel($value, $p['values']["{$def['metric']}_best"] ?? null, $limits['yellow'], $limits['red'], $def['better']);
            if ($level === 'ok') {
                continue;
            }
            $open = (int) ($p['values']['open'] ?? 0);
            $limit = ($def['better'] === 'lower' ? 'máximo ' : 'meta ') . GoalRules::limit($limits['yellow'], $def['format']);
            $items[] = [
                'id' => (int) $id,
                'name' => $p['name'],
                'level' => $level,
                'value' => $value,
                'text' => GoalRules::fmt($value, $def['format']) . ' · ' . $limit
                    . ($level === 'pending' ? ' · todavía puede llegar (' . Card::plural($open, 'pedido', 'pedidos') . ' en camino)' : ''),
                'short' => GoalRules::fmt($value, $def['format']) . ($level === 'pending' ? ' · puede llegar' : ''),
            ];
        }
        if ($items === []) {
            return null;
        }
        // Lo peor primero: rojas, amarillas y al final las que todavía pueden llegar
        usort($items, fn ($a, $b) => [self::RANK[$a['level']], $def['better'] === 'lower' ? -$a['value'] : $a['value']]
            <=> [self::RANK[$b['level']], $def['better'] === 'lower' ? -$b['value'] : $b['value']]);

        return self::make($key, $def['title'], $items, $def['help'] . " Se miran los pedidos que les entraron {$window}. Si todavía tienen pedidos en camino, "
            . 'sale en amarillo o rojo solo quien ya no llega aunque se le entreguen todos; quien todavía puede llegar, en gris. '
            . "Solo cuenta a quien tuvo al menos {$base['min']} {$base['label']}.", 'team', $def['role']);
    }

    /**
     * Quienes tienen más dinero sin entregar que el límite.
     *
     * @param array<int, array{id: ?int, name: string, usd: float}> $holders
     */
    public static function cash(string $key, array $limits, array $holders): ?array
    {
        $def = GoalRules::ALERTS[$key];
        if (!$limits['on'] || $limits['yellow'] === null) {
            return null;
        }
        $items = [];
        foreach ($holders as $h) {
            $level = GoalRules::level($h['usd'], $limits['yellow'], $limits['red'], 'lower');
            if ($level !== null) {
                $items[] = ['id' => $h['id'], 'name' => $h['name'], 'level' => $level, 'value' => $h['usd'], 'text' => Card::money($h['usd']) . ' sin entregar (límite ' . Card::money($limits['yellow']) . ')',
                    'short' => Card::money($h['usd'])];
            }
        }
        if ($items === []) {
            return null;
        }
        usort($items, fn ($a, $b) => $b['value'] <=> $a['value']);

        return self::make($key, $def['title'], $items, $def['help'], $def['section'], $def['role']);
    }

    /**
     * El costo por venta de la publicidad de los últimos días: lo gastado ÷ (entregados + en camino × el % que
     * normalmente se entrega). Si no se espera ninguna venta, cuenta como una (lo gastado entero).
     */
    public static function adCost(array $limits, float $ads, int $delivered, int $open, ?float $rate, string $window): ?array
    {
        $def = GoalRules::ALERTS['ad_cost'];
        if (!$limits['on'] || $limits['yellow'] === null || $ads <= 0) {
            return null;
        }
        $expected = $delivered + ($rate !== null ? $open * $rate : 0);
        $cost = round($ads / max($expected, 1), 2);
        $level = GoalRules::level($cost, $limits['yellow'], $limits['red'], 'lower');
        if ($level === null) {
            return null;
        }
        $sales = number_format($expected, 0, ',', '.');
        $item = [
            'id' => null,
            'name' => null,
            'level' => $level,
            'value' => $cost,
            'text' => 'Cada venta te está costando cerca de ' . Card::money($cost) . ' (límite ' . Card::money($limits['yellow']) . '): '
                . Card::money($ads) . " de publicidad {$window} ÷ ~{$sales} ventas (" . Card::plural($delivered, 'entregada', 'entregadas')
                . ($rate !== null && $open > 0 ? ' y las que se esperan de ' . $open . ' en camino' : '') . ').',
            'short' => Card::money($cost) . ' por venta · límite ' . Card::money($limits['yellow']),
        ];

        return self::make('ad_cost', $def['title'], [$item], $def['help'], $def['section'], null);
    }

    /** Las cancelaciones: de los pedidos que entraron en el período, cuántos ya se cancelaron (solo puede subir). */
    public static function cancellations(array $limits, int $created, int $cancelled, string $window): ?array
    {
        $def = GoalRules::ALERTS['cancellations'];
        if (!$limits['on'] || $limits['yellow'] === null || $created < self::MIN_ORDERS) {
            return null;
        }
        $pct = round($cancelled * 100 / $created, 1);
        $level = GoalRules::level($pct, $limits['yellow'], $limits['red'], 'lower');
        if ($level === null) {
            return null;
        }
        $item = [
            'id' => null,
            'name' => null,
            'level' => $level,
            'value' => $pct,
            'text' => "Se cancelaron {$cancelled} de los {$created} pedidos que entraron {$window} (" . GoalRules::fmt($pct, 'percent') . '; límite ' . GoalRules::limit($limits['yellow'], 'percent') . ').',
            'short' => "{$cancelled} de {$created} (" . GoalRules::fmt($pct, 'percent') . ') · límite ' . GoalRules::limit($limits['yellow'], 'percent'),
        ];

        return self::make('cancellations', $def['title'], [$item], $def['help'], $def['section'], null);
    }

    /** Las rojas primero, después las amarillas y al final las grises; dentro de cada color, en el orden en que se revisaron. */
    public static function sort(array $alerts): array
    {
        $alerts = array_values(array_filter($alerts));
        // usort es estable (PHP 8): a igual color, queda el orden en que se revisaron
        usort($alerts, fn ($a, $b) => self::RANK[$a['level']] <=> self::RANK[$b['level']]);

        return $alerts;
    }

    private static function make(string $type, string $title, array $items, string $help, string $section, ?string $role): array
    {
        return [
            'type' => $type,
            'level' => array_reduce(array_column($items, 'level'), fn ($worst, $l) => self::RANK[$l] < self::RANK[$worst] ? $l : $worst, 'pending'),
            'title' => $title,
            'help' => $help,
            'section' => $section,
            'role' => $role,
            'items' => $items,
        ];
    }
}
