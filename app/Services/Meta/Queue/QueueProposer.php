<?php

namespace App\Services\Meta\Queue;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\ProductAdSetting;
use App\Models\Setting;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las propuestas de encargos de cada mañana (Etapa 5, paso 4b), con el análisis de las 07:00 o "Volver a analizar", o con
 * "Proponer ahora" en la cola:
 * 1. las propuestas de días anteriores que nadie aprobó se reemplazan (quedan descartadas, "reemplazada");
 * 2. las reglas deciden cuántos encargos, de qué productos y de qué tipo (ProposalRules), sin repetir lo ya propuesto hoy;
 * 3. la IA los redacta (QueueWriter) y escribe el aprendizaje de los batches que ya se testearon;
 * 4. quedan en "Para aprobar": nada se manda a nadie ni se toca Meta.
 */
class QueueProposer
{
    /** Lo de la última vez (cuándo, cuántos, si redactó la IA), para la pantalla. */
    public const LAST_RUN = 'meta.queue.last_run';

    /** Cuántos batches testeados sin aprendizaje se mandan por vez. */
    private const MAX_LEARNINGS = 10;

    public function __construct(
        private ?MetaDecisions $decisions = null,
        private ?QueueWriter $writer = null,
        private DecisionSettings $settings = new DecisionSettings(),
    ) {
    }

    /** @return array{at: string, created: int, replaced: int, learnings: int, status: string, model: ?string, error: ?string} */
    public function run(?int $userId = null): array
    {
        $now = now(config('app.timezone'));
        $today = $now->toDateString();
        $s = $this->settings->get();

        $replaced = CreativeBatch::where('status', 'proposed')->where('source', 'rules')->where('proposed_on', '<', $today)
            ->update(['status' => 'discarded', 'discard_reason' => 'Reemplazada por las propuestas del ' . $now->format('d/m') . '.', 'status_at' => now(), 'status_by' => null]);

        $decisions = ($this->decisions ?? app(MetaDecisions::class))->forProducts(MetaAnalyzer::products());
        // Lo propuesto hoy y no aprobado ya cuenta para ese producto (lo aprobado ya está en la banca); todo lo de hoy, para la capacidad
        $todayRows = CreativeBatch::where('source', 'rules')->where('proposed_on', $today)->get(['product_id', 'status']);
        $already = $todayRows->whereIn('status', ['proposed', 'discarded'])->countBy('product_id')->all();
        $capacity = max(0, (int) round($s['video_editors'] * $s['batches_per_editor'] / (1 + $s['corrections_pct'] / 100)) - $todayRows->count());
        $pieces = max(1, (int) $s['batch_pieces']);
        $plan = ProposalRules::plan($decisions, $already, $capacity);
        foreach ($plan as $i => &$p) {
            $p['key'] = 'E' . ($i + 1);
            $p['pieces'] = $pieces;
            $p['context'] = $this->context($p, $decisions);
        }
        unset($p);
        $tested = CreativeBatch::with('product:id,title,showable_name')->where('status', 'tested')->whereNull('learning')->whereNotNull('result')
            ->orderBy('tested_at')->limit(self::MAX_LEARNINGS)->get();
        $testedRows = $tested->map(fn (CreativeBatch $b) => [
            'key' => 'T' . $b->id, 'product' => $b->product?->showable_name ?: $b->product?->title, 'code' => (string) $b->code(), 'kind' => $b->kind,
            'angle' => $b->angle_code, 'hypothesis' => $b->hypothesis, 'verdicts' => $b->result['verdicts'] ?? [],
            'spend' => (float) ($b->result['spend'] ?? 0), 'purchases' => (float) ($b->result['purchases'] ?? 0), 'cpa' => $b->result['cpa'] ?? null,
        ])->values()->all();

        $run = ['at' => now()->toIso8601String(), 'created' => 0, 'replaced' => $replaced, 'learnings' => 0, 'status' => 'nothing', 'model' => null, 'error' => null];
        if ($plan !== [] || $testedRows !== []) {
            [$text, $run] = $this->write($plan, $testedRows, $run);
            DB::transaction(function () use ($plan, $text, $testedRows, $tested, $today, $userId, &$run) {
                $drafts = collect($text['encargos'] ?? [])->keyBy('clave');
                foreach ($plan as $p) {
                    $this->create($p, $drafts[$p['key']] ?? QueueWriter::example([$p], [])['encargos'][0], $today, $userId);
                    $run['created']++;
                }
                $keys = array_column($testedRows, 'key');
                foreach ($text['aprendizajes'] ?? [] as $l) {
                    $b = in_array($l['clave'] ?? '', $keys, true) ? $tested->firstWhere('id', (int) substr($l['clave'], 1)) : null;
                    if ($b && trim((string) $l['texto']) !== '') {
                        $b->forceFill(['learning' => mb_substr(trim($l['texto']), 0, 2000)])->save();
                        $run['learnings']++;
                    }
                }
            });
        }
        Setting::set(self::LAST_RUN, $run);

        return $run;
    }

