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
                        'proposal' => $a['proposal'], 'from' => $a['from'] ?? null, 'to' => $a['to'] ?? null]
                        // .111: la espera de 24 horas después de un cambio grande (hasta cuándo y qué)
                        + (isset($a['cooldown']) ? ['cooldown' => $a['cooldown']] : [])
                        // .123: entre C y BE, qué se espera y hasta cuándo
                        + (isset($a['ladder']) ? ['ladder' => $a['ladder']] : [])
                        // .125: los 14 días sin dejar dinero (lo gastado, lo vendido y lo que se intentó)
                        + (isset($a['chronic']) ? ['chronic' => $a['chronic']] : []),
                    'notes' => array_map(fn ($n) => $n['label'] . ': ' . $n['text'], $c['notes'] ?? []),
                    'budget_notes' => $c['budget_notes'] ?? [],
                    'price_option' => $c['price_option']['text'] ?? null,
                    // .82: gasto y compras de 3 días, C, BE, presupuesto, días encima, qué podar y qué lanzar (para la frase de la tarea)
                    'numbers' => $c['numbers'] ?? null,
                ];
            }
            // .70: lo que dice la regla de cada ciudad, para "ESCALAR" (cuánto subir y dónde)
            $cityActions = [];
            foreach ($p['campaigns'] as $id => $c) {
                if ($c['action'] ?? null) {
                    $cityActions[(string) $id] = ['city' => $c['city'] ?? null, 'key' => $c['action']['key'], 'label' => $c['action']['label'],
                        'proposal' => $c['action']['proposal'] ?? null];
                }
            }
            $creatives = array_values(array_filter($p['creatives'] ?? [], fn ($cr) => $cr['active'] ?? false));
            // .80: qué apagar hoy, por ciudad (de todos los activos, no solo de los 12 que más gastaron)
            $stopByCity = [];
            $stopAll = [];
            foreach ($creatives as $cr) {
                foreach (CreativeCalls::stopIn($cr) as $city) {
                    $stopByCity[$city][] = (string) $cr['label'];
                }
                // .82: los perdedores (PARAR), que se apagan en todas las ciudades
                if (($cr['verdict']['key'] ?? null) === 'loser') {
                    $stopAll[] = (string) $cr['label'];
                }
            }
            usort($creatives, fn ($a, $b) => ($b['week']['spend'] ?? 0) <=> ($a['week']['spend'] ?? 0));
            // .126 (dueño, 2026-11-09: "la ganancia siempre va a pesar más"): entran los 12 que más gastaron, como siempre (así no
            // se esconde uno que gasta y pierde), y se ordenan por lo que dejaron en 7 días (el que no se puede calcular, al final)
            $creatives = array_slice($creatives, 0, self::MAX_CREATIVES);
            usort($creatives, fn ($a, $b) => [($b['gain']['week'] ?? null) !== null ? 1 : 0, $b['gain']['week'] ?? 0, $b['week']['spend'] ?? 0]
                <=> [($a['gain']['week'] ?? null) !== null ? 1 : 0, $a['gain']['week'] ?? 0, $a['week']['spend'] ?? 0]);
            $creatives = array_map(fn ($cr) => [
                'id' => $cr['id'], 'label' => $cr['label'], 'verdict' => $cr['verdict']['label'], 'verdict_key' => $cr['verdict']['key'],
                'tone' => $cr['verdict']['tone'], 'reading' => $cr['verdict']['reading'], 'proposal' => $cr['verdict']['proposal'],
                'cross' => $cr['cross']['label'] ?? null, 'funnel' => $cr['funnel']['label'] ?? null, 'week' => $cr['week'] ?? null,
                'cities' => array_map(fn ($c) => ['city' => $c['city'], 'verdict' => $c['label'], 'data' => $c['data']], $cr['cities'] ?? []),
                'video' => $cr['video'] ? ['window' => $cr['video']['window'], 'diagnosis' => $cr['video']['diagnosis'], 'iterate' => $cr['video']['iterate'],
                    'stages' => $cr['video']['stages'] ?? [], 'watch_pct' => $cr['video']['watch_pct'] ?? null] : null,
                'status' => $cr['status'] ?? null,
                // .70: ESCALAR, MANTENER, PARAR o ITERAR, sus números (para el dueño) y qué hacer el editor (en palabras)
                'type' => $cr['type'] ?? null,
                'call' => CreativeCalls::call($cr, $cityActions),
                'numbers' => CreativeCalls::numbers($cr),
                // .116: si su ángulo o sus iteraciones descansan, no se le piden más versiones
                'rest' => $cr['rest'] ?? null,
                // .126: lo que dejó en 7 días y en toda su vida
                'gain' => $cr['gain'] ?? null,
            ], $creatives);
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
                // .116: los ángulos en descanso y cuál sigue
                'rests' => $plan['rests'] ?? [], 'next_angle' => $plan['next_angle'] ?? null,
                // .117: se quedó sin ninguna ciudad prendida
                'no_city' => $plan['no_city'] ?? null,
                // .125: el producto entero lleva 14 días sin dejar dinero: lo decide el dueño
                'drop' => $plan['drop'] ?? null,
                // .137: las campañas prendidas en una ciudad sin unidades
                'stock_out' => $plan['stock_out'] ?? [],
                // .126: lo que dejó el producto en 3 y en 7 días
                'gain' => $plan['gain'] ?? null,
                'cities' => $cities, 'creatives' => $creatives, 'batches' => $batches, 'stop_by_city' => $stopByCity, 'stop_all' => $stopAll,
                // .119: los ganadores ya apagados siguen en la fila de iteraciones (no salen en la lista del análisis)
                'off_winners' => array_values(array_map(fn ($cr) => ['id' => $cr['id'], 'label' => $cr['label'], 'verdict_key' => $cr['verdict']['key'], 'off_winner' => true,
                    'type' => $cr['type'] ?? null, 'video' => $cr['video'] ? ['iterate' => $cr['video']['iterate']] : null, 'call' => CreativeCalls::afterOff($cr)],
                    array_filter($p['creatives'] ?? [], fn ($cr) => !empty($cr['off_winner']) && empty($cr['rest'])))),
            ];
        }
        // .126: primero el que más deja en 7 días (antes, el más urgente); el que no se puede calcular, al final, por urgencia
        usort($out, fn ($a, $b) => [($a['gain'] ?? null) === null ? 1 : 0, -($a['gain']['d7'] ?? 0), ($a['urgency']['level'] ?? 9), -$a['money_at_risk'], $a['product']]
            <=> [($b['gain'] ?? null) === null ? 1 : 0, -($b['gain']['d7'] ?? 0), ($b['urgency']['level'] ?? 9), -$b['money_at_risk'], $b['product']]);

        return $out;
    }

    /**
     * `.56`: lo que dice el video de cada creativo que lo tiene transcrito (solo los ganadores, `.55`). PHP puro.
     *
     * @param array<int, array{hook: ?string, text: ?string}> $scripts por id de creativo (Videos\Scripts::forCreatives)
     */
    public static function withScripts(array $facts, array $scripts): array
    {
        foreach ($facts as &$p) {
            foreach ($p['creatives'] as &$cr) {
                $cr['script'] = $scripts[$cr['id']] ?? null;
            }
            unset($cr);
        }
        unset($p);

        return $facts;
    }

    /** Lo que recibe la IA: un bloque por producto, en texto. */
    public static function input(array $facts): string
    {
        $lines = [];
        foreach ($facts as $p) {
            $u = $p['urgency'];
            $lines[] = "== PRODUCTO: {$p['product']}" . ($p['mode'] !== 'normal' ? " (modo {$p['mode']})" : '');
            if ($u) {
                $lines[] = "Urgencia: {$u['label']} · batches a pedir: {$u['batches']}" . (($u['bank'] ?? []) ? ' (ya hay en la banca: ' . implode(', ', $u['bank']) . ')' : '')
                    . ($u['reasons'] ? ' · ' . implode('; ', $u['reasons']) : '');
            }
            foreach ($p['cities'] as $c) {
                $a = $c['action'];
                $lines[] = "Ciudad {$c['city']}: {$a['label']} · {$a['data']} · {$a['reading']}" . ($a['proposal'] ? " → {$a['proposal']}" : '');
                foreach (array_merge($c['notes'], $c['budget_notes'], array_filter([$c['price_option']])) as $n) {
                    $lines[] = "  · {$n}";
                }
                // .132: lo que pasó la última vez que se hizo cada cambio en esta ciudad (el historial de cambios)
                foreach ($c['lessons'] ?? [] as $l) {
                    $lines[] = "  · historial: {$l}";
                }
            }
            if (!empty($p['changes'])) {
                $lines[] = "Tus cambios en Meta (60 días): {$p['changes']}";
            }
            foreach ($p['stop_by_city'] ?? [] as $city => $labels) {
                $lines[] = "Apagar hoy en {$city} (ahí pierden): " . implode(', ', $labels);
            }
            foreach ($p['creatives'] as $i => $cr) {
                $week = $cr['week'] ? ' · 7 días: gasto ' . self::money($cr['week']['spend']) . ', ' . rtrim(rtrim(number_format((float) $cr['week']['purchases'], 1, ',', ''), '0'), ',')
                    . ' compras' . ($cr['week']['cpa'] !== null ? ', CPA ' . self::money($cr['week']['cpa']) : '') : '';
                $lines[] = 'Creativo #' . ($i + 1) . " {$cr['label']}: {$cr['verdict']}" . ($cr['cross'] ? " ({$cr['cross']})" : '') . ($cr['funnel'] ? " · {$cr['funnel']}" : '')
                    . $week . " · {$cr['reading']}" . ($cr['proposal'] ? " → {$cr['proposal']}" : '');
                if (!empty($cr['call'])) {
                    $lines[] = "  · decisión: {$cr['call']['label']} — {$cr['call']['todo']}";
                    foreach ($cr['numbers'] ?? [] as $n) {
                        $lines[] = "  · números: {$n}";
                    }
                    if ($cr['call']['editor']) {
                        $lines[] = "  · para el editor (en palabras): {$cr['call']['editor']}";
                    }
                }
                foreach ($cr['video']['diagnosis'] ?? [] as $dx) {
                    $lines[] = "  · video: {$dx}";
                }
                if (!empty($cr['script'])) {
                    $lines[] = '  · lo que dice su video: ' . \App\Services\Meta\Videos\Scripts::line($cr['script']);
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
