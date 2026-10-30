<?php

namespace App\Services\Meta\Analysis;

/**
 * La lista de lo más urgente a lo menos urgente del análisis de la mañana (Etapa 5, paso 3a), en PHP puro. La arman las
 * reglas, no la IA: así el orden y los montos son siempre los del dueño. Como decidió él (2026-10-30), primero lo que está
 * en el break-even o encima y todavía se puede salvar, después los ganadores que se pueden escalar y después el resto;
 * dentro de cada grupo, la urgencia del producto (U1 a U5) y el dinero en riesgo. "Mantener" y "Esperar" no van: quedan
 * en el detalle de cada producto.
 */
final class UrgentList
{
    /** Qué tan urgente es cada cosa (menos es más urgente). */
    public const SEVERITY = [
        'drop' => 1, 'pause_city' => 1, 'not_sold' => 1, 'stock_out' => 1,
        'decrease' => 2, 'intervene' => 2, 'last_test' => 2, 'tracking' => 2,
        'hold_prune' => 3,
        'scale' => 4,
        'alarm' => 5,
        'price' => 6,
    ];

    /**
     * @param list<array> $facts AnalysisFacts::build
     * @return list<array{key: string, product_id: int, product: string, city: ?string, kind: string, label: string, text: string, tone: string, urgency: ?string, severity: int}>
     */
    public static function build(array $facts): array
    {
        $items = [];
        foreach ($facts as $p) {
            $base = ['product_id' => $p['product_id'], 'product' => $p['product'], 'urgency' => $p['urgency']['label'] ?? null,
                '_level' => $p['urgency']['level'] ?? 9, '_risk' => (float) ($p['money_at_risk'] ?? 0)];
            foreach ($p['cities'] as $c) {
                $a = $c['action'];
                $kind = $a['key'] === 'none' && $a['label'] === 'No se vende aquí' ? 'not_sold' : $a['key'];
                if (!isset(self::SEVERITY[$kind])) {
                    continue; // mantener, esperar, nada: van en el detalle
                }
                $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:{$kind}", 'city' => $c['city'], 'kind' => $kind,
                    'label' => $a['label'], 'text' => trim($a['reading'] . ' ' . ($a['proposal'] ?? '')), 'tone' => $a['tone']];
                if ($c['price_option']) {
                    $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:price", 'city' => $c['city'], 'kind' => 'price',
                        'label' => 'Precio', 'text' => $c['price_option'], 'tone' => 'info'];
                }
            }
            foreach ($p['alarms'] as $t) {
                $kind = str_starts_with($t, 'Tracking') ? 'tracking' : (str_contains($t, 'sigue activa') ? 'stock_out' : 'alarm');
                $items[] = $base + ['key' => "{$p['product_id']}:alarm:" . substr(md5($t), 0, 10), 'city' => null, 'kind' => $kind,
                    'label' => $kind === 'tracking' ? 'Tracking' : 'Stock', 'text' => $t, 'tone' => $kind === 'alarm' ? 'warning' : 'error'];
            }
            if ($p['price_watch']) {
                $items[] = $base + ['key' => "{$p['product_id']}:price_watch", 'city' => null, 'kind' => 'price', 'label' => 'Precio',
                    'text' => $p['price_watch'], 'tone' => str_contains($p['price_watch'], 'volver') ? 'warning' : 'info'];
            }
        }
        foreach ($items as &$i) {
            $i['severity'] = self::SEVERITY[$i['kind']];
        }
        unset($i);
        usort($items, fn ($a, $b) => [$a['severity'], $a['_level'], -$a['_risk'], $a['product'], (string) $a['city']]
            <=> [$b['severity'], $b['_level'], -$b['_risk'], $b['product'], (string) $b['city']]);

        return array_map(fn ($i) => array_diff_key($i, ['_level' => 1, '_risk' => 1]), $items);
    }
}
