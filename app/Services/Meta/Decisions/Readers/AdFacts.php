<?php

namespace App\Services\Meta\Decisions\Readers;

use App\Services\Meta\MetaAdsReport;
use App\Services\Meta\MetaMetrics;
use Illuminate\Support\Facades\DB;

/**
 * Los datos con los que deciden las reglas (Etapa 5, paso 2), por producto → campaña de cada ciudad → batch (ad set) →
 * anuncio, y los creativos. Solo lectura, desde nuestra base.
 *
 * Las ventanas, como las usa el dueño:
 * - 3d: los 3 días cerrados (para decidir);
 * - prev: los 3 días cerrados anteriores (para ver qué cambió);
 * - 7d: los 7 días cerrados (la tendencia);
 * - yesterday: ayer (el control);
 * - max: toda la vida (la historia), como la calcula Meta (preset "maximum"); si todavía no llegó, la suma de los días.
 * Las sumas salen del histórico diario, igual que la pantalla de Meta Ads; el alcance y los clics únicos de 3, 7 días y
 * de los 3 anteriores, de lo que calcula Meta para todo el rango (meta_insights_current), también igual que la pantalla.
 */
class AdFacts
{
    public const WINDOWS = ['3d', 'prev', '7d', 'yesterday', 'max'];

    /** Los rangos de Meta que dan el alcance exacto de cada ventana. */
    private const PRESET = ['3d' => 'last_3d', 'prev' => 'prev_3d', '7d' => 'last_7d', 'max' => 'maximum'];

    private const LEVELS = ['campaign', 'adset', 'ad'];

    /** @return array{3d: array, prev: array, 7d: array, yesterday: array} [desde, hasta] de cada ventana */
    public static function dates(): array
    {
        [, $f3, $t3, $fp, $tp] = MetaAdsReport::resolveRange('last_3d');
        [, $f7, $t7] = MetaAdsReport::resolveRange('last_7d');
        [, $fy, $ty] = MetaAdsReport::resolveRange('yesterday');

        return ['3d' => [$f3, $t3], 'prev' => [$fp, $tp], '7d' => [$f7, $t7], 'yesterday' => [$fy, $ty]];
    }

