<?php

namespace App\Services\Meta\Decisions;

use App\Models\MetaCreative;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\Decisions\Rules\PriceRules;
use App\Services\Meta\Decisions\Rules\ProductWorth;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Products\AngleRest;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Los veredictos de las reglas del dueño (Etapa 5, paso 2a), por producto: cada batch (ad set), anuncio y creativo, y lo
 * que se ve de cada campaña (saturación, CTR bajo en toda la ciudad). Solo lee: lo único que escribe son los estados
 * automáticos de los creativos (applyStatuses), y nunca uno puesto a mano. Nada se cambia en Meta.
 */
class MetaDecisions
{
    /** Con menos impresiones que esto, la ventana no alcanza para leer el video. */
    public const MIN_IMPRESSIONS = 1000;

    /** Qué iterar primero, en palabras del dueño. */
    private const ITERATE = ['hook' => 'el hook (los primeros segundos)', 'after_hook' => 'lo que viene justo después del hook',
        'middle' => 'el medio del video (el ritmo y la duración)', 'close' => 'el cierre (CTA)', 'click' => 'la oferta y el llamado a la acción',
        'landing' => 'la landing', 'format' => 'el formato'];

    public function __construct(
        private AdFacts $facts = new AdFacts(),
        private DecisionSettings $settings = new DecisionSettings(),
        private ?OpsFacts $ops = null,
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, array> por producto: campaigns, adsets, ads, creatives (cada uno con su veredicto)
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        sort($productIds);
        if (!$productIds) {
            return [];
        }
        $s = $this->settings->get();
        $synced = DB::table('meta_insights_daily')->max('updated_at');
        $bank = DB::table('creative_batches')->max('updated_at'); // paso 4a: la banca cambia lo que se pide
        $key = 'meta-decisions:' . md5(json_encode([$productIds, $s, $synced, $bank, now()->toDateString()]));

        return Cache::remember($key, 600, fn () => $this->compute($productIds, $s));
    }

    /** Lo mismo, sin guardar en caché (para el comando y las pruebas). */
    public function compute(array $productIds, ?array $s = null): array
    {
        $s ??= $this->settings->get();
        $rules = new AdRules($s);
        $facts = $this->facts->load($productIds);
        // Paso 2b: el historial de cada campaña y lo del producto (stock, precio, finanzas)
        $ops = $this->ops ??= new OpsFacts();
        $campaignIds = [];
        $cityIds = [];
        foreach ($facts as $f) {
            foreach ($f['campaigns'] as $id => $c) {
                $campaignIds[] = (string) $id;
                if ($c['city_id'] !== null) {
                    $cityIds[] = $c['city_id'];
                }
            }
        }
        $history = $ops->campaigns($campaignIds);
        $products = $ops->products(array_keys($facts), array_values(array_unique($cityIds)));
        // .139: la muestra con la que se juzga cada producto (Bueno, Regular o Malo)
        $worth = $ops->worth(array_keys($facts), $s);
        $out = [];
        foreach ($facts as $pid => $f) {
            $f = self::estimateBe($f, (float) $s['be_estimate_mult']);
            $out[$pid] = $this->product($f, $rules, $s);
            $out[$pid] = $this->plan($f, $out[$pid], $history, $products[$pid] ?? null, new CampaignRules($s), $s, $worth[$pid] ?? null);
        }

        return $out;
    }

    /**
     * `.120` (dueño, 2026-11-09: "nunca podemos permitirnos perder dinero sin una razón buena justificada"; ResistBarPro y
     * Releev gastaban con la C del nombre de la campaña y sin BE: nada las podía frenar): una campaña con C y sin BE decide
     * con uno estimado (C × be_estimate_mult) hasta que el dueño ponga el real. Queda marcada con el multiplicador. PHP puro.
     */
    public static function estimateBe(array $f, float $mult): array
    {
        foreach ($f['campaigns'] as $id => $c) {
            if (($c['target'] ?? null) && !($c['break_even'] ?? null)) {
                $f['campaigns'][$id]['break_even'] = round($c['target'] * $mult, 2);
                $f['campaigns'][$id]['break_even_estimated'] = $mult;
            }
        }

        return $f;
    }

    /**
     * `.117`: por ciudad, la campaña que se volvería a prender: la última que se apagó (si no hay otra prendida ahí y sigue en
     * Meta). PHP puro.
     *
     * @return array<int, string> ciudad => campaña
     */
    public static function toRelaunch(array $campaigns, array $history): array
    {
        $busy = [];
        foreach ($campaigns as $c) {
            if ($c['active'] && $c['city_id'] !== null) {
                $busy[$c['city_id']] = true;
            }
        }
        $best = [];
        foreach ($campaigns as $id => $c) {
            $id = (string) $id;
            if ($c['active'] || ($c['missing'] ?? false) || $c['city_id'] === null || isset($busy[$c['city_id']])) {
                continue;
            }
            $off = collect($history[$id]['status_events'] ?? [])->last(fn ($e) => $e['from'] === 'ACTIVE' && $e['to'] !== 'ACTIVE');
            $rank = [(string) ($off['at'] ?? ''), $id];
            if (!isset($best[$c['city_id']]) || $rank > $best[$c['city_id']]) {
                $best[$c['city_id']] = $rank;
            }
        }

        return array_map(fn ($r) => $r[1], $best);
    }

    /**
     * Los estados automáticos de los creativos: Winner, Fatigued, Loser, Archived o Testing. Los que puso el dueño a mano
     * no se tocan. Corre cada día a las 06:45 (meta:creative-statuses) y al volver un creativo a "automático".
     *
     * @return array{updated: int, kept_manual: int, unchanged: int}
     */
    public function applyStatuses(?array $productIds = null, ?array $creativeIds = null): array
    {
        $s = $this->settings->get();
        $stats = ['updated' => 0, 'kept_manual' => 0, 'unchanged' => 0];
        if (!$s['auto_statuses']) {
            return $stats;
        }
        $productIds ??= DB::table('meta_campaigns')->whereNotNull('product_id')->distinct()->pluck('product_id')->all();
        $now = now();
        foreach ($this->compute($productIds, $s) as $product) {
            $lastSpend = $this->lastSpend(array_keys($product['creatives']));
            foreach ($product['creatives'] as $id => $cr) {
                if ($creativeIds !== null && !in_array($id, $creativeIds, true)) {
                    continue;
                }
                $creative = MetaCreative::find($id);
                if (!$creative) {
                    continue;
                }
                if ($creative->status_source === 'manual' || ($creative->status !== null && $creative->status_source === null)) {
                    $stats['kept_manual']++;
                    continue;
                }
                [$status, $reason] = self::autoStatus($cr, $lastSpend[$id] ?? null, $s, $now->toDateString());
                if ($creative->status === $status && $creative->status_source === 'auto' && $creative->status_reason === $reason) {
                    $stats['unchanged']++;
                    continue;
                }
                $changed = $creative->status !== $status;
                $creative->forceFill(array_merge(
                    ['status' => $status, 'status_source' => 'auto', 'status_reason' => mb_substr($reason, 0, 255), 'status_auto_at' => $now],
                    $changed ? ['status_changed_at' => $now, 'status_changed_by' => null] : []
                ))->save();
                $stats['updated']++;
            }
        }

        return $stats;
    }

    /**
     * El estado automático de un creativo (PHP puro): Archived si no tiene anuncios activos ni gasto en 14 días; Loser
     * si pierde en todas sus ciudades; Fatigued si es un ganador que empezó a fallar por fatiga; Winner si es PROVEN en
     * alguna ciudad (también si falla por otra causa, como la subasta); si no, Testing.
     *
     * @return array{0: string, 1: string} estado y motivo
     */
    public static function autoStatus(array $cr, ?string $lastSpendDate, array $s, string $today): array
    {
        $daysSince = $lastSpendDate ? (int) round((strtotime($today) - strtotime($lastSpendDate)) / 86400) : null;
        if (!$cr['active'] && ($daysSince === null || $daysSince >= $s['archive_days'])) {
            return ['archived', 'Sin anuncios activos ni gasto hace ' . (int) $s['archive_days'] . ' días o más.'];
        }
        $v = $cr['verdict'];
        $causes = array_column($v['causes'] ?? [], 'key');

        return match (true) {
            $v['key'] === 'loser' => ['loser', $v['reading']],
            $v['key'] === 'failing' && in_array('fatigue', $causes, true) => ['fatigued', $v['reading']],
            in_array($v['key'], ['proven', 'failing'], true) => ['winner', $v['reading']],
            default => ['testing', $v['reading']],
        };
    }

    /**
     * `.65` (dueño, 2026-11-01): "ningún batch se elimina por sí solo si no han salido perdedores sus creativos… el batch
     * no se apaga por sí solo, a menos que los tres creativos que estén ahí sean perdedores". Si el batch, por sus totales,
     * es perdedor pero alguno de sus anuncios no perdió (un solo creativo se llevó el gasto), el batch sigue: se apaga solo
     * el anuncio perdedor. Si alguno de los demás va bien, el batch va bien (PHP puro).
     *
     * @param array $set el veredicto del ad set por sus totales
     * @param list<array{name: string, active: bool, verdict: array}> $ads sus anuncios
     */
    public static function batchVerdict(array $set, array $ads): array
    {
        $rest = array_values(array_filter($ads, fn ($a) => $a['verdict']['key'] !== 'loser'));
        if ($set['key'] !== 'loser' || $ads === [] || $rest === []) {
            return $set;
        }
        $order = ['proven', 'promising', 'intermediate', 'failing', 'evaluation', 'no_data', 'no_target'];
        $rank = fn (array $a) => ($i = array_search($a['verdict']['key'], $order, true)) === false ? 99 : $i;
        usort($rest, fn ($a, $b) => $rank($a) <=> $rank($b));
        $key = in_array($rest[0]['verdict']['key'], ['proven', 'promising'], true) ? $rest[0]['verdict']['key'] : 'evaluation';
        $lost = array_values(array_filter($ads, fn ($a) => $a['verdict']['key'] === 'loser'));
        $lostOn = array_column(array_filter($lost, fn ($a) => $a['active']), 'name');

        return ['key' => $key, 'label' => AdRules::STATES[$key]['label'], 'tone' => AdRules::STATES[$key]['tone'], 'data' => $set['data'] ?? '',
            'reading' => 'Por sus totales gastó como perdedor, pero no todos sus creativos perdieron: perdió ' . implode(', ', array_column($lost, 'name')) . '; '
                . implode(', ', array_map(fn ($a) => $a['name'] . ' ' . mb_strtolower($a['verdict']['label'] ?? AdRules::STATES[$a['verdict']['key']]['label'] ?? $a['verdict']['key']), $rest)) . '.',
            'proposal' => 'No apagar el batch: ' . ($lostOn ? 'apagar solo ' . implode(', ', $lostOn) . ' para que el gasto pase a los demás creativos.'
                : 'los demás creativos todavía se están probando.'),
            'causes' => [], 'kept' => true];
    }

