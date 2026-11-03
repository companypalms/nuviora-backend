<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\Product;
use App\Models\ProductProfile;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\MetaAdsReport;
use App\Services\Meta\Products\TestingPhase;
use Illuminate\Support\Facades\DB;

/**
 * `.89` (dueño, 2026-11-03): los testeos listos para lanzar: todos los batches de la ronda aprobados en la banca (ninguno
 * salió todavía). Se lanzan juntos y sin mercancía ("siempre se testea sin ni siquiera pedir la mercancía"): un producto
 * nuevo, una campaña en cada ciudad donde hay campañas activas; uno que ya vende, en todas sus campañas activas. La misión
 * la arma UrgentList::testLaunch. Solo lee.
 */
class TestingLaunch
{
    /**
     * @param list<int>|null $productIds
     * @return list<array{product_id: int, product: string, round: int, batches: list<array{code: string, adset: ?string}>, linked: bool,
     *     campaigns: list<array{name: string, city: ?string}>, cities: list<string>, target: ?float, min: float, max: float}>
     */
    public static function ready(?array $productIds = null): array
    {
        $s = (new DecisionSettings())->get();
        $out = [];
        $profiles = ProductProfile::where('phase', 'testing')->where('testing_round', '>', 0)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))->get(['product_id']);
        foreach ($profiles as $p) {
            $pid = (int) $p->product_id;
            $st = TestingPhase::round($pid);
            $ids = array_values(array_filter(array_column($st['slots'], 'batch_id')));
            if ($st['closed'] !== null || $st['slots'] === [] || count($ids) < count($st['slots'])) {
                continue; // terminó, o todavía falta pedir alguno
            }
            $batches = CreativeBatch::with('pieces:id,creative_batch_id,number')->whereIn('id', $ids)->orderBy('number')->get();
            if ($batches->contains(fn (CreativeBatch $b) => $b->status !== 'bank')) {
                continue; // alguno no está listo todavía, o ya salió en Meta
            }
            $product = Product::find($pid, ['id', 'title', 'showable_name', 'product_id']);
            $campaigns = self::campaigns([$pid]);
            try {
                $target = (new MetaAdsReport([], 'last_3d'))->targetFor($pid, null)['target_cpa'] ?? null;
            } catch (\Throwable $e) {
                report($e);
                $target = null;
            }
            $out[] = [
                'product_id' => $pid, 'product' => (string) ($product?->showable_name ?: $product?->title ?: "Producto {$pid}"), 'round' => (int) $st['round'],
                'batches' => $batches->map(fn (CreativeBatch $b) => ['code' => (string) $b->code(),
                    'adset' => QueueRules::metaNames($b->number, $b->angle_code, $b->pieces->pluck('number')->all())['adset']])->values()->all(),
                'linked' => (int) ($product?->product_id ?? 0) > 0, 'campaigns' => $campaigns,
                'cities' => $campaigns === [] ? array_values(array_unique(array_filter(array_column(self::campaigns(null), 'city')))) : [],
                'target' => $target !== null ? (float) $target : null, 'min' => (float) $s['startup_min_mult'], 'max' => (float) $s['startup_max_mult'],
            ];
        }

        return $out;
    }

    /** Las campañas activas de unos productos (null: de todos), con su ciudad. @return list<array{name: string, city: ?string}> */
    private static function campaigns(?array $productIds): array
    {
        return DB::table('meta_campaigns as c')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->where('c.effective_status', 'ACTIVE')->whereNull('c.missing_since')->whereNotNull('c.product_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('c.product_id', $productIds))
            ->orderBy('ci.name')->get(['c.name', 'ci.name as city'])->map(fn ($c) => ['name' => (string) $c->name, 'city' => $c->city])->all();
    }
}
