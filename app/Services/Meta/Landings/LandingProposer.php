<?php

namespace App\Services\Meta\Landings;

use App\Models\AdAngle;
use App\Models\LandingTask;
use App\Models\ProductAdSetting;
use App\Models\Setting;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Los encargos de landing que proponen las reglas cada mañana (Etapa 5, paso 6c), con el análisis de las 07:00, "Volver a
 * analizar" o "Proponer ahora" en Landings › Encargos, como los batches (QueueProposer):
 * 1. las propuestas de días anteriores que nadie aprobó se reemplazan (quedan descartadas, "reemplazada");
 * 2. con los últimos 7 días de cada producto con campañas (LandingReport), lo más grave de cada landing: sin píxel, lenta,
 *    no le habla a un ángulo, la landing del ángulo compra poco, otra compra mejor con el mismo ángulo;
 * 3. nunca una landing con un encargo abierto o en test (nunca dos cambios a la vez), ni una que ya se propuso hoy;
 * 4. como mucho `landing_proposals_max` (3) por día, lo más grave primero; los productos pausados o abandonados, no;
 * 5. la IA los redacta (LandingWriter) y quedan "Propuesto": nada se manda a nadie ni se toca Meta o Shopify.
 */
class LandingProposer
{
    public const LAST_RUN = 'meta.landing_tasks.last_run';

    /** Lo más grave primero. */
    public const PRIORITY = ['no_pixel', 'slow', 'angle_mismatch', 'angle_weak', 'beaten'];

    public function __construct(
        private DecisionSettings $settings = new DecisionSettings(),
        private ?LandingWriter $writer = null,
        private ?LandingReport $report = null,
    ) {
    }

    /** @return array{at: string, created: int, replaced: int, status: string, model: ?string, error: ?string} */
    public function run(?int $userId = null, ?array $productIds = null): array
    {
        $now = now(config('app.timezone'));
        $today = $now->toDateString();
        $s = $this->settings->get();

        $replaced = LandingTask::where('status', 'proposed')->where('source', 'rules')->where('proposed_on', '<', $today)
            ->update(['status' => 'discarded', 'discard_reason' => 'Reemplazada por las propuestas del ' . $now->format('d/m') . '.', 'status_at' => now(), 'status_by' => null]);

        $todayCount = LandingTask::where('source', 'rules')->where('proposed_on', $today)->count();
        $proposedToday = LandingTask::where('source', 'rules')->where('proposed_on', $today)->pluck('landing_id')->filter()->all();
        $skipModes = ProductAdSetting::whereIn('mode', ['paused', 'abandoned'])->pluck('product_id')->all();
        $report = $this->report ?? new LandingReport($this->settings);

        $candidates = [];
        foreach (array_diff($productIds ?? MetaAnalyzer::products(), $skipModes) as $pid) {
            $r = $report->forProduct((int) $pid, 'last_7d');
            foreach ($r['landings'] as $l) {
                if ($l['task'] !== null || in_array($l['id'], $proposedToday, true)) {
                    continue;
                }
                $f = self::worst($l['findings']);
                if ($f !== null) {
                    $candidates[] = ['product_id' => (int) $pid, 'landing' => $l, 'finding' => $f];
                }
            }
        }
        usort($candidates, fn ($a, $b) => [array_search($a['finding']['key'], self::PRIORITY, true), -$a['landing']['totals']['spend']]
            <=> [array_search($b['finding']['key'], self::PRIORITY, true), -$b['landing']['totals']['spend']]);
        $plan = array_slice($candidates, 0, max(0, (int) $s['landing_proposals_max'] - $todayCount));

        $run = ['at' => now()->toIso8601String(), 'created' => 0, 'replaced' => $replaced, 'status' => 'nothing', 'model' => null, 'error' => null];
        if ($plan !== []) {
            $rows = $this->rows($plan);
            [$text, $run] = $this->write($rows, $run);
            DB::transaction(function () use ($plan, $rows, $text, $today, $userId, &$run) {
                $drafts = collect($text['encargos'] ?? [])->keyBy('clave');
                foreach ($plan as $i => $p) {
                    $row = $rows[$i];
                    $t = $drafts[$row['key']] ?? LandingWriter::example([$row])['encargos'][0];
                    $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
                    LandingTask::create([
                        'product_id' => $p['product_id'], 'landing_id' => $p['landing']['id'], 'kind' => $row['kind'], 'angle_code' => $row['angle'],
                        'observed' => $row['observed'], 'hypothesis' => $clean($t['hipotesis'] ?? null), 'change' => $clean($t['cambiar'] ?? null) ?? $row['observed'],
                        'keep' => $clean($t['conservar'] ?? null), 'status' => 'proposed', 'source' => 'rules', 'proposed_on' => $today,
                        'status_at' => now(), 'created_by' => $userId,
                    ]);
                    $run['created']++;
                }
            });
        }
        Setting::set(self::LAST_RUN, $run);

        return $run;
    }

