<?php

namespace App\Services\Metrics\Reports;

use App\Services\Metrics\Rules\GoalRules;

/**
 * Los números de un reporte del negocio (`.48b`), sacados del panel del dueño (las mismas secciones y reglas: Ventas,
 * Dinero, Pérdidas, Equipo, metas y alertas) y de la revisión de los números (48a). PHP puro: recibe lo que devuelve
 * OwnerPanel y NumbersCheck, se queda con lo que va en el reporte y lo escribe en texto para la IA (input), con los
 * números escritos como los tiene que repetir.
 */
final class ReportFacts
{
    /** Las tarjetas de cada parte del reporte, en orden. */
    public const CARDS = [
        'sales' => ['created', 'delivered', 'effectiveness', 'revenue', 'ticket'],
        'money' => ['profit', 'pending_profit', 'cost_per_sale', 'cost_per_order', 'receivable'],
        'losses' => ['cancelled', 'rejected', 'returns', 'failed_shipping', 'refunded'],
    ];

    /** Cuántos productos y personas se nombran. */
    private const TOP = 3;

    /**
     * @param array $panel ['sales' => OwnerPanel::sales, 'money' => ::money, 'losses' => ::losses, 'sellers' => ::team(sellers),
     *                     'agencies' => ::team(agencies), 'alerts' => ::alerts]
     * @param array $check NumbersCheck::run
     */
    public static function build(string $type, string $from, string $to, string $label, array $panel, array $check): array
    {
        $cards = [];
        foreach (self::CARDS as $section => $keys) {
            $byKey = array_column($panel[$section]['cards'] ?? [], null, 'key');
            foreach ($keys as $k) {
                if (isset($byKey[$k])) {
                    $cards[$section][] = self::card($byKey[$k]);
                }
            }
        }
        $goals = [];
        foreach (array_merge($panel['sales']['cards'] ?? [], $panel['money']['cards'] ?? []) as $c) {
            if (isset($c['goal'])) {
                $goals[] = ['label' => $c['goal']['label'] ?? $c['label'], 'status' => $c['goal']['status'] ?? null,
                    'progress' => $c['goal']['progress'] ?? null, 'text' => $c['goal']['text'] ?? ''];
            }
        }

        return [
            'type' => $type,
            'type_label' => ReportPeriods::LABELS[$type] ?? $type,
            'period' => ['from' => $from, 'to' => $to, 'label' => $label],
            'compare_label' => $panel['sales']['period']['compare_label'] ?? 'el período anterior',
            'maturing' => (bool) ($panel['sales']['maturing'] ?? false),
            'cards' => $cards,
            'waterfall' => array_map(fn ($w) => ['key' => $w['key'], 'label' => $w['label'], 'amount' => (float) $w['amount']], $panel['money']['waterfall'] ?? []),
            'products' => self::products($panel['money']['products']['rows'] ?? []),
            'reasons' => self::reasons($panel['losses']['reasons']['rows'] ?? []),
            'team' => [
                'sellers' => self::team($panel['sellers']['role'] ?? null),
                'agencies' => self::team($panel['agencies']['role'] ?? null),
            ],
            'goals' => $goals,
            'alerts' => array_map(fn ($a) => ['level' => $a['level'], 'title' => $a['title'],
                'items' => array_map(fn ($i) => (!empty($i['name']) ? "{$i['name']}: " : '') . $i['text'], array_slice($a['items'] ?? [], 0, 5))],
                $panel['alerts']['alerts'] ?? []),
            'check' => [
                'ok' => (bool) ($check['verdict']['ok'] ?? false),
                'text' => $check['verdict']['text'] ?? '',
                'problems' => array_values(array_map(fn ($p) => array_intersect_key($p, array_flip(['key', 'severity', 'text', 'count', 'amount', 'examples'])),
                    array_filter($check['problems'] ?? [], fn ($p) => $p['severity'] !== 'info'))),
            ],
        ];
    }