    private function product(array $f, AdRules $rules, array $s): array
    {
        $campaigns = $f['campaigns'];
        $tracking = $f['tracking']['ratio'] ?? null;
        $out = ['product' => $f['product'], 'tracking' => $f['tracking'] ?? null, 'dates' => $f['dates'],
            'campaigns' => [], 'adsets' => [], 'ads' => [], 'creatives' => []];

        // Lo que se ve de cada campaña (ciudad): saturación, CTR bajo en todos sus anuncios y cuánto subió el CPM
        $cpmChange = [];
        foreach ($campaigns as $id => $c) {
            // .87: un número de Meta ("120210…") como llave de un arreglo llega como int; los de adentro son texto
            $id = (string) $id;
            $w3 = $c['windows']['3d'] ?? null;
            $cpmChange[$id] = MetaMetrics::change($w3['metrics']['cpm'] ?? null, $c['windows']['prev']['metrics']['cpm'] ?? null);
            $notes = [];
            $freq = $w3['metrics']['frequency'] ?? null;
            if ($rules->saturated($freq)) {
                // .123 (dueño, 2026-11-09: "solo aviso"): la frecuencia alta ya no frena la subida
                $notes[] = ['key' => 'saturated', 'label' => 'Frecuencia alta', 'text' => 'Frecuencia de 3 días ' . AdRules::num($freq)
                    . ': la misma gente está viendo mucho tus anuncios. Es solo un aviso, no frena la subida; conviene tener creativos de arriba del embudo listos.'];
            }
            $withData = array_filter($f['ads'], fn ($a) => $a['campaign_meta_id'] === $id && ($a['windows']['3d']['totals']['impressions'] ?? 0) >= self::MIN_IMPRESSIONS);
            if ($withData && !array_filter($withData, fn ($a) => ($a['windows']['3d']['metrics']['unique_ctr'] ?? 0) >= $s['ctr_weak'])) {
                $notes[] = ['key' => 'city_ctr_low', 'label' => 'CTR bajo en toda la ciudad', 'text' => 'Todos los anuncios con datos tienen el CTR único debajo de '
                    // .123 (dueño: "la subida de presupuesto siempre va primero"): es un aviso, ya no dice "primero creativos"
                    . AdRules::num($s['ctr_weak']) . ' %: hacen falta creativos con mejor gancho. Es solo un aviso.'];
            }
            $out['campaigns'][$id] = ['notes' => $notes, 'cpm_change' => $cpmChange[$id], 'frequency' => $freq, 'city' => $c['city']];
        }

        $history = $this->historyFor($f, $rules);
        $now = now()->toDateTimeString();

        // Batches (ad sets). Si Meta no trajo los números del ad set, se suman los de sus anuncios
        foreach ($f['adsets'] as $id => $set) {
            $id = (string) $id;
            $c = $campaigns[$set['campaign_meta_id']] ?? null;
            $windows = ($set['windows']['max']['totals']['impressions'] ?? 0) > 0 ? $set['windows']
                : $this->sumAds(array_keys(array_filter($f['ads'], fn ($a) => $a['adset_meta_id'] === $id)), $f);
            $out['adsets'][$id] = $this->entity($windows, $c, $rules, 'batch', $history['adset'][$id] ?? [], $cpmChange[$set['campaign_meta_id']] ?? null, $tracking);
            // .111: un batch nuevo (todos sus anuncios con menos de 24 horas) no es perdedor todavía
            if ($young = $rules->young($out['adsets'][$id], self::ageHours($f, array_keys(array_filter($f['ads'], fn ($a) => $a['adset_meta_id'] === $id)), $now))) {
                $out['adsets'][$id] = $young + ['causes' => []];
            }
        }
        // Anuncios: el que concentra el gasto se apaga primero, no el batch
        $adVerdicts = [];
        foreach ($f['ads'] as $id => $ad) {
            $c = $campaigns[$ad['campaign_meta_id']] ?? null;
            $adVerdicts[$id] = $this->entity($ad['windows'], $c, $rules, 'anuncio', $history['ad'][$id] ?? [], $cpmChange[$ad['campaign_meta_id']] ?? null, $tracking);
            // .121: perdedor por toda su vida, pero en los últimos 3 días vende en C o debajo: no se poda
            if ($back = $rules->recovered($adVerdicts[$id], $ad['windows']['3d']['totals'] ?? null, $c['target'] ?? null, $c['break_even'] ?? null, (bool) ($c['break_even_estimated'] ?? false))) {
                $adVerdicts[$id] = $back + ['causes' => []];
            }
            // .127 (dueño, 2026-11-09: "sí acepto gastar 3C" con buenas señales): con 0 o 1 compra y buenas señales, hasta 3×C
            $life = $ad['windows']['max'] ?? null;
            if ($wait = $rules->patient($adVerdicts[$id], (float) ($life['totals']['spend'] ?? 0), (float) ($life['totals']['purchases'] ?? 0), $c['target'] ?? null,
                $rules->signals($life, ($ad['creative_type'] ?? null) !== 'image'))) {
                $adVerdicts[$id] = $wait + ['causes' => []];
            }
            if ($young = $rules->young($adVerdicts[$id], self::ageHours($f, [$id], $now))) {
                $adVerdicts[$id] = $young + ['causes' => []];
            }
        }
        // .65: un batch es perdedor solo si todos sus creativos perdieron
        foreach ($out['adsets'] as $setId => $v) {
            $setId = (string) $setId;
            $inside = array_keys(array_filter($f['ads'], fn ($a) => $a['adset_meta_id'] === $setId));
            $out['adsets'][$setId] = self::batchVerdict($v, array_map(fn ($adId) => ['name' => (string) $f['ads'][$adId]['name'], 'active' => (bool) $f['ads'][$adId]['active'],
                'verdict' => $adVerdicts[$adId]], $inside));
            // .116: el último día que gastó: desde ahí cuenta el descanso de su ángulo
            $spent = array_keys(array_filter($history['adset'][$setId] ?? [], fn ($r) => $r['spend'] > 0));
            $out['adsets'][$setId]['last_spend'] = $spent ? max($spent) : null;
        }
        foreach ($f['ads'] as $id => $ad) {
            $v = $adVerdicts[$id];
            $batch = $out['adsets'][$ad['adset_meta_id']]['key'] ?? null;
            if ($v['key'] === 'loser' && $batch !== null && $batch !== 'loser') {
                $v['proposal'] = 'Apagar este anuncio (no el batch).';
            }
            if (!$ad['active'] && in_array($v['key'], ['loser', 'failing'], true)) {
                $v['proposal'] = 'Ya está apagado.';
            }
            $out['ads'][$id] = $v;
        }
        // Un batch que falla o pierde dice qué anuncios de adentro lo tiran abajo (para podar)
        foreach ($out['adsets'] as $setId => $v) {
            $setId = (string) $setId;
            if (!in_array($v['key'], ['failing', 'loser', 'intermediate'], true)) {
                continue;
            }
            $bad = [];
            foreach ($f['ads'] as $adId => $ad) {
                $av = $out['ads'][$adId] ?? null;
                if ($ad['adset_meta_id'] === $setId && $ad['active'] && $av && in_array($av['key'], ['loser', 'failing'], true)) {
                    $bad[] = $ad['name'] . ': ' . mb_strtolower($av['label']) . ($av['key'] === 'failing' && $av['causes'] ? ' (' . mb_strtolower(implode(', ', array_column($av['causes'], 'label'))) . ')' : '');
                }
            }
            if ($bad) {
                $out['adsets'][$setId]['reading'] .= ' Adentro: ' . implode('; ', $bad) . '.';
            }
        }

        // Creativos: por ciudad, entre ciudades, embudo y video
        $winners = [];
        $wasWinner = [];
        $agg = [];
        foreach ($f['creatives'] as $cid => $cr) {
            $agg[$cid] = $this->creativeWindows($cr, $f);
        }
        $states = [];
        foreach ($f['creatives'] as $cid => $cr) {
            foreach ($agg[$cid]['cities'] as $campaignId => $w) {
                $c = $campaigns[$campaignId] ?? null;
                $states[$cid][$campaignId] = $this->entity($w, $c, $rules, 'creativo', $this->sumHistory($history['ad'], $agg[$cid]['ads_by_city'][$campaignId] ?? []),
                    $cpmChange[$campaignId] ?? null, $tracking);
                $good = $states[$cid][$campaignId]['good'] ?? false;
                $estimated = (bool) ($c['break_even_estimated'] ?? false);
                // .110: si en esta ciudad ya vende aunque gastó poco, no está "sin datos" (y no lo deja ser "perdedor en todas")
                $life = $w['max']['totals'] ?? [];
                if ($early = $rules->sellingEarly($states[$cid][$campaignId], (float) ($life['spend'] ?? 0), (float) ($life['purchases'] ?? 0))) {
                    $states[$cid][$campaignId] = $early + ['causes' => []];
                }
                // .121: perdedor por toda su vida, pero en los últimos 3 días vende en C o debajo: se recuperó
                if ($back = $rules->recovered($states[$cid][$campaignId], $w['3d']['totals'] ?? null, $c['target'] ?? null, $c['break_even'] ?? null, $estimated)) {
                    $states[$cid][$campaignId] = $back + ['causes' => []];
                }
                // .86: intermedio o en evaluación en toda su vida, pero perdiendo en los últimos 3 días en esta ciudad
                // (.114: o en los 7 días, si en 3 no llegó a 2×C; .121: también un prometedor)
                if ($recent = $rules->recentLoser($w['3d']['totals'] ?? null, $states[$cid][$campaignId], $c['target'] ?? null, $c['break_even'] ?? null, $w['7d']['totals'] ?? null, $estimated)) {
                    $states[$cid][$campaignId] = $recent + ['causes' => []];
                }
                // .119: un ganador que falla y pierde dinero se apaga en esta ciudad (y se sigue iterando)
                if ($stop = $rules->failingStop($states[$cid][$campaignId], $w['3d']['totals'] ?? null, $c['target'] ?? null, $c['break_even'] ?? null, $w['7d']['totals'] ?? null, $estimated, $tracking)) {
                    $states[$cid][$campaignId] = $stop;
                }
                $wasWinner[$cid] = ($wasWinner[$cid] ?? false) || $good;
                // .127: con 0 o 1 compra en toda su vida aquí y buenas señales, se le da hasta 3×C antes de apagarlo
                if ($wait = $rules->patient($states[$cid][$campaignId], (float) ($life['spend'] ?? 0), (float) ($life['purchases'] ?? 0), $c['target'] ?? null,
                    $rules->signals($w['max'] ?? null, $cr['type'] !== 'image'))) {
                    $states[$cid][$campaignId] = $wait + ['causes' => []];
                }
                // .111: si todos sus anuncios en esta ciudad tienen menos de 24 horas, todavía no se apaga
                if ($young = $rules->young($states[$cid][$campaignId], self::ageHours($f, $agg[$cid]['ads_by_city'][$campaignId] ?? [], $now))) {
                    $states[$cid][$campaignId] = $young + ['causes' => []];
                }
                if (in_array($states[$cid][$campaignId]['key'], ['proven', 'promising'], true)) {
                    $pick = $this->window($w);
                    if ($pick) {
                        $winners[] = ['id' => $cid, 'metrics' => $pick['metrics']];
                    }
                }
            }
        }
        // .80: cada creativo se compara con los OTROS ganadores del producto (no consigo mismo)
        $medianFor = function (int $cid) use ($winners): array {
            $others = array_column(array_filter($winners, fn ($x) => (int) $x['id'] !== $cid), 'metrics');
            $median = [];
            foreach (['hook_rate', 'hold_rate', 'retention_middle', 'retention_close', 'unique_ctr', 'conversion_rate'] as $k) {
                $median[$k] = AdRules::median(array_map(fn ($m) => $m[$k] ?? null, $others));
            }

            return $median;
        };
        foreach ($f['creatives'] as $cid => $cr) {
            $byCity = $states[$cid] ?? [];
            $cityNames = [];
            foreach ($byCity as $campaignId => $v) {
                $cityNames[$campaigns[$campaignId]['city'] ?? $campaignId] = $v['key'];
            }
            // .113: dónde sigue prendido y dónde todavía se prueba (prendido y sin 1×C gastado)
            $activeIn = [];
            $testing = [];
            foreach ($byCity as $campaignId => $v) {
                $activeIn[$campaignId] = collect($agg[$cid]['ads_by_city'][$campaignId] ?? [])->contains(fn ($m) => $f['ads'][$m]['active'] ?? false);
                if ($v['key'] === 'no_data' && $activeIn[$campaignId]) {
                    $target = $campaigns[$campaignId]['target'] ?? null;
                    $testing[$campaignId] = $target ? $target * $s['no_data_cpa'] : null;
                }
            }
            $cross = $rules->crossCity($cityNames);
            if ($testing && ($cross['key'] ?? null) === 'loser_all') {
                $cross = null;
            }
            $overall = $this->overall($byCity, $cross, $cityNames);
            if ($overall['key'] === 'loser' && $testing) {
                $overall = self::stillTesting($byCity, $testing, $activeIn, array_filter(array_map(fn ($c) => $c['city'] ?? null, $campaigns)), (float) $s['no_data_cpa']);
            }
            $all = $agg[$cid]['all'];
            $w = $this->window($all);
            $isVideo = $cr['type'] !== 'image';
            $length = collect($cr['ads'])->map(fn ($m) => $f['ads'][$m]['video_length'] ?? null)->filter()->max();
            $mainCampaign = collect($agg[$cid]['cities'])->sortByDesc(fn ($x) => $x['3d']['totals']['spend'] ?? 0)->keys()->first();
            $cityCpm = $mainCampaign !== null ? ($campaigns[$mainCampaign]['windows']['3d']['metrics']['cpm'] ?? null) : null;
            $mainC = $mainCampaign !== null ? ($campaigns[$mainCampaign]['target'] ?? null) : null;
            $freq3 = $all['3d']['metrics']['frequency'] ?? null;
            $funnel = $rules->funnel($freq3);
            $out['creatives'][$cid] = [
                'id' => $cid, 'label' => $cr['label'], 'active' => collect($cr['ads'])->contains(fn ($m) => $f['ads'][$m]['active'] ?? false),
                'verdict' => $overall, 'cross' => $cross,
                // .80: si sigue activo en esa ciudad (para "qué apagar hoy, por ciudad")
                'cities' => collect($byCity)->map(fn ($v, $campaignId) => ['campaign_meta_id' => $campaignId, 'city' => $campaigns[$campaignId]['city'] ?? null,
                    'active' => collect($agg[$cid]['ads_by_city'][$campaignId] ?? [])->contains(fn ($m) => $f['ads'][$m]['active'] ?? false)] + $v)->values()->all(),
                'funnel' => $funnel ? ['key' => $funnel, 'label' => AdRules::FUNNEL[$funnel], 'frequency' => $freq3] : null,
                'video' => $w ? $rules->video($w, $isVideo, $length, $medianFor((int) $cid), $cityCpm, $w['metrics']['cpa'] ?? null, $mainC) + ['window' => $w['window']] : null,
                // .70: sus números (hook rate, hold rate, CTR, frecuencia, CPM, cuánta gente llega a cada parte y el tiempo
                // promedio), para que el dueño vea en qué se basa la decisión del análisis
                'metrics' => $w ? ['window' => $w['window'], 'hook_rate' => $w['metrics']['hook_rate'] ?? null, 'hold_rate' => $w['metrics']['hold_rate'] ?? null,
                    'unique_ctr' => $w['metrics']['unique_ctr'] ?? null, 'frequency' => $w['metrics']['frequency'] ?? null, 'cpm' => $w['metrics']['cpm'] ?? null,
                    'retention' => $w['metrics']['retention'] ?? null, 'avg_watch_time' => $w['metrics']['avg_watch_time'] ?? null, 'length' => $length] : null,
                'status' => $cr['status'], 'status_source' => $cr['status_source'], 'angle_code' => $cr['angle_code'], 'type' => $cr['type'],
                // .119: fue ganador, ya no tiene anuncios prendidos y gastó en los últimos 7 días: sigue en la fila de iteraciones
                'off_winner' => !in_array(true, $activeIn, true) && ($wasWinner[$cid] ?? false) && (float) ($all['7d']['totals']['spend'] ?? 0) > 0,
                // Paso 3: sus 7 días, para ordenar los creativos en el análisis de la mañana
                'week' => ['spend' => round((float) ($all['7d']['totals']['spend'] ?? 0), 2), 'purchases' => (float) ($all['7d']['totals']['purchases'] ?? 0),
                    'cpa' => isset($all['7d']['metrics']['cpa']) ? round((float) $all['7d']['metrics']['cpa'], 2) : null],
                // .126 (dueño, 2026-11-09: "la ganancia siempre va a pesar más"): lo que dejó, sumando cada ciudad con su BE
                'gain' => self::creativeGain($agg[$cid]['cities'], $campaigns),
            ];
        }

        return $out;
    }

