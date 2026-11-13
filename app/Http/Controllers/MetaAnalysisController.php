<?php

namespace App\Http\Controllers;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\MetaAnalysis;
use App\Models\NewProductMission;
use App\Models\Setting;
use App\Models\StoredFile;
use App\Services\Meta\Analysis\AnalysisSender;
use App\Services\Meta\Analysis\DayStreak;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Products\NewProductMissions;
use App\Services\Meta\Products\NewProductRules;
use App\Services\Meta\Products\ProductWorthBoard;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * La página "Análisis del día" (Etapa 5, paso 3a). Solo el Administrador, como todo Meta Ads.
 * - Ver el análisis de un día (el último, si no se elige): al abrirlo, la tarea del calendario de ese día se tilda sola.
 * - "Volver a analizar": otra versión del día, como mucho 10 por día.
 * - Marcar un punto de la lista como hecho, o "no estoy de acuerdo" con una nota.
 * - `.102`: los videos de los batches que lanza una misión, para bajarlos ahí mismo.
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
            'new_product' => $this->newProduct(), // .118
        ]);
    }

    /**
     * `.102` (dueño, 2026-11-04: "cuando me diga para lanzar un batch, me debería dejar descargar los creativos desde esa
     * misma ventana"): los videos de unos batches (B0115…), con su nombre de Meta y los enlaces para verlos y bajarlos. Se
     * piden al abrir la misión: los enlaces vencen a las 6 horas y el análisis se hizo a las 00:30.
     */
    public function videos(Request $request)
    {
        $data = $request->validate(['codes' => 'required|array|min:1|max:10', 'codes.*' => ['required', 'string', 'regex:/^B\d{4,}$/']]);
        $numbers = array_values(array_unique(array_map(fn ($c) => (int) substr($c, 1), $data['codes'])));
        $batches = CreativeBatch::with('pieces')->whereIn('number', $numbers)->get()->keyBy('number');
        $files = StoredFile::payloads($batches->flatMap(fn (CreativeBatch $b) => $b->pieces->pluck('file_id'))->all());

        return response()->json(['batches' => collect($numbers)->map(fn ($n) => $batches[$n] ?? null)->filter()->map(fn (CreativeBatch $b) => [
            'code' => $b->code(), 'adset' => QueueRules::metaNames($b->number, $b->angle_code, [])['adset'],
            'status' => $b->status, 'status_label' => QueueRules::STATUSES[$b->status] ?? $b->status,
            // El nombre de cada anuncio en Meta ("C0145 VAGO"); el archivo listo, o el video de Drive de los de antes
            'pieces' => $b->pieces->map(fn (CreativeBatchPiece $p) => ['name' => $p->code() !== null ? trim($p->code() . ' ' . $b->angle_code) : null,
                'file' => $files[$p->file_id] ?? null, 'link' => $p->link])->values()->all(),
        ])->values()->all()]);
    }

    public function run(MetaAnalyzer $analyzer)
    {
        if (MetaAnalyzer::runsLeft() <= 0) {
            return response()->json(['message' => 'Ya se volvió a analizar ' . MetaAnalyzer::MAX_RUNS_PER_DAY . ' veces hoy. El nuevo sale a las 12:30 a. m.'], 429);
        }
        // .84: responde apenas se guarda el análisis; los encargos y "Lo que sabemos" siguen después de responder
        $analysis = $analyzer->run(Auth::id(), later: true);
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
        // .118: "vas tarde con el producto nuevo": no se puede descartar, y "Hecho" termina la etapa
        if (preg_match('/^new_product:(\d+):(\w+)$/', $data['key'], $m)) {
            (new NewProductMissions())->fromList((int) $m[1], $m[2], $data['status'] ?? null);
            $analysis->refresh();
        }
        $marks = $analysis->marks ?? [];
        if (($data['status'] ?? null) === null) {
            unset($marks[$data['key']]);
        } else {
            $marks[$data['key']] = ['status' => $data['status'], 'note' => $data['note'] ?? null, 'by' => Auth::user()?->names, 'at' => now()->toIso8601String()];
        }
        $analysis->forceFill(['marks' => $marks ?: null])->save();
        // .117: con "No estoy de acuerdo", prender esa ciudad no se vuelve a sugerir (si lo deshace, sí). .125: y "decide si
        // sueltas el producto" no se vuelve a preguntar en 14 días. .139: y un producto Malo sigue con sus encargos 14 días. .140: y
        // "baja el precio" no se vuelve a proponer en 14 días
        if (preg_match('/:(relaunch|no_city|drop_product|worth_bad|price_down)$/', $data['key'])) {
            $dismissed = (array) Setting::get(OpsFacts::DISMISSED, []);
            if (($data['status'] ?? null) === 'disagree') {
                $dismissed[$data['key']] = now(config('app.timezone'))->toDateString();
            } else {
                unset($dismissed[$data['key']]);
            }
            Setting::set(OpsFacts::DISMISSED, $dismissed);
            // .139: la página de productos y los editores lo ven ya, sin esperar al próximo análisis
            if (preg_match('/^(\d+):worth_bad$/', $data['key'], $m)) {
                ProductWorthBoard::brake((int) $m[1], ($data['status'] ?? null) !== 'disagree');
            }
        }

        return response()->json(['marks' => $analysis->marks ?? []]);
    }

    /** `.118`: "Hecho" en una etapa de la misión de producto nuevo. */
    public function missionDone(Request $request, NewProductMission $mission)
    {
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys(NewProductRules::STAGES))]]);
        $missions = new NewProductMissions();
        $missions->complete($mission, $data['stage']);

        return response()->json(['view' => $missions->view()]);
    }

    /** `.118`: la misión de producto nuevo; si falla, la pantalla se muestra igual. */
    private function newProduct(): ?array
    {
        try {
            return (new NewProductMissions())->view();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
        // .141: solo sus iteraciones (un concepto nuevo que lo tiene de referencia no es "la variación que se pidió")
        foreach (CreativeBatch::with('editor:id,names,surnames')->where('kind', 'iteration')->whereIn('reference_creative_id', $ids)
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
