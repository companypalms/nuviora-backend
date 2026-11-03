<?php

namespace App\Services\Meta;

use App\Models\MetaCampaign;

/**
 * `.89` (dueño, 2026-11-03: "todo mantiene el flujo correcto sin necesidad de mi intervención"): una campaña nueva sin
 * clasificar se clasifica sola cuando su nombre dice claramente el producto y la ciudad ("TERMAFIT | CCS | CBO…": la marca
 * del producto y la ciudad, cada una de uno solo). Si hay dudas (lo aprendido de otras campañas, el nombre de la cuenta o
 * dos productos posibles), sigue esperando que el Admin la clasifique, como siempre. Lo que ya tiene a mano no se toca.
 */
class CampaignAutoClassifier
{
    /** @return int cuántas clasificó */
    public static function run(?int $accountId = null): int
    {
        $pending = MetaCampaign::with('account:id,name,is_active')->whereNull('missing_since')
            ->where(fn ($w) => $w->whereNull('product_id')->orWhereNull('city_id'))
            ->whereHas('account', fn ($q) => $q->where('is_active', true))
            ->when($accountId !== null, fn ($q) => $q->where('meta_ad_account_id', $accountId))->get();
        if ($pending->isEmpty()) {
            return 0;
        }
        $suggester = new CampaignSuggester();
        $done = 0;
        foreach ($pending as $c) {
            $s = $suggester->suggest($c, $c->account?->name);
            $product = $c->product_id ?? ($s['product_from'] === 'name' ? $s['product_id'] : null);
            $city = $c->city_id ?? ($s['city_from'] === 'name' ? $s['city_id'] : null);
            if ($product === null || $city === null) {
                continue;
            }
            $c->forceFill(['product_id' => $product, 'city_id' => $city, 'classified_by' => null, 'classified_at' => now()])->save();
            // El creativo es producto + código: cambia con el producto de la campaña (como al clasificar a mano)
            (new MetaCreativeLinker())->run(['campaign' => $c->meta_id]);
            $done++;
        }

        return $done;
    }
}
