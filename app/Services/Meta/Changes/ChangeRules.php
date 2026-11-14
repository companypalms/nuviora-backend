<?php

namespace App\Services\Meta\Changes;

use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Decisions\Rules\AdRules;

/**
 * `.131` (dueño, 2026-11-10: "necesito que cada cambio de Meta Ads tenga una hipótesis que se guarda en un historial. De esta
 * manera podemos ver si la hipótesis se cumplió o no se cumplió con el cambio, y así el sistema de IA puede aprender
 * automáticamente de lo que funciona y no funciona"). PHP puro:
 * - qué cambió, leyendo las fotos que guarda la sincronización (MetaSnapshots): el presupuesto y el estado de cada campaña,
 *   los anuncios y ad sets que se apagaron, los mínimos que se quitaron y los ad sets nuevos (batches);
 * - la hipótesis de cada cambio, con textos fijos y los números del momento (la IA no la inventa);
 * - si se cumplió: los 3 días cerrados de antes contra hasta 3 días cerrados de después, cortando en el siguiente cambio
 *   grande de esa campaña. Un batch nuevo se cumple si queda prometedor o PROVEN en esa ciudad. Pausar no se mide.
 */
final class ChangeRules
{
    public const KINDS = [
        'raise' => 'Subir el presupuesto',
        'lower' => 'Bajar el presupuesto',
        'pause' => 'Pausar la ciudad',
        'resume' => 'Prender otra vez',
        'ads_off' => 'Apagar anuncios',
        'new_batch' => 'Batch nuevo',
        'min_off' => 'Quitar el gasto mínimo',
        // .140: del producto entero (no de una campaña); lo mide PriceChangeRules
        'price' => 'Cambiar el precio',
    ];

    /** Los cambios grandes (`.111`): cortan la medición de los demás de esa campaña. */
    public const BIG = ['raise', 'lower', 'pause', 'resume', 'new_batch'];

    public const RESULTS = ['measuring' => 'Midiendo', 'met' => 'Se cumplió', 'not_met' => 'No se cumplió', 'unknown' => 'No se puede saber', 'not_measured' => 'No se mide'];

    /** Las misiones del análisis (UrgentList) que piden cada cambio. */
    public const MISSIONS = [
        'raise' => ['scale'], 'lower' => ['decrease', 'step_down', 'undo_raise'], 'pause' => ['pause_city'], 'resume' => ['relaunch'],
        'ads_off' => ['stop_creatives', 'hold_prune', 'intervene'], 'new_batch' => ['few_batches', 'launch_ready', 'launch_test', 'relaunch', 'intervene'],
        'min_off' => ['min_spend'], 'price' => ['price_down'],
    ];

    /** Los días cerrados que se miran antes y después. */
    public const DAYS = 3;

    /** Cuántos días se espera el veredicto de un batch nuevo. */
    public const BATCH_DAYS = 14;

    /** Qué mide la prueba de cada pieza de un encargo (PieceRules::TESTS) frente al original. */
    public const PIECE_METRIC = [
        'hook' => 'hook_rate', 'fatigue' => 'hook_rate', 'winner_hook' => 'hook_rate',
        'after_hook' => 'hold_rate', 'middle' => 'hold_rate', 'close' => 'hold_rate', 'click' => 'unique_ctr',
    ];

    public const METRIC_LABELS = ['hook_rate' => 'Hook rate', 'hold_rate' => 'Hold rate', 'unique_ctr' => 'CTR único'];