    /**
     * Paso 2b: qué hacer con cada campaña (con montos), el precio, la urgencia del producto, el trabajo siguiente y las
     * alarmas de tracking y stock.
     */
    private function plan(array $f, array $out, array $history, ?array $ops, CampaignRules $cr, array $s, ?array $sample = null): array
    {
        $mode = $f['product']['mode'];
        $today = $history['today'];
        $yesterday = $history['yesterday'];
        $actions = [];
        $prices = [];
        $alarms = [];
        $minFor = [];
        // .117: en cada ciudad sin campañas prendidas, la última que se apagó es la que se vuelve a prender
        $toRelaunch = self::toRelaunch($f['campaigns'], $history['campaigns'] ?? []);
        $relaunchOffers = 0;
        $dismissed = $ops['dismissed'] ?? [];
        $relaunches = false;
        // .139: la etiqueta del producto. Un Malo frena sus encargos nuevos; la misión sale si gastó en Meta en 14 días (los
        // productos viejos sin campañas solo llevan la etiqueta). `.145`: se calcula antes de las campañas, que la usan
        $worth = ProductWorth::verdict($sample, self::periodMoney($f, $history['campaigns'] ?? [], $yesterday, 28)['gain'], $s)
            + ['since' => $sample['since'] ?? null, 'left_out' => (int) ($sample['left_out'] ?? 0), 'cities' => $sample['cities'] ?? []];
        $worth['stop'] = ProductWorth::stops($worth, $mode, $dismissed, (int) $f['product']['id'], $today);
        // .137: las campañas prendidas en una ciudad sin unidades (misión de cada día). Un producto En testeo sin mercancía en
        // ninguna ciudad se testea así a propósito (.89: "siempre se testea sin ni siquiera pedir la mercancía"): ahí, solo el aviso
        $stockOut = [];
        $noGoods = ($ops['phase'] ?? null) === 'testing' && !array_filter($ops['cities'] ?? [], fn ($x) => ($x['stock'] ?? 0) > 0);
        foreach ($f['campaigns'] as $id => $c) {
            $id = (string) $id;
            $h = $history['campaigns'][$id] ?? ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
            $city = $c['city_id'] !== null ? ($ops['cities'][$c['city_id']] ?? null) : null;
            $w3 = $c['windows']['3d'] ?? null;
            $w7 = $c['windows']['7d'] ?? null;
            $cpa7 = $w7['metrics']['cpa'] ?? null;
            $budget = $c['daily_budget'];
            $out['campaigns'][$id]['action'] = null;
            // Paso 4b: para elegir la ciudad de aprendizaje de un encargo (con stock y con ventas)
            $out['campaigns'][$id] += ['city_id' => $c['city_id'], 'active' => $c['active'], 'stock' => $city['stock'] ?? null,
                'purchases7' => (float) ($w7['totals']['purchases'] ?? 0)];
            $out['campaigns'][$id]['budget_notes'] = [];
            if (!$c['active']) {
                // .117: una campaña apagada espera a tener 3 batches nunca publicados en su ciudad. No cuenta para la urgencia
                if (($toRelaunch[$c['city_id']] ?? null) === $id) {
                    $offers = self::offers($f, $out, $id, $ops['bank']['launched'] ?? [], $ops['bank']['ready'] ?? [], $ops['bank']['names'] ?? []);
                    $relaunchOffers = max($relaunchOffers, count($offers));
                    $action = $cr->relaunch(['mode' => $mode, 'c' => $c['target'], 'be' => $c['break_even'], 'city_free' => true, 'available' => $city['available'] ?? true,
                        'dismissed' => isset($dismissed["{$f['product']['id']}:{$id}:relaunch"]), 'offers' => $offers, 'status_events' => $h['status_events'], 'new_ads' => $h['new_ads'],
                        'daily' => $h['daily'], 'today' => $today]);
                    if ($action) {
                        $relaunches = true;
                        $out['campaigns'][$id]['action'] = $action;
                        $out['campaigns'][$id]['numbers'] = ['spend3' => 0.0, 'purchases3' => 0.0, 'c' => $c['target'], 'be' => $c['break_even'], 'source' => $c['target_source'] ?? null, 'budget' => $budget,
                            'streak' => 0, 'prune' => [], 'live' => 0, 'offers' => array_slice($offers, 0, (int) $s['relaunch_batches'])];
                    }
                }
                continue;
            }
            if ($city && !$city['available']) {
                $out['campaigns'][$id]['action'] = ['key' => 'none', 'label' => 'No se vende aquí', 'tone' => 'error', 'data' => '',
                    'reading' => 'El producto está marcado como no disponible en esta ciudad.', 'proposal' => 'Pausar la campaña.', 'from' => null, 'to' => null];
                $actions[$id] = 'pause_city';
                continue;
            }
            // .125: el producto está en pausa o abandonado y su campaña sigue prendida y gastando: hay que apagarla
            if (in_array($mode, ['paused', 'abandoned'], true) && (float) ($w3['totals']['spend'] ?? 0) > 0) {
                $out['campaigns'][$id]['action'] = ['key' => 'none', 'label' => 'Producto en pausa', 'tone' => 'error', 'data' => '',
                    'reading' => 'El producto está ' . ($mode === 'paused' ? 'en pausa' : 'abandonado') . ' y esta campaña sigue prendida: gastó '
                        . AdRules::money((float) $w3['totals']['spend']) . ' en 3 días.', 'proposal' => 'Pausar la campaña.', 'from' => $budget, 'to' => null];
                $out['campaigns'][$id]['numbers'] = ['spend3' => (float) $w3['totals']['spend'], 'purchases3' => (float) ($w3['totals']['purchases'] ?? 0), 'c' => $c['target'],
                    'be' => $c['break_even'], 'source' => $c['target_source'] ?? null, 'budget' => $budget, 'streak' => 0, 'prune' => []];
                $actions[$id] = 'none';
                continue;
            }
            $streak = $c['break_even'] ? CampaignRules::streak($h['daily'], (float) $c['break_even'], $yesterday) : ['days' => 0, 'start' => null];
            $intervention = null;
            if ($streak['days'] > 0) {
                $events = array_merge(array_map(fn ($e) => $e['date'], array_filter($h['budget_events'], fn ($e) => $e['to'] < $e['from'])), $h['ad_pauses'], $h['new_ads']);
                $events = array_filter($events, fn ($d) => $d >= $streak['start']);
                sort($events);
                $intervention = $events[0] ?? null;
            }
            $closed = $intervention ? max(0, (int) round((strtotime($yesterday) - strtotime($intervention)) / 86400)) : 0;
            // .79 (dueño, 2026-11-02): el stock ya no frena la subida ni pide pausar ("al día siguiente ya tenemos productos"):
            // es un aviso, en la alarma del producto y en lo que se propone para la ciudad
            $stockAlert = null;
            // .101 (dueño, 2026-11-04: 55 pedidos de Termafit esperando stock en Distrito Capital y la campaña gastando): los
            // pedidos que esperan stock, con lo gastado desde el más viejo; y no se sube el presupuesto ahí hasta que llegue
            $waiting = $city['waiting'] ?? null;
            $spent = $waiting ? array_sum(array_column(array_filter($h['daily'], fn ($d) => $d >= $waiting['since'], ARRAY_FILTER_USE_KEY), 'spend')) : 0.0;
            $noStock = false;
            if ($city) {
                $low = $city['days_of_stock'] === null ? $city['stock'] <= 0 : $city['days_of_stock'] < $s['stock_warn_days'];
                $stockNote = 'quedan ' . $city['stock'] . ' unidades' . ($city['days_of_stock'] !== null ? ' (≈' . AdRules::num($city['days_of_stock']) . ' días de ventas)' : '');
                if ($city['stock'] <= 0 && !$noGoods) {
                    // .137 (dueño, 2026-11-11: "que me avise cuando una campaña en una ciudad está activa pero la ciudad no tiene
                    // stock. Debe salir en mis análisis de Meta todos los días hasta que lo solucione de alguna manera, o apagando la
                    // campaña o añadiendo stock"): una misión de frenar pérdidas, y no se sube ahí hasta que llegue
                    $noStock = true;
                    $liquidation = $mode === 'liquidation';
                    $stockAlert = $liquidation ? 'se acabó el stock en esta ciudad: pausa la campaña' : 'sin stock en esta ciudad: manda stock o pausa la campaña';
                    $alarms[] = 'Sin stock en ' . ($c['city'] ?? 'una ciudad') . ' y la campaña sigue activa: ' . ($liquidation ? 'pausa la campaña.' : 'manda stock o pausa la campaña.');
                    $stockOut[] = ['campaign_meta_id' => $id, 'city' => $c['city'] ?? null, 'budget' => $budget !== null ? (float) $budget : null,
                        'spend3' => (float) ($w3['totals']['spend'] ?? 0), 'liquidation' => $liquidation,
                        'waiting' => $waiting ? $waiting + ['spent' => round($spent, 2)] : null, 'incoming' => $city['incoming'] ?? null];
                } elseif ($city['stock'] <= 0) {
                    $stockAlert = 'sin stock en esta ciudad: pide al proveedor (no hace falta pausar)';
                    $alarms[] = 'Sin stock en ' . ($c['city'] ?? 'una ciudad') . ': pide al proveedor.';
                } elseif ($low) {
                    $stockAlert = $stockNote . ': pide mercancía';
                    $alarms[] = 'Poco stock en ' . ($c['city'] ?? 'una ciudad') . ': ' . $stockNote . '.';
                }
                if ($city['sizes_out']) {
                    $alarms[] = 'Sin stock en ' . ($c['city'] ?? 'una ciudad') . ' de: ' . implode(', ', $city['sizes_out']) . '.';
                }
            }
            $stockWaiting = $waiting && $waiting['orders'] >= $s['stock_waiting_min'] ? $waiting['orders'] : 0;
            // .137: sin stock, los pedidos que esperan van dentro de su misión (no salen dos veces)
            if ($stockWaiting > 0 && !$noStock) {
                $alarms[] = "{$waiting['orders']} pedidos esperando stock en " . ($c['city'] ?? 'una ciudad') . ' desde el ' . date('d/m', strtotime($waiting['since']))
                    . ': la campaña gastó ' . AdRules::money($spent) . ' desde entonces. Manda stock; mientras tanto no se sube el presupuesto ahí.';
            }
            $prune = [];
            foreach ($f['ads'] as $adId => $ad) {
                if ($ad['campaign_meta_id'] === $id && $ad['active'] && (($out['ads'][$adId]['key'] ?? null) === 'loser' || self::stopsHere($ad, $out, $id))) {
                    $prune[] = $ad['name'];
                }
            }
            $newBatches = 0;
            foreach ($f['adsets'] as $setId => $set) {
                if ($set['campaign_meta_id'] === $id && $set['active'] && $c['target'] && in_array($out['adsets'][$setId]['key'] ?? null, ['no_data'], true)) {
                    $newBatches++;
                }
            }
            // .87: lo que puede lanzar esta ciudad: la banca y los batches que ya salieron en otra ciudad, no en esta
            $offers = self::offers($f, $out, $id, $ops['bank']['launched'] ?? [], $ops['bank']['ready'] ?? [], $ops['bank']['names'] ?? []);
            // .125 (dueño, 2026-11-09: "sí, que corra"): en el BE o encima y sin nada que lanzar, la cuenta corre desde el primer día
            $noBatch = $streak['days'] > 0 && $offers === [] && $mode === 'normal';
            if ($noBatch && ($intervention === null || $intervention > $streak['start'])) {
                $intervention = $streak['start'];
                $closed = max(0, (int) round((strtotime($yesterday) - strtotime($intervention)) / 86400));
            }
            // .125: lo que gastó y vendió en los últimos 14 días cerrados, y lo que se intentó
            $chronic = self::chronic($h, $yesterday, (int) $s['chronic_days']);
            $action = $cr->action([
                'c' => $c['target'], 'be' => $c['break_even'], 'be_estimated' => (bool) ($c['break_even_estimated'] ?? false), 'mode' => $mode, 'budget' => $budget,
                'spend3' => $w3['totals']['spend'] ?? 0, 'purchases3' => $w3['totals']['purchases'] ?? 0, 'cpa7' => $cpa7, 'freq3' => $w3['metrics']['frequency'] ?? null,
                'stock_alert' => $stockAlert, 'stock_waiting' => $stockWaiting, 'stock_out' => $noStock, 'c_source' => $c['target_source'] ?? null,
                'increased_24h' => collect($h['budget_events'])->contains(fn ($e) => $e['to'] > $e['from'] && strtotime($e['at']) >= strtotime('-24 hours')),
                'budget_changed_today' => collect($h['budget_events'])->contains(fn ($e) => $e['date'] === $today),
                'new_ads_today' => in_array($today, $h['new_ads'], true),
                'streak_days' => $streak['days'], 'intervention' => $intervention, 'closed_since_intervention' => $closed,
                'decreased_since_intervention' => $intervention !== null && collect($h['budget_events'])->contains(fn ($e) => $e['to'] < $e['from'] && $e['date'] >= $intervention),
                'history_since' => $history['history_since'], 'prune' => $prune,
                'bank_ready' => array_column($offers, 'code'),
                'recent_change' => self::recentChange($h, $f, $id, now()->toDateTimeString(), (float) $s['change_wait_hours']),
                // .123: entre C y BE, lo último que se hizo desde que la campaña se pasó de C
                'band' => self::band($h, $f, $id, $c['target'] ? (float) $c['target'] : null, $budget !== null ? (float) $budget : null, $today, $yesterday, (int) $s['band_undo_days']),
                // .144: la última subida (o bajada): si todavía se espera, si aguantó o si hay que devolverla
                'raise' => CampaignRules::raiseCheck($h['daily'], $h['budget_events'], $budget !== null ? (float) $budget : null,
                    $c['break_even'] ? (float) $c['break_even'] : null, $yesterday, (int) $s['raise_wait_days'], (float) $s['raise_drop_pct']),
                'chronic' => $chronic, 'no_batch' => $noBatch,
                // .145: el producto es Malo (y no dijiste "No estoy de acuerdo"): sus campañas frenan el gasto
                'worth_bad' => $worth['stop'] ? ['effectiveness' => $worth['effectiveness'], 'orders' => $worth['orders']] : null,
            ]);
            $out['campaigns'][$id]['action'] = $action;
            // .125 (dueño: "puede ser que la campaña se esté comenzando a recuperar e igual me la mande a apagar"): 14 días sin
            // dejar dinero, pero en los últimos 3 vende debajo del BE: no se pausa, y se dice por qué
            $cpa3 = ($w3['totals']['purchases'] ?? 0) > 0 ? $w3['totals']['spend'] / $w3['totals']['purchases'] : null;
            if ($chronic && $c['target'] && $c['break_even'] && $cpa3 !== null && $cpa3 < $c['break_even'] && $chronic['spend'] >= $c['target'] * $s['chronic_min_cpa']
                && ($chronic['purchases'] <= 0 || $chronic['spend'] / $chronic['purchases'] >= $c['break_even'])) {
                $out['campaigns'][$id]['notes'][] = ['key' => 'recovering', 'label' => 'Se está recuperando', 'text' => "En {$chronic['days']} días gastó " . AdRules::money($chronic['spend'])
                    . ($chronic['purchases'] > 0 ? ' y vendió a ' . AdRules::money($chronic['spend'] / $chronic['purchases']) . ' por compra' : ' sin compras') . ' (en tu BE o encima), pero en los últimos 3 días vende a '
                    . AdRules::money($cpa3) . ', debajo del BE: no se pausa. Se vuelve a mirar cada día.'];
            }
            // .124: los batches vivos que ya se probaron y siguen con gasto mínimo, los que todavía se prueban y los que se prueban sin mínimo
            // .130: no se quita el del mejor (el de CPA más bajo en 7 días) ni el de los que venden en la C o debajo
            $mins = self::testedMins($f, $out, $id, in_array($action['key'], ['hold_prune', 'intervene'], true), now()->toDateTimeString(), (int) $s['min_spend_days'], $c['target']);
            // .82: los números de la ciudad, para la frase de cada tarea del análisis ("$10,29 por compra; tu límite es $7,22")
            $out['campaigns'][$id]['numbers'] = ['spend3' => (float) ($w3['totals']['spend'] ?? 0), 'purchases3' => (float) ($w3['totals']['purchases'] ?? 0),
                'c' => $c['target'], 'be' => $c['break_even'], 'source' => $c['target_source'] ?? null, 'budget' => $budget,
                'streak' => $streak['days'], 'prune' => $prune,
                // .87: los batches vivos (contando lo que se apaga hoy) y lo que puede lanzar, en orden
                'live' => self::live($f, $out, $id, in_array($action['key'], ['hold_prune', 'intervene'], true)), 'offers' => $offers,
                'mins' => ['off' => $mins['off'], 'keep' => $mins['keep'], 'stays' => $mins['stays']],
                // .126: lo que dejó la campaña (compras × BE − gasto) en 3 y en 7 días
                'gain3' => self::gain((float) ($w3['totals']['spend'] ?? 0), (float) ($w3['totals']['purchases'] ?? 0), $c['break_even']),
                'gain7' => self::gain((float) ($w7['totals']['spend'] ?? 0), (float) ($w7['totals']['purchases'] ?? 0), $c['break_even'])]
                // .120: el BE es estimado (C × esto): la campaña no tiene uno real
                + (($c['break_even_estimated'] ?? null) ? ['be_estimated' => (float) $c['break_even_estimated']] : []);
            $out['campaigns'][$id]['budget_notes'] = $cr->budgetNotes($budget, (float) $h['mins'], $newBatches, $c['target'], $mins['missing']);
            $actions[$id] = $action['key'];

            // El precio mínimo con el CPA de 7 días, para vigilar un precio que cambió. .140: la opción de bajarlo de a $2 junto al
            // escalado se reemplazó por la recomendación del producto entero (PriceRules, abajo)
            $version = $city['version'] ?? null;
            if (!$version || (float) $version->purchases <= 0 || (int) $version->orders_delivered <= 0) {
                $version = $ops['version'] ?? null;
            }
            if ($version && ($ops['price'] ?? null) && $cpa7) {
                $minFor[$id] = CampaignRules::minPrice($version->toArray(), (float) $ops['price'], (float) $cpa7);
            }
        }

        // .116: los ángulos y los creativos que descansan: siguen prendidos, pero no se les piden más encargos
        $angles = ($ops['angles'] ?? []) + ['order' => [], 'lost' => [], 'used' => [], 'chosen' => null, 'floors' => []];
        $restN = (int) $s['angle_rest_batches'];
        $restDays = (int) $s['angle_rest_days'];
        $rested = AngleRest::angles(AngleRest::batches($f['adsets'], $f['ads'], $out['adsets']), $restN, $restDays, $today, $angles['floors']);
        foreach ($out['creatives'] as $cid => $cr2) {
            $angle = $cr2['angle_code'] ?? null;
            $iterations = AngleRest::state($ops['iterations'][$cid] ?? [], $restN, $restDays, $today);
            $why = match (true) {
                $angle !== null && isset($rested[$angle]) => "No pidas más versiones: el ángulo {$angle} descansa " . AngleRest::untilText($rested[$angle]['until']) . '.',
                $iterations !== null => 'No pidas más versiones de este creativo ' . AngleRest::untilText($iterations['until']) . ": perdieron sus últimas {$restN} iteraciones.",
                default => null,
            };
            if ($why !== null) {
                $out['creatives'][$cid]['rest'] = $why;
            }
        }
        $restedCodes = array_map('strval', array_keys($rested));
        $nextAngle = AngleRest::next($angles['order'], array_merge($angles['lost'], $restedCodes), array_merge($angles['used'], array_filter(array_column($out['creatives'], 'angle_code'))));

        // Urgencia y trabajo siguiente
        $healthy = 0;
        $failing = 0;
        $winner = null;
        foreach ($out['creatives'] as $cid => $cr2) {
            if (!$cr2['active']) {
                continue;
            }
            $key = $cr2['verdict']['key'];
            if ($key === 'failing') {
                $failing++;
            } elseif (in_array($key, ['proven', 'promising'], true)) {
                $healthy++;
            }
            $p7 = 0.0;
            foreach ($f['creatives'][$cid]['ads'] ?? [] as $m) {
                $p7 += (float) ($f['ads'][$m]['windows']['7d']['totals']['purchases'] ?? 0);
            }
            // .126 (dueño, 2026-11-09: "pesa más ganar en dólares"): el ganador es el que más dólares deja en 7 días (antes, el que
            // más compras tenía); si a alguno no se le puede calcular (sin BE), por compras como antes
            $g7 = $cr2['gain']['week'] ?? null;
            $better = !$winner || ($g7 !== null && $winner['gain'] !== null ? $g7 > $winner['gain'] : $p7 > $winner['p7']);
            if ($key === 'proven' && empty($out['creatives'][$cid]['rest']) && $p7 >= $s['proven_purchases'] && $better) {
                $winner = ['id' => $cid, 'label' => $cr2['label'], 'p7' => $p7, 'gain' => $g7, 'iterate' => self::ITERATE[$cr2['video']['iterate'] ?? ''] ?? null,
                    'iterate_key' => $cr2['video']['iterate'] ?? null, // .99: qué prueba cada pieza de su iteración
                    'angle' => $cr2['angle_code'], 'type' => $cr2['type']];
            }
        }
        $activeCities = array_filter($f['campaigns'], fn ($c) => $c['active']);
        // .79: un producto (nuevo) sin C ni BE: dónde ponerlos, para que no se olvide
        if ($activeCities && !array_filter($activeCities, fn ($c) => $c['target'])) {
            array_unshift($alarms, collect($activeCities)->contains(fn ($c) => $c['target_no_cost'] ?? false)
                ? 'Sin CPA: falta registrar la compra de inventario de este producto con su costo.'
                : 'Sin CPA de referencia: escribe "CPA $X | BE $Y" en el nombre de la campaña o ponlo en Meta Ads › Productos y objetivos.');
        }
        $stockAllOut = $ops && $activeCities && !array_filter($activeCities, fn ($c) => ($ops['cities'][$c['city_id']]['stock'] ?? 1) > 0);
        $urgency = $cr->urgency(['mode' => $mode, 'cities' => array_values($actions), 'healthy' => $healthy, 'failing' => $failing, 'stock_all_out' => $stockAllOut]);
        // Paso 4a: lo que ya está en la banca (aprobado sin testear) se resta de lo que hay que pedir
        $bank = $ops['bank']['codes'] ?? [];
        $urgency['needed'] = $urgency['batches'];
        $urgency['bank'] = $bank;
        $urgency['batches'] = QueueRules::minusBank($urgency['batches'], count($bank));
        $angleSpend = [];
        foreach ($f['adsets'] as $setId => $set) {
            if ($set['angle_code']) {
                $angleSpend[$set['angle_code']] = ($angleSpend[$set['angle_code']] ?? 0) + (float) ($set['windows']['max']['totals']['spend'] ?? 0);
            }
        }
        arsort($angleSpend);
        $mainAngle = array_key_first($angleSpend);
        $saturated = [];
        foreach ($activeCities as $id => $c) {
            if (($c['windows']['3d']['metrics']['frequency'] ?? null) !== null && $c['windows']['3d']['metrics']['frequency'] >= $s['saturation_from']) {
                $saturated[] = $c['city'] ?? $id;
            }
        }
        $tracking = $f['tracking']['ratio'] ?? null;
        if ($tracking !== null && ($tracking < $s['tracking_low_pct'] || $tracking > $s['tracking_high_pct'])) {
            array_unshift($alarms, 'Tracking: Meta ve el ' . AdRules::num($tracking) . ' % de los pedidos reales de los últimos 3 días. Revisar el píxel o la API de conversiones.');
        }
        $mainCampaign = collect($activeCities)->sortByDesc(fn ($c) => $c['windows']['7d']['totals']['spend'] ?? 0)->keys()->first();
        $out['product']['worth'] = $worth;
        $m14 = self::periodMoney($f, $history['campaigns'] ?? [], $yesterday, 14);
        $worthBad = $worth['stop'] && $m14['spend'] > 0
            ? $worth + ['phase' => (string) ($ops['phase'] ?? 'iterate'), 'stock' => (int) array_sum(array_column($ops['cities'] ?? [], 'stock')),
                'line' => (float) $s[$worth['early'] ? 'worth_early_bad_pct' : 'worth_bad_pct']]
            : null;
        // .140: un producto Bueno con mucho espacio debajo de su C: bajar el precio un escalón para vender más
        $version = $ops['version'] ?? null;
        $priceDown = PriceRules::suggest(['price' => $ops['price'] ?? null, 'version' => $version instanceof \Illuminate\Database\Eloquent\Model ? $version->toArray() : $version,
            'cpa' => $m14['purchases'] > 0 ? $m14['spend'] / $m14['purchases'] : null, 'purchases' => $m14['purchases'], 'worth' => $worth['level'], 'mode' => $mode,
            'phase' => (string) ($ops['phase'] ?? 'iterate'), 'recent' => ($ops['price_change'] ?? null) !== null || (bool) ($ops['price_measuring'] ?? false),
            'dismissed' => ($dismissed["{$f['product']['id']}:price_down"] ?? '') >= date('Y-m-d', strtotime("{$today} -" . ProductWorth::DISMISS_DAYS . ' days'))], $s);
        if ($priceDown) {
            $priceDown += ['effectiveness' => $worth['effectiveness'], 'orders' => $worth['orders']];
            $prices[] = 'Baja el precio de ' . AdRules::money($priceDown['from']) . ' a ' . AdRules::money($priceDown['to']) . ': tu CPA real de 14 días ('
                . AdRules::money($priceDown['cpa']) . ') está ' . $priceDown['room'] . ' % debajo de tu C (' . AdRules::money($priceDown['c']) . ').';
        }
        $out['plan'] = [
            'price_down' => $priceDown,
            'urgency' => $urgency,
            'next_work' => $cr->nextWork(['mode' => $mode, 'winner' => $winner, 'saturated' => $saturated, 'main_angle' => $mainAngle,
                'rested' => $restedCodes, 'rest_batches' => $restN, 'bank' => $bank, 'needed' => $urgency['needed']], $urgency['batches']),
            'price' => $prices,
            'price_watch' => $cr->priceWatch($ops['price_change'] ?? null, $mainCampaign !== null ? ($minFor[$mainCampaign] ?? null) : null),
            'alarms' => array_values(array_unique($alarms)),
            // .137: las campañas prendidas en una ciudad sin unidades: una misión por cada una
            'stock_out' => $stockOut,
            // Paso 4b: lo mismo que el trabajo siguiente, en datos, para proponer los encargos
            'work' => ['winner' => $winner, 'main_angle' => $mainAngle, 'rested' => $restedCodes, 'rest_batches' => $restN, 'saturated' => $saturated],
            // .116: los ángulos en descanso y cuál sigue, para el aviso del análisis
            'rests' => array_map(fn ($code, $r) => ['angle' => (string) $code] + $r, array_keys($rested), $rested),
            'next_angle' => $nextAngle,
            'money_at_risk' => round(array_sum(array_map(fn ($id) => (float) ($f['campaigns'][$id]['windows']['3d']['totals']['spend'] ?? 0),
                array_keys(array_filter($actions, fn ($k) => in_array($k, ['intervene', 'decrease', 'pause_city'], true))))), 2),
            // .117: el producto se quedó sin ninguna ciudad prendida y no alcanza para prender una: lo decide el dueño
            'no_city' => $f['campaigns'] && !$activeCities && !$relaunches && $mode === 'normal' && !isset($dismissed["{$f['product']['id']}:no_city"])
                ? ['have' => $relaunchOffers, 'need' => (int) $s['relaunch_batches']] : null,
            // .126: lo que dejó el producto entero en 3 y en 7 días (la suma de sus campañas con BE, prendidas o no)
            'gain' => self::productGain($f['campaigns']),
            // .125 (dueño, 2026-11-09: "sí debemos establecer un límite para soltar producto, dependiendo de su CPA y si hicimos
            // cambios y no mejoró"): el producto entero lleva 14 días sin dejar dinero, con batches nuevos en ese tiempo. Lo decide
            // él; si dice que no, no se le vuelve a preguntar en esos días
            // .139: si el producto ya salió Malo, esa misión lo dice (no salen las dos)
            'worth_bad' => $worthBad,
            'drop' => !$worthBad && $mode === 'normal' && $activeCities && (($dismissed["{$f['product']['id']}:drop_product"] ?? '') < date('Y-m-d', strtotime("{$today} -" . (int) $s['chronic_days'] . ' days')))
                ? self::dropProduct($f, $history['campaigns'] ?? [], $yesterday, (int) $s['chronic_days'], (float) $s['chronic_min_cpa'], (int) $s['drop_min_batches']) : null,
        ];

        return $out;
    }

