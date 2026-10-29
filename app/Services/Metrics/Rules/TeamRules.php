<?php

namespace App\Services\Metrics\Rules;

use App\Services\Metrics\Team\TeamRole;

/**
 * Equipo del panel del dueño: arma, para cualquier rol del catálogo, lo que muestra la pantalla. PHP puro. Lo que cada
 * KPI significa y cómo se calcula está en la clase de su rol (Metrics\Team); aquí solo se ordena y se compara:
 * - las tarjetas del rol (los KPIs con "card"), contra el período anterior, como en las demás secciones;
 * - una fila por persona con sus KPIs de ahora y del período anterior;
 * - "pocos datos": quien no llega al mínimo de su rol va al final del ranking, en gris;
 * - las marcas de los KPIs con "flag": quien tiene el doble del promedio o más (y al menos 2).
 */
final class TeamRules
{
    public static function build(TeamRole $role, array $current, array $before, bool $maturing): array
    {
        $metrics = $role->metrics();
        $cards = [];
        foreach ($metrics as $key => $m) {
            if (!empty($m['card'])) {
                $cards[] = Card::make($key, self::cardMeta($m), $current['totals']['values'][$key] ?? null,
                    $before['totals']['values'][$key] ?? null, $current['totals']['details'][$key] ?? null, $maturing);
            }
        }

        return [
            'key' => $role->key(),
            'label' => $role->label(),
            'help' => $role->help(),
            'filters_note' => $role->filtersNote(),
            'columns' => self::columns($role),
            'rank_by' => $role->rankBy(),
            'base' => $role->base(),
            'cards' => $cards,
            'rows' => self::rows($role, $current['people'], $before['people'] ?? []),
            // Todo el rol junto (un pedido que pasó por dos personas cuenta una vez), para comparar a cada una
            'totals' => array_intersect_key($current['totals']['values'], $metrics),
            'totals_previous' => array_map(fn ($key) => empty($metrics[$key]['now']) ? ($before['totals']['values'][$key] ?? null) : null,
                array_combine(array_keys($metrics), array_keys($metrics))),
        ];
    }

    /** Las columnas de la tabla: lo que la pantalla necesita saber de cada KPI. */
    public static function columns(TeamRole $role): array
    {
        $out = [];
        foreach ($role->metrics() as $key => $m) {
            $out[] = [
                'key' => $key,
                'label' => $m['label'],
                'help' => $m['help'],
                'format' => $m['format'],
                'better' => $m['better'] ?? 'higher',
                'matures' => (bool) ($m['matures'] ?? false),
                'now' => (bool) ($m['now'] ?? false),
                'flag' => (bool) ($m['flag'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Una fila por persona, ordenadas por el KPI del ranking (las de pocos datos y las sin valor, al final).
     *
     * @param array<int, array> $people id => name, group, values, details (lo que devuelve compute)
     * @param array<int, array> $before lo mismo del período anterior
     */
    public static function rows(TeamRole $role, array $people, array $before): array
    {
        $metrics = $role->metrics();
        $base = $role->base();
        $rows = [];
        foreach ($people as $id => $p) {
            $values = array_intersect_key($p['values'], $metrics);
            $previous = null;
            if (isset($before[$id])) {
                $previous = [];
                foreach ($metrics as $key => $m) {
                    // Lo de ahora mismo no tiene "antes"
                    $previous[$key] = empty($m['now']) ? ($before[$id]['values'][$key] ?? null) : null;
                }
            }
            $rows[] = [
                'id' => (int) $id,
                'name' => $p['name'],
                'group' => $p['group'] ?? null,
                'values' => $values,
                'previous' => $previous,
                'low_data' => (int) ($values[$base['key']] ?? 0) < $base['min'],
                'flags' => [],
            ];
        }

        foreach ($metrics as $key => $m) {
            if (empty($m['flag']) || $rows === []) {
                continue;
            }
            $average = array_sum(array_map(fn ($r) => (float) ($r['values'][$key] ?? 0), $rows)) / count($rows);
            foreach ($rows as &$r) {
                $v = (float) ($r['values'][$key] ?? 0);
                if ($v >= 2 && $v >= 2 * $average) {
                    $r['flags'][] = $key;
                }
            }
            unset($r);
        }

        return self::sort($rows, $role->rankBy(), $metrics[$role->rankBy()]['better'] ?? 'higher');
    }

    /** Lo mejor primero; las de pocos datos y las sin valor, al final; a igual valor, por nombre. */
    public static function sort(array $rows, string $key, string $better): array
    {
        usort($rows, function ($a, $b) use ($key, $better) {
            if ($a['low_data'] !== $b['low_data']) {
                return $a['low_data'] <=> $b['low_data'];
            }
            $va = $a['values'][$key] ?? null;
            $vb = $b['values'][$key] ?? null;
            if (($va === null) !== ($vb === null)) {
                return $va === null ? 1 : -1;
            }
            $cmp = $better === 'lower' ? $va <=> $vb : $vb <=> $va;

            return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /** Las tarjetas de una persona (el detalle), con todos sus KPIs. */
    public static function personCards(TeamRole $role, array $person, ?array $before, bool $maturing): array
    {
        $cards = [];
        foreach ($role->metrics() as $key => $m) {
            $cards[] = Card::make($key, self::cardMeta($m), $person['values'][$key] ?? null, $before['values'][$key] ?? null,
                $person['details'][$key] ?? null, $maturing);
        }

        return $cards;
    }

    /** Lo de ahora mismo no se compara; "none" se muestra como un número que no es ni bueno ni malo. */
    private static function cardMeta(array $m): array
    {
        if (!empty($m['now'])) {
            $m['compare'] = false;
        }

        return $m;
    }
}
