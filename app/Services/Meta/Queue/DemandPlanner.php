<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;

/**
 * `.88`: lo que necesita cada producto y para cuándo (DemandRules), con lo que ya está pedido en la base: aprobado, en
 * producción, en revisión o listo en la banca, y lo que el dueño descartó hoy (no se vuelve a pedir el mismo día). Solo lee.
 */
class DemandPlanner
{
    /**
     * @param array<int, array> $decisions MetaDecisions::compute
     * @param list<int> $skip los productos en testeo (piden sus batches de inicio aparte)
     * @return array<int, array{product: string, today: int, cities: array<string, string>, consumed: list<string>, why: ?string,
     *     slots: array{advance: array<int, string>, covered: array<int, string>, new: list<string>}}>
     */
    public static function plan(array $decisions, array $skip, array $s, string $today): array
    {
        $ids = [];
        foreach ($decisions as $pid => $d) {
            // En pausa, abandonados, en liquidación o sin trabajo (U5: sin stock, sin C ni BE) no reciben creativos nuevos
            if (($d['product']['mode'] ?? 'normal') === 'normal' && ($d['plan']['urgency']['level'] ?? 5) < 5 && !in_array((int) $pid, $skip, true)) {
                $ids[] = (int) $pid;
            }
        }
        if ($ids === []) {
            return [];
        }
        $open = CreativeBatch::whereIn('product_id', $ids)
            ->where(fn ($q) => $q->whereIn('status', DemandRules::OPEN)->orWhere(fn ($q) => $q->where('status', 'discarded')->whereNotNull('number')
                ->whereNotNull('status_by')->where('status_at', '>=', now(config('app.timezone'))->startOfDay())))
            ->orderBy('id')->get(['id', 'product_id', 'status', 'due_date', 'number'])->groupBy('product_id');

        $out = [];
        foreach ($ids as $pid) {
            $t = DemandRules::today($decisions[$pid]);
            $rows = ($open[$pid] ?? collect())->map(fn (CreativeBatch $b) => ['id' => (int) $b->id, 'status' => $b->status,
                'due_date' => $b->due_date?->toDateString(), 'code' => $b->code()])->values()->all();
            $out[$pid] = $t + ['product' => (string) ($decisions[$pid]['product']['name'] ?? $pid),
                'slots' => DemandRules::slots($t['today'], max(0, (int) $s['bank_min']), $rows, $t['consumed'], $today, (int) $s['reserve_due_days'])];
        }

        return $out;
    }
}
