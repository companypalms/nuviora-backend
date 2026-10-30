<?php

namespace App\Services\Meta\Decisions;

use App\Models\MetaCreative;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\MetaMetrics;
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
    private const ITERATE = ['hook' => 'el hook (los primeros segundos)', 'duration' => 'la duración o el ritmo', 'cta' => 'el cierre (CTA)',
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
        $out = [];
        foreach ($facts as $pid => $f) {
            $out[$pid] = $this->product($f, $rules, $s);
            $out[$pid] = $this->plan($f, $out[$pid], $history, $products[$pid] ?? null, new CampaignRules($s), $s);
        }

        return $out;
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

    private function product(array $f, AdRules $rules, array $s): array
    {
        $campaigns = $f['campaigns'];
        $tracking = $f['tracking']['ratio'] ?? null;
        $out = ['product' => $f['product'], 'tracking' => $f['tracking'] ?? null, 'dates' => $f['dates'],
            'campaigns' => [], 'adsets' => [], 'ads' => [], 'creatives' => []];

        // Lo que se ve de cada campaña (ciudad): saturación, CTR bajo en todos sus anuncios y cuánto subió el CPM
        $cpmChange = [];
        foreach ($campaigns as $id => $c) {
            $w3 = $c['windows']['3d'] ?? null;
            $cpmChange[$id] = MetaMetrics::change($w3['metrics']['cpm'] ?? null, $c['windows']['prev']['metrics']['cpm'] ?? null);
            $notes = [];
            $freq = $w3['metrics']['frequency'] ?? null;
            if ($rules->saturated($freq)) {
                $notes[] = ['key' => 'saturated', 'label' => 'Saturada', 'text' => 'Frecuencia de 3 días ' . AdRules::num($freq)
                    . ': el tráfico frío se está agotando. Hacen falta creativos de arriba del embudo.'];
            }
            $withData = array_filter($f['ads'], fn ($a) => $a['campaign_meta_id'] === $id && ($a['windows']['3d']['totals']['impressions'] ?? 0) >= self::MIN_IMPRESSIONS);
            if ($withData && !array_filter($withData, fn ($a) => ($a['windows']['3d']['metrics']['unique_ctr'] ?? 0) >= $s['ctr_weak'])) {
                $notes[] = ['key' => 'city_ctr_low', 'label' => 'CTR bajo en toda la ciudad', 'text' => 'Todos los anuncios con datos tienen el CTR único debajo de '
                    . AdRules::num($s['ctr_weak']) . ' %: primero creativos, después presupuesto.'];
            }
            $out['campaigns'][$id] = ['notes' => $notes, 'cpm_change' => $cpmChange[$id], 'frequency' => $freq, 'city' => $c['city']];
        }

        $history = $this->historyFor($f, $rules);

        // Batches (ad sets). Si Meta no trajo los números del ad set, se suman los de sus anuncios
        foreach ($f['adsets'] as $id => $set) {
            $c = $campaigns[$set['campaign_meta_id']] ?? null;
            $windows = ($set['windows']['max']['totals']['impressions'] ?? 0) > 0 ? $set['windows']
                : $this->sumAds(array_keys(array_filter($f['ads'], fn ($a) => $a['adset_meta_id'] === $id)), $f);
            $out['adsets'][$id] = $this->entity($windows, $c, $rules, 'batch', $history['adset'][$id] ?? [], $cpmChange[$set['campaign_meta_id']] ?? null, $tracking);
        }
        // Anuncios: el que concentra el gasto se apaga primero, no el batch
        foreach ($f['ads'] as $id => $ad) {
            $c = $campaigns[$ad['campaign_meta_id']] ?? null;
            $v = $this->entity($ad['windows'], $c, $rules, 'anuncio', $history['ad'][$id] ?? [], $cpmChange[$ad['campaign_meta_id']] ?? null, $tracking);
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
                if (in_array($states[$cid][$campaignId]['key'], ['proven', 'promising'], true)) {
                    $pick = $this->window($w);
                    if ($pick) {
                        $winners[] = $pick['metrics'];
                    }
                }
            }
        }
        $median = [];
        foreach (['hook_rate', 'hold_rate', 'unique_ctr', 'conversion_rate'] as $k) {
            $median[$k] = AdRules::median(array_map(fn ($m) => $m[$k] ?? null, $winners));
        }
        foreach ($f['creatives'] as $cid => $cr) {
            $byCity = $states[$cid] ?? [];
            $cityNames = [];
            foreach ($byCity as $campaignId => $v) {
                $cityNames[$campaigns[$campaignId]['city'] ?? $campaignId] = $v['key'];
            }
            $cross = $rules->crossCity($cityNames);
            $overall = $this->overall($byCity, $cross, $cityNames);
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
                'cities' => collect($byCity)->map(fn ($v, $campaignId) => ['campaign_meta_id' => $campaignId, 'city' => $campaigns[$campaignId]['city'] ?? null] + $v)->values()->all(),
                'funnel' => $funnel ? ['key' => $funnel, 'label' => AdRules::FUNNEL[$funnel], 'frequency' => $freq3] : null,
                'video' => $w ? $rules->video($w, $isVideo, $length, $median, $cityCpm, $w['metrics']['cpa'] ?? null, $mainC) + ['window' => $w['window']] : null,
                'status' => $cr['status'], 'status_source' => $cr['status_source'],
                // Paso 3: sus 7 días, para ordenar los creativos en el análisis de la mañana
                'week' => ['spend' => round((float) ($all['7d']['totals']['spend'] ?? 0), 2), 'purchases' => (float) ($all['7d']['totals']['purchases'] ?? 0),
                    'cpa' => isset($all['7d']['metrics']['cpa']) ? round((float) $all['7d']['metrics']['cpa'], 2) : null],
            ];
        }

        return $out;
    }

    /**
     * Paso 2b: qué hacer con cada campaña (con montos), el precio, la urgencia del producto, el trabajo siguiente y las
     * alarmas de tracking y stock.
     */
    private function plan(array $f, array $out, array $history, ?array $ops, CampaignRules $cr, array $s): array
    {
        $mode = $f['product']['mode'];
        $today = $history['today'];
        $yesterday = $history['yesterday'];
        $actions = [];
        $prices = [];
        $alarms = [];
        $minFor = [];
        foreach ($f['campaigns'] as $id => $c) {
            $h = $history['campaigns'][$id] ?? ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
            $city = $c['city_id'] !== null ? ($ops['cities'][$c['city_id']] ?? null) : null;
            $w3 = $c['windows']['3d'] ?? null;
            $w7 = $c['windows']['7d'] ?? null;
            $cpa7 = $w7['metrics']['cpa'] ?? null;
            $budget = $c['daily_budget'];
            $out['campaigns'][$id]['action'] = null;
            $out['campaigns'][$id]['budget_notes'] = [];
            if (!$c['active']) {
                // Una ciudad pausada hace poco porque perdía: el último test
                $paused = collect($h['status_events'])->last(fn ($e) => $e['from'] === 'ACTIVE' && $e['to'] !== 'ACTIVE');
                if ($paused && $c['target'] && $c['break_even'] && $mode === 'normal' && ($cpa7 === null || $cpa7 >= $c['break_even'])
                    && strtotime($paused['date']) >= strtotime("{$today} -14 days")) {
                    $test = $c['target'] * $s['last_test_budget_cpa'];
                    $out['campaigns'][$id]['action'] = ['key' => 'last_test', 'label' => CampaignRules::ACTIONS['last_test']['label'], 'tone' => 'warning',
                        'data' => 'Pausada el ' . $paused['date'] . '.', 'reading' => 'La ciudad se pausó por estar en el break-even o encima.',
                        'proposal' => 'Si quieres, el último test: un batch nuevo con ' . AdRules::money($test) . ' por día durante ' . (int) $s['last_test_days']
                            . ' días. Si también falla, dejar el producto en esta ciudad.', 'from' => null, 'to' => null];
                    $actions[$id] = 'last_test';
                }
                continue;
            }
            if ($city && !$city['available']) {
                $out['campaigns'][$id]['action'] = ['key' => 'none', 'label' => 'No se vende aquí', 'tone' => 'error', 'data' => '',
                    'reading' => 'El producto está marcado como no disponible en esta ciudad.', 'proposal' => 'Pausar la campaña.', 'from' => null, 'to' => null];
                $actions[$id] = 'pause_city';
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
            $lastTest = null;
            $reactivated = collect($h['status_events'])->last(fn ($e) => $e['to'] === 'ACTIVE' && $e['from'] !== 'ACTIVE');
            if ($reactivated && $c['target'] && $reactivated['budget'] !== null && $reactivated['budget'] <= $c['target'] * $s['last_test_budget_cpa'] * 1.15) {
                $days = max(0, (int) round((strtotime($yesterday) - strtotime($reactivated['date'])) / 86400));
                $sum = ['spend' => 0.0, 'purchases' => 0.0];
                foreach ($h['daily'] as $date => $row) {
                    if ($date > $reactivated['date'] && $date <= $yesterday) {
                        $sum['spend'] += $row['spend'];
                        $sum['purchases'] += $row['purchases'];
                    }
                }
                $lastTest = ['days' => $days, 'cpa' => $sum['purchases'] > 0 ? $sum['spend'] / $sum['purchases'] : null];
            }
            $stockOk = null;
            $stockNote = null;
            if ($city) {
                $stockOk = $city['days_of_stock'] === null ? $city['stock'] > 0 : $city['days_of_stock'] >= $s['stock_days_min'];
                $stockNote = 'quedan ' . $city['stock'] . ' unidades' . ($city['days_of_stock'] !== null ? ' (≈' . AdRules::num($city['days_of_stock']) . ' días de ventas)' : '');
                if ($city['stock'] <= 0) {
                    $alarms[] = 'Sin stock en ' . ($c['city'] ?? 'una ciudad') . ' y la campaña sigue activa.';
                } elseif ($stockOk === false) {
                    $alarms[] = 'Poco stock en ' . ($c['city'] ?? 'una ciudad') . ': ' . $stockNote . '.';
                }
                if ($city['sizes_out']) {
                    $alarms[] = 'Sin stock en ' . ($c['city'] ?? 'una ciudad') . ' de: ' . implode(', ', $city['sizes_out']) . '.';
                }
            }
            $prune = [];
            foreach ($f['ads'] as $adId => $ad) {
                if ($ad['campaign_meta_id'] === $id && $ad['active'] && ($out['ads'][$adId]['key'] ?? null) === 'loser') {
                    $prune[] = $ad['name'];
                }
            }
            $newBatches = 0;
            foreach ($f['adsets'] as $setId => $set) {
                if ($set['campaign_meta_id'] === $id && $set['active'] && $c['target'] && in_array($out['adsets'][$setId]['key'] ?? null, ['no_data'], true)) {
                    $newBatches++;
                }
            }
            $action = $cr->action([
                'c' => $c['target'], 'be' => $c['break_even'], 'mode' => $mode, 'budget' => $budget,
                'spend3' => $w3['totals']['spend'] ?? 0, 'purchases3' => $w3['totals']['purchases'] ?? 0, 'cpa7' => $cpa7, 'freq3' => $w3['metrics']['frequency'] ?? null,
                'stock_ok' => $stockOk, 'stock_note' => $stockNote,
                'increased_24h' => collect($h['budget_events'])->contains(fn ($e) => $e['to'] > $e['from'] && strtotime($e['at']) >= strtotime('-24 hours')),
                'budget_changed_today' => collect($h['budget_events'])->contains(fn ($e) => $e['date'] === $today),
                'new_ads_today' => in_array($today, $h['new_ads'], true),
                'streak_days' => $streak['days'], 'intervention' => $intervention, 'closed_since_intervention' => $closed,
                'decreased_since_intervention' => $intervention !== null && collect($h['budget_events'])->contains(fn ($e) => $e['to'] < $e['from'] && $e['date'] >= $intervention),
                'history_since' => $history['history_since'], 'last_test' => $lastTest, 'prune' => $prune,
                'bank_ready' => $ops['bank']['ready'] ?? [],
            ]);
            $out['campaigns'][$id]['action'] = $action;
            $out['campaigns'][$id]['budget_notes'] = $cr->budgetNotes($budget, (float) $h['mins'], $newBatches, $c['target']);
            $actions[$id] = $action['key'];

            // El precio: el mínimo con el CPA de 7 días; se ofrece junto al escalado
            $version = $city['version'] ?? null;
            if (!$version || (float) $version->purchases <= 0 || (int) $version->orders_delivered <= 0) {
                $version = $ops['version'] ?? null;
            }
            if ($version && ($ops['price'] ?? null) && $cpa7) {
                $min = CampaignRules::minPrice($version->toArray(), (float) $ops['price'], (float) $cpa7);
                $minFor[$id] = $min;
                $option = $cr->priceOption((float) $ops['price'], $min, $action['key'] === 'scale' && $cpa7 <= $c['target']);
                if ($option) {
                    $out['campaigns'][$id]['price_option'] = $option;
                    $prices[] = ($c['city'] ?? '') . ': ' . $option['text'];
                }
            }
        }

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
            if ($key === 'proven' && $p7 >= $s['proven_purchases'] && (!$winner || $p7 > $winner['p7'])) {
                $winner = ['label' => $cr2['label'], 'p7' => $p7, 'iterate' => self::ITERATE[$cr2['video']['iterate'] ?? ''] ?? null];
            }
        }
        $activeCities = array_filter($f['campaigns'], fn ($c) => $c['active']);
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
        $failedBatches = $mainAngle ? count(array_unique(array_map(fn ($set) => $set['batch_code'] ?? $set['meta_id'], array_filter($f['adsets'],
            fn ($set) => $set['angle_code'] === $mainAngle && ($out['adsets'][$set['meta_id']]['key'] ?? null) === 'loser')))) : 0;
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
        $out['plan'] = [
            'urgency' => $urgency,
            'next_work' => $cr->nextWork(['mode' => $mode, 'winner' => $winner, 'saturated' => $saturated, 'main_angle' => $mainAngle,
                'failed_batches_in_angle' => $failedBatches, 'bank' => $bank, 'needed' => $urgency['needed']], $urgency['batches']),
            'price' => $prices,
            'price_watch' => $cr->priceWatch($ops['price_change'] ?? null, $mainCampaign !== null ? ($minFor[$mainCampaign] ?? null) : null),
            'alarms' => array_values(array_unique($alarms)),
            'money_at_risk' => round(array_sum(array_map(fn ($id) => (float) ($f['campaigns'][$id]['windows']['3d']['totals']['spend'] ?? 0),
                array_keys(array_filter($actions, fn ($k) => in_array($k, ['intervene', 'decrease', 'pause_city', 'drop'], true))))), 2),
        ];

        return $out;
    }

    /** El veredicto de un batch, anuncio o creativo en una ciudad: el estado de toda su vida y, si fue ganador, si empezó a fallar. */
    private function entity(array $windows, ?array $c, AdRules $rules, string $unit, array $daily, ?float $cityCpmChange, ?float $tracking): array
    {
        $life = $windows['max']['totals'] ?? null;
        $state = $rules->state((float) ($life['spend'] ?? 0), (float) ($life['purchases'] ?? 0), $c['target'] ?? null, $c['break_even'] ?? null, $unit);
        $good = in_array($state['key'], ['no_target', 'no_data'], true) ? false : $rules->hadGoodHistory($state, $daily, $c['target'] ?? null);
        if ($rules->isFailing($good, $windows['3d'] ?? null, $windows['7d'] ?? null, $c['target'] ?? null, $c['break_even'] ?? null)) {
            $causes = $rules->whatChanged($windows['3d'] ?? null, $windows['prev'] ?? null, $windows['max'] ?? null, $cityCpmChange, $tracking);

            return $rules->failingVerdict($state, $causes) + ['causes' => $causes];
        }
        // "Perdedor, salvo historia buena": si fue ganador alguna vez no se trata como perdedor sin ver qué cambió
        if ($state['key'] === 'loser' && $good) {
            $causes = $rules->whatChanged($windows['3d'] ?? null, $windows['prev'] ?? null, $windows['max'] ?? null, $cityCpmChange, $tracking);

            return $rules->failingVerdict($state, $causes) + ['causes' => $causes];
        }

        return $state + ['causes' => []];
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
