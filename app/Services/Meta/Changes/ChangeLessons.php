<?php

namespace App\Services\Meta\Changes;

use App\Services\Meta\Analysis\UrgentList;

/**
 * `.132` (dueño, 2026-11-10: "y así el sistema de IA puede aprender automáticamente de lo que funciona y no funciona"): lo
 * que deja el historial de cambios (`.131`) para el análisis de la mañana. PHP puro.
 * - Cada misión de una ciudad trae "la última vez" que se hizo lo mismo ahí y si se cumplió.
 * - Si el mismo cambio no se cumplió las últimas 3 veces en una ciudad, un aviso (solo aviso: las reglas siguen igual).
 * - La IA recibe, por ciudad, la última vez de cada cambio y, por producto, cuántas veces se cumplió cada tipo.
 * Las reglas del dueño nunca cambian solas: la IA aprende, lo explica y avisa; él decide.
 */
final class ChangeLessons
{
    /** Cuántos días de historial se miran. */
    public const DAYS = 60;

    /** Cuántas veces seguidas sin cumplirse para avisar, y hasta cuántos días después del último cambio. */
    public const STREAK = 3;

    public const NOTICE_DAYS = 30;

    /** El cambio que pide cada misión del análisis (UrgentList). */
    public const OF_MISSION = [
        'scale' => 'raise', 'decrease' => 'lower', 'step_down' => 'lower', 'pause_city' => 'pause', 'relaunch' => 'resume',
        'stop_creatives' => 'ads_off', 'hold_prune' => 'ads_off', 'min_spend' => 'min_off', 'few_batches' => 'new_batch', 'launch_ready' => 'new_batch',
    ];

    public const VERBS = ['raise' => 'subiste el presupuesto', 'lower' => 'bajaste el presupuesto', 'pause' => 'pausaste la campaña', 'resume' => 'la prendiste otra vez',
        'ads_off' => 'apagaste anuncios', 'min_off' => 'quitaste un gasto mínimo', 'new_batch' => 'lanzaste un batch'];

    public const NOUNS = ['raise' => 'subidas de presupuesto', 'lower' => 'bajadas de presupuesto', 'resume' => 'veces que la prendiste otra vez',
        'ads_off' => 'veces que apagaste anuncios', 'min_off' => 'veces que quitaste un gasto mínimo', 'new_batch' => 'batches nuevos'];

    /**
     * Lo medido, por campaña y tipo (la última vez y cuántas seguidas no se cumplieron) y por producto (cuántas se cumplieron).
     *
     * @param list<array{kind: string, campaign: string, city: ?string, product_id: int, date: string, what: string, result: string, result_text: ?string}> $rows
     *        solo "se cumplió" y "no se cumplió", del más nuevo al más viejo
     * @return array{campaigns: array<string, array<string, array{last: array, streak: int, city: ?string, product_id: int}>>, products: array<int, array<string, array{met: int, not_met: int}>>}
     */
    public static function build(array $rows): array
    {
        $out = ['campaigns' => [], 'products' => []];
        foreach ($rows as $r) {
            $k = $r['kind'];
            $c = &$out['campaigns'][$r['campaign']][$k];
            if ($c === null) {
                $c = ['last' => $r, 'streak' => 0, 'open' => true, 'city' => $r['city'], 'product_id' => (int) $r['product_id']];
            }
            if ($c['open'] && $r['result'] === 'not_met') {
                $c['streak']++;
            } else {
                $c['open'] = false;
            }
            unset($c);
            $p = &$out['products'][(int) $r['product_id']][$k];
            $p ??= ['met' => 0, 'not_met' => 0];
            $p[$r['result'] === 'met' ? 'met' : 'not_met']++;
            unset($p);
        }
        foreach ($out['campaigns'] as $id => $kinds) {
            foreach ($kinds as $k => $c) {
                unset($out['campaigns'][$id][$k]['open']);
            }
        }

        return $out;
    }

    /** "La última vez (04/11): de $30,00 a $36,00 por día (+20 %). Se cumplió: …". */
    public static function line(array $last): string
    {
        return 'La última vez (' . ChangeRules::short($last['date']) . '): ' . self::lower($last['what']) . '. ' . ($last['result_text'] ?? ChangeRules::RESULTS[$last['result']]);
    }

    /** "Ojo: las últimas 3 veces que subiste el presupuesto aquí no se cumplió." */
    public static function warning(string $kind, int $streak): ?string
    {
        return $streak >= self::STREAK ? "Ojo: las últimas {$streak} veces que " . self::VERBS[$kind] . ' aquí no se cumplió.' : null;
    }

