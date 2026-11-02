<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\DB;

/**
 * `.79` (dueño, 2026-11-02): "en la campaña yo siempre pongo el CPA referencial": el CPA objetivo y el break-even que el
 * dueño escribe en el nombre de sus campañas ("… | CPA $4| BE $5.6| …", "CPA OBJ $4 | BE $5.5", "CPA 3.7 | BE 7.8").
 * Solo valen como referencia de un producto nuevo, hasta que el sistema tenga sus compras de Meta maduras
 * (MetaAdsReport::effectiveTarget); después manda el número del sistema.
 */
final class CampaignNameTargets
{
    /**
     * El CPA objetivo y el break-even de un nombre (PHP puro). Sin CPA en el nombre, null.
     *
     * @return array{target_cpa: float, break_even_cpa: ?float}|null
     */
    public static function parse(?string $name): ?array
    {
        $num = '\$?\s*(\d+(?:[.,]\d+)?)';
        if ($name === null || !preg_match('/\bCPA(?:\s*OBJ(?:ETIVO)?)?\s*[:=]?\s*' . $num . '/iu', $name, $c)) {
            return null;
        }
        $target = (float) str_replace(',', '.', $c[1]);
        $be = preg_match('/\bBE\s*[:=]?\s*' . $num . '/iu', $name, $b) ? (float) str_replace(',', '.', $b[1]) : null;
        if ($target <= 0 || $target >= 1000) {
            return null;
        }

        return ['target_cpa' => $target, 'break_even_cpa' => $be !== null && $be > 0 && $be < 1000 ? $be : null];
    }

    /**
     * Las referencias de unos productos (null = todos): "p|c", la de su campaña en esa ciudad, y "p|", la del producto.
     * Primero las campañas activas y, entre ellas, la más nueva.
     *
     * @return array<string, array{target_cpa: float, break_even_cpa: ?float}>
     */
    public static function load(?array $productIds = null): array
    {
        $rows = DB::table('meta_campaigns')->whereNotNull('product_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->orderByRaw("CASE WHEN effective_status = 'ACTIVE' AND missing_since IS NULL THEN 0 ELSE 1 END")->orderByDesc('id')
            ->get(['product_id', 'city_id', 'name']);
        $out = [];
        foreach ($rows as $r) {
            if (!($ref = self::parse($r->name))) {
                continue;
            }
            $out["{$r->product_id}|{$r->city_id}"] ??= $ref;
            $out["{$r->product_id}|"] ??= $ref;
        }

        return $out;
    }
}
