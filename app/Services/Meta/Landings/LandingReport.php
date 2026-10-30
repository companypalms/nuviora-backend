<?php

namespace App\Services\Meta\Landings;

use App\Models\AdAngle;
use App\Models\Landing;
use App\Models\MetaAdLandingChange;
use App\Models\Product;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\MetaAdsReport;
use Illuminate\Support\Facades\DB;

/**
 * Las landings de un producto medidas una por una (Etapa 5, paso 6a), para Meta Ads › Landings. Cada día de cada anuncio
 * cuenta para la landing a la que llevaba ese día (meta_ad_landing_changes); por landing, por ciudad y por ángulo; con la
 * C de cada ciudad para el CPA contra C. Lo que dicen los números lo decide LandingRules. Solo lectura.
 */
class LandingReport
{
    public function __construct(private DecisionSettings $settings = new DecisionSettings())
    {
    }

    /** Los productos con landings: los de sus landings y los de las campañas de sus anuncios. */
    public function products(): array
    {
        $ids = DB::table('landings')->whereNotNull('product_id')->pluck('product_id')
            ->merge(DB::table('meta_ad_landing_changes as ch')->join('meta_ads as a', 'a.id', '=', 'ch.meta_ad_id')
                ->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')->whereNotNull('c.product_id')->distinct()->pluck('c.product_id'))
            ->unique()->values();

        return Product::whereIn('id', $ids)->get(['id', 'title', 'showable_name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title])->sortBy('name')->values()->all();
    }

    public function forProduct(int $productId, string $range = 'last_30d', ?string $from = null, ?string $to = null, ?int $cityId = null): array
    {
        [$range, $from, $to] = MetaAdsReport::resolveRange($range, $from, $to);
        $s = $this->settings->get();
        $ads = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->where('c.product_id', $productId)->when($cityId !== null, fn ($q) => $q->where('c.city_id', $cityId))
            ->get(['a.id', 'a.meta_id', 'a.angle_code', 'a.effective_status', 'c.city_id', 'ci.name as city'])->keyBy('id');
        $changes = MetaAdLandingChange::whereIn('meta_ad_id', $ads->keys())->orderBy('changed_at')->orderBy('id')->get(['meta_ad_id', 'landing_id', 'changed_at'])
            ->groupBy('meta_ad_id')->map(fn ($rows) => $rows->map(fn ($r) => [(int) $r->landing_id, $r->changed_at->format('Y-m-d H:i:s')])->values()->all())->all();
        $byMeta = $ads->keyBy('meta_id');
        $rows = $byMeta->isEmpty() ? collect() : DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('ad_meta_id', $byMeta->keys())
            ->whereBetween('date', [$from, $to])->get(['date', 'ad_meta_id', 'spend', 'impressions', 'link_clicks', 'landing_page_views', 'purchases']);

        [$canon, $angleNames] = $this->angles($productId);
        $report = new MetaAdsReport([], $range, $from, $to);
        $targets = [];
        $product = LandingRules::empty();
        $unknown = LandingRules::empty();
        $per = [];
        foreach ($rows as $r) {
            $ad = $byMeta[$r->ad_meta_id];
            $c = $targets[$ad->city_id ?? 0] ??= $report->targetFor($productId, $ad->city_id !== null ? (int) $ad->city_id : null)['target_cpa'];
            $row = (array) $r;
            $lid = LandingRules::at($changes[$ad->id] ?? [], substr((string) $r->date, 0, 10));
            if ($lid === null) {
                LandingRules::add($unknown, $row, $c);
                continue;
            }
            // "El producto" es la suma de sus landings: los anuncios sin landing conocida van aparte
            LandingRules::add($product, $row, $c);
            $per[$lid] ??= ['totals' => LandingRules::empty(), 'angles' => [], 'cities' => []];
            LandingRules::add($per[$lid]['totals'], $row, $c);
            $code = $ad->angle_code !== null ? ($canon[$ad->angle_code] ?? $ad->angle_code) : '';
            $per[$lid]['angles'][$code] ??= LandingRules::empty();
            LandingRules::add($per[$lid]['angles'][$code], $row, $c);
            $cityKey = $ad->city_id ?? 0;
            $per[$lid]['cities'][$cityKey] ??= ['city' => $ad->city, 'totals' => LandingRules::empty()];
            LandingRules::add($per[$lid]['cities'][$cityKey]['totals'], $row, $c);
        }

        // Los anuncios que hoy llevan a cada landing
        $current = [];
        $withoutLanding = 0;
        foreach ($ads as $ad) {
            $list = $changes[$ad->id] ?? [];
            if (!$list) {
                $withoutLanding++;
                continue;
            }
            $lid = end($list)[0];
            $current[$lid]['ads'] = ($current[$lid]['ads'] ?? 0) + 1;
            $current[$lid]['active'] = ($current[$lid]['active'] ?? 0) + ($ad->effective_status === 'ACTIVE' ? 1 : 0);
        }

        $landings = Landing::whereIn('id', array_unique(array_merge(array_keys($per), array_keys($current))))
            ->orWhere('product_id', $productId)->get()->keyBy('id');
        $input = [];
        foreach ($landings as $id => $l) {
            $input[$id] = ['label' => $l->label(), 'angle_code' => $l->angle_code, 'totals' => $per[$id]['totals'] ?? LandingRules::empty(), 'angles' => $per[$id]['angles'] ?? []];
        }
        $findings = LandingRules::findings($input, $product, $s, $angleNames);

        $list = $landings->map(function (Landing $l) use ($per, $current, $findings, $angleNames) {
            $p = $per[$l->id] ?? null;
            $f = $findings[$l->id] ?? [];

            return [
                'id' => $l->id, 'url' => $l->url, 'label' => $l->label(), 'name' => $l->name, 'angle_code' => $l->angle_code,
                'angle_name' => $l->angle_code !== null ? ($angleNames[$l->angle_code] ?? null) : null, 'notes' => $l->notes,
                'product_id' => $l->product_id, 'first_seen_at' => $l->first_seen_at?->format('Y-m-d H:i'),
                'ads' => $current[$l->id]['ads'] ?? 0, 'ads_active' => $current[$l->id]['active'] ?? 0,
            ] + self::block($p['totals'] ?? LandingRules::empty()) + [
                'angles' => collect($p['angles'] ?? [])->map(fn ($t, $code) => ['code' => $code === '' ? null : $code,
                    'name' => $code === '' ? 'Sin ángulo' : ($angleNames[$code] ?? $code)] + self::block($t))->sortByDesc('totals.spend')->values()->all(),
                'cities' => collect($p['cities'] ?? [])->map(fn ($c) => ['city' => $c['city'] ?? 'Sin ciudad'] + self::block($c['totals']))
                    ->sortByDesc('totals.spend')->values()->all(),
                'findings' => $f,
                'level' => LandingRules::level($f),
            ];
        })->sortByDesc(fn ($l) => [$l['totals']['spend'], $l['ads_active']])->values()->all();

        return [
            'range' => $range, 'from' => $from, 'to' => $to,
            'product' => self::block($product),
            'unknown' => self::block($unknown) + ['ads' => $withoutLanding],
            'landings' => $list,
            'angles' => collect($angleNames)->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values()->all(),
            'cities' => $ads->filter(fn ($a) => $a->city_id !== null)->unique('city_id')->map(fn ($a) => ['id' => (int) $a->city_id, 'name' => $a->city])
                ->sortBy('name')->values()->all(),
            'settings' => ['min_clicks' => $s['landing_min_clicks'], 'min_visits' => $s['landing_min_visits'], 'visit_leak' => $s['visit_leak'],
                'visit_ok' => $s['visit_ok'], 'gap' => $s['winners_gap_pct']],
        ];
    }

    /** Los ángulos del producto: cada código apunta al suyo (FAS → FASP) y su nombre. */
    private function angles(int $productId): array
    {
        $angles = AdAngle::where('product_id', $productId)->get(['id', 'code', 'alias_of', 'name'])->keyBy('id');
        $canon = [];
        $names = [];
        foreach ($angles as $a) {
            $root = $a->alias_of ? ($angles[$a->alias_of] ?? $a) : $a;
            $canon[$a->code] = $root->code;
            $names[$root->code] = $root->name ?: $root->code;
        }

        return [$canon, $names];
    }

    private static function block(array $t): array
    {
        return [
            'totals' => ['spend' => round($t['spend'], 2), 'impressions' => (int) $t['impressions'], 'link_clicks' => (int) $t['link_clicks'],
                'visits' => (int) $t['landing_page_views'], 'purchases' => (int) round($t['purchases'])],
            'metrics' => LandingRules::metrics($t),
        ];
    }
}
