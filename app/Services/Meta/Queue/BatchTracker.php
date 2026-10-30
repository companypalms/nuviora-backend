<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Services\Meta\Decisions\MetaDecisions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los encargos que se mueven solos con lo que trae Meta (Etapa 5, paso 4a):
 * - aparece en Meta un ad set "B0123 …" (o un anuncio "C0457 …") del mismo producto → "En test", con la fecha;
 * - las reglas del dueño le dan un veredicto en cada ciudad donde salió (prometedor, PROVEN, intermedio, perdedor o
 *   ganador que empezó a fallar) → "Testeado", con el resultado y sus números.
 * Corre después de cada sincronización (solo "En test"), a las 06:45 con los estados de los creativos y al abrir la cola.
 * Solo lee Meta.
 */
class BatchTracker
{
    public function __construct(private ?MetaDecisions $decisions = null)
    {
    }

    /** @return array{testing: int, tested: int} */
    public function run(?array $productIds = null, bool $verdicts = true): array
    {
        $out = ['testing' => 0, 'tested' => 0];
        $batches = CreativeBatch::with('pieces')->whereNotNull('number')->whereIn('status', [...QueueRules::BANK, 'testing'])
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))->get();
        if ($batches->isEmpty()) {
            return $out;
        }
        $found = $this->found($batches);
        foreach ($batches as $b) {
            if (in_array($b->status, QueueRules::BANK, true) && isset($found[$b->id])) {
                $b->forceFill(['status' => 'testing', 'testing_at' => $found[$b->id]['first'] ?? now(), 'status_at' => now(), 'status_by' => null])->save();
                $out['testing']++;
            }
        }
        $testing = $batches->filter(fn ($b) => $b->status === 'testing' && isset($found[$b->id]));
        if (!$verdicts || $testing->isEmpty()) {
            return $out;
        }

        $decisions = ($this->decisions ?? app(MetaDecisions::class))->forProducts($testing->pluck('product_id')->unique()->values()->all());
        foreach ($testing as $b) {
            $d = $decisions[$b->product_id] ?? ['adsets' => [], 'ads' => [], 'campaigns' => []];
            $f = $found[$b->id];
            // Por ad set (un batch puede salir en varias ciudades); si solo se encontraron anuncios sueltos, por anuncio
            [$level, $entities] = $f['adsets'] ? ['adsets', $f['adsets']] : ['ads', $f['ads']];
            $list = [];
            foreach ($entities as $metaId => $campaignId) {
                $v = $d[$level][$metaId] ?? null;
                $list[] = ['city' => $d['campaigns'][$campaignId]['city'] ?? null, 'key' => $v['key'] ?? 'no_data', 'label' => $v['label'] ?? 'Sin datos'];
            }
            $outcome = QueueRules::outcome($list);
            if ($outcome === null) {
                continue;
            }
            $b->forceFill(['status' => 'tested', 'tested_at' => now(), 'status_at' => now(), 'status_by' => null,
                'result' => ['verdicts' => $outcome] + self::numbers($level, array_keys($entities))])->save();
            $out['tested']++;
        }

        return $out;
    }

    /**
     * Dónde está cada encargo en Meta.
     *
     * @return array<int, array{adsets: array<string, string>, ads: array<string, string>, first: ?string}> por encargo:
     *         meta_id => campaña
     */
    private function found(Collection $batches): array
    {
        $productIds = $batches->pluck('product_id')->unique()->values()->all();
        $codes = $batches->map(fn ($b) => $b->code())->all();
        $pieceCodes = $batches->flatMap(fn ($b) => $b->pieces->map(fn ($p) => $p->code())->filter())->values()->all();
        $adsets = DB::table('meta_adsets as s')->join('meta_campaigns as c', 'c.meta_id', '=', 's.campaign_meta_id')
            ->whereIn('c.product_id', $productIds)->whereIn('s.batch_code', $codes ?: ['-'])
            ->get(['s.meta_id', 's.campaign_meta_id', 's.batch_code', 's.created_time', 'c.product_id']);
        $ads = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->whereIn('c.product_id', $productIds)->whereIn('a.creative_code', $pieceCodes ?: ['-'])
            ->get(['a.meta_id', 'a.campaign_meta_id', 'a.creative_code', 'a.created_time', 'c.product_id']);

        $out = [];
        foreach ($batches as $b) {
            $mine = $b->pieces->map(fn ($p) => $p->code())->filter()->all();
            $sets = $adsets->filter(fn ($s) => (int) $s->product_id === $b->product_id && $s->batch_code === $b->code());
            $loose = $ads->filter(fn ($a) => (int) $a->product_id === $b->product_id && in_array($a->creative_code, $mine, true));
            if ($sets->isEmpty() && $loose->isEmpty()) {
                continue;
            }
            $out[$b->id] = [
                'adsets' => $sets->mapWithKeys(fn ($s) => [(string) $s->meta_id => (string) $s->campaign_meta_id])->all(),
                'ads' => $loose->mapWithKeys(fn ($a) => [(string) $a->meta_id => (string) $a->campaign_meta_id])->all(),
                'first' => $sets->pluck('created_time')->merge($loose->pluck('created_time'))->filter()->min(),
            ];
        }

        return $out;
    }

    /** El gasto, las compras y el CPA de toda la vida del batch en Meta. */
    private static function numbers(string $level, array $metaIds): array
    {
        $row = DB::table('meta_insights_daily')->where('level', 'ad')->whereIn($level === 'adsets' ? 'adset_meta_id' : 'ad_meta_id', $metaIds)
            ->selectRaw('SUM(spend) spend, SUM(purchases) purchases')->first();
        $spend = round((float) ($row->spend ?? 0), 2);
        $purchases = (float) ($row->purchases ?? 0);

        return ['spend' => $spend, 'purchases' => $purchases, 'cpa' => $purchases > 0 ? round($spend / $purchases, 2) : null];
    }
}