    /**
     * `.87` (dueño, 2026-11-03: "sí estaban activos, pero ninguno de sus anuncios estaban activos"): cuántos batches siguen
     * vivos en una campaña: ad sets prendidos con al menos un anuncio prendido que hoy no se apaga (un creativo perdedor, uno
     * que pierde en esta ciudad o, si la ciudad se poda, un anuncio perdedor). PHP puro.
     */
    public static function live(array $f, array $out, string $campaignId, bool $prunes): int
    {
        return count(self::liveSets($f, $out, $campaignId, $prunes));
    }

    /** Los ad sets vivos de una campaña (ver `live`). @return array<string, true> */
    private static function liveSets(array $f, array $out, string $campaignId, bool $prunes): array
    {
        $sets = [];
        foreach ($f['ads'] as $adId => $ad) {
            $set = $f['adsets'][$ad['adset_meta_id']] ?? null;
            if ((string) $ad['campaign_meta_id'] !== $campaignId || !$ad['active'] || !($set['active'] ?? false)) {
                continue;
            }
            $cr = $ad['creative_id'] !== null ? ($out['creatives'][$ad['creative_id']] ?? null) : null;
            $here = collect($cr['cities'] ?? [])->first(fn ($x) => (string) $x['campaign_meta_id'] === $campaignId);
            $off = ($cr['verdict']['key'] ?? null) === 'loser' || ($here['key'] ?? null) === 'loser' || !empty($here['stop'])
                || $prunes && ($out['ads'][$adId]['key'] ?? null) === 'loser';
            if (!$off) {
                $sets[(string) $ad['adset_meta_id']] = true;
            }
        }

        return $sets;
    }

