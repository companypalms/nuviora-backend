<?php

namespace App\Services\Storefront;

use App\Models\MetaAd;
use App\Models\MetaAdset;
use App\Models\MetaPurchaseMode;
use App\Services\Orders\OrderSourceRules;

/**
 * `.168` (dueño, 2026-11-21: "si funciona bien y los kpis muestran que es el camino correcto, lo aplicamos en el resto de los
 * productos"): las campañas marcadas "Purchase al entregar". Se compara con dos campañas iguales del mismo producto, una
 * marcada y otra no. Un pedido sigue a la campaña de su anuncio (los parámetros de URL de la `.53`).
 */
final class PurchaseModes
{
    /** @return list<array{campaign: string, from: string, to: ?string}> */
    public static function ranges(): array
    {
        return MetaPurchaseMode::orderBy('id')->get()->map(fn (MetaPurchaseMode $m) => [
            'campaign' => (string) $m->campaign_meta_id, 'from' => $m->starts_on->toDateString(), 'to' => $m->ends_on?->toDateString(),
        ])->all();
    }

    /** La campaña de un pedido del formulario: la de su landing (utm_campaign) o la de su anuncio o su ad set. */
    public static function campaignOf(?string ...$urls): ?string
    {
        foreach ($urls as $url) {
            if (!$url) {
                continue;
            }
            $p = OrderSourceRules::parse(['landing_site' => $url]);
            if ($p['campaign_meta_id']) {
                return $p['campaign_meta_id'];
            }
            $fromAd = $p['ad_meta_id'] ? MetaAd::where('meta_id', $p['ad_meta_id'])->value('campaign_meta_id') : null;
            $fromAdset = !$fromAd && $p['adset_meta_id'] ? MetaAdset::where('meta_id', $p['adset_meta_id'])->value('campaign_meta_id') : null;
            if ($fromAd || $fromAdset) {
                return (string) ($fromAd ?: $fromAdset);
            }
        }

        return null;
    }

    /** Marca o desmarca una campaña desde hoy. Al desmarcar, hoy queda como su último día (lo de hoy ya salió así). */
    public static function set(?int $productId, string $campaignMetaId, bool $on): void
    {
        $open = MetaPurchaseMode::where('campaign_meta_id', $campaignMetaId)->whereNull('ends_on')->first();
        if ($on && !$open) {
            MetaPurchaseMode::create(['product_id' => $productId, 'campaign_meta_id' => $campaignMetaId, 'starts_on' => today()->toDateString()]);
        } elseif (!$on && $open) {
            $open->update(['ends_on' => today()->toDateString()]);
        }
    }

    /** Las campañas marcadas ahora. @return list<string> */
    public static function markedNow(): array
    {
        return MetaPurchaseMode::whereNull('ends_on')->pluck('campaign_meta_id')->map(fn ($v) => (string) $v)->unique()->values()->all();
    }
}