    /**
     * Los cambios de cada campaña desde $since (fecha de Venezuela), con las fotos en orden. Cada foto: status, daily_budget,
     * min_spend y at ("Y-m-d H:i:s", hora de Venezuela).
     *
     * @param array<string, list<array>> $campaigns meta_id => fotos de la campaña
     * @param array<string, list<array>> $adsets meta_id => fotos del ad set
     * @param array<string, list<array>> $ads meta_id => fotos del anuncio
     * @param array<string, array{campaign: string, name: string, created: ?string}> $adsetInfo
     * @param array<string, array{campaign: string, adset: ?string, name: string}> $adInfo
     * @return list<array{fingerprint: string, kind: string, campaign: string, adset: ?string, date: string, at: string, detail: array}>
     */
    public static function detect(array $campaigns, array $adsets, array $ads, array $adsetInfo, array $adInfo, string $since): array
    {
        $out = [];
        $day = fn (string $at) => substr($at, 0, 10);
        foreach ($campaigns as $id => $rows) {
            $budget = [];
            $status = [];
            foreach (self::pairs($rows) as [$prev, $cur]) {
                $d = $day($cur['at']);
                if ($d < $since) {
                    continue;
                }
                if ($prev['daily_budget'] !== null && $cur['daily_budget'] !== null && abs((float) $cur['daily_budget'] - (float) $prev['daily_budget']) >= 0.01) {
                    $budget[$d] ??= ['from' => (float) $prev['daily_budget'], 'at' => $cur['at']];
                    $budget[$d]['to'] = (float) $cur['daily_budget'];
                }
                if ($prev['status'] !== null && $cur['status'] !== null && $prev['status'] !== $cur['status']) {
                    $status[$d] ??= ['from' => $prev['status'], 'at' => $cur['at']];
                    $status[$d]['to'] = $cur['status'];
                    $status[$d]['budget'] = $cur['daily_budget'] !== null ? (float) $cur['daily_budget'] : null;
                }
            }
            foreach ($budget as $d => $b) {
                if (abs($b['to'] - $b['from']) >= 0.01) {
                    $out[] = self::change($b['to'] > $b['from'] ? 'raise' : 'lower', (string) $id, null, $d, $b['at'], ['from' => $b['from'], 'to' => $b['to']]);
                }
            }
            foreach ($status as $d => $s) {
                $on = fn (string $v) => $v === 'ACTIVE';
                if ($on($s['from']) !== $on($s['to'])) {
                    $out[] = self::change($on($s['to']) ? 'resume' : 'pause', (string) $id, null, $d, $s['at'], ['budget' => $s['budget']]);
                }
            }
        }

        // Ad sets: el mínimo que se quitó y los que se apagaron (un batch entero)
        $off = [];
        $setsOff = [];
        foreach ($adsets as $id => $rows) {
            $info = $adsetInfo[(string) $id] ?? null;
            if (!$info) {
                continue;
            }
            foreach (self::pairs($rows) as [$prev, $cur]) {
                $d = $day($cur['at']);
                if ($d < $since) {
                    continue;
                }
                if ((float) ($prev['min_spend'] ?? 0) > 0 && (float) ($cur['min_spend'] ?? 0) <= 0) {
                    $out[] = self::change('min_off', $info['campaign'], (string) $id, $d, $cur['at'], ['adset' => $info['name'], 'min' => (float) $prev['min_spend']]);
                }
                if ($prev['status'] === 'ACTIVE' && $cur['status'] !== null && $cur['status'] !== 'ACTIVE') {
                    $off[$info['campaign']][$d] ??= ['at' => $cur['at'], 'ads' => [], 'adsets' => []];
                    $off[$info['campaign']][$d]['adsets'][] = $info['name'];
                    $setsOff[(string) $id][$d] = true;
                }
            }
        }
        // Anuncios apagados (los de un ad set que se apagó el mismo día van con su ad set)
        foreach ($ads as $id => $rows) {
            $info = $adInfo[(string) $id] ?? null;
            if (!$info) {
                continue;
            }
            foreach (self::pairs($rows) as [$prev, $cur]) {
                $d = $day($cur['at']);
                if ($d < $since || $prev['status'] !== 'ACTIVE' || $cur['status'] === null || $cur['status'] === 'ACTIVE' || isset($setsOff[(string) $info['adset']][$d])) {
                    continue;
                }
                $off[$info['campaign']][$d] ??= ['at' => $cur['at'], 'ads' => [], 'adsets' => []];
                $off[$info['campaign']][$d]['ads'][] = $info['name'];
                $off[$info['campaign']][$d]['at'] = min($off[$info['campaign']][$d]['at'], $cur['at']);
            }
        }
        foreach ($off as $campaign => $days) {
            foreach ($days as $d => $o) {
                $out[] = self::change('ads_off', (string) $campaign, null, $d, $o['at'], ['ads' => array_values(array_unique($o['ads'])), 'adsets' => array_values(array_unique($o['adsets']))]);
            }
        }
        // Ad sets nuevos: un batch que se lanzó (o se duplicó de otra ciudad)
        foreach ($adsetInfo as $id => $info) {
            $created = $info['created'] ?? null;
            if ($created !== null && $day($created) >= $since) {
                $out[] = self::change('new_batch', $info['campaign'], (string) $id, $day($created), $created, ['adset' => $info['name']]);
            }
        }
        usort($out, fn ($a, $b) => [$a['at'], $a['fingerprint']] <=> [$b['at'], $b['fingerprint']]);

        return $out;
    }