    /** Lo más grave de una landing que da para un encargo, o null. */
    public static function worst(array $findings): ?array
    {
        $useful = array_filter($findings, fn ($f) => in_array($f['key'], self::PRIORITY, true));
        usort($useful, fn ($a, $b) => array_search($a['key'], self::PRIORITY, true) <=> array_search($b['key'], self::PRIORITY, true));

        return $useful[0] ?? null;
    }

    /** Lo que necesita la IA de cada encargo: el producto, la landing, el ángulo con su hipótesis y los avales reales. */
    private function rows(array $plan): array
    {
        $productIds = array_unique(array_column($plan, 'product_id'));
        $names = DB::table('products')->whereIn('id', $productIds)->get(['id', 'title', 'showable_name'])->keyBy('id');
        $proof = ProductAdSetting::whereIn('product_id', $productIds)->pluck('proof', 'product_id');
        $angles = AdAngle::whereIn('product_id', $productIds)->whereNull('alias_of')->get(['product_id', 'code', 'name', 'hypothesis'])
            ->keyBy(fn ($a) => "{$a->product_id}:{$a->code}");

        return array_values(array_map(function (array $p, int $i) use ($names, $proof, $angles) {
            $f = $p['finding'];
            $l = $p['landing'];
            $angle = $f['angle'] ?? null;
            $a = $angle !== null ? ($angles["{$p['product_id']}:{$angle}"] ?? null) : null;
            $m = $l['metrics'];

            return [
                'key' => 'L' . ($i + 1), 'product' => ($names[$p['product_id']]->showable_name ?? null) ?: ($names[$p['product_id']]->title ?? 'el producto'),
                'url' => $l['url'], 'kind' => LandingTaskRules::kindFor([$f['key']]), 'finding' => $f['key'], 'angle' => $angle, 'angle_name' => $a?->name, 'angle_hypothesis' => $a?->hypothesis,
                'best' => $f['best'] ?? null, 'proof' => $proof[$p['product_id']] ?? null,
                'observed' => $f['text'] . ' (últimos 7 días en «' . $l['label'] . '»: ' . number_format($l['totals']['link_clicks'], 0, ',', '.') . ' clics, '
                    . number_format($l['totals']['visits'], 0, ',', '.') . ' visitas, ' . $l['totals']['purchases'] . ' compras'
                    . ($m['visit'] !== null ? ', visita ' . str_replace('.', ',', (string) $m['visit']) . ' %' : '')
                    . ($m['conversion'] !== null ? ', compra ' . str_replace('.', ',', (string) $m['conversion']) . ' %' : '') . ').',
            ];
        }, $plan, array_keys($plan)));
    }

    /** @return array{0: array, 1: array} el texto (de la IA o de la plantilla) y lo de esta vez */
    private function write(array $rows, array $run): array
    {
        if (!config('services.openai.key')) {
            $run['status'] = app()->environment('local') ? 'example' : 'no_ai';

            return [LandingWriter::example($rows), $run];
        }
        try {
            $ai = ($this->writer ?? new LandingWriter())->ask(LandingWriter::input($rows));
            $run['status'] = 'ok';
            $run['model'] = $ai['model'];

            return [$ai['text'], $run];
        } catch (RuntimeException $e) {
            report($e);
            $run['status'] = 'error';
            $run['error'] = 'La IA no pudo redactar los encargos de landing: quedan con una plantilla. (' . $e->getMessage() . ')';

            return [LandingWriter::example($rows), $run];
        }
    }
}
