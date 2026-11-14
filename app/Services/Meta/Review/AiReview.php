<?php

namespace App\Services\Meta\Review;

/**
 * `.143` (dueño, 2026-11-14: "quiero saber si ese criterio realmente es bueno comparado a cómo eran nuestros resultados
 * antes… no quiero que la IA lo empeore, sino que lo potencie"): las cuentas de la radiografía antes / después de la IA de
 * Meta Ads, en PHP puro. Una sola vara para los dos períodos: el BE y el C de hoy de cada producto y ciudad (la fórmula
 * cambió varias veces; con la vara de cada día, la comparación saldría torcida).
 */
final class AiReview
{
    /** Un anuncio "nuevo" se juzga por sus primeros días: los mismos para los dos períodos. */
    public const WINNER_DAYS = 7;

    public const WINNER_MIN_PURCHASES = 3;

    /** Una semana de una campaña "subió" si gastó por día un 20 % o más que la anterior. */
    public const SCALE_JUMP = 0.2;

    /** Lo que cuenta como "se apagó": menos de $1 en los días siguientes. */
    public const OFF_SPEND = 1.0;

    /** Las misiones que piden frenar: tenían razón si, sin hacerles caso, se siguió perdiendo plata. */
    public const BRAKES = ['decrease', 'step_down', 'undo_raise', 'pause_city', 'not_sold', 'product_off', 'stop_creatives', 'stock_out'];

    public static function perDay(float $value, int $days): ?float
    {
        return $days > 0 ? round($value / $days, 2) : null;
    }

    /** "+12 %", "−8 %" o "—": cuánto cambió de antes a después. */
    public static function change(?float $before, ?float $after): string
    {
        if ($before === null || $after === null || abs($before) < 0.005) {
            return '—';
        }
        $pct = ($after - $before) / abs($before) * 100;

        return ($pct >= 0 ? '+' : '−') . number_format(abs($pct), 0) . ' %';
    }

    public static function cpa(float $spend, float $purchases): ?float
    {
        return $purchases > 0 ? round($spend / $purchases, 2) : null;
    }

    /** Lo que deja según Meta, con la vara del BE (`.126`): compras × BE − gasto. */
    public static function gain(float $spend, float $purchases, float $be): float
    {
        return round($purchases * $be - $spend, 2);
    }

    /** Pierde plata: gastó más de un BE y no compró, o compró más caro que el BE. */
    public static function losing(float $spend, float $purchases, float $be): bool
    {
        return $be > 0 && $spend > $be && ($purchases <= 0 || $spend / $purchases > $be);
    }

    /** El lunes de la semana de una fecha. */
    public static function week(string $date): string
    {
        $date = substr($date, 0, 10);

        return date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days'));
    }