    /** @return array{0: array, 1: array} el texto (de la IA o de la plantilla) y lo de esta vez */
    private function write(array $plan, array $tested, array $run): array
    {
        if (!config('services.openai.key')) {
            $run['status'] = app()->environment('local') ? 'example' : 'no_ai';

            return [QueueWriter::example($plan, $tested), $run];
        }
        try {
            $ai = ($this->writer ?? new QueueWriter())->ask(QueueWriter::input($plan, $tested));
            $run['status'] = 'ok';
            $run['model'] = $ai['model'];

            return [$ai['text'], $run];
        } catch (RuntimeException $e) {
            report($e);
            $run['status'] = 'error';
            $run['error'] = 'La IA no pudo redactar los encargos: quedan con una plantilla. (' . $e->getMessage() . ')';

            return [QueueWriter::example($plan, $tested), $run];
        }
    }

    private function create(array $p, array $t, string $today, ?int $userId): void
    {
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $angle = $p['angle_code'];
        if ($angle === null && $p['kind'] === 'new_angle' && is_string($t['angulo_codigo'] ?? null)) {
            $angle = mb_substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($t['angulo_codigo'])), 0, 20) ?: null;
        }
        $hypothesis = $clean(($p['kind'] === 'new_angle' && $clean($t['angulo_nombre'] ?? null) ? 'Ángulo nuevo: ' . trim($t['angulo_nombre']) . '. ' : '') . ($t['hipotesis'] ?? ''));
        $batch = CreativeBatch::create([
            'product_id' => $p['product_id'], 'kind' => $p['kind'], 'angle_code' => $angle, 'concept' => mb_substr((string) $clean($t['concepto'] ?? null), 0, 120) ?: null,
            'hypothesis' => $hypothesis, 'audience' => mb_substr((string) $clean($t['publico'] ?? null), 0, 255) ?: null,
            'reference_creative_id' => $p['reference_id'], 'learning_city_id' => $p['city_id'], 'format' => $p['format'], 'keep' => $clean($t['conservar'] ?? null),
            'offer' => $p['context']['price'] ? 'Precio ' . $p['context']['price'] : null, 'deliverables' => $clean($t['entregables'] ?? null),
            'urgency' => $p['urgency'], 'source' => 'rules', 'proposed_on' => $today, 'status' => 'proposed', 'status_at' => now(), 'created_by' => $userId,
        ]);
        $texts = array_values(array_slice((array) ($t['piezas'] ?? []), 0, $p['pieces']));
        for ($i = 0; $i < $p['pieces']; $i++) {
            CreativeBatchPiece::create(['creative_batch_id' => $batch->id, 'position' => $i + 1, 'change' => $clean($texts[$i] ?? null)]);
        }
    }

    /** Lo del producto para la IA: sus avales reales, el precio, sus ángulos y los textos y el video de la referencia. */
    private function context(array $p, array $decisions): array
    {
        $pid = $p['product_id'];
        $price = DB::table('products')->where('id', $pid)->value('price');
        $refTexts = $p['reference_id'] ? DB::table('meta_ads')->where('meta_creative_ref_id', $p['reference_id'])
            ->where(fn ($q) => $q->whereNotNull('ad_body')->orWhereNotNull('ad_title'))->orderByDesc('created_time')->limit(2)->get(['ad_title', 'ad_body'])
            ->map(fn ($a) => trim(($a->ad_title ? $a->ad_title . ' — ' : '') . mb_strimwidth((string) $a->ad_body, 0, 280, '…')))->unique()->values()->all() : [];

        return [
            'proof' => ProductAdSetting::where('product_id', $pid)->value('proof'),
            'price' => $price !== null ? '$' . number_format((float) $price, 2, ',', '.') : null,
            'angles' => AdAngle::where('product_id', $pid)->whereNull('alias_of')->orderBy('code')->get(['code', 'name', 'hypothesis'])
                ->mapWithKeys(fn ($a) => [$a->code => trim(($a->name ?? '') . ($a->hypothesis ? ": {$a->hypothesis}" : ''))])->all(),
            'reference_texts' => $refTexts,
            'reference_video' => $p['reference_id'] ? ($decisions[$pid]['creatives'][$p['reference_id']]['video']['diagnosis'] ?? []) : [],
        ];
    }
}