    /**
     * `.124` (dueño, 2026-11-09: "cuando ese batch ya se probó, es decir, ya tiene tiempo activo y ha tenido algunos datos, se
     * procede a eliminar ese gasto mínimo… le damos la libertad a Meta para distribuir ese presupuesto en otros batches que
     * sean más ganadores"): los batches vivos de una campaña que siguen con gasto mínimo. Ya se probó (`off`) el que tiene
     * veredicto en esa ciudad (los mismos que pasan un encargo de "En test" a "Testeado") o lleva $days días prendido; los
     * demás todavía se prueban (`keep`) y su mínimo se queda; y los que se prueban sin mínimo (`missing`). PHP puro.
     *
     * `.130` (dueño, 2026-11-10: "no se elimina el límite del presupuesto de el mejor batch, ni tampoco de los batch que tienen un
     * CPA igual o menor al CPA objetivo", "para asegurarnos de no empeorar el rendimiento"): de los que ya se probaron, el mínimo
     * se queda (`stays`) en el mejor de los batches vivos de la ciudad (el de CPA más bajo en los últimos 7 días; eligió el CPA)
     * y en los que venden en la C ($target) o debajo en esos 7 días. Se quita (`off`) de los demás: encima de la C o sin compras.
     *
     * @return array{off: list<array{adset: string, min: float, why: string, cpa: ?float}>, keep: list<array{adset: string, min: float}>,
     *     stays: list<array{adset: string, min: float, why: string, cpa: float, best: bool}>, missing: list<string>}
     */
    public static function testedMins(array $f, array $out, string $campaignId, bool $prunes, string $now, int $days, ?float $target = null): array
    {
        $res = ['off' => [], 'keep' => [], 'stays' => [], 'missing' => []];
        $live = array_keys(self::liveSets($f, $out, $campaignId, $prunes));
        // .130: el CPA de 7 días de cada batch vivo (si Meta no trajo los números del ad set, los de sus anuncios) y el más bajo
        $cpa = [];
        foreach ($live as $setId) {
            $set = $f['adsets'][$setId] ?? [];
            $t = ($set['windows']['max']['totals']['impressions'] ?? 0) > 0 ? ($set['windows']['7d']['totals'] ?? [])
                : MetaMetrics::sum(array_values(array_filter(array_map(fn ($ad) => (string) $ad['adset_meta_id'] === (string) $setId ? ($ad['windows']['7d']['totals'] ?? null) : null, $f['ads']))));
            $cpa[$setId] = ($t['purchases'] ?? 0) > 0 ? round((float) $t['spend'] / (float) $t['purchases'], 2) : null;
        }
        $best = array_filter($cpa, fn ($v) => $v !== null) ? min(array_filter($cpa, fn ($v) => $v !== null)) : null;
        foreach ($live as $setId) {
            $set = $f['adsets'][$setId] ?? null;
            // Los análisis y las pruebas de antes no traen el mínimo de cada ad set: ahí no se opina
            if (!$set || !array_key_exists('min_spend', $set)) {
                continue;
            }
            $min = (float) $set['min_spend'];
            $v = $out['adsets'][$setId] ?? null;
            $born = array_filter([$set['created_time'] ?? null, ...array_map(fn ($ad) => (string) $ad['adset_meta_id'] === (string) $setId ? ($ad['created_time'] ?? null) : null, $f['ads'])]);
            $age = $born ? (int) floor((strtotime($now) - min(array_map('strtotime', $born))) / 86400) : null;
            $why = match (true) {
                in_array($v['key'] ?? null, QueueRules::FINAL_VERDICTS, true) => (string) ($v['label'] ?? 'Ya tiene veredicto'),
                $age !== null && $age >= $days => "lleva {$age} días prendido",
                default => null,
            };
            if ($min <= 0) {
                if ($why === null) {
                    $res['missing'][] = (string) $set['name'];
                }
            } elseif ($why !== null) {
                $isBest = $best !== null && $cpa[$setId] === $best;
                $inC = $target && $cpa[$setId] !== null && $cpa[$setId] <= round($target, 2);
                if ($isBest || $inC) {
                    $res['stays'][] = ['adset' => (string) $set['name'], 'min' => $min, 'cpa' => $cpa[$setId], 'best' => $isBest,
                        'why' => ($isBest && $inC ? 'es el mejor y vende en tu C' : ($isBest ? 'es el mejor de la ciudad' : 'vende en tu C'))
                            . ': ' . AdRules::money($cpa[$setId]) . ' por compra en 7 días' . ($inC ? ' (tu C es ' . AdRules::money($target) . ')' : '')];
                } else {
                    $res['off'][] = ['adset' => (string) $set['name'], 'min' => $min, 'why' => $why, 'cpa' => $cpa[$setId]];
                }
            } else {
                $res['keep'][] = ['adset' => (string) $set['name'], 'min' => $min];
            }
        }

        return $res;
    }