    /**
     * @param int[] $productIds
     * @return array<int, array> por producto: product, campaigns, adsets, ads, creatives, tracking, dates
     */
    public function load(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $dates = self::dates();
        $report = new MetaAdsReport([], 'last_3d');

        $campaigns = DB::table('meta_campaigns as c')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->whereIn('c.product_id', $productIds)
            ->get(['c.meta_id', 'c.name', 'c.product_id', 'c.city_id', 'ci.name as city', 'c.effective_status', 'c.missing_since', 'c.meta']);
        $campaignIds = $campaigns->pluck('meta_id')->map(fn ($v) => (string) $v)->all();
        $adsets = DB::table('meta_adsets')->whereIn('campaign_meta_id', $campaignIds ?: ['-'])
            ->get(['meta_id', 'name', 'campaign_meta_id', 'effective_status', 'missing_since', 'batch_code', 'angle_code', 'concept', 'stage']);
        $ads = DB::table('meta_ads')->whereIn('campaign_meta_id', $campaignIds ?: ['-'])
            ->get(['id', 'meta_id', 'name', 'campaign_meta_id', 'adset_meta_id', 'effective_status', 'missing_since', 'meta_creative_ref_id',
                'creative_type', 'video_length', 'angle_code', 'batch_code', 'created_time']);
        $creatives = DB::table('meta_creatives')->whereIn('id', $ads->pluck('meta_creative_ref_id')->filter()->unique()->values()->all() ?: [0])
            ->get(['id', 'label', 'tracking_id', 'type', 'status', 'status_source', 'angle_code', 'batch_code', 'iteration_of', 'creative_code']);
        $products = DB::table('products')->whereIn('id', $productIds)->get(['id', 'title', 'showable_name'])->keyBy('id');
        $modes = DB::table('product_ad_settings')->whereIn('product_id', $productIds)->pluck('mode', 'product_id');

        $windows = [];
        foreach (self::LEVELS as $level) {
            $windows[$level] = $this->windows($level, $campaignIds, $dates);
        }

        $out = [];
        foreach ($productIds as $pid) {
            $p = $products->get($pid);
            $out[$pid] = [
                'product' => ['id' => $pid, 'name' => $p ? ($p->showable_name ?: $p->title) : "Producto {$pid}", 'mode' => $modes[$pid] ?? 'normal'],
                'campaigns' => [], 'adsets' => [], 'ads' => [], 'creatives' => [], 'dates' => $dates,
            ];
        }
        $productOf = [];
        $targets = [];
        foreach ($campaigns as $c) {
            $productOf[(string) $c->meta_id] = (int) $c->product_id;
            $key = $c->product_id . '|' . $c->city_id;
            $targets[$key] ??= $report->targetFor((int) $c->product_id, $c->city_id !== null ? (int) $c->city_id : null);
            $meta = json_decode((string) $c->meta, true) ?: [];
            $out[(int) $c->product_id]['campaigns'][(string) $c->meta_id] = [
                'meta_id' => (string) $c->meta_id, 'name' => $c->name, 'city_id' => $c->city_id !== null ? (int) $c->city_id : null, 'city' => $c->city,
                'active' => $c->effective_status === 'ACTIVE' && $c->missing_since === null,
                'daily_budget' => isset($meta['daily_budget']) && is_numeric($meta['daily_budget']) ? round((float) $meta['daily_budget'] / 100, 2) : null,
                'target' => $targets[$key]['target_cpa'], 'break_even' => $targets[$key]['break_even_cpa'], 'target_source' => $targets[$key]['source'],
                'windows' => $windows['campaign'][(string) $c->meta_id] ?? [],
            ];
        }
        foreach ($adsets as $s) {
            $pid = $productOf[(string) $s->campaign_meta_id] ?? null;
            if ($pid === null) {
                continue;
            }
            $out[$pid]['adsets'][(string) $s->meta_id] = [
                'meta_id' => (string) $s->meta_id, 'name' => $s->name, 'campaign_meta_id' => (string) $s->campaign_meta_id,
                'active' => $s->effective_status === 'ACTIVE' && $s->missing_since === null,
                'batch_code' => $s->batch_code, 'angle_code' => $s->angle_code, 'concept' => $s->concept, 'stage' => $s->stage,
                'windows' => $windows['adset'][(string) $s->meta_id] ?? [],
            ];
        }
        $creativeRows = $creatives->keyBy('id');
        foreach ($ads as $a) {
            $pid = $productOf[(string) $a->campaign_meta_id] ?? null;
            if ($pid === null) {
                continue;
            }
            $out[$pid]['ads'][(string) $a->meta_id] = [
                'id' => (int) $a->id, 'meta_id' => (string) $a->meta_id, 'name' => $a->name, 'campaign_meta_id' => (string) $a->campaign_meta_id,
                'adset_meta_id' => (string) $a->adset_meta_id, 'creative_id' => $a->meta_creative_ref_id !== null ? (int) $a->meta_creative_ref_id : null,
                'creative_type' => $a->creative_type, 'video_length' => $a->video_length !== null ? (float) $a->video_length : null,
                'angle_code' => $a->angle_code, 'batch_code' => $a->batch_code,
                'active' => $a->effective_status === 'ACTIVE' && $a->missing_since === null,
                'windows' => $windows['ad'][(string) $a->meta_id] ?? [],
            ];
            if ($a->meta_creative_ref_id !== null && ($cr = $creativeRows->get($a->meta_creative_ref_id))) {
                $out[$pid]['creatives'][(int) $cr->id] ??= [
                    'id' => (int) $cr->id, 'label' => $cr->label ?: $cr->tracking_id, 'type' => $cr->type, 'status' => $cr->status,
                    'status_source' => $cr->status_source, 'angle_code' => $cr->angle_code, 'batch_code' => $cr->batch_code,
                    'creative_code' => $cr->creative_code, 'iteration_of' => $cr->iteration_of, 'ads' => [],
                ];
                $out[$pid]['creatives'][(int) $cr->id]['ads'][] = (string) $a->meta_id;
            }
        }
        foreach ($this->tracking($productIds, $dates['3d']) as $pid => $t) {
            $out[$pid]['tracking'] = $t;
        }

        return $out;
    }