    /**
     * El siguiente cambio grande de la misma campaña: otro el mismo día o el primero de los días que siguen.
     *
     * @param list<array{fingerprint: string, kind: string, campaign: string, date: string}> $all
     * @return ?array{date: string, kind: string}
     */
    public static function nextBig(array $change, array $all): ?array
    {
        $next = null;
        foreach ($all as $o) {
            if ($o['campaign'] !== $change['campaign'] || $o['fingerprint'] === $change['fingerprint'] || !in_array($o['kind'], self::BIG, true) || $o['date'] < $change['date']) {
                continue;
            }
            if ($next === null || $o['date'] < $next['date']) {
                $next = ['date' => $o['date'], 'kind' => $o['kind']];
            }
        }

        return $next;
    }

    /**
     * Los números de una campaña en unos días: gasto, compras, CPA, y por día (dinero que deja = compras × BE − gasto).
     *
     * @param array<string, array{spend: float, purchases: float}> $daily fecha => números de la campaña
     */
    public static function numbers(array $daily, string $from, string $to, ?float $be): array
    {
        $spend = 0.0;
        $purchases = 0.0;
        $days = 0;
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
            $spend += (float) ($daily[$d]['spend'] ?? 0);
            $purchases += (float) ($daily[$d]['purchases'] ?? 0);
            $days++;
        }
        $days = max(1, $days);