    /** `.119`: si el creativo de este anuncio es un ganador que pierde dinero en esta ciudad. */
    private static function stopsHere(array $ad, array $out, string $campaignId): bool
    {
        $cr = $ad['creative_id'] !== null ? ($out['creatives'][$ad['creative_id']] ?? null) : null;

        return !empty(collect($cr['cities'] ?? [])->first(fn ($x) => (string) $x['campaign_meta_id'] === $campaignId)['stop']);
    }

    /**
     * `.87` (dueño, 2026-11-03: "ese batch debería aparecer como que está lanzado en Maracay, pero que está en banca en
     * Carabobo y en Caracas"): lo que puede lanzar una campaña, en orden: los batches que ya ganan en otra ciudad (prometedor
     * o PROVEN), los de la banca y los demás que salieron en otra ciudad pero no en esta (también si allá perdieron: el dueño
     * eligió ofrecerlos igual, cada ciudad es distinta). De los que ya están en Meta se duplica el ad set que mejor va. PHP puro.
     *
     * @param list<string> $launched los B#### del producto en test o testeados
     * @param list<string> $ready los B#### en banca, listos para lanzar
     * @param array<string, array{adset: ?string, ads: list<string>}> $names .89: los nombres exactos de los de la banca
     * @return list<array{code: string, how: string, adset?: string, ads?: list<string>, campaign?: ?string, city?: ?string, verdict?: ?string}>
     */
    public static function offers(array $f, array $out, string $campaignId, array $launched, array $ready, array $names = []): array
    {
        $rank = ['proven' => 0, 'promising' => 1, 'failing' => 2, 'intermediate' => 3, 'evaluation' => 4, 'no_data' => 5, 'no_target' => 6, 'loser' => 7];
        $rankOf = fn (array $set) => $rank[$out['adsets'][$set['meta_id']]['key'] ?? ''] ?? 9;
        $cityOf = fn (string $id) => $f['campaigns'][$id]['city_id'] ?? null;
        $mine = $cityOf($campaignId);
        $here = fn (array $set) => $mine !== null ? $cityOf((string) $set['campaign_meta_id']) === $mine : (string) $set['campaign_meta_id'] === $campaignId;
        $good = [];
        $rest = [];
        foreach ($launched as $code) {
            $sets = array_filter($f['adsets'], fn ($set) => ($set['batch_code'] ?? null) === $code);
            $from = array_values(array_filter($sets, fn ($set) => !($set['missing'] ?? false)));
            if (!$from || array_filter($sets, $here)) {
                continue; // ya salió en esta ciudad, o ya no está en Meta para duplicarlo
            }
            usort($from, fn ($a, $b) => $rankOf($a) <=> $rankOf($b));
            $v = $out['adsets'][$from[0]['meta_id']] ?? null;
            $c = $f['campaigns'][(string) $from[0]['campaign_meta_id']] ?? [];
            $offer = ['code' => $code, 'how' => 'copy', 'adset' => (string) $from[0]['name'], 'campaign' => $c['name'] ?? null, 'city' => $c['city'] ?? null,
                'verdict' => $v['label'] ?? null, '_rank' => $rankOf($from[0])];
            if (in_array($v['key'] ?? null, ['proven', 'promising'], true)) {
                $good[] = $offer;
            } else {
                $rest[] = $offer;
            }
        }
        usort($rest, fn ($a, $b) => $a['_rank'] <=> $b['_rank']);
        $strip = fn (array $o) => array_diff_key($o, ['_rank' => 1]);

        $bank = fn ($code) => ['code' => (string) $code, 'how' => 'bank'] + (($names[$code]['adset'] ?? null) ? ['adset' => $names[$code]['adset'], 'ads' => $names[$code]['ads']] : []);

        return [...array_map($strip, $good), ...array_map($bank, $ready), ...array_map($strip, $rest)];
    }

    /** El veredicto de un batch, anuncio o creativo en una ciudad: el estado de toda su vida y, si fue ganador, si empezó a fallar. */
    private function entity(array $windows, ?array $c, AdRules $rules, string $unit, array $daily, ?float $cityCpmChange, ?float $tracking): array
    {
        $life = $windows['max']['totals'] ?? null;
        $state = $rules->state((float) ($life['spend'] ?? 0), (float) ($life['purchases'] ?? 0), $c['target'] ?? null, $c['break_even'] ?? null, $unit, (bool) ($c['break_even_estimated'] ?? false));
        $good = in_array($state['key'], ['no_target', 'no_data'], true) ? false : $rules->hadGoodHistory($state, $daily, $c['target'] ?? null);
        if ($rules->isFailing($good, $windows['3d'] ?? null, $windows['7d'] ?? null, $c['target'] ?? null, $c['break_even'] ?? null)) {
            $causes = $rules->whatChanged($windows['3d'] ?? null, $windows['prev'] ?? null, $windows['max'] ?? null, $cityCpmChange, $tracking);

            return $rules->failingVerdict($state, $causes) + ['causes' => $causes, 'good' => $good];
        }
        // "Perdedor, salvo historia buena": si fue ganador alguna vez no se trata como perdedor sin ver qué cambió
        if ($state['key'] === 'loser' && $good) {
            $causes = $rules->whatChanged($windows['3d'] ?? null, $windows['prev'] ?? null, $windows['max'] ?? null, $cityCpmChange, $tracking);

            return $rules->failingVerdict($state, $causes) + ['causes' => $causes, 'good' => $good];
        }

        return $state + ['causes' => [], 'good' => $good];
    }

