<?php

namespace App\Services\Storefront;

use App\Models\MetaAd;
use App\Models\MetaAdset;
use App\Models\MetaPurchaseMode;
use App\Services\Orders\OrderSourceRules;
use Illuminate\Support\Facades\DB;

/**
 * `.168` (dueño, 2026-11-21: "si funciona bien y los kpis muestran que es el camino correcto, lo aplicamos en el resto de los
 * productos"): cuándo sale el Purchase. Un pedido sigue a la campaña de su anuncio (los parámetros de URL de la `.53`).
 *
 * `.171` (dueño, 2026-11-21: "hay que buscar una manera más sencilla sin duplicar campañas"): se prende por producto
 * ("Purchase según cómo paga el cliente"), para todas sus campañas, también las que se creen después. Las marcas por
 * campaña de la `.168` quedan como historia (el sistema sigue midiendo sus días por pedidos).
 */
final class PurchaseModes
{
    /**
     * Los rangos: los de cada campaña, y los de cada producto prendido (sin campaña) con cada una de sus campañas de hoy.
     *
     * @return list<array{campaign: ?string, product: ?int, from: string, to: ?string}>
     */
    public static function ranges(): array
    {
        $rows = MetaPurchaseMode::orderBy('id')->get();
        $products = $rows->whereNull('campaign_meta_id')->pluck('product_id')->filter()->unique()->values()->all();
        $campaigns = $products === [] ? collect() : DB::table('meta_campaigns')->whereIn('product_id', $products)->get(['meta_id', 'product_id'])->groupBy('product_id');
        $out = [];
        foreach ($rows as $m) {
            $range = ['from' => $m->starts_on->toDateString(), 'to' => $m->ends_on?->toDateString()];
            if ($m->campaign_meta_id !== null) {
                $out[] = ['campaign' => (string) $m->campaign_meta_id, 'product' => $m->product_id] + $range;
                continue;
            }
            $out[] = ['campaign' => null, 'product' => (int) $m->product_id] + $range;
            foreach ($campaigns->get($m->product_id, collect()) as $c) {
                $out[] = ['campaign' => (string) $c->meta_id, 'product' => (int) $m->product_id] + $range;
            }
        }

        return $out;
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

    /**
     * `.171`: prende o apaga "Purchase según cómo paga" en todo el producto, desde hoy. Al apagarlo, hoy queda como su último
     * día (lo de hoy ya salió así). Prenderlo cierra las marcas por campaña de la `.168` de ese producto.
     */
    public static function setProduct(int $productId, bool $on): void
    {
        $open = fn () => MetaPurchaseMode::where('product_id', $productId)->whereNull('ends_on');
        if (!$on) {
            $open()->update(['ends_on' => today()->toDateString()]);

            return;
        }
        $open()->whereNotNull('campaign_meta_id')->update(['ends_on' => today()->toDateString()]);
        if (!$open()->whereNull('campaign_meta_id')->exists()) {
            MetaPurchaseMode::create(['product_id' => $productId, 'campaign_meta_id' => null, 'starts_on' => today()->toDateString()]);
        }
    }

    /** Desde cuándo el producto manda el Purchase según cómo paga (null = apagado). */
    public static function productSince(int $productId): ?string
    {
        return MetaPurchaseMode::where('product_id', $productId)->whereNull('campaign_meta_id')->whereNull('ends_on')->first()?->starts_on?->toDateString();
    }
}
