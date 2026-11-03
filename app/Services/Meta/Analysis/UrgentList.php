<?php

namespace App\Services\Meta\Analysis;

use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\Queue\ForecastRules;

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
 * ciudad (ahí). Como un juego (dueño: "que todo tenga la lógica y la usabilidad de un juego"): cada punto es una misión,
 * "hecho" o "no estoy de acuerdo" la resuelven (`tally`) y `money` dice cuánto presupuesto por día mueve (lo que se baja o
 * se pausa y lo que se sube; apagar creativos no cuenta: con CBO, Meta pasa esa plata a los otros anuncios).
 *
 * `.83` (dueño: "debo poder saber en 3 segundos qué es lo que debo hacer"): cada misión dice dónde (`where`: el nombre corto
 * del producto y la ciudad), qué (`title`: una frase corta que empieza con el verbo) y cómo (`steps`: 2 a 4 pasos en Ads
 * Manager con el nombre exacto de la campaña, los anuncios, el batch y el monto; los nombres van entre « »).
 *
 * `.87` (dueño, 2026-11-03): una misión siempre se puede hacer hoy: si depende de algo que no llegó ("cuando llegue un
 * batch"), es un aviso y no cuenta para la racha. Una campaña prendida nunca con menos de 2 batches vivos, y lo que se
 * lanza sale de la banca de esa ciudad: también un batch que ya salió en otra ciudad (se duplica su ad set).
 *
 * `.89` (dueño, 2026-11-03): lo que llega a la banca durante el día y una ciudad necesita ya, se agrega como misión al
 * análisis de hoy (`launchReady`); un encargo atrasado es una misión para presionar al editor líder (`late`).
 */
final class UrgentList
{
    /** Qué tan urgente es cada cosa (menos es más urgente). */
    public const SEVERITY = [
        'drop' => 1, 'pause_city' => 1, 'not_sold' => 1, 'stock_out' => 1, 'stop_creatives' => 1,
        'decrease' => 2, 'intervene' => 2, 'last_test' => 2, 'tracking' => 2, 'late_urgent' => 2,
        'hold_prune' => 3, 'few_batches' => 3, 'late_batch' => 3, 'launch_ready' => 3,
        'scale' => 4, 'launch_test' => 4,
        'alarm' => 5,
        'price' => 6,
    ];

    /** `.82`: en qué grupo de la pantalla va cada cosa. */
    public const GROUP = [
        'drop' => 'frenar', 'pause_city' => 'frenar', 'not_sold' => 'frenar', 'stop_creatives' => 'frenar', 'decrease' => 'frenar',
        'intervene' => 'arreglar', 'last_test' => 'arreglar', 'tracking' => 'arreglar', 'hold_prune' => 'arreglar', 'few_batches' => 'arreglar',
        'late_urgent' => 'arreglar', 'late_batch' => 'arreglar', 'launch_ready' => 'arreglar',
        'scale' => 'crecer', 'launch_test' => 'crecer',
        'stock_out' => 'aviso', 'alarm' => 'aviso', 'price' => 'aviso',
    ];

    /** Cuántos nombres de creativos caben en la línea del porqué. */
    private const LABELS_IN_WHY = 4;

    /** `.83`: cuántos nombres de anuncios caben en un paso (los demás, en "¿Por qué?"). */
    private const NAMES_IN_STEP = 8;

    /** `.87`: las ciudades que piden creativos: su misión ya lanza los batches que hagan falta. */
    private const FEEDS = ['hold_prune', 'intervene'];

    /** `.87`: las ciudades que se pausan o tienen su propio test: ahí no se lanzan batches. */
    private const NO_BATCHES = ['pause_city', 'not_sold', 'drop', 'last_test'];

    /** `.87`: el mínimo de batches vivos en una campaña prendida (dueño: "por lo menos montar dos batch"). */
    public const MIN_LIVE = 2;

    /**
     * @param list<array> $facts AnalysisFacts::build
     * @return list<array{key: string, product_id: int, product: string, city: ?string, kind: string, label: string, text: string, tone: string, urgency: ?string, severity: int, group: string, title: string, why: ?string, detail: list<string>, money: ?array{cut: ?float, add: ?float}}>
     */
    public static function build(array $facts, array $late = [], ?string $leader = null, array $tests = []): array
    {
        // .89: los encargos atrasados, para presionar al editor líder
        $items = self::late($late, $leader);
        foreach ($facts as $p) {
            $base = ['product_id' => $p['product_id'], 'product' => $p['product'], 'urgency' => $p['urgency']['label'] ?? null,
                '_level' => $p['urgency']['level'] ?? 9, '_risk' => (float) ($p['money_at_risk'] ?? 0)];
            $name = $p['product'];
            $short = self::short($name);
            $kinds = [];
            $campaigns = [];
            foreach ($p['cities'] as $c) {
                $kinds[(string) $c['city']] = self::kind($c['action']);
                $campaigns[(string) $c['city']] ??= $c['campaign'] ?? null;
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
                $items[] = $base + self::stop($p, null, $stopAll, null);
            }
            foreach ($stopCity as $city => $labels) {
                $items[] = $base + self::stop($p, $city, $labels, $campaigns[$city] ?? null);
            }

            foreach ($p['cities'] as $c) {
                $a = $c['action'];
                $kind = self::kind($a);
                $n = $c['numbers'] ?? null;
                $mode = $p['mode'] ?? 'normal';
                // .87: los batches que hay que lanzar aquí (mínimo 2 vivos) y cuáles, de la banca de esta ciudad
                $need = self::need($kind, $n, $mode);
                $launch = array_slice(self::offers($n), 0, $need);
                if ($need > 0 && !in_array($kind, self::FEEDS, true)) {
                    $items[] = $base + self::few($p['product_id'], $c, $n, $launch, $short);
                }
                if (!isset(self::SEVERITY[$kind])) {
                    continue; // mantener, esperar, nada: van en el detalle
                }
                $mission = self::mission($kind, $a, $n, $c['campaign'] ?? null, $c['city'], $short, isset($stopCity[(string) $c['city']]), $mode, $launch);
                // .87: nada que hacer hoy en Ads Manager (no hay qué podar ni qué lanzar): un aviso, no una misión
                $wait = $mission['title'] === null;
                if ($wait && $mode === 'liquidation') {
                    continue; // en liquidación no entran creativos: si no hay qué podar, es mantener
                }
                $prune = $n['prune'] ?? [];
                $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:{$kind}", 'city' => $c['city'], 'kind' => $kind,
                    'label' => $a['label'], 'text' => trim($a['reading'] . ' ' . ($a['proposal'] ?? '')), 'tone' => $a['tone'],
                    'where' => $short . ($c['city'] ? " · {$c['city']}" : ''), 'title' => $wait ? self::waiting($kind, $n) : $mission['title'],
                    'steps' => $wait ? [] : $mission['steps'],
                    'why' => self::why($kind, $a, $n),
                    'detail' => array_values(array_filter([...self::numbers($n), $a['reading'], $a['proposal'], ...$c['notes'], ...$c['budget_notes'],
                        count($prune) > self::NAMES_IN_STEP ? 'Todos los anuncios a apagar: ' . CreativeCalls::join($prune) . '.' : null, self::liveLine($n)])),
                    'money' => self::money($kind, $a, $n)] + ($wait ? ['group' => 'aviso'] : []);
                if ($c['price_option']) {
                    $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:price", 'city' => $c['city'], 'kind' => 'price',
                        'label' => 'Precio', 'text' => $c['price_option'], 'tone' => 'info',
                        'where' => $short . ($c['city'] ? " · {$c['city']}" : ''), 'title' => $c['price_option'], 'steps' => [], 'why' => null, 'detail' => []];
                }
            }
            foreach ($p['alarms'] as $t) {
                $kind = str_starts_with($t, 'Tracking') ? 'tracking' : (str_contains($t, 'sigue activa') ? 'stock_out' : 'alarm');
                $items[] = $base + ['key' => "{$p['product_id']}:alarm:" . substr(md5($t), 0, 10), 'city' => null, 'kind' => $kind,
                    'label' => $kind === 'tracking' ? 'Tracking' : 'Stock', 'text' => $t, 'tone' => $kind === 'alarm' ? 'warning' : 'error',
                    'where' => $short, 'title' => $kind === 'tracking' ? 'Revisa el píxel' : $t,
                    'steps' => $kind === 'tracking' ? ['Abre el Administrador de eventos de Meta.', 'Revisa que el píxel y la API de conversiones estén recibiendo las compras.'] : [],
                    'why' => $kind === 'tracking' ? self::firstSentence(preg_replace('/^Tracking:\s*/u', '', $t)) : null,
                    'detail' => $kind === 'tracking' ? [$t] : []];
            }
            if ($p['price_watch']) {
                $items[] = $base + ['key' => "{$p['product_id']}:price_watch", 'city' => null, 'kind' => 'price', 'label' => 'Precio',
                    'text' => $p['price_watch'], 'tone' => str_contains($p['price_watch'], 'volver') ? 'warning' : 'info',
                    'where' => $short, 'title' => $p['price_watch'], 'steps' => [], 'why' => null, 'detail' => []];
            }
        }
        foreach ($items as &$i) {
            // .87: una misión que hoy no se puede hacer es un aviso, y va con los avisos (no entre lo más urgente)
            $i['severity'] = isset($i['group']) && self::GROUP[$i['kind']] !== 'aviso' ? self::SEVERITY['alarm'] : self::SEVERITY[$i['kind']];
            $i['group'] ??= self::GROUP[$i['kind']];
            $i['money'] ??= null;
        }
        unset($i);
        usort($items, fn ($a, $b) => [$a['severity'], $a['_level'], -$a['_risk'], $a['product'], (string) $a['city']]
            <=> [$b['severity'], $b['_level'], -$b['_risk'], $b['product'], (string) $b['city']]);

        // .89: los testeos listos para lanzar (TestingLaunch), al final: van en "Crecer"
        return [...array_map(fn ($i) => array_diff_key($i, ['_level' => 1, '_risk' => 1]), $items), ...array_map(fn ($t) => self::testLaunch($t), $tests)];
    }

    /**
     * `.89` (dueño, 2026-11-03: un producto nuevo, "3 juntos, en todas las ciudades"; uno que ya vende, "en todas sus
     * campañas activas"; "siempre se testea sin ni siquiera pedir la mercancía"): el testeo listo para lanzar, cuando sus
     * batches están todos en la banca. Antes, lo que falta para lanzarlo bien: el link de Shopify y su C.
     *
     * @param array $t TestingLaunch::ready
     */
    public static function testLaunch(array $t): array
    {
        $short = self::short($t['product']);
        $n = count($t['batches']);
        $codes = CreativeCalls::join(array_column($t['batches'], 'code'));
        $upload = "sube los {$n} ad sets: " . CreativeCalls::join(array_map(fn ($b) => self::q((string) ($b['adset'] ?? $b['code'])), $t['batches']))
            . ' (sus videos están en Cola y banca).';
        if ($t['campaigns'] !== []) {
            $title = "Lanza los ángulos nuevos de {$short}: {$codes}";
            $why = "Ronda {$t['round']} del testeo: sus {$n} batches están listos en la banca";
            $steps = ['En cada campaña de ' . $short . ' (' . CreativeCalls::join(array_map(fn ($c) => self::q($c['name']), $t['campaigns'])) . '), ' . $upload,
                'No toques los anuncios que ya están: los batches del testeo van en sus propios ad sets.'];
        } else {
            $title = "Lanza el testeo de {$short}: {$codes}";
            $why = "Producto nuevo: sus {$n} batches del testeo están listos en la banca";
            $c = $t['target'] ?? null;
            $steps = array_values(array_filter([
                $t['linked'] ? null : "Antes: pásale a tu asistente el link de {$short} en Shopify (así sus pedidos se suman a {$short}).",
                $c ? null : "Antes: pon el CPA de referencia de {$short} en Meta Ads › Productos y objetivos (sin él no se puede juzgar el testeo).",
                'Crea una campaña CBO en ' . ($t['cities'] ? 'cada ciudad: ' . CreativeCalls::join($t['cities']) : 'cada ciudad donde vendes')
                    . '. En el nombre pon «' . mb_strtoupper($short) . '» y la ciudad (así se clasifica sola).',
                'En cada una, ' . $upload,
                $c ? 'Presupuesto de arranque por campaña: entre ' . AdRules::money($c * $n * $t['min']) . ' y ' . AdRules::money($c * $n * $t['max'])
                    . " por día (tu regla: C × {$n} batches × " . AdRules::num($t['min']) . ' a ' . AdRules::num($t['max']) . ').' : null,
            ]));
        }

        return ['key' => "{$t['product_id']}:test:{$t['round']}", 'product_id' => $t['product_id'], 'product' => $t['product'], 'urgency' => null,
            'city' => null, 'kind' => 'launch_test', 'label' => 'Testeo', 'tone' => 'success', 'text' => "{$why}.", 'where' => $short,
            'title' => $title, 'steps' => $steps, 'why' => $why, 'detail' => [], 'money' => null,
            'severity' => self::SEVERITY['launch_test'], 'group' => self::GROUP['launch_test']];
    }

    /**
     * `.82`: las misiones de un análisis (lo que no es un aviso) y cuántas están resueltas: "hecho" o "no estoy de acuerdo"
     * (el dueño decidió no hacerlo: nunca se traba). Sirve para la racha y para el calendario.
     *
     * @return array{tasks: int, resolved: int}
     */
    public static function tally(array $urgent, array $marks): array
    {
        $tasks = array_filter($urgent, fn ($u) => ($u['group'] ?? self::GROUP[$u['kind']] ?? 'aviso') !== 'aviso');

        return ['tasks' => count($tasks), 'resolved' => count(array_filter($tasks, fn ($u) => in_array($marks[$u['key']]['status'] ?? null, ['done', 'disagree'], true)))];
    }

    /** `.82`: cuánto presupuesto por día mueve la tarea: lo que se baja o se pausa (cut) y lo que se sube (add). */
    private static function money(string $kind, array $a, ?array $n): ?array
    {
        $from = $a['from'] ?? null;
        $to = $a['to'] ?? null;
        $budget = $from ?? ($n['budget'] ?? null);

        return match (true) {
            $kind === 'scale' && $from && $to => ['cut' => null, 'add' => round($to - $from, 2)],
            $kind === 'decrease' && $from && $to => ['cut' => round($from - $to, 2), 'add' => null],
            in_array($kind, ['pause_city', 'drop'], true) && $budget => ['cut' => round((float) $budget, 2), 'add' => null],
            default => null,
        };
    }

    /** La clase de cosa que pide la regla de una ciudad ("No se vende aquí" es pausarla). */
    private static function kind(array $a): string
    {
        return $a['key'] === 'none' && $a['label'] === 'No se vende aquí' ? 'not_sold' : $a['key'];
    }

    /** `.82`: apagar creativos: los perdedores en todas las ciudades ($city null) o los que pierden en una ciudad. */
    private static function stop(array $p, ?string $city, array $labels, ?string $campaign): array
    {
        $count = count($labels);
        $where = $city ? " en {$city}" : ' en todas las ciudades';
        $short = self::short($p['product']);
        $names = CreativeCalls::join(array_map(fn ($l) => self::q($l), $labels));
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
            'where' => $short . ' · ' . ($city ?? 'todas las ciudades'),
            'title' => $city ? ($count === 1 ? "Apaga {$labels[0]}: aquí pierde" : "Apaga {$count} creativos que aquí pierden")
                : ($count === 1 ? "Apaga {$labels[0]} en todas las campañas" : "Apaga {$count} creativos perdedores en todas las campañas"),
            'steps' => $city ? [self::open($campaign, $short, $city), "Apaga los anuncios de {$names} (búscalos por nombre).", 'En las otras ciudades no los toques.']
                : [$count === 1 ? 'En el buscador de Ads Manager, escribe ' . self::q($labels[0]) . '.' : "En el buscador de Ads Manager, busca cada uno: {$names}.",
                    "Apaga todos sus anuncios activos, en todas las campañas de {$short}."],
            'why' => ($count === 1 ? '' : implode(', ', $shown) . ($more > 0 ? " y {$more} más" : '') . ' · ')
                . ($city ? ($count === 1 ? 'ahí pierde' : 'ahí pierden') : ($count === 1 ? 'es perdedor con tus reglas' : 'son perdedores con tus reglas')),
            'detail' => [...$lines, $city ? ($count === 1 ? 'Apágalo' : 'Apágalos') . " solo en {$city}: en las otras ciudades no " . ($count === 1 ? 'lo' : 'los') . ' toques.'
                : ($count === 1 ? 'Apágalo' : 'Apágalos') . ' en todas las ciudades donde ' . ($count === 1 ? 'esté activo.' : 'estén activos.')]];
    }

    /**
     * `.83`: qué hacer en una frase corta que empieza con el verbo (sin el producto ni la ciudad: van en `where`) y cómo, en
     * pasos de Ads Manager con los nombres exactos.
     *
     * @return array{title: string, steps: list<string>}
     */
    private static function mission(string $kind, array $a, ?array $n, ?string $campaign, ?string $city, string $short, bool $stopHere, string $mode, array $launch = []): array
    {
        $from = $a['from'] ?? null;
        $to = $a['to'] ?? null;
        $open = self::open($campaign, $short, $city);
        $prune = $stopHere ? [] : ($n['prune'] ?? []);
        $count = count($prune);
        $losing = $count === 1 ? '1 anuncio que pierde' : "{$count} anuncios que pierden";
        $costly = $count === 1 ? '1 anuncio caro' : "{$count} anuncios caros";
        $off = $count ? 'Apaga ' . CreativeCalls::join(array_map(fn ($x) => self::q($x), array_slice($prune, 0, self::NAMES_IN_STEP)))
            . ($count > self::NAMES_IN_STEP ? ' y ' . ($count - self::NAMES_IN_STEP) . ' más (todos en «¿Por qué?»)' : '') . '.' : null;
        // .87: qué lanzar (de la banca o duplicado de otra ciudad); si no hay nada, la misión no lo pide ("cuando llegue")
        $go = $launch ? CreativeCalls::join(array_column($launch, 'code')) : null;
        $launchSteps = self::launchSteps($launch);
        $pct = $from && $to ? ' (' . ($to > $from ? '+' : '−') . AdRules::num(abs(round(($to / $from - 1) * 100))) . ' %)' : '';
        $switchOff = ['Apágala con el interruptor de la izquierda.'];
        preg_match('/(\$[\d.,]+) por día durante (\d+) días/u', (string) ($a['proposal'] ?? ''), $test);

        [$title, $steps] = match ($kind) {
            'scale' => [$to ? 'Sube el presupuesto a ' . AdRules::money($to) . ' por día' : 'Sube el presupuesto',
                [$open, $from && $to ? 'Cambia el presupuesto diario de ' . AdRules::money($from) . ' a ' . AdRules::money($to) . "{$pct}." : (string) $a['proposal']]],
            'decrease' => [$to ? 'Baja el presupuesto a ' . AdRules::money($to) . ' por día' : 'Baja el presupuesto',
                [$open, $from && $to ? 'Cambia el presupuesto diario de ' . AdRules::money($from) . ' a ' . AdRules::money($to) . "{$pct}." : (string) $a['proposal']]],
            'pause_city' => ['Pausa la campaña', [$open, ...$switchOff]],
            'not_sold' => ['Pausa la campaña: ahí no se vende', [$open, ...$switchOff]],
            'drop' => ['Decide si dejas de vender aquí', ['Si lo dejas: ' . lcfirst($open), ...$switchOff, 'Si quieres seguir: toca «No estoy de acuerdo» y escribe por qué.']],
            'last_test' => ['Si quieres, haz el último test', [$open, $test ? "Lanza un batch nuevo con {$test[1]} por día durante {$test[2]} días." : (string) $a['proposal'],
                'Si también pierde, deja el producto en esta ciudad.']],
            'intervene' => [match (true) {
                $count && $go => "Apaga {$losing} y lanza {$go}",
                $count > 0 => "Apaga {$losing}",
                $go !== null => "Lanza {$go}",
                default => null,
            }, array_values(array_filter([$open, $off, ...$launchSteps, 'No toques el presupuesto hoy.']))],
            'hold_prune' => $mode === 'liquidation'
                ? [$count ? 'Deja solo los anuncios que no pierden' : null, array_values(array_filter([$open, $off]))]
                : [match (true) {
                    $count && $go => "Apaga {$costly} y lanza {$go}",
                    $count > 0 => "Apaga {$costly}",
                    $go !== null => "Lanza {$go}",
                    default => null,
                }, array_values(array_filter([$open, $off, ...$launchSteps]))],
            default => [(string) $a['label'], array_values(array_filter([$open, $a['proposal'] ?? null]))],
        };

        return ['title' => $title, 'steps' => array_values(array_filter($steps, fn ($s) => $s !== ''))];
    }

    /**
     * `.87`: cuántos batches lanzar en una ciudad. Una campaña prendida nunca con menos de 2 batches vivos (dueño: "para
     * darle un poco más de chance al algoritmo y que el presupuesto… no le pegue tan fuerte a ese batch nuevo"); si la
     * ciudad pide creativos (podar o intervenir), por lo menos 1. Nada en liquidación ni donde se pausa. Los análisis de
     * antes de la .87 no traen los vivos: solo el de la banca, como antes.
     */
    public static function need(string $kind, ?array $n, string $mode): int
    {
        if ($n === null || $mode === 'liquidation' || in_array($kind, self::NO_BATCHES, true)) {
            return 0;
        }
        $live = $n['live'] ?? null;
        if (in_array($kind, self::FEEDS, true)) {
            return max(1, self::MIN_LIVE - (int) ($live ?? self::MIN_LIVE));
        }

        return $live === null ? 0 : max(0, self::MIN_LIVE - (int) $live);
    }

    /** `.87`: lo que puede lanzar la ciudad, en orden; los análisis de antes solo traían el primero de la banca. */
    private static function offers(?array $n): array
    {
        return $n['offers'] ?? (($n['bank'] ?? null) ? [['code' => (string) $n['bank'], 'how' => 'bank']] : []);
    }

    /** `.87`: cómo lanzar cada batch: el de la banca, o duplicar el ad set de la ciudad donde ya salió. @return list<string> */
    private static function launchSteps(array $launch): array
    {
        return array_map(fn ($o) => ($o['how'] ?? 'bank') === 'copy'
            ? 'Duplica el ad set ' . self::q((string) $o['adset']) . ' de ' . (($o['campaign'] ?? null) ? 'la campaña ' . self::q($o['campaign']) : ($o['city'] ?? 'la otra ciudad'))
                . ' en esta campaña' . (($o['city'] ?? null) && ($o['verdict'] ?? null) ? " (en {$o['city']}: " . mb_strtolower($o['verdict']) . ')' : '') . '.'
            : 'Lanza el batch ' . self::q((string) $o['code']) . ' de la banca (sus videos están en Cola y banca).', $launch);
    }

    /**
     * `.87`: una ciudad que no pide creativos (subir, mantener, esperar o bajar) pero con menos de 2 batches vivos: lanzar
     * los que falten de su banca o, si no hay, un aviso.
     */
    private static function few(int $productId, array $c, array $n, array $launch, string $short): array
    {
        $live = (int) $n['live'];
        $go = CreativeCalls::join(array_column($launch, 'code'));
        $budget = ($n['budget'] ?? null) ? AdRules::money($n['budget']) . ' por día' : 'el presupuesto';

        return ['key' => "{$productId}:{$c['campaign_meta_id']}:few_batches", 'city' => $c['city'], 'kind' => 'few_batches', 'label' => 'Batches vivos',
            'text' => self::alive($live) . ' en esta campaña.', 'tone' => 'warning', 'where' => $short . ($c['city'] ? " · {$c['city']}" : ''),
            'title' => match (true) {
                $launch === [] => self::alive($live) . ' y no hay más en la banca',
                $live === 1 && count($launch) === 1 => "Lanza 1 batch más: {$go}",
                default => "Lanza {$go}",
            },
            'steps' => $launch ? [self::open($c['campaign'] ?? null, $short, $c['city']), ...self::launchSteps($launch)] : [],
            'why' => $live === 0 ? 'No queda ningún batch vivo: la campaña no tiene anuncios que mostrar'
                : "Solo queda 1 batch vivo: {$budget} cae todo en él; con 2, Meta reparte",
            'detail' => array_values(array_filter([self::liveLine($n), $launch === [] ? 'Cuando haya un batch en la banca, te sale como misión.' : null])),
            'money' => null] + ($launch === [] ? ['group' => 'aviso'] : []);
    }

    /** `.87`: el aviso de una ciudad que pide creativos y hoy no tiene qué podar ni qué lanzar. */
    private static function waiting(string $kind, ?array $n): string
    {
        $live = $n['live'] ?? null;
        $state = $live !== null && $live < self::MIN_LIVE ? self::alive((int) $live) . '. ' : '';

        return $state . ($kind === 'intervene' ? 'No toques el presupuesto y espera un batch nuevo' : 'Espera un batch nuevo para meter creativos')
            . ': no hay en la banca. Cuando haya uno, te sale como misión.';
    }

    private static function alive(int $live): string
    {
        return $live === 0 ? 'No queda ningún batch vivo' : ($live === 1 ? 'Solo queda 1 batch vivo' : "Quedan {$live} batches vivos");
    }

    /** `.87`: los batches vivos de la ciudad, para "¿Por qué?" (solo si son menos de 2). */
    private static function liveLine(?array $n): ?string
    {
        $live = $n['live'] ?? null;

        return $live === null || $live >= self::MIN_LIVE ? null
            : self::alive((int) $live) . ' aquí (ad sets prendidos con algún anuncio prendido, sin contar lo que apagas hoy). Tu regla: nunca menos de ' . self::MIN_LIVE . '.';
    }

    /**
     * `.89` (dueño: "si se le pidió al editor en líder pero está atrasado, debería decirme algo tipo, métele presión al
     * editor en líder para que entregue este batch que se necesita con urgencia"): un encargo que pasó su fecha sin
     * entregarse. Urgente si su producto necesita un batch hoy.
     *
     * @param list<array{id: int, product_id: int, product: string, code: string, due: string, days: int, editor: ?string, urgent: bool, cities: list<string>}> $late
     */
    public static function late(array $late, ?string $leader): array
    {
        $to = $leader ? "a {$leader}" : 'al editor líder';

        return array_map(function ($b) use ($to) {
            $short = self::short($b['product']);
            $due = ForecastRules::days([$b['due']]);
            // .89: el editor no entregó, o entregó y las correcciones del líder pasaron de la fecha "lista"
            $fixing = ($b['stage'] ?? 'delivery') === 'corrections';
            $what = $fixing ? 'ya se entregó, pero sus correcciones tenían que terminar ' . ForecastRules::days([$b['ready'] ?? $b['due']]) : "era para {$due}";
            $need = match (true) {
                $b['urgent'] && $b['cities'] !== [] => ' y ' . CreativeCalls::join($b['cities']) . (count($b['cities']) > 1 ? ' lo necesitan' : ' lo necesita')
                    . ' hoy para volver a bajar el costo por compra.',
                (bool) ($b['testing'] ?? false) => "; es del testeo de {$short}.",
                default => '; es la reserva para seguir escalando.',
            };

            return ['key' => "late:{$b['id']}", 'product_id' => $b['product_id'], 'product' => $b['product'], 'urgency' => null,
                '_level' => $b['urgent'] ? 0 : 9, '_risk' => 0.0, 'city' => null, 'kind' => $b['urgent'] ? 'late_urgent' : 'late_batch',
                'label' => 'Atrasado', 'tone' => 'warning', 'text' => "{$b['code']} de {$short} {$what}.",
                'where' => "{$short} · {$b['code']}",
                'title' => $b['urgent'] ? "Presiona al editor líder: {$b['code']} se necesita con urgencia" : "Pregúntale al editor líder por {$b['code']}: está atrasado",
                'steps' => [
                    "Escríbele {$to}: " . self::q($b['code']) . " de {$short} {$what}{$need}",
                    $fixing ? (($b['returned'] ?? false) ? 'Lo devolvió para corregir: que ' . ($b['editor'] ?? 'el editor') . ' entregue la corrección hoy.'
                        : 'Que lo apruebe o lo devuelva hoy' . ($b['editor'] ? " (lo hizo {$b['editor']})." : '.'))
                        : ($b['editor'] ? "Lo tiene {$b['editor']}." : 'Todavía no tiene editor: que lo asigne hoy.'),
                    'El sistema también se lo recordó en su campanita. Si mañana sigue sin entregarse, vuelve a salir aquí.',
                ],
                'why' => ($b['days'] === 1 ? '1 día' : "{$b['days']} días") . ($fixing ? ' atrasado en correcciones · ' : ' atrasado · ')
                    . ($b['editor'] ? ($fixing ? "lo hizo {$b['editor']}" : "lo tiene {$b['editor']}") : 'sin editor'),
                'detail' => [], 'money' => null];
        }, $late);
    }

    /**
     * `.89` (dueño: "en el análisis de campaña del día me debe decir que debo lanzar"): un batch que llegó a la banca durante
     * el día y que una o más ciudades necesitan ya. Se agrega al análisis de hoy (BankArrival), con su grupo y su urgencia.
     *
     * @param list<array{city: ?string, campaign: ?string, offer: array, why: ?string}> $cities
     */
    public static function launchReady(int $productId, string $product, string $code, array $cities, string $at): array
    {
        $short = self::short($product);
        $names = CreativeCalls::join(array_values(array_filter(array_column($cities, 'city'))));
        $steps = [];
        foreach ($cities as $c) {
            $steps[] = self::open($c['campaign'], $short, $c['city']);
            array_push($steps, ...self::launchSteps([$c['offer']]));
        }
        $whys = array_values(array_filter(array_map(fn ($c) => $c['city'] && $c['why'] ? "{$c['city']} {$c['why']}" : null, $cities)));

        return ['key' => "{$productId}:ready:{$code}", 'product_id' => $productId, 'product' => $product, 'urgency' => null,
            'city' => count($cities) === 1 ? $cities[0]['city'] : null, 'kind' => 'launch_ready', 'label' => 'Lanzar', 'tone' => 'success',
            'text' => "{$code} llegó a la banca a las {$at} (hora de Venezuela).", 'where' => $short . ($names !== '' ? " · {$names}" : ''),
            'title' => "Lanza {$code}: ya llegó a la banca", 'steps' => $steps,
            'why' => "Llegó a la banca a las {$at}" . ($whys ? ' · ' . implode('; ', $whys) : ''),
            'detail' => [], 'money' => null, 'severity' => self::SEVERITY['launch_ready'], 'group' => self::GROUP['launch_ready']];
    }

    /** `.83`: el primer paso: abrir la campaña por su nombre exacto. */
    private static function open(?string $campaign, string $short, ?string $city): string
    {
        return $campaign ? 'Abre la campaña ' . self::q($campaign) . '.' : "Abre la campaña de {$short}" . ($city ? " en {$city}" : '') . '.';
    }

    /** `.83`: un nombre exacto, entre « » (la pantalla lo muestra como una etiqueta). */
    private static function q(string $name): string
    {
        return '«' . trim($name) . '»';
    }

    /** `.83`: el nombre corto del producto: lo que va antes de ®, ™ o un guion ("FlexiStep® Tobillera de…" → "FlexiStep"). */
    public static function short(string $name): string
    {
        $cut = trim(preg_split('/[®™©]|\s[-–|]\s/u', $name)[0] ?? '');

        return $cut !== '' ? $cut : trim($name);
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
