<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\DB;

/**
 * `.89` (dueño, 2026-11-03: "es importante que el sistema siempre pueda saber de qué creativo, de qué batch y de qué
 * campaña estamos hablando"; eligió que avise "solo lo que no puede reconocer"): en las campañas activas, lo que rompe la
 * cadena: la campaña sin producto o ciudad (no se clasificó sola), sus ad sets prendidos sin número B y sus anuncios
 * prendidos sin código C. Los nombres viejos que el sistema sí entiende ("CRE001-FLEX-FAS-BATCH004") no se avisan. Solo lee.
 */
class NameCheck
{
    /** @return list<array{campaign_meta_id: string, campaign: string, product_id: ?int, product: ?string, unclassified: bool, sets: list<string>, ads: list<string>}> */
    public static function find(): array
    {
        $campaigns = DB::table('meta_campaigns as c')->join('meta_ad_accounts as a', 'a.id', '=', 'c.meta_ad_account_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')
            ->where('a.is_active', true)->where('c.effective_status', 'ACTIVE')->whereNull('c.missing_since')
            ->get(['c.meta_id', 'c.name', 'c.product_id', 'c.city_id', 'p.title', 'p.showable_name']);
        if ($campaigns->isEmpty()) {
            return [];
        }
        $ids = $campaigns->pluck('meta_id')->map(fn ($v) => (string) $v)->all();
        $sets = DB::table('meta_adsets')->whereIn('campaign_meta_id', $ids)->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->whereNull('batch_code')->orderBy('name')->get(['campaign_meta_id', 'name'])->groupBy('campaign_meta_id');
        $ads = DB::table('meta_ads')->whereIn('campaign_meta_id', $ids)->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->whereNull('creative_code')->orderBy('name')->get(['campaign_meta_id', 'name'])->groupBy('campaign_meta_id');
        $out = [];
        foreach ($campaigns as $c) {
            $id = (string) $c->meta_id;
            $unclassified = $c->product_id === null || $c->city_id === null;
            $badSets = ($sets[$id] ?? collect())->pluck('name')->map(fn ($v) => (string) $v)->all();
            $badAds = ($ads[$id] ?? collect())->pluck('name')->map(fn ($v) => (string) $v)->all();
            if ($unclassified || $badSets || $badAds) {
                $out[] = ['campaign_meta_id' => $id, 'campaign' => (string) $c->name, 'product_id' => $c->product_id !== null ? (int) $c->product_id : null,
                    'product' => $c->showable_name ?: $c->title, 'unclassified' => $unclassified, 'sets' => $badSets, 'ads' => $badAds];
            }
        }

        return $out;
    }
}
