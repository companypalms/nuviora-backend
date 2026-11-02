<?php

namespace App\Http\Controllers;

use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Services\Meta\Analysis\AnalysisSender;
use App\Services\Meta\Analysis\DayStreak;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * La página "Análisis del día" (Etapa 5, paso 3a). Solo el Administrador, como todo Meta Ads.
 * - Ver el análisis de un día (el último, si no se elige): al abrirlo, la tarea del calendario de ese día se tilda sola.
 * - "Volver a analizar": otra versión del día, como mucho 10 por día.
 * - Marcar un punto de la lista como hecho, o "no estoy de acuerdo" con una nota.
 */
class MetaAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'version' => 'nullable|integer|min:1']);
        $q = MetaAnalysis::query()->with('requester:id,names');
        $analysis = $request->filled('date')
            ? $q->where('date', $request->get('date'))->when($request->filled('version'), fn ($w) => $w->where('version', $request->integer('version')))
                ->orderByDesc('version')->first()
            : $q->orderByDesc('date')->orderByDesc('version')->first();
        if ($analysis && $analysis->viewed_at === null) {
            $analysis->forceFill(['viewed_at' => now(), 'viewed_by' => Auth::id()])->save();
        }

        return response()->json([
            'analysis' => $analysis ? $this->present($analysis) : null,
            'dates' => MetaAnalysis::selectRaw('date, MAX(version) versions')->groupBy('date')->orderByDesc('date')->limit(60)->get()
                ->map(fn ($r) => ['date' => substr((string) $r->date, 0, 10), 'versions' => (int) $r->versions]),
            'runs_left' => MetaAnalyzer::runsLeft(),
            'queue_pending' => CreativeBatch::where('status', 'proposed')->count(), // paso 4b
        ]);
    }

    public function run(MetaAnalyzer $analyzer)
    {
        if (MetaAnalyzer::runsLeft() <= 0) {
            return response()->json(['message' => 'Ya se volvió a analizar ' . MetaAnalyzer::MAX_RUNS_PER_DAY . ' veces hoy. Mañana a las 7:00 sale el nuevo.'], 429);
        }
        $analysis = $analyzer->run(Auth::id());
        $analysis->forceFill(['viewed_at' => now(), 'viewed_by' => Auth::id()])->save();

        return response()->json(['analysis' => $this->present($analysis->load('requester:id,names')), 'runs_left' => MetaAnalyzer::runsLeft()]);
    }

    public function mark(Request $request, MetaAnalysis $analysis)
    {
        $keys = array_column($analysis->urgent ?? [], 'key');
        $data = $request->validate([
            'key' => ['required', 'string', Rule::in($keys)],
            'status' => ['nullable', Rule::in(['done', 'disagree'])],
            'note' => 'nullable|string|max:500',
        ]);
        $marks = $analysis->marks ?? [];
        if (($data['status'] ?? null) === null) {
            unset($marks[$data['key']]);
        } else {
            $marks[$data['key']] = ['status' => $data['status'], 'note' => $data['note'] ?? null, 'by' => Auth::user()?->names, 'at' => now()->toIso8601String()];
        }
        $analysis->forceFill(['marks' => $marks ?: null])->save();

        return response()->json(['marks' => $analysis->marks ?? []]);
    }

    /**
     * Por creativo del análisis, su encargo más nuevo que está en manos del equipo o en test: el código, en qué va y quién
     * lo hace.
     *
     * @return array<int, array{code: ?string, status: string, status_label: string, editor: ?string}>
     */
    private static function sent(array $facts): array
    {
        $ids = array_merge([], ...array_map(fn ($p) => array_column($p['creatives'] ?? [], 'id'), $facts));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (CreativeBatch::with('editor:id,names,surnames')->whereIn('reference_creative_id', $ids)
            ->whereIn('status', [...AnalysisSender::OPEN, 'testing'])->orderBy('id')->get() as $b) {
            $out[(int) $b->reference_creative_id] = ['code' => $b->code(), 'status' => $b->status,
                'status_label' => QueueRules::STATUSES[$b->status] ?? $b->status,
                'editor' => $b->editor ? trim($b->editor->names . ' ' . $b->editor->surnames) : null];
        }

        return $out;
    }

    private function present(MetaAnalysis $a): array
    {
        return [
            'id' => $a->id, 'date' => $a->date->toDateString(), 'version' => $a->version, 'status' => $a->status,
            'created_at' => $a->created_at?->toIso8601String(), 'requested_by' => $a->requester?->names,
            'model' => $a->model, 'error' => $a->error, 'warnings' => $a->warnings ?? [],
            'urgent' => $a->urgent ?? [], 'marks' => (object) ($a->marks ?? []),
            'facts' => $a->facts ?? [], 'text' => $a->text,
            // .82: la racha: cuántos días seguidos, hasta el día anterior a este análisis, se resolvieron todas las misiones
            'streak' => ['before' => DayStreak::before($a->date->toDateString())],
            // .71: lo que ya tiene el equipo de cada creativo (el encargo que se le mandó al editor líder)
            'sent' => (object) self::sent($a->facts ?? []),
            // .78: por producto, su turno: el creativo que va primero y sus encargos en camino o en la banca
            'turns' => (object) collect(AnalysisSender::turns($a->facts ?? [], app(\App\Services\Meta\Decisions\DecisionSettings::class)->get()))
                ->mapWithKeys(fn ($t) => [(string) $t['product_id'] => ['pick' => (int) $t['creative']['id'], 'open' => $t['open'], 'full' => $t['full']]])->all(),
            'versions' => MetaAnalysis::where('date', $a->date)->orderBy('version')->get(['version', 'created_at', 'requested_by'])
                ->map(fn ($v) => ['version' => $v->version, 'created_at' => $v->created_at?->toIso8601String(), 'auto' => $v->requested_by === null]),
        ];
    }
}
