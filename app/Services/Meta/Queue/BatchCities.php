<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `.87` (dueño, 2026-11-03: "ese batch, en teoría, debería aparecer como que está lanzado en Maracay, pero que está en banca
 * en Carabobo y en Caracas"): en qué ciudades salió cada encargo en Meta y para cuáles sigue en banca: las de las campañas
 * prendidas de su producto donde todavía no salió (si su ad set sigue en Meta para duplicarlo). Solo lee.
 */
class BatchCities
{
    /**
     * @param Collection<int, CreativeBatch> $batches
     * @return array<int, array{launched: list<string>, bank_for: list<string>}> por encargo, solo los que ya salieron en Meta
     */
    public static function of(Collection $batches): array
    {
        $out = $batches->filter(fn ($b) => in_array($b->status, ['testing', 'tested'], true) && $b->number !== null);
        if ($out->isEmpty()) {
            return [];
        }
        $productIds = $out->pluck('product_id')->unique()->values()->all();
        $sets = DB::table('meta_adsets as s')->join('meta_campaigns as c', 'c.meta_id', '=', 's.campaign_meta_id')
            ->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->whereIn('c.product_id', $productIds)->whereIn('s.batch_code', $out->map(fn ($b) => $b->code())->values()->all())
            ->get(['c.product_id', 's.batch_code', 's.missing_since', 'c.city_id', 'ci.name as city']);
        $active = DB::table('meta_campaigns as c')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->whereIn('c.product_id', $productIds)->where('c.effective_status', 'ACTIVE')->whereNull('c.missing_since')->whereNotNull('c.city_id')
            ->get(['c.product_id', 'c.city_id', 'ci.name as city']);

        $result = [];
        foreach ($out as $b) {
            $mine = $sets->filter(fn ($s) => (int) $s->product_id === (int) $b->product_id && $s->batch_code === $b->code());
            if ($mine->isEmpty()) {
                continue;
            }
            $there = $mine->pluck('city_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
            $copyable = $mine->contains(fn ($s) => $s->missing_since === null);
            $result[$b->id] = [
                'launched' => $mine->pluck('city')->filter()->unique()->sort()->values()->all(),
                'bank_for' => $copyable ? $active->filter(fn ($c) => (int) $c->product_id === (int) $b->product_id && !in_array((int) $c->city_id, $there, true))
                    ->pluck('city')->filter()->unique()->sort()->values()->all() : [],
            ];
        }

        return $result;
    }
}
