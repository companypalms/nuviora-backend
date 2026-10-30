<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchDelivery;
use App\Services\Meta\MetaAdsReport;
use App\Services\Metrics\Period;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se lee de la base para los indicadores de los editores (Etapa 5, paso 5b): sus encargos, sus entregas revisadas
 * y lo que hicieron en Meta los anuncios de cada encargo, por ciudad, con la C y el BE de esa ciudad. Las cuentas las hace
 * EditorKpis (PHP puro).
 *
 * Un anuncio es de un encargo si está en el ad set "B0123 …" o se llama "C0457 …" con una de sus piezas, en una campaña
 * del mismo producto (como BatchTracker). Solo cuentan los encargos que tienen editor: los creativos viejos no.
 */
class EditorFacts
{
    /**
     * @return array{
     *   batches: list<array{id: int, code: string, editor_id: int, product: ?string, status: string, due_date: ?string, delivered_at: ?string,
     *     testing_at: ?string, tested_at: ?string, verdicts: list<array{city: ?string, key: string, label: string}>, versions: int, returned: int}>,
     *   reviews: list<array{editor_id: int, review: string, reviewed_at: string}>,
     *   rows: list<array{batch_id: int, city: ?string, spend: float, purchases: float, life_spend: float, life_purchases: float, c: ?float, be: ?float}>
     * }
     */
    public function read(Period $period, ?array $editorIds = null): array
    {
        $batches = CreativeBatch::with(['pieces:id,creative_batch_id,number', 'product:id,title,showable_name', 'deliveries:id,creative_batch_id,editor_id,version,review'])
            ->whereNotNull('editor_id')->whereNotNull('number')->where('status', '!=', 'proposed')
            ->when($editorIds !== null, fn ($q) => $q->whereIn('editor_id', $editorIds))->get();
        $from = $period->start->format('Y-m-d H:i:s');
        $to = $period->end->format('Y-m-d H:i:s');

        $reviews = CreativeBatchDelivery::whereIn('review', ['approved', 'returned'])->whereNotNull('editor_id')->whereBetween('reviewed_at', [$from, $to])
            ->when($editorIds !== null, fn ($q) => $q->whereIn('editor_id', $editorIds))->get(['editor_id', 'review', 'reviewed_at'])
            ->map(fn ($d) => ['editor_id' => (int) $d->editor_id, 'review' => $d->review, 'reviewed_at' => $d->reviewed_at->format('Y-m-d H:i:s')])->values()->all();

        return [
            'batches' => $batches->map(fn (CreativeBatch $b) => [
                'id' => $b->id, 'code' => $b->code(), 'editor_id' => (int) $b->editor_id, 'product' => $b->product?->showable_name ?: $b->product?->title,
                'status' => $b->status, 'due_date' => $b->due_date?->toDateString(), 'delivered_at' => $b->delivered_at?->format('Y-m-d H:i:s'),
                'testing_at' => $b->testing_at?->format('Y-m-d H:i:s'), 'tested_at' => $b->tested_at?->format('Y-m-d H:i:s'),
                'verdicts' => array_values($b->result['verdicts'] ?? []),
                'versions' => $b->deliveries->count(), 'returned' => $b->deliveries->where('review', 'returned')->count(),
            ])->values()->all(),
            'reviews' => $reviews,
            'rows' => $this->rows($batches, $period->start->toDateString(), $period->end->toDateString()),
        ];
    }

    /** Lo que gastaron y vendieron en Meta los anuncios de cada encargo, en el período y en toda su vida, por ciudad. */
    private function rows($batches, string $from, string $to): array
    {
        if ($batches->isEmpty()) {
            return [];
        }
        $byBatchCode = $batches->mapWithKeys(fn ($b) => [$b->code() => $b])->all();
        $byPieceCode = [];
        foreach ($batches as $b) {
            foreach ($b->pieces as $p) {
                if ($p->number !== null) {
                    $byPieceCode[QueueRules::pieceCode($p->number)] = $b;
                }
            }
        }
        $ads = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->leftJoin('meta_adsets as s', 's.meta_id', '=', 'a.adset_meta_id')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')
            ->whereIn('c.product_id', $batches->pluck('product_id')->unique()->values())
            ->where(fn ($q) => $q->whereIn('s.batch_code', array_keys($byBatchCode))->orWhereIn('a.batch_code', array_keys($byBatchCode))
                ->orWhereIn('a.creative_code', array_keys($byPieceCode) ?: ['-']))
            ->get(['a.meta_id', 'a.batch_code', 'a.creative_code', 's.batch_code as adset_batch', 'c.product_id', 'c.city_id', 'ci.name as city']);

        $owner = [];
        foreach ($ads as $a) {
            $b = $byBatchCode[$a->adset_batch] ?? $byBatchCode[$a->batch_code] ?? $byPieceCode[$a->creative_code] ?? null;
            if ($b !== null && (int) $a->product_id === (int) $b->product_id) {
                $owner[(string) $a->meta_id] = ['batch_id' => $b->id, 'product_id' => (int) $b->product_id, 'city_id' => $a->city_id !== null ? (int) $a->city_id : null, 'city' => $a->city];
            }
        }
        if ($owner === []) {
            return [];
        }
        $sums = DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('ad_meta_id', array_keys($owner))->groupBy('ad_meta_id')
            ->selectRaw('ad_meta_id, SUM(CASE WHEN date BETWEEN ? AND ? THEN spend ELSE 0 END) spend, SUM(CASE WHEN date BETWEEN ? AND ? THEN purchases ELSE 0 END) purchases,
                SUM(spend) life_spend, SUM(purchases) life_purchases', [$from, $to, $from, $to])->get();

        $report = new MetaAdsReport();
        $targets = [];
        $rows = [];
        foreach ($sums as $s) {
            $o = $owner[(string) $s->ad_meta_id];
            $key = "{$o['batch_id']}:{$o['city_id']}";
            $t = $targets["{$o['product_id']}:{$o['city_id']}"] ??= $report->targetFor($o['product_id'], $o['city_id']);
            $rows[$key] ??= ['batch_id' => $o['batch_id'], 'city' => $o['city'], 'spend' => 0.0, 'purchases' => 0.0, 'life_spend' => 0.0, 'life_purchases' => 0.0,
                'c' => $t['target_cpa'], 'be' => $t['break_even_cpa']];
            foreach (['spend', 'purchases', 'life_spend', 'life_purchases'] as $k) {
                $rows[$key][$k] += (float) $s->{$k};
            }
        }

        return array_values($rows);
    }
}