    /**
     * Gasto y compras de cada día de unos anuncios (para la "historia buena": si alguna vez fueron ganadores).
     *
     * @param string[] $adMetaIds
     * @return array<string, array<string, array{spend: float, purchases: float}>>
     */
    public function dailyHistory(array $adMetaIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($adMetaIds)), 500) as $chunk) {
            $rows = DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('meta_id', $chunk)
                ->get(['meta_id', 'date', 'spend', 'purchases']);
            foreach ($rows as $r) {
                $out[(string) $r->meta_id][substr((string) $r->date, 0, 10)] = ['spend' => (float) $r->spend, 'purchases' => (float) ($r->purchases ?? 0)];
            }
        }

        return $out;
    }

    /** Las ventanas de cada entidad de un nivel: totales y fórmulas. */
    private function windows(string $level, array $campaignIds, array $dates): array
    {
        if (!$campaignIds) {
            return [];
        }
        $out = [];
        $sum = fn (?string $from, ?string $to) => DB::table('meta_insights_daily as d')->where('d.level', $level)
            ->whereIn('d.campaign_meta_id', $campaignIds)
            ->when($from !== null, fn ($q) => $q->whereBetween('d.date', [$from, $to]))
            ->groupBy('d.meta_id')->selectRaw(MetaAdsReport::SUMS . ', d.meta_id mid')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->mid => (array) $r]);
        $current = fn (string $range) => DB::table('meta_insights_current as d')->where('d.level', $level)->where('d.range', $range)
            ->whereIn('d.campaign_meta_id', $campaignIds)->get()->mapWithKeys(fn ($r) => [(string) $r->meta_id => (array) $r]);

        foreach (['3d', 'prev', '7d', 'yesterday'] as $w) {
            $sums = $sum(...$dates[$w]);
            $exact = isset(self::PRESET[$w]) ? $current(self::PRESET[$w]) : collect();
            foreach ($sums as $id => $row) {
                $totals = MetaMetrics::sum([$row]);
                // El alcance y los clics únicos de varios días solo son exactos calculados por Meta para todo el rango
                if ($w !== 'yesterday' && ($e = $exact->get($id)) && $e['reach'] !== null) {
                    $totals['reach'] = (float) $e['reach'];
                    if ($e['unique_link_clicks'] !== null) {
                        $totals['unique_link_clicks'] = (float) $e['unique_link_clicks'];
                    }
                }
                $out[$id][$w] = ['totals' => $totals, 'metrics' => MetaMetrics::compute($totals)];
            }
        }
        // Toda la vida: el "maximum" de Meta; si todavía no llegó, la suma de todos los días guardados
        $max = $current('maximum');
        $all = $sum(null, null);
        foreach ($all->keys()->merge($max->keys())->unique() as $id) {
            $totals = MetaMetrics::sum([$max->get($id) ?? $all->get($id)]);
            $out[$id]['max'] = ['totals' => $totals, 'metrics' => MetaMetrics::compute($totals), 'source' => $max->has($id) ? 'meta' : 'days'];
        }

        return $out;
    }

    /**
     * Compras de Meta contra pedidos reales de cada producto en los 3 días (los anuncios son el único canal: si Meta ve
     * muchos menos, se pierden compras en el píxel o la API de conversiones).
     */
    private function tracking(array $productIds, array $range): array
    {
        $meta = DB::table('meta_insights_daily as d')->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->whereBetween('d.date', $range)->whereIn('c.product_id', $productIds)
            ->groupBy('c.product_id')->selectRaw('c.product_id pid, SUM(d.purchases) purchases')->pluck('purchases', 'pid');
        $orders = DB::table('order_financials')->where('kind', 'normal')->whereBetween('created_date', $range)
            ->whereIn('product_id', $productIds)->groupBy('product_id')->selectRaw('product_id pid, COUNT(*) n')->pluck('n', 'pid');
        $out = [];
        foreach ($productIds as $pid) {
            $m = (float) ($meta[$pid] ?? 0);
            $o = (int) ($orders[$pid] ?? 0);
            $out[$pid] = ['meta_purchases' => $m, 'orders' => $o, 'ratio' => $o > 0 ? round($m / $o * 100, 1) : null];
        }

        return $out;
    }
}
