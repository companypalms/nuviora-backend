<?php

namespace App\Services\Meta\Analysis;

use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\Decisions\Rules\CampaignRules;

/**
 * La lista de lo más urgente a lo menos urgente del análisis de la mañana (Etapa 5, paso 3a), en PHP puro. La arman las
 * reglas, no la IA: así el orden y los montos son siempre los del dueño. Como decidió él (2026-10-30), primero lo que está
 * en el break-even o encima y todavía se puede salvar, después los ganadores que se pueden escalar y después el resto;
 * dentro de cada grupo, la urgencia del producto (U1 a U5) y el dinero en riesgo. "Mantener" y "Esperar" no van: quedan
 * en el detalle de cada producto.
 *
 * `.82` (dueño, 2026-11-02: "mucho más intuitivo… que cada cosa tenga una lógica y un porqué"): cada punto es una sola
 * cosa que hacer en Ads Manager, en una frase con los montos exactos (`title`), su porqué en una línea (`why`), el detalle
 * para "¿Por qué?" (`detail`) y su grupo en la pantalla (`group`: frenar pérdidas, arreglar, crecer o un aviso, que no es
 * de Ads Manager). Suma los creativos que hay que apagar: los perdedores (en todas las ciudades) y los que pierden en una
 * ciudad (ahí).
 */
final class UrgentList
{
    /** Qué tan urgente es cada cosa (menos es más urgente). */
    public const SEVERITY = [
        'drop' => 1, 'pause_city' => 1, 'not_sold' => 1, 'stock_out' => 1, 'stop_creatives' => 1,
        'decrease' => 2, 'intervene' => 2, 'last_test' => 2, 'tracking' => 2,
        'hold_prune' => 3,
        'scale' => 4,
        'alarm' => 5,
        'price' => 6,
    ];

    /** `.82`: en qué grupo de la pantalla va cada cosa. */
    public const GROUP = [
        'drop' => 'frenar', 'pause_city' => 'frenar', 'not_sold' => 'frenar', 'stop_creatives' => 'frenar', 'decrease' => 'frenar',
        'intervene' => 'arreglar', 'last_test' => 'arreglar', 'tracking' => 'arreglar', 'hold_prune' => 'arreglar',
        'scale' => 'crecer',
        'stock_out' => 'aviso', 'alarm' => 'aviso', 'price' => 'aviso',
    ];

    /** Cuántos nombres de creativos caben en la línea del porqué. */
    private const LABELS_IN_WHY = 4;

