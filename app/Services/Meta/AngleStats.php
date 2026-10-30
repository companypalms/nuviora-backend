<?php

namespace App\Services\Meta;

use App\Models\AdAngle;
use App\Models\Product;
use App\Models\ProductAdSetting;
use Illuminate\Support\Facades\DB;

/**
 * Los ángulos de un producto con sus números (Etapa 5): lo que se ve en Meta Ads › Ángulos y lo que lee el asistente, y
 * los productos que se trabajan en Meta (también para la cola de producción). Solo lee.
 */
final class AngleStats
{
    /** Los ángulos de un producto: los que valen solos, cada uno con sus alias, sus anuncios y 30 días de gasto y compras. */
    public static function forProduct(int $productId): array
    {
        $rows = AdAngle::where('product_id', $productId)->with('confirmer:id,names')->orderBy('code')->get();
        $ads = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->where('c.product_id', $productId)->whereNotNull('a.angle_code')->groupBy('a.angle_code')
            ->selectRaw("a.angle_code code, COUNT(*) ads, SUM(a.effective_status = 'ACTIVE' AND a.missing_since IS NULL) active,
                COUNT(DISTINCT a.meta_creative_ref_id) creatives, MAX(a.created_time) last_ad")
            ->get()->keyBy('code');
        $since = now()->subDays(29)->toDateString();
        $money = DB::table('meta_insights_daily as d')->join('meta_ads as a', 'a.meta_id', '=', 'd.ad_meta_id')
            ->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->where('d.level', 'ad')->where('d.date', '>=', $since)->where('c.product_id', $productId)->whereNotNull('a.angle_code')
            ->groupBy('a.angle_code')->selectRaw('a.angle_code code, SUM(d.spend) spend, SUM(d.purchases) purchases')
            ->get()->keyBy('code');

        // Un código sin anuncios y sin nombre (por ejemplo, un error de tipeo que se corrigió en Meta) no se muestra
        $visible = $rows->whereNull('alias_of')->filter(fn (AdAngle $a) => isset($ads[$a->code]) || $a->status !== 'detected');

        return $visible->map(function (AdAngle $a) use ($rows, $ads, $money) {
            $sample = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
                ->where('c.product_id', $a->product_id)->where('a.angle_code', $a->code)
                ->where(fn ($q) => $q->whereNotNull('a.ad_body')->orWhereNotNull('a.ad_title'))
                ->orderByDesc('a.created_time')->first(['a.ad_title', 'a.ad_body']);
            $spend = (float) ($money[$a->code]->spend ?? 0);
            $purchases = (float) ($money[$a->code]->purchases ?? 0);

            return [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'hypothesis' => $a->hypothesis, 'status' => $a->status,
                'proposed_by' => $a->proposed_by, 'suggested_alias' => $a->suggested_alias,
                'confirmed_by' => $a->confirmer?->names, 'confirmed_at' => $a->confirmed_at?->toIso8601String(),
                'aliases' => $rows->where('alias_of', $a->id)->map(fn ($x) => ['id' => $x->id, 'code' => $x->code])->values()->all(),
                'ads' => (int) ($ads[$a->code]->ads ?? 0), 'active_ads' => (int) ($ads[$a->code]->active ?? 0),
                'creatives' => (int) ($ads[$a->code]->creatives ?? 0), 'last_ad' => $ads[$a->code]->last_ad ?? null,
                'spend' => round($spend, 2), 'purchases' => round($purchases, 2),
                'cpa' => $purchases > 0 ? round($spend / $purchases, 2) : null,
                'sample' => $sample ? trim(($sample->ad_title ? $sample->ad_title . ' — ' : '') . mb_strimwidth((string) $sample->ad_body, 0, 280, '…')) : null,
            ];
        })->sortBy([['active_ads', 'desc'], ['spend', 'desc']])->values()->all();
    }

    /**
     * Los productos con campañas de Meta, ángulos o modo puesto; primero los que tienen más campañas activas.
     *
     * @return array<int, array{id: int, name: string, campaigns: int, active_campaigns: int}>
     */
    public static function products(): array
    {
        $campaigns = DB::table('meta_campaigns')->whereNotNull('product_id')->groupBy('product_id')
            ->selectRaw("product_id, COUNT(*) n, SUM(effective_status = 'ACTIVE' AND missing_since IS NULL) active")->get()->keyBy('product_id');
        $ids = $campaigns->keys()->merge(AdAngle::distinct()->pluck('product_id'))->merge(ProductAdSetting::pluck('product_id'))->unique();

        return Product::whereIn('id', $ids)->get(['id', 'title', 'showable_name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title,
                'campaigns' => (int) ($campaigns[$p->id]->n ?? 0), 'active_campaigns' => (int) ($campaigns[$p->id]->active ?? 0)])
            ->sortBy([['active_campaigns', 'desc'], ['name', 'asc']])->values()->all();
    }
}