    /** El estado de un creativo en general, a partir de sus ciudades. */
    private function overall(array $byCity, ?array $cross, array $cityNames = []): array
    {
        if (!$byCity) {
            return ['key' => 'no_data', 'label' => AdRules::STATES['no_data']['label'], 'tone' => 'default', 'data' => 'Sin gasto todavía.',
                'reading' => 'Sin datos.', 'proposal' => null, 'causes' => []];
        }
        $order = ['failing', 'proven', 'promising', 'intermediate', 'evaluation', 'loser', 'no_data', 'no_target'];
        $best = collect($byCity)->sortBy(fn ($v) => array_search($v['key'], $order, true))->first();
        if (($cross['key'] ?? null) === 'loser_all' || count($byCity) === 1 && $best['key'] === 'loser') {
            $best = collect($byCity)->first(fn ($v) => $v['key'] === 'loser') ?? $best;
        }
        $v = $best;
        if ($v['key'] === 'loser') {
            $v['proposal'] = count($byCity) > 1 ? 'Apagarlo en todas las ciudades.' : 'Apagarlo' . (($city = array_key_first($cityNames)) ? " en {$city}" : '') . '.';
        }
        if ($cross) {
            $v['reading'] = $cross['label'] . ($cross['detail'] ? " ({$cross['detail']})" : '') . '. ' . $v['reading'];
            if ($cross['key'] === 'city_sensitive') {
                $v['proposal'] = 'Apagarlo solo en las ciudades donde pierde; mantenerlo donde gana.';
            }
        }

        return $v;
    }

    /**
     * `.113` (dueño, 2026-11-07: ZenPulse C0153 salió "Apaga C0153 en todas las campañas" porque perdió en Aragua, pero en
     * Carabobo seguía prendido con $1,15 gastados: "todavía no ha gastado suficiente como para poder determinarlo perdedor o
     * ganador"): si pierde donde se probó, pero en otra ciudad sigue prendido sin haber gastado 1×C, no es perdedor en todas.
     * Se apaga solo donde perdió y en las otras se sigue probando, también si ya perdió en 2 ciudades o más (lo eligió el
     * dueño). Si ahí ya está apagado, sigue siendo perdedor (overall). PHP puro.
     *
     * @param array<string, array> $byCity el veredicto por campaña (ciudad)
     * @param array<string, ?float> $testing campaña => desde cuánto gasto se juzga ahí (1×C), donde se sigue probando
     * @param array<string, bool> $activeIn campaña => si sigue prendido ahí
     * @param array<string, string> $cityOf campaña => ciudad
     */
    public static function stillTesting(array $byCity, array $testing, array $activeIn, array $cityOf, float $noDataCpa): array
    {
        $city = fn ($id) => $cityOf[$id] ?? (string) $id;
        $lostIds = array_keys(array_filter($byCity, fn ($v) => $v['key'] === 'loser'));
        $lost = array_map($city, $lostIds);
        $lostOn = array_map($city, array_values(array_filter($lostIds, fn ($id) => $activeIn[$id] ?? false)));
        $there = CreativeCalls::join(array_map($city, array_keys($testing)));
        $from = count($testing) === 1 ? reset($testing) : null;
        $wait = "En {$there} no lo toques: todavía se prueba ("
            . ($from !== null ? 'se juzga ahí desde ' . AdRules::money($from) : 'se juzga en cada una cuando gaste ' . AdRules::num($noDataCpa) . '×C') . ').';

        return ['key' => 'evaluation', 'label' => AdRules::STATES['evaluation']['label'], 'tone' => AdRules::STATES['evaluation']['tone'],
            'data' => implode(' | ', array_map(fn ($id) => $city($id) . ': ' . $byCity[$id]['data'], [...$lostIds, ...array_keys($testing)])),
            'reading' => 'Pierde en ' . CreativeCalls::join($lost) . "; en {$there} todavía no gastó " . AdRules::num($noDataCpa) . '×C.',
            'proposal' => ($lostOn ? 'Apagarlo solo en ' . CreativeCalls::join($lostOn) . '. ' : '') . $wait,
            'wait' => $wait, 'causes' => []];
    }

    /** Las ventanas de un creativo: sumando sus anuncios por ciudad (campaña) y en total. */
    private function creativeWindows(array $cr, array $f): array
    {
        $cities = [];
        $byCity = [];
        foreach ($cr['ads'] as $mid) {
            $ad = $f['ads'][$mid] ?? null;
            if (!$ad) {
                continue;
            }
            $byCity[$ad['campaign_meta_id']][] = $mid;
        }
        foreach ($byCity as $campaignId => $metaIds) {
            $cities[$campaignId] = $this->sumAds($metaIds, $f);
        }

        return ['cities' => $cities, 'ads_by_city' => $byCity, 'all' => $this->sumAds($cr['ads'], $f)];
    }

    /** Las ventanas de un grupo de anuncios, sumadas (el alcance sumado es aproximado). */
    private function sumAds(array $metaIds, array $f): array
    {
        $out = [];
        foreach (AdFacts::WINDOWS as $w) {
            $rows = array_values(array_filter(array_map(fn ($m) => $f['ads'][$m]['windows'][$w]['totals'] ?? null, $metaIds)));
            if ($rows) {
                $totals = MetaMetrics::sum($rows);
                $out[$w] = ['totals' => $totals, 'metrics' => MetaMetrics::compute($totals)];
            }
        }

        return $out;
    }

    /** La ventana con muestra para leer el video: 3 días, si no 7, si no toda la vida. */
    private function window(array $windows): ?array
    {
        foreach (['3d' => '3 días', '7d' => '7 días', 'max' => 'toda la vida'] as $w => $label) {
            if (($windows[$w]['totals']['impressions'] ?? 0) >= self::MIN_IMPRESSIONS) {
                return $windows[$w] + ['window' => $label];
            }
        }

        return null;
    }

    /**
     * El gasto y las compras por día de lo que podría ser "ganador que falla": solo de los anuncios y batches con gasto
     * suficiente para tener un veredicto.
     */
    private function historyFor(array $f, AdRules $rules): array
    {
        $needs = function (array $e, ?array $c) {
            $life = $e['windows']['max']['totals'] ?? null;

            return $c && $c['target'] && $life && (float) $life['spend'] >= (float) $c['target'];
        };
        $ads = array_keys(array_filter($f['ads'], fn ($a) => $needs($a, $f['campaigns'][$a['campaign_meta_id']] ?? null)
            || $a['creative_id'] !== null));
        $adsDaily = $this->facts->dailyHistory($ads);
        // El de un batch es la suma de sus anuncios
        $sets = [];
        foreach ($f['ads'] as $mid => $a) {
            foreach ($adsDaily[$mid] ?? [] as $date => $row) {
                $sets[$a['adset_meta_id']][$date]['spend'] = ($sets[$a['adset_meta_id']][$date]['spend'] ?? 0) + $row['spend'];
                $sets[$a['adset_meta_id']][$date]['purchases'] = ($sets[$a['adset_meta_id']][$date]['purchases'] ?? 0) + $row['purchases'];
            }
        }

        return ['ad' => $adsDaily, 'adset' => $sets];
    }

    private function sumHistory(array $adsDaily, array $metaIds): array
    {
        $out = [];
        foreach ($metaIds as $mid) {
            foreach ($adsDaily[$mid] ?? [] as $date => $row) {
                $out[$date]['spend'] = ($out[$date]['spend'] ?? 0) + $row['spend'];
                $out[$date]['purchases'] = ($out[$date]['purchases'] ?? 0) + $row['purchases'];
            }
        }

        return $out;
    }

    /**
     * `.111` (dueño, 2026-11-06: "si se hizo un cambio importante en una campaña, como añadirle un batch nuevo o aumentarle
     * el presupuesto, se deben esperar 24 horas para ver qué tal resultó ese cambio antes de tomar otra decisión"): el último
     * cambio grande de la campaña en las últimas $hours horas: el presupuesto (la sincronización lo ve cada 30 minutos), un
     * anuncio nuevo (su hora de creación en Meta) o volver a prenderla. PHP puro.
     *
     * @return array{what: string, at: string, until: string, hours: float}|null
     */
    public static function recentChange(array $h, array $f, string $campaignId, string $now, float $hours): ?array
    {
        if ($hours <= 0) {
            return null;
        }
        $changes = [];
        foreach ($h['budget_events'] ?? [] as $e) {
            $changes[] = [$e['at'], ($e['to'] > $e['from'] ? 'subiste' : 'bajaste') . ' el presupuesto de ' . AdRules::money($e['from']) . ' a ' . AdRules::money($e['to'])];
        }
        foreach ($h['status_events'] ?? [] as $e) {
            if ($e['to'] === 'ACTIVE' && $e['from'] === 'PAUSED') {
                $changes[] = [$e['at'], 'volviste a prender la campaña'];
            }
        }
        foreach ($f['ads'] ?? [] as $ad) {
            if ((string) $ad['campaign_meta_id'] === $campaignId && ($ad['created_time'] ?? null)) {
                $set = $f['adsets'][$ad['adset_meta_id']]['name'] ?? null;
                $changes[] = [(string) $ad['created_time'], $set ? 'entró el batch «' . $set . '»' : 'entraron anuncios nuevos'];
            }
        }
        $end = strtotime($now);
        $recent = array_values(array_filter($changes, fn ($c) => strtotime($c[0]) > $end - $hours * 3600 && strtotime($c[0]) <= $end));
        if (!$recent) {
            return null;
        }
        usort($recent, fn ($a, $b) => strtotime($b[0]) <=> strtotime($a[0]));
        [$at, $what] = $recent[0];

        return ['what' => $what, 'at' => date('Y-m-d H:i:s', strtotime($at)), 'until' => date('Y-m-d H:i:s', strtotime($at) + (int) round($hours * 3600)),
            'hours' => $hours];
    }