    /**
     * @param list<array> $facts AnalysisFacts::build
     * @return list<array{key: string, product_id: int, product: string, city: ?string, kind: string, label: string, text: string, tone: string, urgency: ?string, severity: int, group: string, title: string, why: ?string, detail: list<string>}>
     */
    public static function build(array $facts): array
    {
        $items = [];
        foreach ($facts as $p) {
            $base = ['product_id' => $p['product_id'], 'product' => $p['product'], 'urgency' => $p['urgency']['label'] ?? null,
                '_level' => $p['urgency']['level'] ?? 9, '_risk' => (float) ($p['money_at_risk'] ?? 0)];
            $name = $p['product'];
            $kinds = [];
            foreach ($p['cities'] as $c) {
                $kinds[(string) $c['city']] = self::kind($c['action']);
            }

            // .82: qué creativos apagar. Los perdedores, en todas las ciudades; los que pierden en una ciudad, ahí (salvo
            // que esa ciudad se pause entera)
            $stopAll = $p['stop_all'] ?? [];
            $stopCity = [];
            foreach ($p['stop_by_city'] ?? [] as $city => $labels) {
                $labels = array_values(array_diff($labels, $stopAll));
                if ($labels && !in_array($kinds[(string) $city] ?? null, ['pause_city', 'not_sold', 'drop'], true)) {
                    $stopCity[(string) $city] = $labels;
                }
            }
            if ($stopAll) {
                $items[] = $base + self::stop($p, null, $stopAll);
            }
            foreach ($stopCity as $city => $labels) {
                $items[] = $base + self::stop($p, $city, $labels);
            }

            foreach ($p['cities'] as $c) {
                $a = $c['action'];
                $kind = self::kind($a);
                if (!isset(self::SEVERITY[$kind])) {
                    continue; // mantener, esperar, nada: van en el detalle
                }
                $n = $c['numbers'] ?? null;
                $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:{$kind}", 'city' => $c['city'], 'kind' => $kind,
                    'label' => $a['label'], 'text' => trim($a['reading'] . ' ' . ($a['proposal'] ?? '')), 'tone' => $a['tone'],
                    'title' => self::title($kind, $name, $c['city'], $a, $n, isset($stopCity[(string) $c['city']]), $p['mode'] ?? 'normal'),
                    'why' => self::why($kind, $a, $n),
                    'detail' => array_values(array_filter([...self::numbers($n), $a['reading'], $a['proposal'], ...$c['notes'], ...$c['budget_notes']]))];
                if ($c['price_option']) {
                    $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:price", 'city' => $c['city'], 'kind' => 'price',
                        'label' => 'Precio', 'text' => $c['price_option'], 'tone' => 'info',
                        'title' => ($c['city'] ? "{$c['city']}: " : '') . $c['price_option'], 'why' => null, 'detail' => []];
                }
            }
            foreach ($p['alarms'] as $t) {
                $kind = str_starts_with($t, 'Tracking') ? 'tracking' : (str_contains($t, 'sigue activa') ? 'stock_out' : 'alarm');
                $items[] = $base + ['key' => "{$p['product_id']}:alarm:" . substr(md5($t), 0, 10), 'city' => null, 'kind' => $kind,
                    'label' => $kind === 'tracking' ? 'Tracking' : 'Stock', 'text' => $t, 'tone' => $kind === 'alarm' ? 'warning' : 'error',
                    'title' => $kind === 'tracking' ? "Revisa el píxel de {$name} (o la API de conversiones)" : $t,
                    'why' => $kind === 'tracking' ? self::firstSentence(preg_replace('/^Tracking:\s*/u', '', $t)) : null,
                    'detail' => $kind === 'tracking' ? [$t] : []];
            }
            if ($p['price_watch']) {
                $items[] = $base + ['key' => "{$p['product_id']}:price_watch", 'city' => null, 'kind' => 'price', 'label' => 'Precio',
                    'text' => $p['price_watch'], 'tone' => str_contains($p['price_watch'], 'volver') ? 'warning' : 'info',
                    'title' => $p['price_watch'], 'why' => null, 'detail' => []];
            }
        }
        foreach ($items as &$i) {
            $i['severity'] = self::SEVERITY[$i['kind']];
            $i['group'] = self::GROUP[$i['kind']];
        }
        unset($i);
        usort($items, fn ($a, $b) => [$a['severity'], $a['_level'], -$a['_risk'], $a['product'], (string) $a['city']]
            <=> [$b['severity'], $b['_level'], -$b['_risk'], $b['product'], (string) $b['city']]);

        return array_map(fn ($i) => array_diff_key($i, ['_level' => 1, '_risk' => 1]), $items);
    }

    /** La clase de cosa que pide la regla de una ciudad ("No se vende aquí" es pausarla). */
    private static function kind(array $a): string
    {
        return $a['key'] === 'none' && $a['label'] === 'No se vende aquí' ? 'not_sold' : $a['key'];
    }

    /** `.82`: apagar creativos: los perdedores en todas las ciudades ($city null) o los que pierden en una ciudad. */
    private static function stop(array $p, ?string $city, array $labels): array
    {
        $count = count($labels);
        $where = $city ? " en {$city}" : ' en todas las ciudades';
        $shown = array_slice($labels, 0, self::LABELS_IN_WHY);
        $more = $count - count($shown);
        // Sus números: en esa ciudad o, si pierde en todas, su lectura (solo los 12 que más gastaron están en el análisis)
        $lines = [];
        foreach ($labels as $label) {
            $cr = collect($p['creatives'] ?? [])->first(fn ($x) => (string) $x['label'] === $label);
            $there = $cr && $city ? collect($cr['cities'] ?? [])->first(fn ($x) => ($x['city'] ?? null) === $city) : null;
            $lines[] = match (true) {
                $there !== null && ($there['data'] ?? '') !== '' => "{$label} en {$city}: {$there['data']}",
                $cr !== null && !$city => "{$label}: {$cr['reading']}",
                default => $label,
            };
        }

        return ['key' => "{$p['product_id']}:stop:" . substr(md5(($city ?? '*') . '|' . implode(',', $labels)), 0, 10), 'city' => $city,
            'kind' => 'stop_creatives', 'label' => 'Apagar creativos', 'tone' => 'error',
            'text' => 'Apagar' . $where . ': ' . CreativeCalls::join($labels) . ($city ? ' (ahí pierden).' : ' (pierden con tus reglas).'),
            'title' => $count === 1 ? "Apaga {$labels[0]} de {$p['product']}{$where}" : "Apaga {$count} creativos de {$p['product']}{$where}",
            'why' => ($count === 1 ? '' : implode(', ', $shown) . ($more > 0 ? " y {$more} más" : '') . ' · ')
                . ($city ? ($count === 1 ? 'ahí pierde' : 'ahí pierden') : ($count === 1 ? 'es perdedor con tus reglas' : 'son perdedores con tus reglas')),
            'detail' => [...$lines, $city ? ($count === 1 ? 'Apágalo' : 'Apágalos') . " solo en {$city}: en las otras ciudades no " . ($count === 1 ? 'lo' : 'los') . ' toques.'
                : ($count === 1 ? 'Apágalo' : 'Apágalos') . ' en todas las ciudades donde ' . ($count === 1 ? 'esté activo.' : 'estén activos.')]];
    }

    /** `.82`: la tarea en una frase, con los montos exactos. */
    private static function title(string $kind, string $product, ?string $city, array $a, ?array $n, bool $stopHere, string $mode): string
    {
        $in = $city ? " en {$city}" : '';
        $move = ($a['from'] ?? null) && ($a['to'] ?? null) ? ': ' . AdRules::money($a['from']) . ' → ' . AdRules::money($a['to']) : '';
        $prune = $stopHere ? 0 : count($n['prune'] ?? []);
        $ads = $prune === 1 ? '1 anuncio caro' : "{$prune} anuncios caros";
        $launch = ($n['bank'] ?? null) ? "{$n['bank']} de la banca" : 'un batch nuevo';

        return match ($kind) {
            'scale' => "Sube {$product}{$in}{$move}",
            'decrease' => "Baja {$product}{$in}{$move}",
            'pause_city' => "Pausa {$product}{$in}",
            'not_sold' => "Pausa {$product}{$in}: ahí no se vende",
            'drop' => "Decide si dejas de vender {$product}{$in}",
            'last_test' => "Si quieres, haz el último test de {$product}{$in}",
            'intervene' => "Interviene {$product}{$in}: " . ($prune ? "apaga {$ads} y lanza {$launch}" : "lanza {$launch}") . ', sin tocar el presupuesto',
            'hold_prune' => match (true) {
                $mode === 'liquidation' => "Poda {$product}{$in}: deja solo los anuncios que no pierden",
                $prune > 0 => "Poda {$product}{$in}: apaga {$ads} y mete creativos nuevos",
                default => "Mete creativos nuevos en {$product}{$in}",
            },
            default => trim("{$a['label']} · {$product}{$in}"),
        };
    }

    /** `.82`: el porqué en una línea ("+20 % · $3,54 por compra; tu objetivo es $5,39"). */
    private static function why(string $kind, array $a, ?array $n): ?string
    {
        if (in_array($kind, ['not_sold', 'drop', 'last_test'], true) || $n === null) {
            return match ($kind) {
                'not_sold' => 'Está marcado como no disponible en esa ciudad',
                'drop' => 'El último test también quedó en tu límite o encima',
                'last_test' => 'Se pausó por estar en tu límite o encima',
                default => self::firstSentence($a['reading']),
            };
        }
        $cpa = $n['purchases3'] > 0 ? AdRules::money($n['spend3'] / $n['purchases3']) . ' por compra' : AdRules::money($n['spend3']) . ' gastados sin compras';
        $c = AdRules::money($n['c']);
        $be = $n['be'] ? AdRules::money($n['be']) : null;
        $streak = (int) ($n['streak'] ?? 0);
        $days = $streak > 0 ? " · {$streak} " . ($streak === 1 ? 'día' : 'días') . ' encima' : '';

        return match ($kind) {
            'scale' => (($a['from'] ?? null) && ($a['to'] ?? null) ? '+' . AdRules::num(round(($a['to'] / $a['from'] - 1) * 100)) . ' % · ' : '')
                . "{$cpa}; tu objetivo es {$c}",
            'hold_prune' => $be ? "{$cpa}: entre tu objetivo ({$c}) y tu límite ({$be})" : "{$cpa}; tu objetivo es {$c}",
            default => ($be ? "{$cpa}; tu límite es {$be}" : "{$cpa}; tu objetivo es {$c}") . $days,
        };
    }

    /** `.82`: los números de la ciudad en palabras, para "¿Por qué?". @return list<string> */
    private static function numbers(?array $n): array
    {
        if ($n === null || !$n['c']) {
            return [];
        }
        $source = CampaignRules::SOURCES[$n['source'] ?? ''] ?? null;

        return array_values(array_filter([
            'En 3 días: gastaste ' . AdRules::money($n['spend3']) . ($n['purchases3'] > 0
                ? ' y Meta vio ' . AdRules::num($n['purchases3']) . ' ' . ($n['purchases3'] == 1 ? 'compra' : 'compras') . ' = ' . AdRules::money($n['spend3'] / $n['purchases3']) . ' por compra.'
                : ' y Meta no vio compras.'),
            'Tu objetivo (C) aquí es ' . AdRules::money($n['c']) . ($n['be'] ? ' y tu límite para no perder (BE) es ' . AdRules::money($n['be']) : '')
                . ($source ? " ({$source})" : '') . '.',
            $n['budget'] ? 'Presupuesto: ' . AdRules::money($n['budget']) . ' por día.' : null,
        ]));
    }

    private static function firstSentence(string $t): string
    {
        return rtrim(preg_split('/(?<=\.)\s/u', trim($t))[0] ?? $t, '.');
    }
}