    /** Lo que se le manda a la IA: todos los números del reporte, escritos como los tiene que repetir. */
    public static function input(array $f): string
    {
        $fmt = [GoalRules::class, 'fmt'];
        $lines = ["Reporte {$f['type_label']} del negocio · {$f['period']['label']} · comparado con {$f['compare_label']}."];
        if ($f['maturing']) {
            $lines[] = 'OJO: los pedidos de los últimos días todavía se están entregando; entregados, efectividad y ganancia de este período pueden subir.';
        }
        $titles = ['sales' => 'VENTAS', 'money' => 'DINERO', 'losses' => 'PÉRDIDAS'];
        foreach ($titles as $section => $title) {
            $lines[] = '';
            $lines[] = $title;
            foreach ($f['cards'][$section] ?? [] as $c) {
                $lines[] = '- ' . self::cardLine($c);
            }
            if ($section === 'money' && $f['waterfall']) {
                $lines[] = '- A dónde se fue el dinero: ' . implode('; ', array_map(fn ($w) => "{$w['label']} " . $fmt($w['amount'], 'money'), $f['waterfall'])) . '.';
                $sold = collect($f['waterfall'])->firstWhere('key', 'revenue')['amount'] ?? null;
                $revenue = collect($f['cards']['sales'] ?? [])->firstWhere('key', 'revenue')['value'] ?? null;
                if ($sold !== null && $revenue !== null && abs($sold - $revenue) >= 0.01) {
                    $lines[] = '- (Lo vendido de "A dónde se fue el dinero" incluye los cambios de producto; el monto vendido de Ventas no.)';
                }
            }
        }
        $lines[] = '';
        $lines[] = 'PRODUCTOS (ganancia después de su publicidad)';
        foreach ($f['products']['best'] as $p) {
            $lines[] = "- Más dejó: {$p['name']}: ganancia " . $fmt($p['profit'], 'money') . ', vendido ' . $fmt($p['revenue'], 'money') . ", {$p['delivered']} entregados.";
        }
        foreach ($f['products']['losing'] as $p) {
            $lines[] = "- Perdió: {$p['name']}: ganancia " . $fmt($p['profit'], 'money') . ', vendido ' . $fmt($p['revenue'], 'money') . ', publicidad ' . $fmt($p['ads'], 'money') . '.';
        }
        if ($f['reasons']) {
            $lines[] = '- Motivos de cancelación más comunes: ' . implode('; ', array_map(fn ($r) => "{$r['label']} ({$r['count']}, " . $fmt($r['pct'], 'percent') . ')', $f['reasons'])) . '.';
        }
        foreach (['sellers' => 'VENDEDORAS', 'agencies' => 'AGENCIAS'] as $k => $title) {
            $t = $f['team'][$k];
            if ($t === null || ($t['best'] === [] && $t['dropped'] === [])) {
                continue;
            }
            $lines[] = '';
            $lines[] = "{$title} (por {$t['metric']})";
            foreach ($t['best'] as $p) {
                $lines[] = "- Mejor: {$p['name']}: " . $fmt($p['value'], $t['format']) . ($p['previous'] !== null ? ' (antes ' . $fmt($p['previous'], $t['format']) . ')' : '') . '.';
            }
            foreach ($t['dropped'] as $p) {
                $lines[] = "- Bajó: {$p['name']}: " . $fmt($p['value'], $t['format']) . ' (antes ' . $fmt($p['previous'], $t['format']) . ').';
            }
        }
        if ($f['goals']) {
            $lines[] = '';
            $lines[] = 'METAS';
            foreach ($f['goals'] as $g) {
                $lines[] = "- {$g['label']}: {$g['text']}";
            }
        }
        if ($f['alerts']) {
            $lines[] = '';
            $lines[] = 'ALERTAS (según los límites del dueño)';
            foreach ($f['alerts'] as $a) {
                $lines[] = '- ' . ($a['level'] === 'red' ? 'ROJA' : ($a['level'] === 'yellow' ? 'AMARILLA' : 'GRIS')) . " · {$a['title']}: " . implode(' ', $a['items']);
            }
        }
        $lines[] = '';
        $lines[] = 'CALIDAD DE LOS NÚMEROS: ' . $f['check']['text'];
        foreach ($f['check']['problems'] as $p) {
            $lines[] = '- ' . ($p['severity'] === 'grave' ? 'GRAVE' : 'aviso') . " ({$p['count']}" . ($p['amount'] !== null ? ', ' . $fmt($p['amount'], 'money') : '') . "): {$p['text']}"
                . ($p['examples'] ? ' Ej.: ' . implode('; ', array_slice($p['examples'], 0, 3)) . '.' : '');
        }

        return implode("\n", $lines);
    }