    /**
     * `.123` (dueño, 2026-11-09: "prioriza siempre el batch nuevo, si con el batch nuevo no vuelve a la normalidad entonces sí
     * se le baja el presupuesto. Pero solo un cambio grande a la vez"): lo último que se hizo en una campaña desde que su CPA
     * de 3 días se pasó de C (contando los 3 días de esa primera lectura): el último batch que entró, la última bajada de
     * presupuesto después de él y la última subida de los últimos $undoDays días que todavía se puede devolver. PHP puro.
     *
     * @return array{since: string, batch: ?array{date: string, name: ?string, closed: int}, lowered: ?array{date: string, from: float, to: float, closed: int}, undo: ?array{date: string, from: float, to: float}}
     */
    public static function band(array $h, array $f, string $campaignId, ?float $c, ?float $budget, string $today, string $yesterday, int $undoDays): array
    {
        $above = $c ? CampaignRules::streak($h['daily'] ?? [], $c, $yesterday) : ['days' => 0, 'start' => null];
        $since = date('Y-m-d', strtotime(($above['start'] ?? $yesterday) . ' -2 days'));
        $closed = fn (string $date) => max(0, (int) round((strtotime($yesterday) - strtotime($date)) / 86400));
        $batch = null;
        if ($dates = array_filter($h['new_ads'] ?? [], fn ($d) => $d >= $since && $d <= $today)) {
            $date = max($dates);
            $name = null;
            foreach ($f['ads'] ?? [] as $ad) {
                if ((string) $ad['campaign_meta_id'] === $campaignId && ($ad['created_time'] ?? null) && date('Y-m-d', strtotime((string) $ad['created_time'])) === $date) {
                    $name = $f['adsets'][$ad['adset_meta_id']]['name'] ?? $name;
                }
            }
            $batch = ['date' => $date, 'name' => $name, 'closed' => $closed($date)];
        }
        $events = $h['budget_events'] ?? [];
        usort($events, fn ($a, $b) => strtotime($a['at'] ?? $a['date']) <=> strtotime($b['at'] ?? $b['date']));
        $lowered = null;
        $undo = null;
        $from = date('Y-m-d', strtotime("{$today} -{$undoDays} days"));
        foreach ($events as $e) {
            if ($e['to'] < $e['from'] && $e['date'] >= ($batch['date'] ?? $since)) {
                $lowered = ['date' => $e['date'], 'from' => (float) $e['from'], 'to' => (float) $e['to'], 'closed' => $closed($e['date'])];
            }
            // Una subida que sigue puesta: el presupuesto de hoy está encima de lo que había antes de ella
            if ($e['to'] > $e['from'] && $e['date'] >= $from && $budget !== null && $e['from'] > 0 && $e['from'] < $budget - 0.005) {
                $undo = ['date' => $e['date'], 'from' => (float) $e['from'], 'to' => (float) $e['to']];
            }
        }

        return ['since' => $since, 'batch' => $batch, 'lowered' => $lowered, 'undo' => $undo];
    }

    /**
     * `.126` (dueño, 2026-11-09: "pesa más ganar en dólares, la ganancia siempre va a pesar más"): la ganancia estimada de un
     * gasto: compras de Meta × BE − gasto. El BE es lo que deja cada compra de Meta antes de pagar la publicidad (ya trae
     * los costos, las entregas y los gastos fijos). Sin BE no se puede calcular. PHP puro.
     */
    public static function gain(float $spend, float $purchases, ?float $be): ?float
    {
        return $be ? round($purchases * $be - $spend, 2) : null;
    }

    /**
     * `.126`: lo que dejó un creativo en 7 días y en toda su vida, sumando cada ciudad con su BE (las ciudades sin BE no
     * entran; si ninguna lo tiene, null). PHP puro.
     *
     * @param array<string, array> $cities campaña => sus ventanas (creativeWindows)
     * @return array{week: ?float, life: ?float}
     */
    public static function creativeGain(array $cities, array $campaigns): array
    {
        $out = ['week' => null, 'life' => null];
        foreach ($cities as $campaignId => $w) {
            $be = $campaigns[$campaignId]['break_even'] ?? null;
            foreach (['week' => '7d', 'life' => 'max'] as $key => $window) {
                $g = self::gain((float) ($w[$window]['totals']['spend'] ?? 0), (float) ($w[$window]['totals']['purchases'] ?? 0), $be ? (float) $be : null);
                if ($g !== null) {
                    $out[$key] = round(($out[$key] ?? 0) + $g, 2);
                }
            }
        }

        return $out;
    }

    /**
     * `.126`: lo que dejó el producto entero en 3 y en 7 días: la suma de sus campañas con BE (también las apagadas que
     * gastaron en esos días). Null si ninguna tiene BE. PHP puro.
     *
     * @return array{d3: float, d7: float, spend3: float, spend7: float}|null
     */
    public static function productGain(array $campaigns): ?array
    {
        $out = null;
        foreach ($campaigns as $c) {
            if (!($c['break_even'] ?? null)) {
                continue;
            }
            $out ??= ['d3' => 0.0, 'd7' => 0.0, 'spend3' => 0.0, 'spend7' => 0.0];
            foreach (['3' => '3d', '7' => '7d'] as $n => $window) {
                $spend = (float) ($c['windows'][$window]['totals']['spend'] ?? 0);
                $out["spend{$n}"] = round($out["spend{$n}"] + $spend, 2);
                $out["d{$n}"] = round($out["d{$n}"] + self::gain($spend, (float) ($c['windows'][$window]['totals']['purchases'] ?? 0), (float) $c['break_even']), 2);
            }
        }

        return $out;
    }

    /**
     * `.139`: lo que gastó el producto entero en sus últimos $days días cerrados, sus compras (`.140`: para su CPA real) y lo que
     * dejó (compras × BE − gasto, la suma de sus campañas con BE; null si ninguna lo tiene). PHP puro.
     *
     * @return array{spend: float, purchases: float, gain: ?float}
     */
    public static function periodMoney(array $f, array $histories, string $yesterday, int $days): array
    {
        $from = date('Y-m-d', strtotime("{$yesterday} -" . ($days - 1) . ' days'));
        $spend = $purchases = 0.0;
        $gain = null;
        foreach ($f['campaigns'] as $id => $c) {
            $window = array_filter($histories[(string) $id]['daily'] ?? [], fn ($d) => $d >= $from && $d <= $yesterday, ARRAY_FILTER_USE_KEY);
            $s = (float) array_sum(array_column($window, 'spend'));
            $p = (float) array_sum(array_column($window, 'purchases'));
            $spend += $s;
            $purchases += $p;
            if ($c['break_even'] ?? null) {
                $gain = ($gain ?? 0) + self::gain($s, $p, (float) $c['break_even']);
            }
        }

        return ['spend' => round($spend, 2), 'purchases' => $purchases, 'gain' => $gain === null ? null : round($gain, 2)];
    }

    /**
     * `.125`: lo que gastó y vendió una campaña en sus últimos $days días cerrados y lo que se intentó en ese tiempo (las veces
     * que entraron anuncios nuevos y las que se bajó el presupuesto). Null si todavía no lleva $days días gastando. PHP puro.
     *
     * @return array{days: int, spend: float, purchases: float, batches: int, lowered: int}|null
     */
    public static function chronic(array $h, string $yesterday, int $days): ?array
    {
        $daily = $h['daily'] ?? [];
        $from = date('Y-m-d', strtotime("{$yesterday} -" . ($days - 1) . ' days'));
        $spent = array_keys(array_filter($daily, fn ($r) => ($r['spend'] ?? 0) > 0));
        if (!$spent || min($spent) > $from) {
            return null;
        }
        $window = array_filter($daily, fn ($d) => $d >= $from && $d <= $yesterday, ARRAY_FILTER_USE_KEY);

        return ['days' => $days, 'spend' => round((float) array_sum(array_column($window, 'spend')), 2), 'purchases' => (float) array_sum(array_column($window, 'purchases')),
            'batches' => count(array_unique(array_filter($h['new_ads'] ?? [], fn ($d) => $d >= $from && $d <= $yesterday))),
            'lowered' => count(array_filter($h['budget_events'] ?? [], fn ($e) => $e['to'] < $e['from'] && $e['date'] >= $from && $e['date'] <= $yesterday))];
    }

    /**
     * `.125` (dueño, 2026-11-09: "sí debemos establecer un límite para soltar producto, dependiendo de su CPA y si hicimos
     * cambios y no mejoró"): el producto entero en sus últimos $days días cerrados: si entre todas sus campañas no dejó dinero
     * (compras × BE − gasto ≤ 0, con $minCpa×C gastado en cada una que gastó) y en ese tiempo entraron $minBatches batches o
     * más. Null si no aplica, o si todavía no lleva $days días gastando. PHP puro.
     *
     * @return array{days: int, spend: float, purchases: float, profit: float, batches: int, cities: list<array{city: ?string, spend: float, purchases: float, be: float}>}|null
     */
    public static function dropProduct(array $f, array $histories, string $yesterday, int $days, float $minCpa, int $minBatches): ?array
    {
        $from = date('Y-m-d', strtotime("{$yesterday} -" . ($days - 1) . ' days'));
        $spend = $buys = $profit = $need = 0.0;
        $first = null;
        $cities = [];
        $dates = [];
        foreach ($f['campaigns'] as $id => $c) {
            $h = $histories[(string) $id] ?? [];
            $daily = $h['daily'] ?? [];
            if (!$c['target'] || !$c['break_even'] || !$daily) {
                continue;
            }
            $spent = array_keys(array_filter($daily, fn ($r) => ($r['spend'] ?? 0) > 0));
            $first = $spent ? min($first ?? '9999', min($spent)) : $first;
            $window = array_filter($daily, fn ($d) => $d >= $from && $d <= $yesterday, ARRAY_FILTER_USE_KEY);
            $s = (float) array_sum(array_column($window, 'spend'));
            $p = (float) array_sum(array_column($window, 'purchases'));
            if ($s <= 0) {
                continue;
            }
            $spend += $s;
            $buys += $p;
            $profit += $p * $c['break_even'] - $s;
            $need += $minCpa * $c['target'];
            $cities[] = ['city' => $c['city'] ?? null, 'spend' => round($s, 2), 'purchases' => $p, 'be' => (float) $c['break_even']];
            array_push($dates, ...array_filter($h['new_ads'] ?? [], fn ($d) => $d >= $from && $d <= $yesterday));
        }
        if ($first === null || $first > $from || $spend <= 0 || $spend < $need || $profit > 0) {
            return null;
        }
        // Un batch que salió en 3 ciudades cuenta una vez: por el nombre de su ad set o, si no se sabe cuándo se creó, por el día
        $names = [];
        foreach ($f['adsets'] ?? [] as $set) {
            if (($set['created_time'] ?? null) && isset($f['campaigns'][$set['campaign_meta_id']]) && date('Y-m-d', strtotime((string) $set['created_time'])) >= $from) {
                $names[(string) $set['name']] = true;
            }
        }
        $batches = $names ? count($names) : count(array_unique($dates));
        if ($batches < $minBatches) {
            return null;
        }

        return ['days' => $days, 'spend' => round($spend, 2), 'purchases' => $buys, 'profit' => round($profit, 2), 'batches' => $batches, 'cities' => $cities];
    }

    /** `.111`: las horas desde que se creó el más viejo de unos anuncios (null si alguno no tiene la hora). PHP puro. */
    public static function ageHours(array $f, array $adIds, string $now): ?float
    {
        $times = array_map(fn ($id) => $f['ads'][$id]['created_time'] ?? null, $adIds);
        if (!$times || in_array(null, $times, true)) {
            return null;
        }

        return (strtotime($now) - min(array_map('strtotime', $times))) / 3600;
    }

    /** El último día con gasto de cada creativo. */
    private function lastSpend(array $creativeIds): array
    {
        if (!$creativeIds) {
            return [];
        }

        return DB::table('meta_insights_daily as d')->join('meta_ads as a', 'a.meta_id', '=', 'd.ad_meta_id')
            ->where('d.level', 'ad')->where('d.spend', '>', 0)->whereIn('a.meta_creative_ref_id', $creativeIds)
            ->groupBy('a.meta_creative_ref_id')->selectRaw('a.meta_creative_ref_id id, MAX(d.date) last')
            ->pluck('last', 'id')->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }
}
