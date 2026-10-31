<?php

namespace App\Services\Orders;

use App\Models\Landing;
use App\Models\MetaAd;
use App\Models\Order;
use App\Models\OrderSource;
use App\Services\Meta\Landings\LandingUrl;

/**
 * Guarda de dónde vino un pedido nuevo de Shopify (`.53`): lo lee OrderSourceRules y aquí se busca su anuncio (por el id
 * que dicen los parámetros) y su landing del sistema. La llama el webhook de orders/create, dentro de un try: si algo
 * falla, el pedido entra igual. Se guarda una sola vez por pedido (un reenvío de Shopify no lo cambia).
 */
class OrderSources
{
    public function record(Order $order, array $shopifyOrder): ?OrderSource
    {
        if (OrderSource::where('order_id', $order->id)->exists()) {
            return null;
        }
        $p = OrderSourceRules::parse($shopifyOrder);
        $ad = $p['ad_meta_id'] !== null ? MetaAd::where('meta_id', $p['ad_meta_id'])->first(['id', 'landing_url']) : null;
        [$landingId, $from] = $this->landing($p, $ad);

        return OrderSource::create([
            'order_id' => $order->id,
            'landing_url' => $p['landing_url'] !== null ? mb_substr($p['landing_url'], 0, 500) : null,
            'referring_site' => $p['referring_site'],
            'source_name' => $p['source_name'],
            'utm_source' => $p['utm_source'],
            'utm_medium' => $p['utm_medium'],
            'utm_campaign' => $p['utm_campaign'],
            'utm_term' => $p['utm_term'],
            'utm_content' => $p['utm_content'],
            'has_fbclid' => $p['has_fbclid'],
            'ad_meta_id' => $p['ad_meta_id'],
            'adset_meta_id' => $p['adset_meta_id'],
            'campaign_meta_id' => $p['campaign_meta_id'],
            'meta_ad_id' => $ad?->id,
            'landing_id' => $landingId,
            'landing_from' => $from,
        ]);
    }

    /**
     * La landing del sistema: la de la página donde entró el pedido (con dominio, por su URL; si Shopify mandó solo la
     * ruta, la única landing con esa ruta, o la del anuncio si hay varias). Si no se encuentra, la del anuncio.
     *
     * @return array{0: ?int, 1: ?string} la landing y de dónde salió (visit o ad)
     */
    private function landing(array $p, ?MetaAd $ad): array
    {
        $adLanding = $ad?->landing_url ? LandingUrl::normalize($ad->landing_url) : null;
        if ($p['landing_url'] !== null) {
            if ($p['landing_absolute']) {
                if ($id = Landing::where('url_hash', LandingUrl::hash($p['landing_url']))->value('id')) {
                    return [(int) $id, 'visit'];
                }
            } else {
                $candidates = Landing::where('url', 'like', '%' . $p['landing_url'])->get(['id', 'url'])
                    ->filter(fn (Landing $l) => OrderSourceRules::pathOf($l->url) === $p['landing_url'])->values();
                if ($candidates->count() === 1) {
                    return [(int) $candidates[0]->id, 'visit'];
                }
                if ($adLanding !== null && ($hit = $candidates->first(fn (Landing $l) => $l->url === $adLanding))) {
                    return [(int) $hit->id, 'visit'];
                }
            }
        }
        if ($adLanding !== null && ($id = Landing::where('url_hash', LandingUrl::hash($adLanding))->value('id'))) {
            return [(int) $id, 'ad'];
        }

        return [null, null];
    }
}