    /** "Ganancia: $1.234,56 (antes $1.000,00, +23,5 %)". */
    public static function cardLine(array $c): string
    {
        $fmt = [GoalRules::class, 'fmt'];
        $line = "{$c['label']}: " . $fmt($c['value'], $c['format']);
        if ($c['compare'] && $c['previous'] !== null) {
            $line .= ' (antes ' . $fmt($c['previous'], $c['format']);
            if ($c['change'] !== null) {
                $sign = $c['change'] > 0 ? '+' : ($c['change'] < 0 ? '−' : '');
                $line .= ', ' . $sign . ($c['change_unit'] === 'points'
                    ? rtrim(rtrim(number_format(abs($c['change']), 1, ',', '.'), '0'), ',') . ' puntos'
                    : $fmt(abs($c['change']), 'percent'));
            }
            $line .= ')';
        }
        if ($c['neutral']) {
            $line .= ' — todavía puede cambiar';
        }

        return $line . '.';
    }

    private static function card(array $c): array
    {
        // Lo mismo que una tarjeta del panel (la página la muestra con MetricCard), sin la meta (va aparte)
        return array_intersect_key($c, array_flip(['key', 'label', 'help', 'format', 'value', 'previous', 'change', 'change_unit', 'better', 'neutral', 'compare', 'detail', 'note']));
    }

    /** Los que más ganancia dejaron (con ventas) y los que perdieron, sin "Otros productos". */
    private static function products(array $rows): array
    {
        $named = array_values(array_filter($rows, fn ($r) => empty($r['others']) && $r['product_id'] !== null));
        $pick = fn (array $r) => ['name' => $r['name'] ?? "Producto {$r['product_id']}", 'revenue' => (float) $r['revenue'], 'ads' => (float) $r['ads'],
            'profit' => (float) $r['profit'], 'delivered' => (int) $r['delivered']];
        $best = array_filter($named, fn ($r) => $r['profit'] > 0);
        usort($best, fn ($a, $b) => $b['profit'] <=> $a['profit']);
        $losing = array_filter($named, fn ($r) => $r['profit'] < 0);
        usort($losing, fn ($a, $b) => $a['profit'] <=> $b['profit']);

        return ['best' => array_map($pick, array_slice($best, 0, self::TOP)), 'losing' => array_map($pick, array_slice($losing, 0, self::TOP))];
    }

    /** Los motivos de cancelación más comunes, con su % de todas las cancelaciones. */
    private static function reasons(array $rows): array
    {
        $total = array_sum(array_column($rows, 'count'));
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_map(fn ($r) => ['label' => $r['label'], 'count' => (int) $r['count'], 'pct' => $total > 0 ? round($r['count'] / $total * 100, 1) : 0.0],
            array_slice($rows, 0, self::TOP));
    }

    /**
     * Las mejores personas de un rol por el KPI de su ranking y las que más bajaron contra el período anterior (sin las de
     * pocos datos).
     */
    private static function team(?array $role): ?array
    {
        if ($role === null || empty($role['rows'])) {
            return null;
        }
        $key = $role['rank_by'];
        $column = collect($role['columns'] ?? [])->firstWhere('key', $key) ?? ['label' => $key, 'format' => 'count', 'better' => 'higher'];
        $higher = ($column['better'] ?? 'higher') !== 'lower';
        $rows = array_values(array_filter($role['rows'], fn ($r) => !$r['low_data'] && ($r['values'][$key] ?? null) !== null));
        $pick = fn (array $r) => ['name' => $r['name'], 'value' => (float) $r['values'][$key], 'previous' => isset($r['previous'][$key]) ? (float) $r['previous'][$key] : null];

        $best = $rows;
        usort($best, fn ($a, $b) => $higher ? $b['values'][$key] <=> $a['values'][$key] : $a['values'][$key] <=> $b['values'][$key]);
        // Las que más bajaron, sin repetir a las mejores
        $bestIds = array_column(array_slice($best, 0, self::TOP), 'id');
        $dropped = array_filter($rows, function ($r) use ($key, $higher, $bestIds) {
            $before = $r['previous'][$key] ?? null;
            return !in_array($r['id'], $bestIds, true) && $before !== null && ($higher ? $r['values'][$key] < $before : $r['values'][$key] > $before);
        });
        usort($dropped, fn ($a, $b) => abs($b['values'][$key] - $b['previous'][$key]) <=> abs($a['values'][$key] - $a['previous'][$key]));

        return [
            'label' => $role['label'],
            'metric' => mb_strtolower($column['label']),
            'format' => $column['format'] ?? 'count',
            'best' => array_map($pick, array_slice($best, 0, self::TOP)),
            'dropped' => array_map($pick, array_slice(array_values($dropped), 0, self::TOP)),
        ];
    }
}