    /**
     * El dinero quemado: de lo que se gastó en anuncios, cuánto fue en semanas en que ese anuncio perdía plata.
     *
     * @param list<array{ad: string, cell: string, date: string, spend: float, purchases: float}> $days un anuncio por día
     * @param array<string, array{be: ?float, c: ?float}> $yard la vara de cada producto|ciudad
     * @return array{spend: float, losing: float, pct: ?float, no_yard: float}
     */
    public static function burned(array $days, array $yard): array
    {
        $weeks = [];
        foreach ($days as $d) {
            $k = $d['ad'] . '|' . self::week($d['date']);
            $weeks[$k] ??= ['cell' => $d['cell'], 'spend' => 0.0, 'purchases' => 0.0];
            $weeks[$k]['spend'] += $d['spend'];
            $weeks[$k]['purchases'] += $d['purchases'];
        }
        $out = ['spend' => 0.0, 'losing' => 0.0, 'pct' => null, 'no_yard' => 0.0];
        foreach ($weeks as $w) {
            $be = $yard[$w['cell']]['be'] ?? null;
            if (!$be) {
                $out['no_yard'] += $w['spend'];
                continue;
            }
            $out['spend'] += $w['spend'];
            $out['losing'] += self::losing($w['spend'], $w['purchases'], $be) ? $w['spend'] : 0.0;
        }
        $out['pct'] = $out['spend'] > 0 ? round($out['losing'] / $out['spend'] * 100, 1) : null;

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $out);
    }

    /**
     * Cuánto alcanzaba a gastar un anuncio perdedor antes de apagarse: los que dejaron de gastar antes de $cut y en toda su
     * vida perdieron plata.
     *
     * @param list<array{ad: string, cell: string, date: string, spend: float, purchases: float}> $days
     * @return array{losers: int, avg_spend: ?float, median_spend: ?float, avg_days: ?float, total: float}
     */
    public static function untilOff(array $days, array $yard, string $cut): array
    {
        $ads = [];
        foreach ($days as $d) {
            if ($d['spend'] <= 0) {
                continue;
            }
            $a = &$ads[$d['ad']];
            $a ??= ['cell' => $d['cell'], 'spend' => 0.0, 'purchases' => 0.0, 'first' => $d['date'], 'last' => $d['date']];
            $a['spend'] += $d['spend'];
            $a['purchases'] += $d['purchases'];
            $a['first'] = min($a['first'], $d['date']);
            $a['last'] = max($a['last'], $d['date']);
            unset($a);
        }
        $spends = [];
        $lives = [];
        foreach ($ads as $a) {
            $be = $yard[$a['cell']]['be'] ?? null;
            if ($a['last'] >= $cut || !$be || !self::losing($a['spend'], $a['purchases'], $be)) {
                continue;
            }
            $spends[] = $a['spend'];
            $lives[] = (strtotime($a['last']) - strtotime($a['first'])) / 86400 + 1;
        }
        sort($spends);
        $n = count($spends);

        return ['losers' => $n, 'avg_spend' => $n ? round(array_sum($spends) / $n, 2) : null,
            'median_spend' => $n ? round($n % 2 ? $spends[intdiv($n, 2)] : ($spends[$n / 2 - 1] + $spends[$n / 2]) / 2, 2) : null,
            'avg_days' => $n ? round(array_sum($lives) / $n, 1) : null, 'total' => round(array_sum($spends), 2)];
    }

    /**
     * Los anuncios que empezaron a gastar en el período, juzgados por sus primeros 7 días: ganador si trajo 3 compras o más
     * a un CPA en el C o debajo. Solo los que ya cumplieron 7 días ($lastStart).
     *
     * @return array{new: int, judged: int, winners: int, pct: ?float, gain: float, spend: float}
     */
    public static function winners(array $days, array $yard, string $from, string $lastStart): array
    {
        $ads = [];
        foreach ($days as $d) {
            if ($d['spend'] > 0) {
                $ads[$d['ad']]['first'] = min($ads[$d['ad']]['first'] ?? $d['date'], $d['date']);
                $ads[$d['ad']]['cell'] = $d['cell'];
            }
        }
        $out = ['new' => 0, 'judged' => 0, 'winners' => 0, 'pct' => null, 'gain' => 0.0, 'spend' => 0.0];
        $first = [];
        foreach ($ads as $ad => $a) {
            if ($a['first'] >= $from) {
                $out['new']++;
                $first[$ad] = $a;
            }
        }
        $totals = [];
        foreach ($days as $d) {
            $a = $first[$d['ad']] ?? null;
            if ($a === null || $a['first'] > $lastStart || $d['date'] >= date('Y-m-d', strtotime($a['first'] . ' +' . self::WINNER_DAYS . ' days'))) {
                continue;
            }
            $totals[$d['ad']]['spend'] = ($totals[$d['ad']]['spend'] ?? 0) + $d['spend'];
            $totals[$d['ad']]['purchases'] = ($totals[$d['ad']]['purchases'] ?? 0) + $d['purchases'];
        }
        foreach ($totals as $ad => $t) {
            $y = $yard[$first[$ad]['cell']] ?? [];
            if (!($y['c'] ?? null) || !($y['be'] ?? null)) {
                continue;
            }
            $out['judged']++;
            $out['spend'] += $t['spend'];
            $out['gain'] += self::gain($t['spend'], $t['purchases'], $y['be']);
            if ($t['purchases'] >= self::WINNER_MIN_PURCHASES && $t['spend'] / $t['purchases'] <= $y['c']) {
                $out['winners']++;
            }
        }
        $out['pct'] = $out['judged'] ? round($out['winners'] / $out['judged'] * 100, 1) : null;
        $out['gain'] = round($out['gain'], 2);
        $out['spend'] = round($out['spend'], 2);

        return $out;
    }

    /**
     * Las semanas en que una campaña subió su gasto por día un 20 % o más: si el CPA y lo que deja aguantaron.
     *
     * @param list<array{campaign: string, cell: string, date: string, spend: float, purchases: float}> $days la campaña por día
     * @return list<array{campaign: string, week: string, spend_before: float, spend_after: float, cpa_before: ?float, cpa_after: ?float,
     *     gain_before: float, gain_after: float, held: bool}> gasto y ganancia por día
     */
    public static function scaleWeeks(array $days, array $yard): array
    {
        $weeks = [];
        foreach ($days as $d) {
            $k = $d['campaign'] . '|' . self::week($d['date']);
            $weeks[$k] ??= ['campaign' => $d['campaign'], 'cell' => $d['cell'], 'week' => self::week($d['date']), 'spend' => 0.0, 'purchases' => 0.0, 'days' => 0];
            $weeks[$k]['spend'] += $d['spend'];
            $weeks[$k]['purchases'] += $d['purchases'];
            $weeks[$k]['days'] += $d['spend'] > 0 ? 1 : 0;
        }
        $out = [];
        foreach ($weeks as $w) {
            $prev = $weeks[$w['campaign'] . '|' . date('Y-m-d', strtotime($w['week'] . ' -7 days'))] ?? null;
            $be = $yard[$w['cell']]['be'] ?? null;
            // dos semanas casi completas (5 días o más con gasto), para no tomar un arranque o un apagado por una subida
            if (!$prev || !$be || $prev['days'] < 5 || $w['days'] < 5) {
                continue;
            }
            $before = $prev['spend'] / $prev['days'];
            $after = $w['spend'] / $w['days'];
            if ($before <= 0 || ($after - $before) / $before < self::SCALE_JUMP) {
                continue;
            }
            $gainBefore = self::gain($prev['spend'], $prev['purchases'], $be) / $prev['days'];
            $gainAfter = self::gain($w['spend'], $w['purchases'], $be) / $w['days'];
            $out[] = ['campaign' => $w['campaign'], 'week' => $w['week'], 'spend_before' => round($before, 2), 'spend_after' => round($after, 2),
                'cpa_before' => self::cpa($prev['spend'], $prev['purchases']), 'cpa_after' => self::cpa($w['spend'], $w['purchases']),
                'gain_before' => round($gainBefore, 2), 'gain_after' => round($gainAfter, 2), 'held' => $gainAfter >= $gainBefore];
        }
        usort($out, fn ($a, $b) => [$a['week'], $a['campaign']] <=> [$b['week'], $b['campaign']]);

        return $out;
    }

    /**
     * Los productos que estuvieron en los dos períodos (gastaron en Meta el 80 % de los días o más en cada uno), para comparar
     * sin los que entraron o salieron.
     *
     * @param array<int, int> $daysBefore producto => días con gasto antes
     * @param array<int, int> $daysAfter producto => días con gasto después
     * @return list<int>
     */
    public static function sameProducts(array $daysBefore, array $daysAfter, int $lenBefore, int $lenAfter, float $share = 0.8): array
    {
        $ids = [];
        foreach ($daysBefore as $id => $n) {
            if ($lenBefore > 0 && $lenAfter > 0 && $n / $lenBefore >= $share && ($daysAfter[$id] ?? 0) / $lenAfter >= $share) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Una misión repetida varios días seguidos es una sola: se agrupan las fechas de cada clave sin huecos de más de un día.
     *
     * @param array<string, list<string>> $dates clave => fechas en que salió
     * @return list<array{key: string, from: string, to: string, dates: list<string>}>
     */
    public static function streaks(array $dates): array
    {
        $out = [];
        foreach ($dates as $key => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $run = [];
            foreach ($list as $d) {
                if ($run && strtotime($d) - strtotime(end($run)) > 2 * 86400) {
                    $out[] = ['key' => $key, 'from' => $run[0], 'to' => end($run), 'dates' => $run];
                    $run = [];
                }
                $run[] = $d;
            }
            if ($run) {
                $out[] = ['key' => $key, 'from' => $run[0], 'to' => end($run), 'dates' => $run];
            }
        }
        usort($out, fn ($a, $b) => [$a['from'], $a['key']] <=> [$b['from'], $b['key']]);

        return $out;
    }

    /**
     * Si la IA tenía razón cuando no se le hizo caso, por lo que pasó en los 7 días siguientes:
     * - lo que pedía frenar (apagar, bajar, pausar): tenía razón si siguió perdiendo plata (CPA por encima del BE);
     * - lo que pedía subir: tenía razón si el CPA siguió en el C o debajo.
     * null si no hubo gasto para saberlo, o si es otra clase de misión (lanzar batches, gasto mínimo…): esas no se juzgan así.
     */
    public static function rightWhenIgnored(string $kind, float $spend, float $purchases, ?float $c, ?float $be): ?bool
    {
        if ($spend < self::OFF_SPEND || !$be || ($kind !== 'scale' && !in_array($kind, self::BRAKES, true))) {
            return null;
        }
        if ($kind === 'scale') {
            return $c ? $purchases > 0 && $spend / $purchases <= $c : null;
        }

        return $purchases <= 0 || $spend / $purchases > $be;
    }
}
