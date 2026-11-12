<?php

namespace App\Services\Meta\Queue;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\MetaAnalysis;
use App\Models\ProductAdSetting;
use App\Models\Setting;
use App\Services\Meta\Analysis\AnalysisSender;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Products\AngleProposals;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\ProductProfiles;
use App\Services\Meta\Products\TestingPhase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las propuestas de encargos de cada mañana (Etapa 5, paso 4b), con el análisis de las 00:30 o "Volver a analizar", o con
 * "Proponer ahora" en la cola:
 * 1. las propuestas de días anteriores que nadie aprobó se reemplazan (quedan descartadas, "reemplazada");
 * 2. las reglas deciden cuántos encargos, de qué productos y de qué tipo (ProposalRules), sin repetir lo ya propuesto hoy;
 *    `.64`: solo de los productos con la ficha completa (los demás quedan en missing_profile, para avisarle al dueño);
 *    `.65`: los productos en testeo piden sus batches del testeo (TestingPhase) y no los de las reglas de siempre;
 * 3. la IA los redacta (QueueWriter) y escribe el aprendizaje de los batches que ya se testearon;
 * 4. quedan en "Para aprobar": nada se manda a nadie ni se toca Meta.
 *
 * `.88` (dueño, 2026-11-03: "que al editor líder le aparezca todo lo que tengamos que hacer y el tiempo en que lo
 * necesitamos… sin contar cuántos editores tenemos disponibles… en orden de lo más urgente a lo menos urgente"): cuántos
 * y para cuándo lo dicen las fechas de cada producto (DemandPlanner: hoy y la reserva), sin tope de editores. Lo que ya
 * viene en camino cuenta (se adelanta su fecha); lo que falta se pide, primero la iteración del análisis (AnalysisSender) y
 * después lo que redacta la IA, y le llega directo al líder: aprobado, con su número y su fecha (el dueño lo ve en Cola y
 * banca y puede descartarlo). El porqué de cada uno queda en DEMAND, en palabras y sin montos, para el líder.
 */
class QueueProposer
{
    /** Lo de la última vez (cuándo, cuántos, si redactó la IA), para la pantalla. */
    public const LAST_RUN = 'meta.queue.last_run';

    /** `.88`: lo que necesita cada producto hoy: el porqué de cada encargo y lo que no se pudo pedir (para el líder). */
    public const DEMAND = 'meta.queue.demand';

    /** `.88`: el porqué de los batches de inicio de un producto nuevo. */
    public const START = 'Producto nuevo: uno de sus batches de inicio, para probar sus ángulos.';

    /** Cuántos batches testeados sin aprendizaje se mandan por vez. */
    private const MAX_LEARNINGS = 10;

    public function __construct(
        private ?MetaDecisions $decisions = null,
        private ?QueueWriter $writer = null,
        private DecisionSettings $settings = new DecisionSettings(),
        private ?AnalysisSender $sender = null,
        private AngleProposals $proposals = new AngleProposals(),
    ) {
    }

    /** @return array{at: string, created: int, replaced: int, learnings: int, status: string, model: ?string, error: ?string, missing_profile: list<string>, sent: list<string>, unfilled: int} */
    public function run(?int $userId = null, ?MetaAnalysis $analysis = null): array
    {
        $now = now(config('app.timezone'));
        $today = $now->toDateString();
        $s = $this->settings->get();

        $replaced = CreativeBatch::where('status', 'proposed')->where('source', 'rules')->where('proposed_on', '<', $today)
            ->update(['status' => 'discarded', 'discard_reason' => 'Reemplazada por las propuestas del ' . $now->format('d/m') . '.', 'status_at' => now(), 'status_by' => null]);

        $decisions = ($this->decisions ?? app(MetaDecisions::class))->forProducts(MetaAnalyzer::products());
        $pieces = max(1, (int) $s['batch_pieces']);
        // .65: las rondas de testeo que ya tienen todos sus resultados se cierran (con un ganador, el producto pasa a iterar)
        TestingPhase::refresh();
        $testingIds = \App\Models\ProductProfile::where('phase', 'testing')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        // .64: sin ficha completa, la IA no escribe encargos de ese producto (no sabría qué es ni para qué sirve)
        $complete = ProductProfiles::completeIds(array_unique(array_merge(array_keys($decisions), $testingIds)));
        $missingProfile = [];
        foreach ($decisions as $pid => $d) {
            if (!in_array((int) $pid, $complete, true) && ($d['product']['mode'] ?? 'normal') === 'normal' && !in_array((int) $pid, $testingIds, true)) {
                $missingProfile[] = (string) ($d['product']['name'] ?? $pid);
            }
        }
        $missingProfile = array_merge($missingProfile, \Illuminate\Support\Facades\DB::table('products')->whereIn('id', array_diff($testingIds, $complete) ?: [0])
            ->get(['title', 'showable_name'])->map(fn ($p) => $p->showable_name ?: $p->title)->all());
        $normal = array_diff_key(array_intersect_key($decisions, array_flip($complete)), array_flip($testingIds));
        foreach ($normal as $pid => &$d) {
            $d['profile_angles'] = TestingPhase::profileAngles((int) $pid);
        }
        unset($d);
        // .65: el testeo, solo de los productos en modo normal (no en pausa, abandonados ni en liquidación)
        $stopped = ProductAdSetting::where('mode', '!=', 'normal')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        // .139: ni el de un producto que salió Malo por su efectividad, mientras el dueño decide
        $stopped = array_merge($stopped, array_keys(array_filter($decisions, fn ($d) => (bool) ($d['product']['worth']['stop'] ?? false))));
        // .88: lo que necesita cada producto y para cuándo; lo que ya viene en camino cuenta (se adelanta su fecha)
        $demand = DemandPlanner::plan($decisions, $testingIds, $s, $today);
        $reasons = [];
        foreach ($demand as $dm) {
            foreach ($dm['slots']['advance'] as $id => $date) {
                CreativeBatch::whereKey($id)->update(['due_date' => $date]);
            }
            foreach ($dm['slots']['covered'] as $id => $date) {
                $reasons[$id] = self::reason($dm, $date, $today);
            }
        }
        $quotas = array_filter(array_map(fn ($dm) => $dm['slots']['new'], $demand));
        // 1. La iteración del creativo más importante de cada producto (.71/.78), con las indicaciones del análisis de hoy
        $analysis ??= MetaAnalysis::where('date', $today)->orderByDesc('version')->first();
        // .116: si el dueño eligió un ángulo, el siguiente encargo es de ese ángulo
        $chosen = array_filter($normal, fn ($d) => ($d['profile_angles']['chosen'] ?? null) !== null);
        $sent = $analysis && $quotas ? ($this->sender ?? new AnalysisSender($this->settings))->fill($analysis, array_diff_key($quotas, $chosen)) : ['sent' => [], 'used' => []];
        $done = [];
        foreach ($sent['used'] as $pid => $used) {
            $quotas[$pid] = array_slice($quotas[$pid], count($used));
            $done[$pid] = count($used);
            foreach ($used as $id => $date) {
                $reasons[$id] = self::reason($demand[$pid], $date, $today);
            }
        }
        // 2. Lo demás lo piden las reglas y lo redacta la IA (.64: solo con la ficha completa); el testeo, para la fecha de la reserva
        $later = $now->copy()->addDays((int) $s['reserve_due_days'])->toDateString();
        $testing = array_map(fn ($items) => array_map(fn ($i) => $i + ['due' => $later], $items),
            TestingPhase::candidates(array_values(array_diff(array_intersect($testingIds, $complete), $stopped)), $decisions));
        // .133: un creativo con una variación sin resultado (o que hoy no se itera) tampoco lo iteran las reglas
        $winners = array_filter(array_map(fn ($d) => (int) ($d['plan']['work']['winner']['id'] ?? 0), $normal));
        $iterations = IterationRules::state(array_values($winners));
        $plan = ProposalRules::plan($normal, array_filter($quotas), $testing, $done,
            ['skip' => array_values(array_unique([...$iterations['busy'], ...($sent['skipped'] ?? [])])), 'prev' => $iterations['prev']]);
        // .116: sin ángulos disponibles no se pide nada: la IA se lo propone al dueño (solo a él) en su calendario
        $noAngle = array_unique(array_column(array_filter($plan, fn ($p) => $p['needs_angle'] ?? false), 'product_id'));
        $plan = array_values(array_filter($plan, fn ($p) => !($p['needs_angle'] ?? false)));
        foreach ($noAngle as $pid) {
            $this->proposals->ensure((int) $pid);
        }
        // Lo que no se puede pedir: sin la ficha del producto o sin una ciudad donde probarlo (el líder lo ve igual)
        $planned = array_count_values(array_column($plan, 'product_id'));
        $unfilled = [];
        foreach (array_filter($quotas) as $pid => $dates) {
            foreach (array_slice($dates, (int) ($planned[$pid] ?? 0)) as $date) {
                $unfilled[] = ['product_id' => (int) $pid, 'product' => $demand[$pid]['product'], 'due' => $date, 'why' => self::reason($demand[$pid], $date, $today),
                    'blocked' => in_array($pid, $noAngle, false) ? 'El dueño tiene que elegir el ángulo.' : (isset($normal[$pid]) ? 'No hay una ciudad activa con stock donde probarlo.' : 'Falta la ficha del producto: el dueño la completa para que la IA escriba el encargo.')];
            }
        }
        foreach ($plan as $i => &$p) {
            $p['key'] = 'E' . ($i + 1);
            $p['pieces'] = $pieces;
            $p['context'] = $this->context($p, $decisions);
        }
        unset($p);
        $tested = CreativeBatch::with(['product:id,title,showable_name', 'persona:id,name'])->where('status', 'tested')->whereNull('learning')->whereNotNull('result')
            ->orderBy('tested_at')->limit(self::MAX_LEARNINGS)->get();
        $testedRows = $tested->map(fn (CreativeBatch $b) => [
            'key' => 'T' . $b->id, 'product' => $b->product?->showable_name ?: $b->product?->title, 'code' => (string) $b->code(), 'kind' => $b->kind,
            'angle' => $b->angle_code, 'hypothesis' => $b->hypothesis, 'verdicts' => $b->result['verdicts'] ?? [],
            'spend' => (float) ($b->result['spend'] ?? 0), 'purchases' => (float) ($b->result['purchases'] ?? 0), 'cpa' => $b->result['cpa'] ?? null,
            // .66: qué se probó (para que el aprendizaje diga qué hipótesis ganó o perdió)
            'test_idea' => $b->test_idea, 'persona' => $b->persona?->name, 'style' => $b->style, 'concept' => $b->concept,
        ])->values()->all();

        $run = ['at' => now()->toIso8601String(), 'created' => 0, 'replaced' => $replaced, 'learnings' => 0, 'status' => 'nothing', 'model' => null, 'error' => null,
            'missing_profile' => $missingProfile, 'sent' => $sent['sent'], 'unfilled' => count($unfilled)];
        if ($plan !== [] || $testedRows !== []) {
            [$text, $run] = $this->write($plan, $testedRows, $run);
            DB::transaction(function () use ($plan, $text, $testedRows, $tested, $today, $userId, $demand, &$reasons, &$run) {
                $drafts = collect($text['encargos'] ?? [])->keyBy('clave');
                $queue = new BatchQueue($this->settings);
                foreach ($plan as $p) {
                    // .88: directo al líder, aprobado y con su fecha
                    $batch = $this->create($p, $drafts[$p['key']] ?? QueueWriter::example([$p], [])['encargos'][0], $today, $userId);
                    $batch->forceFill(['due_date' => $p['due']])->save();
                    $batch = $queue->approve($batch, null);
                    $reasons[$batch->id] = isset($p['testing']) ? self::START : self::reason($demand[$p['product_id']], $p['due'], $today);
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
        Setting::set(self::DEMAND, ['date' => $today, 'at' => now()->toIso8601String(), 'batches' => (object) $reasons, 'unfilled' => $unfilled]);

        return $run;
    }

    /**
     * `.88`: lo de hoy para la pantalla: el porqué de cada encargo (por su id) y lo que hace falta pero no se pudo pedir.
     *
     * @return array{batches: array<int|string, string>, unfilled: list<array>}
     */
    public static function demandToday(): array
    {
        $d = Setting::get(self::DEMAND);

        return ($d['date'] ?? null) === now(config('app.timezone'))->toDateString()
            ? ['batches' => (array) ($d['batches'] ?? []), 'unfilled' => array_values($d['unfilled'] ?? [])] : ['batches' => [], 'unfilled' => []];
    }

    /** `.89`: ya hay un recálculo pendiente en este proceso (varias cuentas de Meta, uno solo). */
    private static bool $pending = false;

    /**
     * `.89` (dueño, 2026-11-03: "no quiero tener que darle a un botón que diga proponer ahora… todo mantiene el flujo
     * correcto sin necesidad de mi intervención"): cuando la revisión de Meta (cada 30 minutos) ve que se lanzó un batch de
     * la banca, se recalcula lo que necesita cada producto al terminar, y el líder recibe el reemplazo en ese momento.
     */
    public static function later(): void
    {
        if (self::$pending) {
            return;
        }
        self::$pending = true;
        \Illuminate\Support\defer(function () {
            self::$pending = false;
            try {
                (new self())->run();
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /** `.88`: el pronóstico de los editores; si falla, la pantalla se muestra igual. */
    public static function forecast(): ?array
    {
        try {
            return (new EditorForecast())->get();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** `.88`: por qué un encargo hace falta para esa fecha, en palabras y sin montos: lo de hoy o la reserva. */
    private static function reason(array $dm, string $date, string $today): string
    {
        return $date <= $today && $dm['why'] ? $dm['why'] : DemandRules::RESERVE;
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

    private function create(array $p, array $t, string $today, ?int $userId): CreativeBatch
    {
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $angle = $p['angle_code'];
        $hypothesis = $clean($t['hipotesis'] ?? null);
        $batch = CreativeBatch::create([
            'product_id' => $p['product_id'], 'kind' => $p['kind'], 'angle_code' => $angle, 'concept' => mb_substr((string) $clean($t['concepto'] ?? null), 0, 120) ?: null,
            'hypothesis' => $hypothesis,
            // .64: el buyer persona de la ficha que eligió la IA (el editor solo ve ese); .65: en el testeo, el del ángulo
            'persona_id' => $p['persona_id'] ?? ProductProfileRules::findByName($p['context']['personas'] ?? [], is_string($t['buyer'] ?? null) ? $t['buyer'] : null)['id'] ?? null,
            'testing_round' => $p['testing']['round'] ?? null,
            // .128: una línea general de qué se prueba (en una variación, ni eso: sus textos son fijos, BatchTask)
            'test_idea' => $p['kind'] === 'iteration' ? null : $clean($t['probando'] ?? null),
            'reference_creative_id' => $p['reference_id'], 'learning_city_id' => $p['city_id'], 'format' => $p['format'],
            'style' => isset(QueueRules::STYLES[$t['estilo'] ?? '']) ? $t['estilo'] : null,
            // .69: la oferta ya no se llena con el precio (el editor no lo ve)
            'offer' => null, 'deliverables' => $clean($t['entregables'] ?? null),
            'urgency' => $p['urgency'], 'source' => 'rules', 'proposed_on' => $today, 'status' => 'proposed', 'status_at' => now(), 'created_by' => $userId,
        ]);
        // .133: una variación de formato dice en qué formatos (los que no se probaron, como en la del análisis)
        $formats = [];
        if (($p['piece_test'] ?? null) === 'format' && $p['reference_id']) {
            $stats = PieceFacts::formatStats();
            $formats = PieceRules::pickFormats($p['pieces'], $stats[$p['product_id']] ?? [], PieceFacts::others($stats, $p['product_id']),
                PieceFacts::exclude($p['product_id'], (int) $p['reference_id']));
        }
        for ($i = 0; $i < $p['pieces']; $i++) {
            // .81: cada pieza sabe qué prueba (el tipo del encargo); su hipótesis es la del encargo
            // .99: en una iteración, lo que prueba (el hook, un tramo o el formato), para que el encargo lo diga
            $f = $formats ? $formats[$i % count($formats)] : null;
            CreativeBatchPiece::create(['creative_batch_id' => $batch->id, 'position' => $i + 1, 'test' => $p['piece_test'] ?? $p['kind']]
                + ($f ? ['style' => $f['style'], 'format' => $f['style'] === 'imagen' ? 'image' : 'video', 'reason' => $f['reason']] : []));
        }

        return $batch;
    }

    /** Lo del producto para la IA: su ficha, sus avales reales, sus ángulos y los textos y el video de la referencia (.69: sin el precio). */
    private function context(array $p, array $decisions): array
    {
        $pid = $p['product_id'];
        $profile = ProductProfiles::get($pid);
        $refTexts = $p['reference_id'] ? DB::table('meta_ads')->where('meta_creative_ref_id', $p['reference_id'])
            ->where(fn ($q) => $q->whereNotNull('ad_body')->orWhereNotNull('ad_title'))->orderByDesc('created_time')->limit(2)->get(['ad_title', 'ad_body'])
            ->map(fn ($a) => trim(($a->ad_title ? $a->ad_title . ' — ' : '') . mb_strimwidth((string) $a->ad_body, 0, 280, '…')))->unique()->values()->all() : [];

        return [
            // .64: la ficha del producto, en texto para la IA, y sus buyer personas (con su id, para guardar el que elija)
            'profile' => ProductProfileRules::forAi($profile['profile'], $profile['personas']),
            'personas' => $profile['personas'],
            // .66: lo que sabemos de este producto (lo que funcionó, lo que no y las notas del dueño)
            'memory' => \App\Services\Meta\Products\ProductMemories::forWriter($pid),
            'proof' => ProductAdSetting::where('product_id', $pid)->value('proof'),
            'angles' => AdAngle::where('product_id', $pid)->whereNull('alias_of')->orderBy('code')->get(['code', 'name', 'hypothesis'])
                ->mapWithKeys(fn ($a) => [$a->code => trim(($a->name ?? '') . ($a->hypothesis ? ": {$a->hypothesis}" : ''))])->all(),
            'reference_texts' => $refTexts,
            'reference_video' => $p['reference_id'] ? ($decisions[$pid]['creatives'][$p['reference_id']]['video']['diagnosis'] ?? []) : [],
            // .63: cómo le fue a cada parte del video (hook, retención, clic, compra) y dónde se va la gente
            'reference_grades' => $p['reference_id'] ? ($decisions[$pid]['creatives'][$p['reference_id']]['video']['grades'] ?? []) : [],
            'reference_leaves' => $p['reference_id'] ? ($decisions[$pid]['creatives'][$p['reference_id']]['video']['leaves'] ?? null) : null,
            // .56: lo que dice el video de la referencia (si es un ganador transcrito)
            'reference_script' => $p['reference_id'] ? (\App\Services\Meta\Videos\Scripts::forCreatives([$p['reference_id']])[$p['reference_id']] ?? null) : null,
        ];
    }
}
