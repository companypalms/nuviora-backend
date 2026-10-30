<?php

namespace App\Services\Meta\Landings;

use App\Models\Landing;
use App\Models\MetaAdLandingChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A qué landing lleva cada anuncio (Etapa 5, paso 6a). Corre al final de la sincronización de cada hora, con lo que ya se
 * leyó de Meta (meta_ads.landing_url): no hace llamadas nuevas.
 * - Una URL nueva crea su landing, con el producto de la campaña del anuncio.
 * - Si un anuncio cambia de landing (no solo de UTM), se anota desde cuándo. La primera vez que se ve un anuncio se anota
 *   su landing de ese momento, y vale también para los días de antes.
 */
class LandingTracker
{
    /** @return int cuántos cambios se anotaron */
    public function run(?int $accountId = null, ?Carbon $now = null): int
    {
        $now ??= now();
        $ads = DB::table('meta_ads as a')->leftJoin('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->whereNotNull('a.landing_url')
            ->when($accountId !== null, fn ($q) => $q->where('a.meta_ad_account_id', $accountId))
            ->get(['a.id', 'a.landing_url', 'c.product_id']);
        if ($ads->isEmpty()) {
            return 0;
        }
        $last = MetaAdLandingChange::whereIn('id', MetaAdLandingChange::selectRaw('MAX(id)')->whereIn('meta_ad_id', $ads->pluck('id'))->groupBy('meta_ad_id'))
            ->pluck('landing_id', 'meta_ad_id')->all();

        $landings = [];
        $count = 0;
        foreach ($ads as $ad) {
            $url = LandingUrl::normalize($ad->landing_url);
            if ($url === null) {
                continue;
            }
            $hash = LandingUrl::hash($url);
            $landing = $landings[$hash] ??= Landing::firstOrCreate(['url_hash' => $hash], ['url' => $url, 'product_id' => $ad->product_id, 'first_seen_at' => $now]);
            if ($landing->product_id === null && $ad->product_id !== null) {
                $landing->update(['product_id' => $ad->product_id]);
            }
            if ((int) ($last[$ad->id] ?? 0) === (int) $landing->id) {
                continue;
            }
            MetaAdLandingChange::create(['meta_ad_id' => $ad->id, 'landing_id' => $landing->id, 'url' => $ad->landing_url, 'changed_at' => $now]);
            $last[$ad->id] = (int) $landing->id;
            $count++;
        }

        return $count;
    }
}
