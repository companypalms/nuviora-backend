<?php

namespace App\Services\Meta\Analysis;

/**
 * Los hechos del análisis de la mañana (Etapa 5, paso 3a), en PHP puro: lo que dijeron las reglas del dueño (Paso 2) de
 * cada producto, resumido para la página y para la IA. Solo lo activo y lo que pide algo: el detalle completo sigue en
 * Meta Ads.
 */
final class AnalysisFacts
{
    /** Cuántos creativos de cada producto van al análisis (los que más gastaron en 7 días). */
    public const MAX_CREATIVES = 12;

    /**
     * @param array<int, array> $decisions MetaDecisions::compute
     * @param array{campaigns?: array<string, string>, adsets?: array<string, string>} $names meta_id => nombre
     * @return list<array>
     */
    public static function build(array $decisions, array $names = []): array
    {
        $out = [];
        foreach ($decisions as $pid => $p) {
            $plan = $p['plan'] ?? null;
            $cities = [];
            foreach ($p['campaigns'] as $id => $c) {
                if (!($c['action'] ?? null)) {
                    continue;
                }
                $a = $c['action'];
                $cities[] = [
                    'campaign_meta_id' => (string) $id, 'campaign' => $names['campaigns'][$id] ?? null, 'city' => $c['city'] ?? null,
                    'action' => ['key' => $a['key'], 'label' => $a['label'], 'tone' => $a['tone'], 'data' => $a['data'], 'reading' => $a['reading'],
                        'proposal' => $a['proposal'], 'from' => $a['from'] ?? null, 'to' => $a['to'] ?? null],
                    'notes' => array_map(fn ($n) => $n['label'] . ': ' . $n['text'], $c['notes'] ?? []),
                    'budget_notes' => $c['budget_notes'] ?? [],
                    'price_option' => $c['price_option']['text'] ?? null,
                ];
            }
            $creatives = array_values(array_filter($p['creatives'] ?? [], fn ($cr) => $cr['active'] ?? false));
            usort($creatives, fn ($a, $b) => ($b['week']['spend'] ?? 0) <=> ($a['week']['spend'] ?? 0));
            $creatives = array_map(fn ($cr) => [
                'id' => $cr['id'], 'label' => $cr['label'], 'verdict' => $cr['verdict']['label'], 'verdict_key' => $cr['verdict']['key'],
                'tone' => $cr['verdict']['tone'], 'reading' => $cr['verdict']['reading'], 'proposal' => $cr['verdict']['proposal'],
                'cross' => $cr['cross']['label'] ?? null, 'funnel' => $cr['funnel']['label'] ?? null, 'week' => $cr['week'] ?? null,
                'cities' => array_map(fn ($c) => ['city' => $c['city'], 'verdict' => $c['label'], 'data' => $c['data']], $cr['cities'] ?? []),
                'video' => $cr['video'] ? ['window' => $cr['video']['window'], 'diagnosis' => $cr['video']['diagnosis'], 'iterate' => $cr['video']['iterate']] : null,
                'status' => $cr['status'] ?? null,
            ], array_slice($creatives, 0, self::MAX_CREATIVES));
            $batches = [];
            foreach ($p['adsets'] ?? [] as $id => $v) {
                if (in_array($v['key'], ['loser', 'failing'], true) && isset($names['adsets'][$id])) {
                    $batches[] = ['name' => $names['adsets'][$id], 'verdict' => $v['label'], 'reading' => $v['reading'], 'proposal' => $v['proposal']];
                }
            }
            $out[] = [
                'product_id' => (int) $pid, 'product' => $p['product']['name'], 'mode' => $p['product']['mode'],
                'urgency' => $plan['urgency'] ?? null, 'money_at_risk' => $plan['money_at_risk'] ?? 0,
                'next_work' => $plan['next_work'] ?? [], 'price' => $plan['price'] ?? [], 'price_watch' => $plan['price_watch'] ?? null,
                'alarms' => $plan['alarms'] ?? [], 'tracking' => $p['tracking'] ?? null,
                'cities' => $cities, 'creatives' => $creatives, 'batches' => $batches,
            ];
        }
        usort($out, fn ($a, $b) => [($a['urgency']['level'] ?? 9), -$a['money_at_risk'], $a['product']] <=> [($b['urgency']['level'] ?? 9), -$b['money_at_risk'], $b['product']]);

        return $out;
    }

    /** Lo que recibe la IA: un bloque por producto, en texto. */
    public static function input(array $facts): string
    {
        $lines = [];
        foreach ($facts as $p) {
            $u = $p['urgency'];
            $lines[] = "== PRODUCTO: {$p['product']}" . ($p['mode'] !== 'normal' ? " (modo {$p['mode']})" : '');
            if ($u) {
                $lines[] = "Urgencia: {$u['label']} · batches a pedir: {$u['batches']}" . ($u['reasons'] ? ' · ' . implode('; ', $u['reasons']) : '');
            }
            foreach ($p['cities'] as $c) {
                $a = $c['action'];
                $lines[] = "Ciudad {$c['city']}: {$a['label']} · {$a['data']} · {$a['reading']}" . ($a['proposal'] ? " → {$a['proposal']}" : '');
                foreach (array_merge($c['notes'], $c['budget_notes'], array_filter([$c['price_option']])) as $n) {
                    $lines[] = "  · {$n}";
                }
            }
            foreach ($p['creatives'] as $i => $cr) {
                $week = $cr['week'] ? ' · 7 días: gasto ' . self::money($cr['week']['spend']) . ', ' . rtrim(rtrim(number_format((float) $cr['week']['purchases'], 1, ',', ''), '0'), ',')
                    . ' compras' . ($cr['week']['cpa'] !== null ? ', CPA ' . self::money($cr['week']['cpa']) : '') : '';
                $lines[] = 'Creativo #' . ($i + 1) . " {$cr['label']}: {$cr['verdict']}" . ($cr['cross'] ? " ({$cr['cross']})" : '') . ($cr['funnel'] ? " · {$cr['funnel']}" : '')
                    . $week . " · {$cr['reading']}" . ($cr['proposal'] ? " → {$cr['proposal']}" : '');
                foreach ($cr['video']['diagnosis'] ?? [] as $dx) {
                    $lines[] = "  · video: {$dx}";
                }
            }
            foreach ($p['batches'] as $b) {
                $lines[] = "Batch {$b['name']}: {$b['verdict']} · {$b['reading']}" . ($b['proposal'] ? " → {$b['proposal']}" : '');
            }
            foreach ($p['next_work'] as $t) {
                $lines[] = "Trabajo siguiente: {$t}";
            }
            foreach (array_merge($p['price'], array_filter([$p['price_watch']])) as $t) {
                $lines[] = "Precio: {$t}";
            }
            foreach ($p['alarms'] as $t) {
                $lines[] = "Alarma: {$t}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public static function money(?float $v): string
    {
        return $v === null ? 'N/A' : '$' . number_format($v, 2, ',', '.');
    }
}