    /**
     * A cada misión de una ciudad: lo que pasó la última vez que se hizo lo mismo ahí.
     *
     * @param list<array> $facts los productos del análisis (AnalysisFacts), para saber la campaña de cada ciudad
     */
    public static function missions(array $urgent, array $facts, array $lessons): array
    {
        $campaignOf = [];
        foreach ($facts as $p) {
            foreach ($p['cities'] ?? [] as $c) {
                $campaignOf[$p['product_id'] . '|' . ($c['city'] ?? '')] ??= (string) ($c['campaign_meta_id'] ?? '');
            }
        }
        foreach ($urgent as $i => $item) {
            $kind = self::OF_MISSION[$item['kind'] ?? ''] ?? null;
            if ($kind === null) {
                continue;
            }
            $parts = explode(':', (string) ($item['key'] ?? ''));
            $campaign = isset($parts[1], $lessons['campaigns'][$parts[1]]) ? $parts[1] : ($campaignOf[($item['product_id'] ?? '') . '|' . ($item['city'] ?? '')] ?? null);
            $l = $campaign !== null ? ($lessons['campaigns'][$campaign][$kind] ?? null) : null;
            if ($l === null) {
                continue;
            }
            $urgent[$i]['last'] = ['text' => self::line($l['last']), 'result' => $l['last']['result'], 'warn' => self::warning($kind, $l['streak']),
                'kind' => $kind, 'city' => $l['city'], 'product_id' => $l['product_id']];
        }

        return $urgent;
    }

    /**
     * Los avisos: el mismo cambio que no se cumplió las últimas 3 veces en una ciudad, si el último fue hace 30 días o menos.
     *
     * @param array<int, string> $products los productos del análisis (id => nombre)
     */
    public static function notices(array $lessons, array $products, string $today): array
    {
        $out = [];
        foreach ($lessons['campaigns'] as $campaign => $kinds) {
            foreach ($kinds as $kind => $l) {
                $name = $products[$l['product_id']] ?? null;
                if ($l['streak'] < self::STREAK || $name === null || $l['last']['date'] < ChangeRules::addDays($today, -self::NOTICE_DAYS)) {
                    continue;
                }
                $where = $l['city'] ? " en {$l['city']}" : '';
                $title = "Las últimas {$l['streak']} " . self::NOUNS[$kind] . "{$where} no se cumplieron";
                $out[] = ['key' => "{$l['product_id']}:{$campaign}:lesson:{$kind}", 'product_id' => $l['product_id'], 'product' => $name, 'urgency' => null,
                    'city' => $l['city'], 'kind' => 'lesson', 'label' => 'Lo que aprendimos', 'tone' => 'warning', 'text' => "{$title}.",
                    'where' => UrgentList::short($name) . ($l['city'] ? " · {$l['city']}" : ''), 'title' => $title, 'steps' => [], 'batches' => [],
                    'why' => 'Es solo un aviso: tus reglas siguen igual',
                    'detail' => [self::line($l['last']), 'El sistema no cambia sus decisiones por esto: tú decides si cambias algo. Todos los cambios, en Meta Ads › Historial de cambios.'],
                    'money' => null, 'severity' => UrgentList::SEVERITY['lesson'], 'group' => UrgentList::GROUP['lesson'],
                    'last' => ['text' => self::line($l['last']), 'result' => 'not_met', 'warn' => null, 'kind' => $kind, 'city' => $l['city'], 'product_id' => $l['product_id']]];
            }
        }

        return $out;
    }

    /** Para la IA: en cada ciudad, la última vez de cada cambio; en cada producto, cuántas veces se cumplió cada tipo. */
    public static function intoFacts(array $facts, array $lessons): array
    {
        foreach ($facts as $i => $p) {
            foreach ($p['cities'] ?? [] as $j => $c) {
                $lines = [];
                foreach ($lessons['campaigns'][(string) ($c['campaign_meta_id'] ?? '')] ?? [] as $kind => $l) {
                    $lines[] = ChangeRules::KINDS[$kind] . ': ' . self::line($l['last']) . (($w = self::warning($kind, $l['streak'])) ? " {$w}" : '');
                }
                $facts[$i]['cities'][$j]['lessons'] = $lines;
            }
            $facts[$i]['changes'] = self::summary($lessons['products'][(int) $p['product_id']] ?? []);
        }

        return $facts;
    }

    /** "Subir el presupuesto: se cumplió 3 de 4; Apagar anuncios: se cumplió 1 de 2". */
    public static function summary(array $kinds): ?string
    {
        $parts = [];
        foreach (ChangeRules::KINDS as $kind => $label) {
            if ($n = $kinds[$kind] ?? null) {
                $parts[] = "{$label}: se cumplió {$n['met']} de " . ($n['met'] + $n['not_met']);
            }
        }

        return $parts ? implode('; ', $parts) : null;
    }

    private static function lower(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
