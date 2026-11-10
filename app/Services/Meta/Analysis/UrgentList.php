<?php

namespace App\Services\Meta\Analysis;

use App\Services\Meta\CampaignNameTargets;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\Products\AngleRest;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\Queue\ForecastRules;
use App\Services\Meta\Upload\UploadRules;

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
 *
 * `.123` (dueño, 2026-11-09: "solo un cambio grande a la vez"): entre C y BE, mientras se espera a ver si el último cambio
 * devolvió la campaña a C, no se lanza otro batch (solo si quedan menos de 2 vivos); y devolver la última subida
 * (`step_down`) es una misión de "Arreglar".
 *
 * `.124` (dueño, 2026-11-09: "todos los batches nuevos los colocamos con un gasto mínimo… cuando ese batch ya se probó… se
 * procede a eliminar ese gasto mínimo"): cada misión que lanza un batch dice qué gasto mínimo ponerle a su ad set y pide
 * pegar los parámetros de URL en los anuncios nuevos (`copy`: el texto para copiar); y una misión de "Arreglar" por campaña
 * pide quitar el mínimo de los batches que ya se probaron (`min_spend`). No es un cambio grande: no espera las 24 horas.
 * `.130`: no del mejor (el de CPA más bajo en 7 días) ni de los que venden en la C o debajo.
 *
 * `.125` (dueño, 2026-11-09: "sí debemos establecer un límite para soltar producto"; "nunca podemos permitirnos perder dinero
 * sin una razón buena justificada"): un producto en pausa con una campaña prendida es una misión de apagarla (`product_off`);
 * el producto entero 14 días sin dejar dinero es una misión para decidir (`drop_product`), nunca automática; y si los datos
 * de Meta son viejos, se avisa (`stale`).
 *
 * `.126` (dueño, 2026-11-09: "pesa más ganar en dólares, la ganancia siempre va a pesar más"): cada misión de una ciudad dice
 * lo que dejó en 3 días (compras de Meta × BE − gasto), y las de subir salen ordenadas: primero la que más deja.
 */
final class UrgentList
{
    /** Qué tan urgente es cada cosa (menos es más urgente). */
    public const SEVERITY = [
        'pause_city' => 1, 'not_sold' => 1, 'product_off' => 1, 'stock_out' => 1, 'stock_waiting' => 1, 'stop_creatives' => 1,
        'decrease' => 2, 'intervene' => 2, 'tracking' => 2, 'late_urgent' => 2, 'drop_product' => 2,
        'hold_prune' => 3, 'step_down' => 3, 'few_batches' => 3, 'min_spend' => 3, 'late_batch' => 3, 'launch_ready' => 3, 'no_city' => 3, 'no_be' => 3,
        'scale' => 4, 'launch_test' => 4, 'relaunch' => 4, 'new_product_late' => 4,
        'alarm' => 5, 'names' => 5, 'cooldown' => 5, 'angle_rest' => 5, 'left_city' => 5, 'stale' => 5,
        'price' => 6,
    ];

    /** `.82`: en qué grupo de la pantalla va cada cosa. */
    public const GROUP = [
        'pause_city' => 'frenar', 'not_sold' => 'frenar', 'product_off' => 'frenar', 'drop_product' => 'frenar', 'stop_creatives' => 'frenar', 'decrease' => 'frenar',
        'intervene' => 'arreglar', 'tracking' => 'arreglar', 'hold_prune' => 'arreglar', 'step_down' => 'arreglar', 'few_batches' => 'arreglar', 'min_spend' => 'arreglar',
        'late_urgent' => 'arreglar', 'late_batch' => 'arreglar', 'launch_ready' => 'arreglar', 'no_city' => 'arreglar', 'no_be' => 'arreglar',
        'scale' => 'crecer', 'launch_test' => 'crecer', 'relaunch' => 'crecer', 'new_product_late' => 'crecer',
        'stock_out' => 'aviso', 'stock_waiting' => 'aviso', 'alarm' => 'aviso', 'price' => 'aviso', 'names' => 'aviso', 'cooldown' => 'aviso', 'angle_rest' => 'aviso', 'left_city' => 'aviso', 'stale' => 'aviso',
    ];

    /** Cuántos nombres de creativos caben en la línea del porqué. */
    private const LABELS_IN_WHY = 4;

    /** `.83`: cuántos nombres de anuncios caben en un paso (los demás, en "¿Por qué?"). */
    private const NAMES_IN_STEP = 8;

    /** `.87`: las ciudades que piden creativos: su misión ya lanza los batches que hagan falta. */
    private const FEEDS = ['hold_prune', 'intervene'];

    /** `.87`: las ciudades que se pausan o se prenden con sus propios batches (`.117`): ahí no se lanzan aparte. */
    private const NO_BATCHES = ['pause_city', 'not_sold', 'product_off', 'relaunch', 'left_city'];

    /** `.87`: el mínimo de batches vivos en una campaña prendida (dueño: "por lo menos montar dos batch"). */
    public const MIN_LIVE = 2;

    /**
     * @param list<array> $facts AnalysisFacts::build
     * @return list<array{key: string, product_id: int, product: string, city: ?string, kind: string, label: string, text: string, tone: string, urgency: ?string, severity: int, group: string, title: string, why: ?string, detail: list<string>, money: ?array{cut: ?float, add: ?float}}>
     */
    public static function build(array $facts, array $late = [], ?string $leader = null, array $tests = [], array $names = []): array
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
                if ($labels && !in_array($kinds[(string) $city] ?? null, ['pause_city', 'not_sold'], true)) {
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
                // .123: tampoco mientras se espera a ver si el último cambio la devolvió a C
                $need = self::need($kind, $n, $mode, self::holds($a));
                $launch = array_slice(self::offers($n), 0, $need);
                if ($need > 0 && !in_array($kind, self::FEEDS, true)) {
                    $items[] = $base + self::few($p['product_id'], $c, $n, $launch, $short);
                }
                // .111: lo que espera las 24 horas de tu regla (subir, bajar o pausar) es un aviso con la hora
                if ($kind === 'wait' && ($cool = $a['cooldown'] ?? null)) {
                    $items[] = $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:cooldown", 'city' => $c['city'], 'kind' => 'cooldown',
                        'label' => $a['label'], 'text' => trim($a['reading'] . ' ' . ($a['proposal'] ?? '')), 'tone' => 'default',
                        'where' => $short . ($c['city'] ? " · {$c['city']}" : ''), 'title' => self::cooling($cool), 'steps' => [], 'batches' => [],
                        'why' => self::cooledBy($cool), 'detail' => array_values(array_filter([...self::numbers($n), $a['reading'], $a['proposal']]))];
                }
                // .124: los batches que ya se probaron y siguen con gasto mínimo (no donde la campaña se pausa ni en un producto en pausa)
                if (($n['mins']['off'] ?? []) && !in_array($kind, ['pause_city', 'not_sold', 'product_off'], true) && !in_array($mode, ['paused', 'abandoned'], true)) {
                    $items[] = $base + self::minSpend((int) $p['product_id'], $c, $n, $short);
                }
                if (!isset(self::SEVERITY[$kind])) {
                    continue; // mantener, esperar, nada: van en el detalle
                }
                $mission = self::mission($kind, $a, $n, $c['campaign'] ?? null, $c['city'], $short, isset($stopCity[(string) $c['city']]), $mode, $kind === 'relaunch' ? self::offers($n) : $launch);
                // .87: nada que hacer hoy en Ads Manager (no hay qué podar ni qué lanzar): un aviso, no una misión
                $wait = $mission['title'] === null;
                if ($wait && $mode === 'liquidation') {
                    continue; // en liquidación no entran creativos: si no hay qué podar, es mantener
                }
                $prune = $n['prune'] ?? [];
                // .126: las de subir, primero la que más deja
                $items[] = ['_gain' => $kind === 'scale' ? (float) ($n['gain3'] ?? 0) : 0.0] + $base + ['key' => "{$p['product_id']}:{$c['campaign_meta_id']}:{$kind}", 'city' => $c['city'], 'kind' => $kind,
                    'label' => $a['label'], 'text' => trim($a['reading'] . ' ' . ($a['proposal'] ?? '')), 'tone' => $a['tone'],
                    'where' => $short . ($c['city'] ? " · {$c['city']}" : ''), 'title' => $wait ? self::waiting($kind, $n, $a['cooldown'] ?? null, $a['ladder'] ?? null) : $mission['title'],
                    'steps' => $wait ? [] : $mission['steps'], 'batches' => $wait ? [] : $mission['batches'],
                    'copy' => $wait ? null : $mission['copy'],
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
                $kind = match (true) {
                    str_starts_with($t, 'Tracking') => 'tracking',
                    str_contains($t, 'sigue activa') => 'stock_out',
                    str_contains($t, 'esperando stock') => 'stock_waiting',
                    default => 'alarm',
                };
                // .101: "55 pedidos esperando stock en Distrito Capital" de título; lo gastado y qué hacer, en el porqué
                $waiting = $kind === 'stock_waiting' ? explode(': ', $t, 2) : null;
                $items[] = $base + ['key' => "{$p['product_id']}:alarm:" . substr(md5($t), 0, 10), 'city' => null, 'kind' => $kind,
                    'label' => $kind === 'tracking' ? 'Tracking' : 'Stock', 'text' => $t, 'tone' => $kind === 'alarm' ? 'warning' : 'error',
                    'where' => $short, 'title' => match ($kind) { 'tracking' => 'Revisa el píxel', 'stock_waiting' => $waiting[0], default => $t },
                    'steps' => $kind === 'tracking' ? ['Abre el Administrador de eventos de Meta.', 'Revisa que el píxel y la API de conversiones estén recibiendo las compras.'] : [],
                    'why' => match ($kind) { 'tracking' => self::firstSentence(preg_replace('/^Tracking:\s*/u', '', $t)), 'stock_waiting' => $waiting[1] ?? null, default => null },
                    'detail' => in_array($kind, ['tracking', 'stock_waiting'], true) ? [$t] : []];
            }
            // .125: el producto entero lleva 14 días sin dejar dinero: lo decide el dueño
            if ($p['drop'] ?? null) {
                $items[] = $base + self::drop((int) $p['product_id'], $short, $p['drop']);
            }
            // .117: se quedó sin ninguna ciudad prendida
            if ($p['no_city'] ?? null) {
                $items[] = $base + self::noCity((int) $p['product_id'], $short, $p['no_city']);
            }
            // .120: campañas con C y sin BE: deciden con uno estimado hasta que ponga el real
            if ($noBe = array_values(array_filter($p['cities'], fn ($c) => !empty($c['numbers']['be_estimated'])))) {
                $items[] = $base + self::noBe((int) $p['product_id'], $short, $noBe);
            }
            // .116: un ángulo que descansa: qué perdió y qué sigue
            foreach ($p['rests'] ?? [] as $r) {
                $next = ($p['next_angle'] ?? null) ? "El próximo encargo será de {$p['next_angle']}." : 'Ya no quedan ángulos en la ficha: te llegará una propuesta a tu calendario.';
                $text = "El ángulo {$r['angle']} descansa " . AngleRest::untilText($r['until']) . ': perdieron sus últimos ' . count($r['codes']) . ' batches (' . AngleRest::codesText($r['codes']) . "). {$next}";
                $items[] = $base + ['key' => "{$p['product_id']}:rest:{$r['angle']}", 'city' => null, 'kind' => 'angle_rest', 'label' => 'Ángulo en descanso', 'text' => $text, 'tone' => 'warning',
                    'where' => $short, 'title' => $text, 'steps' => [], 'why' => null, 'detail' => []];
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
        usort($items, fn ($a, $b) => [$a['severity'], -($a['_gain'] ?? 0), $a['_level'], -$a['_risk'], $a['product'], (string) $a['city']]
            <=> [$b['severity'], -($b['_gain'] ?? 0), $b['_level'], -$b['_risk'], $b['product'], (string) $b['city']]);

        // .89: los testeos listos para lanzar (TestingLaunch), al final: van en "Crecer"
        return [...array_map(fn ($i) => array_diff_key($i, ['_level' => 1, '_risk' => 1, '_gain' => 1]), $items), ...array_map(fn ($t) => self::testLaunch($t), $tests),
            ...array_map(fn ($n) => self::names($n), $names)];
    }

    /**
     * `.89` (dueño: "que el sistema siempre pueda saber de qué creativo, de qué batch y de qué campaña estamos hablando"):
     * un aviso por campaña con lo que el sistema no puede reconocer y qué nombre ponerle (NameCheck).
     *
     * @param array{campaign_meta_id: string, campaign: string, product_id: ?int, product: ?string, unclassified: bool, sets: list<string>, ads: list<string>} $n
     */
    public static function names(array $n): array
    {
        $list = fn (array $names) => CreativeCalls::join(array_map(fn ($x) => self::q($x), array_slice($names, 0, 3))) . (count($names) > 3 ? ' y ' . (count($names) - 3) . ' más' : '');
        $parts = array_values(array_filter([
            $n['unclassified'] ? 'no se reconoce la campaña: nómbrala «PRODUCTO | CIUDAD | CBO | fecha» (como «TERMAFIT | CCS | CBO | 03/11/2026») o clasifícala en Meta Ads › Configuración' : null,
            $n['sets'] ? (count($n['sets']) === 1 ? 'un ad set sin su número B (' : count($n['sets']) . ' ad sets sin su número B (') . $list($n['sets'])
                . '): ponle el nombre de su batch, «B#### ÁNGULO» (está en Cola y banca)' : null,
            $n['ads'] ? (count($n['ads']) === 1 ? 'un anuncio sin su código C (' : count($n['ads']) . ' anuncios sin su código C (') . $list($n['ads'])
                . '): ponle a cada uno el nombre de su pieza, «C#### ÁNGULO» (está en Cola y banca)' : null,
        ]));

        return ['key' => "names:{$n['campaign_meta_id']}", 'product_id' => (int) ($n['product_id'] ?? 0), 'product' => (string) ($n['product'] ?? $n['campaign']),
            'urgency' => null, 'city' => null, 'kind' => 'names', 'label' => 'Nombres', 'tone' => 'warning',
            'text' => ucfirst(implode('; ', $parts)) . '.', 'where' => self::q($n['campaign']), 'title' => 'Renombra en Meta: ' . implode('; ', $parts) . '.',
            'steps' => [], 'why' => 'Sin su nombre, el sistema no sabe de qué creativo, batch o campaña se trata', 'detail' => [], 'money' => null,
            'severity' => self::SEVERITY['names'], 'group' => self::GROUP['names']];
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
        // .89: con los nombres exactos de cada ad set y sus anuncios
        $upload = "crea los {$n} ad sets: " . implode('; ', array_map(fn ($b) => self::adsetWithAds((string) ($b['adset'] ?? $b['code']), $b['ads'] ?? []), $t['batches']))
            . ' (sus videos, aquí abajo).';
        // .124: el gasto mínimo de cada ad set nuevo y los parámetros de URL de sus anuncios
        $c = $t['target'] ?? null;
        if ($t['campaigns'] !== []) {
            $title = "Lanza los ángulos nuevos de {$short}: {$codes}";
            $why = "Ronda {$t['round']} del testeo: sus {$n} batches están listos en la banca";
            $mins = array_values(array_unique(array_filter(array_map(fn ($x) => CampaignNameTargets::parse($x['name'] ?? null)['target_cpa'] ?? null, $t['campaigns']))));
            $steps = ['En cada campaña de ' . $short . ' (' . CreativeCalls::join(array_map(fn ($c) => self::q($c['name']), $t['campaigns'])) . '), ' . $upload,
                'No toques los anuncios que ya están: los batches del testeo van en sus propios ad sets.',
                ...self::newAdSteps($n, count($mins) === 1 ? [(float) $mins[0], 'el CPA del nombre de la campaña'] : ($mins === [] && $c ? [(float) $c, 'tu C'] : null))];
        } else {
            $title = "Lanza el testeo de {$short}: {$codes}";
            $why = "Producto nuevo: sus {$n} batches del testeo están listos en la banca";
            $steps = array_values(array_filter([
                $t['linked'] ? null : "Antes: pásale a tu asistente el link de {$short} en Shopify (así sus pedidos se suman a {$short}).",
                $c ? null : "Antes: pon el CPA de referencia de {$short} en Meta Ads › Productos y objetivos (sin él no se puede juzgar el testeo).",
                // .118: primero la landing
                ($t['landing_pending'] ?? false) ? "Antes: termina la landing de {$short} y márcala en la misión de producto nuevo." : null,
                $t['cities'] ? 'Crea una campaña CBO en cada ciudad, con estos nombres: '
                    . CreativeCalls::join(array_map(fn ($city) => self::q(\App\Services\Meta\Naming::campaign($t['product'], $city, $t['date'] ?? 'today')), $t['cities'])) . '.'
                    : 'Crea una campaña CBO en cada ciudad donde vendes, con el nombre ' . self::q(\App\Services\Meta\Naming::product($t['product']) . ' | CIUDAD | CBO | '
                        . date('d/m/Y', strtotime($t['date'] ?? 'today'))) . ' (CIUDAD: CCS, CBOBO, MCAY…).',
                'En cada una, ' . $upload,
                $c ? 'Presupuesto de arranque por campaña: entre ' . AdRules::money($c * $n * $t['min']) . ' y ' . AdRules::money($c * $n * $t['max'])
                    . " por día (tu regla: C × {$n} batches × " . AdRules::num($t['min']) . ' a ' . AdRules::num($t['max']) . ').' : null,
                ...self::newAdSteps($n, $c ? [(float) $c, 'tu CPA de referencia'] : null),
            ]));
        }

        return ['key' => "{$t['product_id']}:test:{$t['round']}", 'product_id' => $t['product_id'], 'product' => $t['product'], 'urgency' => null,
            'city' => null, 'kind' => 'launch_test', 'label' => 'Testeo', 'tone' => 'success', 'text' => "{$why}.", 'where' => $short,
            'title' => $title, 'steps' => $steps, 'batches' => array_values(array_map('strval', array_column($t['batches'], 'code'))), 'copy' => self::urlTags(), 'why' => $why,
            'detail' => [], 'money' => null, 'severity' => self::SEVERITY['launch_test'], 'group' => self::GROUP['launch_test']];
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
            in_array($kind, ['decrease', 'step_down'], true) && $from && $to => ['cut' => round($from - $to, 2), 'add' => null],
            in_array($kind, ['pause_city', 'product_off'], true) && $budget => ['cut' => round((float) $budget, 2), 'add' => null],
            $kind === 'relaunch' && $to => ['cut' => null, 'add' => round((float) $to, 2)],
            default => null,
        };
    }

    /** La clase de cosa que pide la regla de una ciudad ("No se vende aquí" es pausarla; `.125`: "Producto en pausa", también). */
    private static function kind(array $a): string
    {
        return $a['key'] !== 'none' ? $a['key'] : (['No se vende aquí' => 'not_sold', 'Producto en pausa' => 'product_off'][$a['label']] ?? 'none');
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
     * @return array{title: string, steps: list<string>, batches: list<string>, copy: ?array{label: string, text: string}}
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
        $launchSteps = self::launchSteps($launch, self::newMin($campaign, $n));
        $pct = $from && $to ? ' (' . ($to > $from ? '+' : '−') . AdRules::num(abs(round(($to / $from - 1) * 100))) . ' %)' : '';
        $switchOff = ['Apágala con el interruptor de la izquierda.'];

        [$title, $steps] = match ($kind) {
            'scale' => [$to ? 'Sube el presupuesto a ' . AdRules::money($to) . ' por día' : 'Sube el presupuesto',
                [$open, $from && $to ? 'Cambia el presupuesto diario de ' . AdRules::money($from) . ' a ' . AdRules::money($to) . "{$pct}." : (string) $a['proposal']]],
            'decrease' => [$to ? 'Baja el presupuesto a ' . AdRules::money($to) . ' por día' : 'Baja el presupuesto',
                [$open, $from && $to ? 'Cambia el presupuesto diario de ' . AdRules::money($from) . ' a ' . AdRules::money($to) . "{$pct}." : (string) $a['proposal']]],
            // .123: entre C y BE, con el batch nuevo no volvió a C: se devuelve la última subida
            'step_down' => [$to ? 'Devuelve la última subida: baja el presupuesto a ' . AdRules::money($to) . ' por día' : 'Devuelve la última subida',
                [$open, $from && $to ? 'Cambia el presupuesto diario de ' . AdRules::money($from) . ' a ' . AdRules::money($to) . "{$pct}." : (string) $a['proposal'],
                    'No lances batches hoy: un cambio grande a la vez.']],
            // .125: si es por los 14 días sin dejar dinero, lo dice
            'pause_city' => [($a['chronic'] ?? null) ? "Pausa la campaña: {$a['chronic']['days']} días sin dejar dinero" : 'Pausa la campaña', [$open, ...$switchOff]],
            'not_sold' => ['Pausa la campaña: ahí no se vende', [$open, ...$switchOff]],
            // .125: el producto está en pausa o abandonado y la campaña sigue gastando
            'product_off' => ['Pausa la campaña: el producto está en pausa', [$open, ...$switchOff]],
            // .117: se prende solo con los batches nuevos
            'relaunch' => ["Prende esta campaña con {$go}", [$open, 'Deja apagados los ad sets y los anuncios que ya estaban: esta vez solo van estos ' . count($launch) . '.', ...$launchSteps,
                'Pon el presupuesto de arranque entre ' . AdRules::money((float) $to) . ' y ' . AdRules::money((float) $from) . ' por día (tu regla: C × ' . count($launch) . ' batches × 2,5 a 3).', 'Prende la campaña.']],
            'left_city' => ['Te recomiendo dejar el producto aquí: volvió a perder con batches nuevos. Lo decides tú', []],
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

        // .102: los de la banca que lanza (podar e intervenir; en liquidación no entran creativos)
        $batches = (in_array($kind, self::FEEDS, true) || $kind === 'relaunch') && $mode !== 'liquidation' ? self::bankCodes($launch) : [];

        // .124: los parámetros de URL para copiar, si la misión lanza algo
        $launches = $launch !== [] && (in_array($kind, self::FEEDS, true) && $mode !== 'liquidation' || $kind === 'relaunch');

        return ['title' => $title, 'steps' => array_values(array_filter($steps, fn ($s) => $s !== '')), 'batches' => $batches, 'copy' => $launches ? self::urlTags() : null];
    }

    /**
     * `.87`: cuántos batches lanzar en una ciudad. Una campaña prendida nunca con menos de 2 batches vivos (dueño: "para
     * darle un poco más de chance al algoritmo y que el presupuesto… no le pegue tan fuerte a ese batch nuevo"); si la
     * ciudad pide creativos (podar o intervenir), por lo menos 1. Nada en liquidación ni donde se pausa. Los análisis de
     * antes de la .87 no traen los vivos: solo el de la banca, como antes.
     */
    public static function need(string $kind, ?array $n, string $mode, bool $cooling = false): int
    {
        if ($n === null || $mode === 'liquidation' || in_array($kind, self::NO_BATCHES, true)) {
            return 0;
        }
        $live = $n['live'] ?? null;
        // .111: en las 24 horas después de un cambio grande, un batch solo si quedan menos de 2 vivos
        if (in_array($kind, self::FEEDS, true) && !$cooling) {
            return max(1, self::MIN_LIVE - (int) ($live ?? self::MIN_LIVE));
        }

        return $live === null ? 0 : max(0, self::MIN_LIVE - (int) $live);
    }

    /**
     * `.123`: la ciudad espera a ver cómo resultó su último cambio grande: las 24 horas de la `.111` o, entre C y BE, los días
     * cerrados después de un batch o de devolver una subida. Mientras tanto no se lanza otro batch (salvo menos de 2 vivos).
     */
    public static function holds(array $action): bool
    {
        return isset($action['cooldown']) || !empty($action['ladder']['hold']);
    }

    /** `.87`: lo que puede lanzar la ciudad, en orden; los análisis de antes solo traían el primero de la banca. */
    private static function offers(?array $n): array
    {
        return $n['offers'] ?? (($n['bank'] ?? null) ? [['code' => (string) $n['bank'], 'how' => 'bank']] : []);
    }

    /**
     * `.87`: cómo lanzar cada batch: el de la banca, o duplicar el ad set de la ciudad donde ya salió. `.124`: y dos pasos
     * más: el gasto mínimo de cada ad set nuevo ($min: [monto, de dónde sale]) y los parámetros de URL de sus anuncios.
     *
     * @return list<string>
     */
    private static function launchSteps(array $launch, ?array $min = null): array
    {
        if ($launch === []) {
            return [];
        }
        // .89: con los nombres exactos (el ad set y sus anuncios), para que todos hablen de lo mismo
        return [...array_map(fn ($o) => match (true) {
            ($o['how'] ?? 'bank') === 'copy' => 'Duplica el ad set ' . self::q((string) $o['adset']) . ' de '
                . (($o['campaign'] ?? null) ? 'la campaña ' . self::q($o['campaign']) : ($o['city'] ?? 'la otra ciudad'))
                . ' en esta campaña, con el mismo nombre (sin « - Copia»)' . (($o['city'] ?? null) && ($o['verdict'] ?? null) ? " (en {$o['city']}: " . mb_strtolower($o['verdict']) . ')' : '') . '.',
            !empty($o['adset']) => 'Crea el ad set ' . self::adsetWithAds($o['adset'], $o['ads'] ?? []) . ' (sus videos, aquí abajo).',
            default => 'Lanza el batch ' . self::q((string) $o['code']) . ' de la banca (sus videos, aquí abajo).',
        }, $launch), ...self::newAdSteps(count($launch), $min)];
    }

    /**
     * `.124` (dueño, 2026-11-09: "todos los batches nuevos los colocamos con un gasto mínimo de 4 o de 5 dólares, dependiendo de
     * cuál es el CPA con el que nosotros estamos creando esta campaña. Es decir, el CPA, el nombre de la campaña"; y los
     * parámetros de URL, "sí, en los anuncios nuevos"): los dos pasos que cierran cada lanzamiento.
     *
     * @param ?array{0: float, 1: string} $min el monto y de dónde sale
     * @return list<string>
     */
    private static function newAdSteps(int $sets, ?array $min): array
    {
        $each = $sets === 1 ? 'al ad set nuevo' : 'a cada ad set nuevo';

        return [
            $min ? "Ponle {$each} un gasto mínimo diario de " . AdRules::money($min[0]) . " ({$min[1]}). Va solo mientras se prueba."
                : "Ponle {$each} un gasto mínimo diario igual al CPA del nombre de su campaña (o a tu C, si no lo trae). Va solo mientras se prueba.",
            'En cada anuncio nuevo, abre «Seguimiento» y pega los parámetros de URL (botón «Copiar parámetros», aquí abajo).',
        ];
    }

    /** `.124`: el gasto mínimo de un batch nuevo: el CPA del nombre de la campaña o, si no lo trae, la C de esa ciudad. @return ?array{0: float, 1: string} */
    private static function newMin(?string $campaign, ?array $n): ?array
    {
        if (($name = CampaignNameTargets::parse($campaign)['target_cpa'] ?? null) !== null) {
            return [(float) $name, 'el CPA del nombre de la campaña'];
        }

        return ($n['c'] ?? null) ? [(float) $n['c'], 'tu C aquí'] : null;
    }

    /** `.124`: los parámetros de URL de un anuncio nuevo (los de la `.53`: dicen de qué anuncio viene cada pedido). */
    public static function urlTags(): array
    {
        return ['label' => 'Copiar parámetros', 'text' => UploadRules::urlTags(null)];
    }

    /**
     * `.124` (dueño, 2026-11-09: "cuando un batch ya deja de estar en estatus de testeo, debe quitarse el gasto mínimo del
     * batch… para darle más libertad a Meta"): los batches vivos de una campaña que ya se probaron y siguen con gasto mínimo.
     * `.130` (dueño, 2026-11-10: "para asegurarnos de no empeorar el rendimiento"): solo los que no son el mejor y no venden en
     * la C; el mejor y los que venden en la C se quedan con su mínimo, y la misión lo dice.
     */
    private static function minSpend(int $productId, array $c, array $n, string $short): array
    {
        $off = $n['mins']['off'];
        $keep = $n['mins']['keep'] ?? [];
        $stays = $n['mins']['stays'] ?? [];
        $cpaOf = fn (array $o) => ($o['cpa'] ?? null) !== null ? AdRules::money($o['cpa']) . ' por compra en 7 días' . (($n['c'] ?? null) ? ', encima de tu C (' . AdRules::money($n['c']) . ')' : '')
            : 'sin compras en 7 días';
        $count = count($off);
        $total = array_sum(array_column($off, 'min'));
        $same = count(array_unique(array_map(fn ($o) => (string) $o['min'], $off))) === 1;
        $names = CreativeCalls::join(array_map(fn ($o) => self::q($o['adset']) . ($same ? '' : ' (' . AdRules::money($o['min']) . ')'), $off));
        $budget = ($n['budget'] ?? null) ? ' de ' . AdRules::money($n['budget']) : '';

        return ['key' => "{$productId}:{$c['campaign_meta_id']}:min_spend", 'city' => $c['city'], 'kind' => 'min_spend', 'label' => 'Gasto mínimo', 'tone' => 'info',
            'text' => 'Quitar el gasto mínimo de ' . CreativeCalls::join(array_column($off, 'adset')) . ': ya se ' . ($count === 1 ? 'probó y no vende en tu C.' : 'probaron y no venden en tu C.'),
            'where' => $short . ($c['city'] ? " · {$c['city']}" : ''),
            'title' => $count === 1 ? "Quita el gasto mínimo de {$off[0]['adset']}: ya se probó y no vende en tu C" : "Quita el gasto mínimo de {$count} batches que ya se probaron y no venden en tu C",
            'steps' => array_values(array_filter([
                self::open($c['campaign'] ?? null, $short, $c['city']),
                ($count === 1 ? 'Edita el conjunto de anuncios ' : 'Edita cada uno de estos conjuntos de anuncios: ') . $names . '.',
                'En «Presupuesto y calendario» › «Límites de gasto del conjunto de anuncios», borra el mínimo diario' . ($same ? ' (' . AdRules::money($off[0]['min']) . ')' : '') . ' y publica.',
                $stays ? 'Deja el mínimo de ' . CreativeCalls::join(array_map(fn ($o) => self::q($o['adset']), $stays)) . ': '
                    . (count($stays) === 1 ? ($stays[0]['best'] ? 'es el mejor de la ciudad.' : 'vende en tu C.') : 'el mejor y los que venden en tu C no se tocan.') : null,
                $keep ? 'No toques el de ' . CreativeCalls::join(array_map(fn ($o) => self::q($o['adset']), $keep)) . ': todavía se ' . (count($keep) === 1 ? 'prueba.' : 'prueban.') : null,
            ])),
            'batches' => [],
            'why' => AdRules::money($total) . "{$budget} por día " . ($count === 1 ? 'está amarrado a un batch que ya se probó y no vende en tu C' : 'están amarrados a batches que ya se probaron y no venden en tu C'),
            'detail' => [...array_map(fn ($o) => "{$o['adset']}: mínimo " . AdRules::money($o['min']) . " por día · {$o['why']} · " . $cpaOf($o) . '.', $off),
                ...array_map(fn ($o) => "Se queda: {$o['adset']} · {$o['why']}.", $stays),
                'Tu regla: el gasto mínimo es solo mientras el batch se prueba, y no se quita del mejor (el de CPA más bajo en 7 días) ni de los que venden en tu C o debajo, para no empeorar el rendimiento. Sin él, Meta pasa esa plata a los que más venden.',
                'No es un cambio grande: no hace falta esperar 24 horas.'],
            'money' => null];
    }

    /**
     * `.102` (dueño, 2026-11-04: "cuando me diga para lanzar un batch, me debería dejar descargar los creativos desde esa
     * misma ventana"): los batches de la banca que lanza una misión, para bajar sus videos ahí mismo. Duplicar el ad set
     * de otra ciudad no se baja: ya está en Meta.
     *
     * @return list<string>
     */
    private static function bankCodes(array $launch): array
    {
        return array_values(array_unique(array_map(fn ($o) => (string) $o['code'], array_filter($launch, fn ($o) => ($o['how'] ?? 'bank') !== 'copy'))));
    }

    /** `.89`: "«B0110 POSP» con los anuncios «C0457 POSP», «C0458 POSP» y «C0459 POSP»". */
    private static function adsetWithAds(string $adset, array $ads): string
    {
        return self::q($adset) . ($ads ? ' con ' . (count($ads) === 1 ? 'el anuncio ' : 'los anuncios ') . CreativeCalls::join(array_map(fn ($a) => self::q($a), $ads)) : '');
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
            'steps' => $launch ? [self::open($c['campaign'] ?? null, $short, $c['city']), ...self::launchSteps($launch, self::newMin($c['campaign'] ?? null, $n))] : [],
            'batches' => self::bankCodes($launch), 'copy' => $launch ? self::urlTags() : null,
            'why' => $live === 0 ? 'No queda ningún batch vivo: la campaña no tiene anuncios que mostrar'
                : "Solo queda 1 batch vivo: {$budget} cae todo en él; con 2, Meta reparte",
            'detail' => array_values(array_filter([self::liveLine($n), $launch === [] ? 'Cuando haya un batch en la banca, te sale como misión.' : null])),
            'money' => null] + ($launch === [] ? ['group' => 'aviso'] : []);
    }

    /**
     * `.125` (dueño, 2026-11-09: "sí debemos establecer un límite para soltar producto, dependiendo de su CPA y si hicimos
     * cambios y no mejoró"): el producto entero lleva 14 días sin dejar dinero, con batches nuevos en ese tiempo. Es una
     * decisión suya: el sistema no pausa nada solo, y con "No estoy de acuerdo" no se le vuelve a preguntar en esos días.
     *
     * @param array{days: int, spend: float, purchases: float, profit: float, batches: int, cities: list<array>} $d
     */
    private static function drop(int $productId, string $short, array $d): array
    {
        $left = $d['profit'] < 0 ? 'dejó −' . AdRules::money(abs($d['profit'])) : 'no dejó nada';
        $why = "En {$d['days']} días gastó " . AdRules::money($d['spend']) . " y {$left}, con " . ($d['batches'] === 1 ? '1 batch nuevo' : "{$d['batches']} batches nuevos");

        return ['key' => "{$productId}:drop_product", 'city' => null, 'kind' => 'drop_product', 'label' => 'Soltar el producto', 'tone' => 'error', 'text' => "{$why}.", 'where' => $short,
            'title' => "Decide si sueltas {$short}: {$d['days']} días sin dejar dinero",
            'steps' => ["Si lo sueltas: dile a tu asistente «pausa {$short}». Después te sale la misión de apagar sus campañas.",
                "Si le das otra oportunidad: dile a tu asistente «pon {$short} en testeo»: se piden batches de ángulos nuevos.",
                "Si prefieres esperar: toca «No estoy de acuerdo» y no te lo vuelvo a preguntar en {$d['days']} días."],
            'why' => $why,
            'detail' => [...array_map(fn ($c) => ($c['city'] ?? 'Sin ciudad') . ': gastó ' . AdRules::money($c['spend']) . ' · ' . AdRules::num($c['purchases']) . ' compras'
                . ($c['purchases'] > 0 ? ' · ' . AdRules::money($c['spend'] / $c['purchases']) . ' por compra' : '') . ' · BE ' . AdRules::money($c['be']) . '.', $d['cities']),
                'Lo que dejó = compras de Meta × tu BE − gasto. El sistema no pausa nada solo: lo decides tú.'],
            'money' => null];
    }

    /**
     * `.125`: los datos de Meta son viejos (la última lectura fue hace más de $hours horas): un aviso, arriba de los demás,
     * para no decidir con números de ayer. PHP puro.
     */
    public static function stale(?string $last, string $now, float $hours): ?array
    {
        $ago = $last !== null ? (strtotime($now) - strtotime($last)) / 3600 : null;
        if ($ago !== null && $ago < $hours) {
            return null;
        }
        $text = $last === null ? 'Meta todavía no se ha leído nunca'
            : 'La última lectura de Meta fue hace ' . (int) floor($ago) . ' horas (el ' . date('d/m', strtotime($last)) . ' a las ' . date('H:i', strtotime($last)) . ', hora de Venezuela)';

        return ['key' => 'stale:' . date('Y-m-d', strtotime($now)), 'product_id' => 0, 'product' => 'Meta', 'urgency' => null, 'city' => null, 'kind' => 'stale', 'label' => 'Datos viejos',
            'tone' => 'warning', 'text' => "{$text}.", 'where' => 'Meta',
            'title' => "{$text}: estas misiones pueden estar hechas con números viejos. Revisa la conexión en Meta Ads › Configuración y vuelve a analizar",
            'steps' => [], 'why' => null, 'detail' => [], 'money' => null, 'severity' => self::SEVERITY['stale'], 'group' => self::GROUP['stale']];
    }

    /** `.117`: el producto se quedó sin ninguna ciudad prendida y no alcanzan los batches para prender una. */
    private static function noCity(int $productId, string $short, array $n): array
    {
        $why = "Tiene {$n['have']} de los {$n['need']} batches sin publicar que hacen falta para volver a prender una ciudad, y sin ciudades prendidas no se piden más";

        return ['key' => "{$productId}:no_city", 'city' => null, 'kind' => 'no_city', 'label' => 'Sin ciudades', 'tone' => 'warning', 'text' => "{$why}.", 'where' => $short,
            'title' => 'Se quedó sin ninguna ciudad prendida: decide qué hacer',
            'steps' => ["Si ya no lo vas a vender: dile a tu asistente «pausa {$short}».", "Si quieres volver a lanzarlo: dile a tu asistente «pon {$short} en testeo»: se piden los batches que faltan, contando los que ya están hechos."],
            'why' => $why, 'detail' => [], 'money' => null];
    }

    /**
     * `.120` (dueño, 2026-11-09: "nunca podemos permitirnos perder dinero sin una razón buena justificada"): sus campañas con C
     * y sin BE deciden con uno estimado (C × be_estimate_mult); la misión es poner el real, donde ya puso la C.
     *
     * @param list<array> $cities las ciudades del análisis con numbers.be_estimated (el multiplicador)
     */
    private static function noBe(int $productId, string $short, array $cities): array
    {
        $n = $cities[0]['numbers'];
        $mult = rtrim(rtrim(number_format((float) $n['be_estimated'], 2, ',', '.'), '0'), ',');
        $names = array_values(array_filter(array_map(fn ($c) => $c['campaign'] ?? null, $cities)));
        $why = 'Sin BE no hay límite para frenar; mientras tanto se usa ' . AdRules::money($n['be']) . " ({$mult} × tu C de " . AdRules::money($n['c']) . ')';
        $steps = ($n['source'] ?? null) === 'name'
            ? ['En Ads Manager, agrega «| BE $X» con tu BE real al nombre de ' . ($names ? CreativeCalls::join(array_map(fn ($x) => self::q($x), $names)) : "las campañas de {$short}") . '.',
                "O ponlo una sola vez en Meta Ads › Productos y objetivos: el Target CPA y el Break-even CPA de {$short}."]
            : ['Abre Meta Ads › Productos y objetivos.', "Ponle a {$short} su Break-even CPA (ya tiene el Target CPA) y guarda."];

        return ['key' => "{$productId}:no_be", 'city' => null, 'kind' => 'no_be', 'label' => 'Sin break-even', 'tone' => 'warning', 'text' => "{$why}.", 'where' => $short,
            'title' => 'Pon el BE real: hoy se decide con uno estimado', 'steps' => $steps, 'why' => $why,
            'detail' => array_map(fn ($c) => ($c['city'] ?? 'Sin ciudad') . ': C ' . AdRules::money($c['numbers']['c']) . ' · BE estimado ' . AdRules::money($c['numbers']['be']) . '.', $cities),
            'money' => null];
    }

    /** `.87`: el aviso de una ciudad que pide creativos y hoy no tiene qué podar ni qué lanzar. */
    private static function waiting(string $kind, ?array $n, ?array $cool = null, ?array $ladder = null): string
    {
        $live = $n['live'] ?? null;
        // .123: entre C y BE, un cambio a la vez: "Espera hasta el 13/11: el 09/11 entró el batch «B0118»…"
        if (($ladder['hold'] ?? false) && ($live === null || $live >= self::MIN_LIVE)) {
            return 'Espera hasta el ' . date('d/m', strtotime($ladder['until'])) . ': el ' . date('d/m', strtotime($ladder['since'])) . " {$ladder['what']} y se le dan "
                . ((int) $ladder['days'] === 1 ? '1 día cerrado' : (int) $ladder['days'] . ' días cerrados') . " para ver si vuelve a tu objetivo. Si no, toca {$ladder['next']}";
        }
        if ($cool && ($live === null || $live >= self::MIN_LIVE)) {
            return self::cooling($cool) . ': ' . lcfirst(self::cooledBy($cool));
        }
        $state = $live !== null && $live < self::MIN_LIVE ? self::alive((int) $live) . '. ' : '';

        return $state . ($kind === 'intervene' ? 'No toques el presupuesto y espera un batch nuevo' : 'Espera un batch nuevo para meter creativos')
            . ': no hay en la banca. Cuando haya uno, te sale como misión.';
    }

    /** `.111`: "Espera hasta el 07/11 a las 20:10 para subir el presupuesto". */
    private static function cooling(array $cool): string
    {
        return 'Espera hasta el ' . date('d/m', strtotime($cool['until'])) . ' a las ' . date('H:i', strtotime($cool['until'])) . ' para ' . $cool['held'];
    }

    /** `.111`: "El 06/11 a las 20:10 entró el batch «B0110 POSP»; tu regla: 24 horas para ver cómo resultó". */
    private static function cooledBy(array $cool): string
    {
        return 'El ' . date('d/m', strtotime($cool['at'])) . ' a las ' . date('H:i', strtotime($cool['at'])) . ' ' . $cool['what']
            . '; tu regla: ' . AdRules::num($cool['hours']) . ' horas para ver cómo resultó (hora de Venezuela)';
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
     * @param list<array{city: ?string, campaign: ?string, offer: array, why: ?string, c?: ?float}> $cities
     */
    public static function launchReady(int $productId, string $product, string $code, array $cities, string $at): array
    {
        $short = self::short($product);
        $names = CreativeCalls::join(array_values(array_filter(array_column($cities, 'city'))));
        $steps = [];
        foreach ($cities as $c) {
            $steps[] = self::open($c['campaign'], $short, $c['city']);
            array_push($steps, ...self::launchSteps([$c['offer']], self::newMin($c['campaign'], ['c' => $c['c'] ?? null])));
        }
        $whys = array_values(array_filter(array_map(fn ($c) => $c['city'] && $c['why'] ? "{$c['city']} {$c['why']}" : null, $cities)));

        return ['key' => "{$productId}:ready:{$code}", 'product_id' => $productId, 'product' => $product, 'urgency' => null,
            'city' => count($cities) === 1 ? $cities[0]['city'] : null, 'kind' => 'launch_ready', 'label' => 'Lanzar', 'tone' => 'success',
            'text' => "{$code} llegó a la banca a las {$at} (hora de Venezuela).", 'where' => $short . ($names !== '' ? " · {$names}" : ''),
            'title' => "Lanza {$code}: ya llegó a la banca", 'steps' => $steps, 'batches' => self::bankCodes(array_column($cities, 'offer')), 'copy' => self::urlTags(),
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
        if (in_array($kind, ['not_sold', 'product_off', 'relaunch', 'left_city'], true) || $n === null) {
            return match ($kind) {
                'not_sold' => 'Está marcado como no disponible en esa ciudad',
                'product_off' => 'Gastó ' . AdRules::money((float) ($n['spend3'] ?? 0)) . ' en 3 días con el producto en pausa',
                'relaunch' => 'Hay ' . count($n['offers'] ?? []) . ' batches que nunca se publicaron aquí',
                default => self::firstSentence($a['reading']),
            };
        }
        $cpa = $n['purchases3'] > 0 ? AdRules::money($n['spend3'] / $n['purchases3']) . ' por compra' : AdRules::money($n['spend3']) . ' gastados sin compras';
        $c = AdRules::money($n['c']);
        $be = $n['be'] ? AdRules::money($n['be']) : null;
        // .120: si el BE es estimado, se dice
        $limit = !empty($n['be_estimated']) ? 'límite estimado' : 'límite';
        $streak = (int) ($n['streak'] ?? 0);
        $days = $streak > 0 ? " · {$streak} " . ($streak === 1 ? 'día' : 'días') . ' encima' : '';
        // .125: "En 14 días gastó $290,10 a $8,53 por compra; tu límite es $7,49"
        if ($kind === 'pause_city' && ($ch = $a['chronic'] ?? null) && $be) {
            return "En {$ch['days']} días gastó " . AdRules::money($ch['spend']) . ($ch['purchases'] > 0 ? ' a ' . AdRules::money($ch['spend'] / $ch['purchases']) . ' por compra' : ' sin compras')
                . "; tu {$limit} es {$be}";
        }

        return match ($kind) {
            // .126: "… · deja unos $350,00 por día"
            'scale' => (($a['from'] ?? null) && ($a['to'] ?? null) ? '+' . AdRules::num(round(($a['to'] / $a['from'] - 1) * 100)) . ' % · ' : '')
                . "{$cpa}; tu objetivo es {$c}" . (($n['gain3'] ?? 0) > 0 ? ' · deja unos ' . AdRules::money($n['gain3'] / 3) . ' por día' : ''),
            'hold_prune' => $be ? "{$cpa}: entre tu objetivo ({$c}) y tu {$limit} ({$be})" : "{$cpa}; tu objetivo es {$c}",
            // .123: "−17 % · $6,55 por compra: entre tu objetivo ($4,44) y tu límite ($7,61)"
            'step_down' => (($a['from'] ?? null) && ($a['to'] ?? null) ? '−' . AdRules::num(abs(round(($a['to'] / $a['from'] - 1) * 100))) . ' % · ' : '')
                . ($be ? "{$cpa}: entre tu objetivo ({$c}) y tu {$limit} ({$be})" : "{$cpa}; tu objetivo es {$c}"),
            default => ($be ? "{$cpa}; tu {$limit} es {$be}" : "{$cpa}; tu objetivo es {$c}") . $days,
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
            // .120: si el BE es estimado, la referencia es solo de la C
            !empty($n['be_estimated'])
                ? 'Tu objetivo (C) aquí es ' . AdRules::money($n['c']) . ($source ? " ({$source})" : '') . '. Todavía no tiene BE: se usa ' . AdRules::money($n['be']) . ', estimado.'
                : 'Tu objetivo (C) aquí es ' . AdRules::money($n['c']) . ($n['be'] ? ' y tu límite para no perder (BE) es ' . AdRules::money($n['be']) : '')
                    . ($source ? " ({$source})" : '') . '.',
            $n['budget'] ? 'Presupuesto: ' . AdRules::money($n['budget']) . ' por día.' : null,
            // .126: lo que dejó, en dólares (con el BE estimado, se dice)
            ($n['gain3'] ?? null) !== null ? 'En 3 días ' . ($n['gain3'] >= 0 ? 'dejó unos ' : 'perdió unos ') . AdRules::money(abs($n['gain3'])) . ' (compras × tu BE'
                . (!empty($n['be_estimated']) ? ' estimado' : '') . ' − gasto): unos ' . AdRules::money(abs($n['gain3']) / 3) . ' por día.' : null,
        ]));
    }

    private static function firstSentence(string $t): string
    {
        return rtrim(preg_split('/(?<=\.)\s/u', trim($t))[0] ?? $t, '.');
    }
}