        return ['from' => $from, 'to' => $to, 'days' => $days, 'spend' => round($spend, 2), 'purchases' => $purchases,
            'cpa' => $purchases > 0 ? round($spend / $purchases, 2) : null, 'per_day' => round($purchases / $days, 2),
            'gain_day' => $be !== null ? round(($purchases * $be - $spend) / $days, 2) : null];
    }

    /**
     * La hipótesis de un cambio (no de un batch nuevo) y cuándo se cumple, con los números de antes.
     *
     * @return array{hypothesis: string, criterion: string}
     */
    public static function hypothesis(string $kind, array $detail, array $before, ?float $c, ?float $be): array
    {
        $m = fn (?float $v) => AdRules::money($v);
        $pct = fn (float $from, float $to) => $from > 0 ? (int) round(abs($to - $from) / $from * 100) : 0;
        switch ($kind) {
            case 'raise':
                $h = "Si subo el presupuesto de {$m($detail['from'])} a {$m($detail['to'])} (+{$pct($detail['from'], $detail['to'])} %), ";

                return $be !== null
                    ? ['hypothesis' => $h . 'la ciudad deja más dinero por día que antes (' . ($before['gain_day'] < 0 ? 'perdía ' . $m(-$before['gain_day']) : 'dejaba ' . $m($before['gain_day']))
                        . ") sin pasar tu BE ({$m($be)}).",
                        'criterion' => 'Se cumple si en los días después deja más dinero por día que antes, sin pasar tu BE.']
                    : ['hypothesis' => $h . 'la ciudad vende más compras por día que antes (' . AdRules::num($before['per_day']) . ')' . ($c !== null ? " sin pasar tu C ({$m($c)})." : '.'),
                        'criterion' => 'Se cumple si en los días después vende más compras por día que antes' . ($c !== null ? ', en tu C o debajo.' : '.')];
            case 'lower':
                [$target, $name] = self::lowerTarget($before, $c, $be);

                return ['hypothesis' => "Si bajo el presupuesto de {$m($detail['from'])} a {$m($detail['to'])} (−{$pct($detail['from'], $detail['to'])} %), la ciudad vuelve a vender en tu {$name} o debajo ({$m($target)}).",
                    'criterion' => "Se cumple si en los días después vende en tu {$name} o debajo."];
            case 'pause':
                $loss = $before['gain_day'] !== null && $before['gain_day'] < 0 ? -$before['gain_day'] : null;

                return ['hypothesis' => $loss !== null ? "Si pauso la campaña, dejo de perder unos {$m($loss)} por día."
                        : 'Si pauso la campaña, dejo de gastar unos ' . $m(round($before['spend'] / $before['days'], 2)) . ' por día.',
                    'criterion' => 'No se mide: la campaña queda apagada y no hay con qué comparar.'];
            case 'resume':
                [$target, $name] = $be !== null ? [$be, 'BE'] : [$c, 'C'];

                return ['hypothesis' => "Si la prendo otra vez, vuelve a vender en tu {$name} o debajo ({$m($target)}).",
                    'criterion' => "Se cumple si en los días después vende en tu {$name} o debajo."];
            case 'ads_off':
                $names = CreativeCalls::join([...$detail['ads'] ?? [], ...array_map(fn ($s) => "el batch {$s}", $detail['adsets'] ?? [])]);

                return ['hypothesis' => "Si apago {$names}, " . ($before['cpa'] !== null ? "el CPA de la ciudad baja de {$m($before['cpa'])}." : 'la ciudad empieza a vender.'),
                    'criterion' => 'Se cumple si en los días después el CPA de la ciudad baja.'];
            case 'min_off':
                return ['hypothesis' => "Si quito el gasto mínimo de {$detail['adset']} ({$m($detail['min'])}), Meta pasa esa plata a los que más venden y "
                        . ($before['cpa'] !== null ? "el CPA de la ciudad no sube de {$m($before['cpa'])}." : 'la ciudad empieza a vender.'),
                    'criterion' => 'Se cumple si en los días después el CPA de la ciudad no sube.'];
        }

        return ['hypothesis' => '', 'criterion' => ''];
    }

    /**
     * Si se cumplió un cambio (no un batch nuevo): los días cerrados de después, hasta 3, cortando en el siguiente cambio grande.
     *
     * @param ?array{date: string, kind: string} $next el siguiente cambio grande de esa campaña (nextBig)
     * @return array{after: ?array, result: string, text: string}
     */
    public static function measure(string $kind, string $date, array $before, array $daily, ?float $c, ?float $be, ?array $next, string $today): array
    {
        $m = fn (?float $v) => AdRules::money($v);
        if ($kind === 'pause') {
            return ['after' => null, 'result' => 'not_measured', 'text' => 'No se mide: la campaña quedó apagada y no hay con qué comparar.'];
        }
        if ($next !== null && $next['date'] === $date) {
            return ['after' => null, 'result' => 'unknown', 'text' => 'No se puede saber: el mismo día hiciste otro cambio grande en esta ciudad (' . mb_strtolower(self::KINDS[$next['kind']]) . ').'];
        }
        $start = self::addDays($date, 1);
        $end = self::addDays($date, self::DAYS);
        if ($next !== null && $next['date'] <= $end) {
            if ($next['date'] === $start) {
                return ['after' => null, 'result' => 'unknown', 'text' => 'No se puede saber: al día siguiente hiciste otro cambio grande en esta ciudad ('
                    . mb_strtolower(self::KINDS[$next['kind']]) . '), y no quedó ni un día para medir.'];
            }
            $end = self::addDays($next['date'], -1);
        }
        $closed = self::addDays($today, -1);
        if ($closed < $end) {
            $left = self::diffDays($closed < $start ? self::addDays($start, -1) : $closed, $end);

            return ['after' => $closed >= $start ? self::numbers($daily, $start, $closed, $be) : null, 'result' => 'measuring',
                'text' => 'Midiendo: ' . ($left === 1 ? 'falta 1 día' : "faltan {$left} días") . ' (se mide hasta el ' . self::short($end) . ').'];
        }
        $after = self::numbers($daily, $start, $end, $be);
        $range = $after['days'] === 1 ? 'el ' . self::short($start) : 'del ' . self::short($start) . ' al ' . self::short($end);
        if ($c !== null && $after['spend'] < $c) {
            return ['after' => $after, 'result' => 'unknown', 'text' => "No se puede saber: {$range} gastó muy poco ({$m($after['spend'])}; hace falta al menos una C, {$m($c)})."];
        }
        $sells = fn (array $n) => $n['cpa'] !== null ? 'vende a ' . $m($n['cpa']) . ' por compra' : 'no vende';
        switch ($kind) {
            case 'raise':
                if ($be !== null) {
                    $ok = $after['gain_day'] > $before['gain_day'] && $after['gain_day'] >= 0;
                    $now = $after['gain_day'] >= 0 ? 'deja ' . $m($after['gain_day']) . ' por día' : 'pierde ' . $m(-$after['gain_day']) . ' por día';

                    return ['after' => $after, 'result' => $ok ? 'met' : 'not_met', 'text' => ($ok ? 'Se cumplió' : 'No se cumplió') . ": {$range} {$now} (antes "
                        . ($before['gain_day'] >= 0 ? 'dejaba ' . $m($before['gain_day']) : 'perdía ' . $m(-$before['gain_day'])) . ") y {$sells($after)}."];
                }
                if ($c === null) {
                    return ['after' => $after, 'result' => 'unknown', 'text' => 'No se puede saber: la campaña no tiene C ni BE.'];
                }
                $ok = $after['cpa'] !== null && $after['cpa'] <= $c && $after['per_day'] > $before['per_day'];

                return ['after' => $after, 'result' => $ok ? 'met' : 'not_met', 'text' => ($ok ? 'Se cumplió' : 'No se cumplió') . ": {$range} " . AdRules::num($after['per_day'])
                    . ' compras por día (antes ' . AdRules::num($before['per_day']) . ") y {$sells($after)}; tu C es {$m($c)}."];
            case 'lower':
            case 'resume':
                [$target, $name] = $kind === 'lower' ? self::lowerTarget($before, $c, $be) : ($be !== null ? [$be, 'BE'] : [$c, 'C']);
                if ($target === null) {
                    return ['after' => $after, 'result' => 'unknown', 'text' => 'No se puede saber: la campaña no tiene C ni BE.'];
                }
                $ok = $after['cpa'] !== null && $after['cpa'] <= $target;

                return ['after' => $after, 'result' => $ok ? 'met' : 'not_met', 'text' => ($ok ? 'Se cumplió' : 'No se cumplió') . ": {$range} {$sells($after)}"
                    . ($ok ? ", en tu {$name} o debajo ({$m($target)})." : ($after['cpa'] !== null ? ", encima de tu {$name} ({$m($target)})." : '.'))];
            case 'ads_off':
            case 'min_off':
                $ok = $after['cpa'] !== null && ($before['cpa'] === null || ($kind === 'ads_off' ? $after['cpa'] < $before['cpa'] : $after['cpa'] <= $before['cpa']));

                return ['after' => $after, 'result' => $ok ? 'met' : 'not_met', 'text' => ($ok ? 'Se cumplió' : 'No se cumplió') . ": {$range} la ciudad {$sells($after)}"
                    . ($before['cpa'] !== null ? ' (antes ' . $m($before['cpa']) . ').' : ' (antes no vendía).')];
        }

        return ['after' => $after, 'result' => 'unknown', 'text' => 'No se puede saber.'];
    }

    /**
     * Si se cumplió un batch nuevo: su veredicto en esa ciudad, y lo que quería mejorar frente al original.
     *
     * @param ?array{code: string, status: string, verdicts: list<array{city: ?string, key: string, label: string}>, hypothesis: ?string, metric: ?array{key: string, mine: ?float, original: ?float}} $batch
     * @return array{hypothesis: string, criterion: string, result: string, text: string}
     */
    public static function batch(?array $batch, string $adset, ?string $city, string $date, string $today): array
    {
        $where = $city ? " en {$city}" : '';
        if ($batch === null) {
            return ['hypothesis' => "Si lanzo {$adset}{$where}, queda prometedor o PROVEN.", 'criterion' => 'Se cumple si queda prometedor o PROVEN en esta ciudad.',
                'result' => 'not_measured', 'text' => 'No se mide: este ad set no es un batch de la cola (su nombre no empieza con B y su número).'];
        }
        $out = ['hypothesis' => $batch['hypothesis'] ?: "Si lanzo {$batch['code']}{$where}, queda prometedor o PROVEN.",
            'criterion' => 'Se cumple si queda prometedor o PROVEN en esta ciudad.'];
        $metric = '';
        if (($k = $batch['metric']['key'] ?? null) && $batch['metric']['mine'] !== null && $batch['metric']['original'] !== null) {
            $metric = ' ' . self::METRIC_LABELS[$k] . ': ' . AdRules::num($batch['metric']['mine']) . ' % (el original, ' . AdRules::num($batch['metric']['original']) . ' %)'
                . ($batch['metric']['mine'] > $batch['metric']['original'] ? ', mejoró.' : ', no mejoró.');
        }
        $v = collect($batch['verdicts'])->first(fn ($x) => $city !== null && ($x['city'] ?? null) === $city)
            ?? (count($batch['verdicts']) === 1 && ($batch['verdicts'][0]['city'] ?? null) === null ? $batch['verdicts'][0] : null);
        if ($batch['status'] === 'tested' && $v && in_array($v['key'], ['promising', 'proven'], true)) {
            return $out + ['result' => 'met', 'text' => "Se cumplió: {$v['label']}{$where}.{$metric}"];
        }
        if ($batch['status'] === 'tested' && $v && in_array($v['key'], ['loser', 'intermediate', 'failing'], true)) {
            return $out + ['result' => 'not_met', 'text' => "No se cumplió: {$v['label']}{$where}.{$metric}"];
        }
        if (self::diffDays($date, $today) >= self::BATCH_DAYS) {
            return $out + ['result' => 'unknown', 'text' => 'No se puede saber: en ' . self::BATCH_DAYS . " días no llegó a un veredicto{$where}.{$metric}"];
        }

        return $out + ['result' => 'measuring', 'text' => 'Midiendo: esperando su veredicto' . $where . ' (salió el ' . self::short($date) . ').'];
    }

    /** Lo que cambió, en una frase. */
    public static function what(string $kind, array $detail): string
    {
        $m = fn (?float $v) => AdRules::money($v);

        return match ($kind) {
            'raise', 'lower' => "De {$m($detail['from'])} a {$m($detail['to'])} por día (" . ($detail['to'] > $detail['from'] ? '+' : '−')
                . ($detail['from'] > 0 ? (int) round(abs($detail['to'] - $detail['from']) / $detail['from'] * 100) : 0) . ' %)',
            'pause' => 'Pausaste la campaña',
            'resume' => 'La prendiste otra vez' . (($detail['budget'] ?? null) ? ' con ' . $m($detail['budget']) . ' por día' : ''),
            'ads_off' => 'Apagaste ' . CreativeCalls::join([...$detail['ads'] ?? [], ...array_map(fn ($s) => "el batch {$s}", $detail['adsets'] ?? [])]),
            'new_batch' => "Lanzaste {$detail['adset']}",
            'min_off' => "Quitaste el mínimo de {$m($detail['min'])} de {$detail['adset']}",
            'price' => "El precio, de {$m($detail['from'])} a {$m($detail['to'])}",
            default => '',
        };
    }

    /** Bajar el presupuesto busca el BE si vendía encima del BE (o no vendía), y la C si estaba entre C y BE. @return array{0: ?float, 1: string} */
    private static function lowerTarget(array $before, ?float $c, ?float $be): array
    {
        if ($be !== null && ($before['cpa'] === null || $before['cpa'] > $be || $c === null)) {
            return [$be, 'BE'];
        }

        return [$c, 'C'];
    }

    private static function change(string $kind, string $campaign, ?string $adset, string $date, string $at, array $detail): array
    {
        return ['fingerprint' => "{$kind}:{$campaign}:" . ($adset ?? '') . ":{$date}", 'kind' => $kind, 'campaign' => $campaign, 'adset' => $adset,
            'date' => $date, 'at' => $at, 'detail' => $detail];
    }

    /** Cada foto con la anterior. @return list<array{0: array, 1: array}> */
    private static function pairs(array $rows): array
    {
        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $out[] = [$rows[$i - 1], $rows[$i]];
        }

        return $out;
    }

    public static function addDays(string $date, int $n): string
    {
        return (new \DateTimeImmutable($date))->modify(($n >= 0 ? '+' : '') . "{$n} days")->format('Y-m-d');
    }

    public static function diffDays(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    /** "13/11". */
    public static function short(string $date): string
    {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2);
    }
}
