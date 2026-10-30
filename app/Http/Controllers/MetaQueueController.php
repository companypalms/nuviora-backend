<?php

namespace App\Http\Controllers;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\MetaCreative;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Meta\AngleStats;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Queue\BatchQueue;
use App\Services\Meta\Queue\BatchTracker;
use App\Services\Meta\Queue\QueueProposer;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Meta Ads › Cola y banca (Etapa 5, paso 4a). Solo el Administrador, como todo Meta Ads (hasta el Paso 5 no entran los
 * editores). Cada encargo es un batch con sus piezas:
 * - se crea a mano, o lo proponen las reglas cada mañana y lo redacta la IA (paso 4b, QueueProposer), y queda "Propuesto";
 * - al aprobarlo, el sistema le da su B#### y a cada pieza su C####, y entra al final de la cola;
 * - el dueño lo mueve (En producción, En revisión, En banca) o lo descarta con un motivo;
 * - "En test" y "Testeado" se ponen solos cuando aparece en Meta y cuando las reglas le dan un veredicto (BatchTracker).
 * Nada de esto toca Meta.
 */
class MetaQueueController extends Controller
{
    /** Los testeados y descartados que se muestran (los más nuevos). */
    private const HISTORY = 60;

    public function index(DecisionSettings $settings, MetaDecisions $decisions)
    {
        try {
            (new BatchTracker($decisions))->run();
        } catch (\Throwable $e) {
            report($e); // la cola se muestra igual
        }
        $open = CreativeBatch::whereNotIn('status', ['tested', 'discarded'])->get();
        $closed = CreativeBatch::whereIn('status', ['tested', 'discarded'])->orderByDesc('status_at')->limit(self::HISTORY)->get();
        $batches = $open->merge($closed);
        $batches->load(['pieces', 'product:id,title,showable_name', 'reference:id,label', 'learningCity:id,name', 'approver:id,names', 'statusUser:id,names']);
        $s = $settings->get();

        return response()->json([
            'batches' => self::presentAll($batches),
            'products' => $this->products($decisions),
            'settings' => [
                'batch_pieces' => (int) $s['batch_pieces'], 'due_days' => (int) $s['due_days'], 'bank_min' => (int) $s['bank_min'], 'bank_max' => (int) $s['bank_max'],
                'video_editors' => (int) $s['video_editors'],
                // 2 batches por editor por día, menos el tiempo de las correcciones
                'capacity' => round($s['video_editors'] * $s['batches_per_editor'] / (1 + $s['corrections_pct'] / 100), 1),
            ],
            'statuses' => QueueRules::STATUSES, 'kinds' => QueueRules::KINDS, 'formats' => QueueRules::FORMATS,
            'last_run' => Setting::get(QueueProposer::LAST_RUN),
        ]);
    }

    /** "Proponer ahora": lo mismo que corre con el análisis de la mañana (no repite lo ya propuesto hoy). */
    public function propose(QueueProposer $proposer)
    {
        return response()->json(['run' => $proposer->run(Auth::id())]);
    }

    /** Lo que se elige al crear o editar un encargo de un producto: sus ángulos, sus creativos, sus ciudades y su precio. */
    public function options(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $pid = (int) $data['product_id'];

        return response()->json([
            'angles' => AdAngle::where('product_id', $pid)->whereNull('alias_of')->orderBy('code')->get(['code', 'name', 'hypothesis'])->values(),
            'creatives' => MetaCreative::where('product_id', $pid)->orderByRaw("FIELD(status, 'winner', 'testing', 'fatigued', 'loser', 'archived')")
                ->orderBy('label')->get(['id', 'label', 'status', 'angle_code', 'batch_code'])->values(),
            'cities' => DB::table('meta_campaigns as c')->join('cities as ci', 'ci.id', '=', 'c.city_id')->where('c.product_id', $pid)
                ->distinct()->orderBy('ci.name')->get(['ci.id', 'ci.name']),
            'price' => ($p = Product::find($pid, ['price'])) && $p->price !== null ? (float) $p->price : null,
        ]);
    }

    public function store(Request $request, MetaDecisions $decisions)
    {
        $data = $this->validated($request, null);
        $batch = DB::transaction(function () use ($data, $decisions) {
            $urgency = $data['urgency'] ?? null;
            if ($urgency === null) {
                $plan = $decisions->forProducts([$data['product_id']])[$data['product_id']]['plan'] ?? null;
                $urgency = $plan['urgency']['level'] ?? null;
            }
            $batch = CreativeBatch::create(array_diff_key($data, ['pieces' => 1]) + ['status' => 'proposed', 'source' => 'owner',
                'urgency' => $urgency, 'created_by' => Auth::id(), 'status_at' => now(), 'status_by' => Auth::id()]);
            $this->savePieces($batch, $data['pieces']);

            return $batch;
        });

        return response()->json(['batch' => $this->presentOne($batch)], 201);
    }

    public function update(Request $request, CreativeBatch $batch)
    {
        $data = $this->validated($request, $batch);
        DB::transaction(function () use ($batch, $data) {
            $batch->fill(array_diff_key($data, ['pieces' => 1, 'product_id' => 1]))->save();
            if (array_key_exists('pieces', $data)) {
                $this->savePieces($batch, $data['pieces']);
            }
        });

        return response()->json(['batch' => $this->presentOne($batch)]);
    }

    public function approve(CreativeBatch $batch, BatchQueue $queue)
    {
        return response()->json(['batch' => $this->presentOne($queue->approve($batch, (int) Auth::id()))]);
    }

    /** Mover de estado a mano: los de la cola, a la banca, descartar (con motivo) o volver de un descarte. */
    public function status(Request $request, CreativeBatch $batch)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(QueueRules::STATUSES))],
            'reason' => 'nullable|string|max:255',
        ]);
        if (!QueueRules::canMove($batch->status, $data['status'], $batch->number !== null)) {
            throw ValidationException::withMessages(['status' => 'No se puede pasar de "' . QueueRules::STATUSES[$batch->status] . '" a "'
                . QueueRules::STATUSES[$data['status']] . '".']);
        }
        if ($data['status'] === 'discarded' && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe por qué se descarta.']);
        }
        $now = now();
        $batch->forceFill(['status' => $data['status'], 'status_at' => $now, 'status_by' => Auth::id(),
            'discard_reason' => $data['status'] === 'discarded' ? trim($data['reason']) : null,
            'testing_at' => $data['status'] === 'testing' ? ($batch->testing_at ?? $now) : $batch->testing_at,
            'tested_at' => $data['status'] === 'tested' ? ($batch->tested_at ?? $now) : $batch->tested_at,
            'position' => in_array($data['status'], QueueRules::QUEUE, true) && !in_array($batch->status, QueueRules::QUEUE, true)
                ? (int) CreativeBatch::whereIn('status', QueueRules::QUEUE)->max('position') + 1 : $batch->position,
        ])->save();

        return response()->json(['batch' => $this->presentOne($batch)]);
    }

    /** El orden de la cola: los ids en el orden nuevo. */
    public function order(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer']);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                CreativeBatch::whereKey($id)->whereIn('status', QueueRules::QUEUE)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['status' => true]);
    }

    private function validated(Request $request, ?CreativeBatch $batch): array
    {
        $productId = $batch->product_id ?? (int) $request->input('product_id');
        $data = $request->validate([
            'product_id' => $batch ? 'prohibited' : 'required|integer|exists:products,id',
            'kind' => [$batch ? 'sometimes' : 'required', Rule::in(array_keys(QueueRules::KINDS))],
            'angle_code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'concept' => 'sometimes|nullable|string|max:120',
            'hypothesis' => 'sometimes|nullable|string|max:2000',
            'audience' => 'sometimes|nullable|string|max:255',
            'reference_creative_id' => ['sometimes', 'nullable', 'integer', Rule::exists('meta_creatives', 'id')->where('product_id', $productId)],
            'learning_city_id' => 'sometimes|nullable|integer|exists:cities,id',
            'format' => ['sometimes', 'nullable', Rule::in(array_keys(QueueRules::FORMATS))],
            'keep' => 'sometimes|nullable|string|max:2000',
            'offer' => 'sometimes|nullable|string|max:255',
            'deliverables' => 'sometimes|nullable|string|max:2000',
            'urgency' => 'sometimes|nullable|integer|min:1|max:5',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'learning' => 'sometimes|nullable|string|max:2000',
            'pieces' => [$batch ? 'sometimes' : 'present', 'array', 'max:10'],
            'pieces.*.id' => 'sometimes|nullable|integer',
            'pieces.*.change' => 'nullable|string|max:1000',
            'pieces.*.format' => ['nullable', Rule::in(array_keys(QueueRules::FORMATS))],
        ]);
        if (isset($data['angle_code'])) {
            $data['angle_code'] = strtoupper($data['angle_code']);
        }
        if (in_array($batch?->status, ['tested', 'discarded'], true) && array_diff(array_keys($data), ['learning'])) {
            throw ValidationException::withMessages(['status' => 'De un encargo testeado o descartado solo se puede cambiar el aprendizaje.']);
        }

        return $data;
    }

    /** Las piezas: antes de aprobar se agregan y se quitan; después, las nuevas reciben su número y las numeradas no se quitan. */
    private function savePieces(CreativeBatch $batch, array $pieces): void
    {
        $existing = $batch->pieces()->get()->keyBy('id');
        $keep = [];
        foreach (array_values($pieces) as $i => $p) {
            $piece = isset($p['id']) ? $existing->get($p['id']) : null;
            $piece ??= new CreativeBatchPiece(['creative_batch_id' => $batch->id]);
            $piece->fill(['position' => $i + 1, 'change' => $p['change'] ?? null, 'format' => $p['format'] ?? null])->save();
            if ($batch->number !== null && $piece->number === null) {
                BatchQueue::numberPiece($piece);
            }
            $keep[] = $piece->id;
        }
        $gone = $existing->except($keep);
        if ($gone->contains(fn ($p) => $p->number !== null)) {
            throw ValidationException::withMessages(['pieces' => 'Una pieza que ya tiene su número no se quita: si no va, déjala anotada en su texto.']);
        }
        CreativeBatchPiece::whereIn('id', $gone->keys())->delete();
    }

    /** Los productos con campañas, con su banca contra lo que piden las reglas. */
    private function products(MetaDecisions $decisions): array
    {
        $products = AngleStats::products();
        $active = array_column(array_filter($products, fn ($p) => $p['active_campaigns'] > 0), 'id');
        try {
            $plans = $decisions->forProducts($active);
        } catch (\Throwable $e) {
            report($e);
            $plans = [];
        }
        $bank = CreativeBatch::whereIn('status', QueueRules::BANK)->get(['product_id', 'status'])->groupBy('product_id');

        return array_map(function ($p) use ($plans, $bank) {
            $u = $plans[$p['id']]['plan']['urgency'] ?? null;

            return $p + [
                'bank' => ($bank[$p['id']] ?? collect())->count(),
                'ready' => ($bank[$p['id']] ?? collect())->where('status', 'bank')->count(),
                'urgency' => $u ? ['level' => $u['level'], 'label' => $u['label'], 'to_order' => $u['batches'], 'needed' => $u['needed'] ?? $u['batches']] : null,
            ];
        }, $products);
    }

    private function presentOne(CreativeBatch $batch): array
    {
        $batch->load(['pieces', 'product:id,title,showable_name', 'reference:id,label', 'learningCity:id,name', 'approver:id,names', 'statusUser:id,names']);

        return self::presentAll(collect([$batch]))[0];
    }

    /** @param \Illuminate\Support\Collection<int, CreativeBatch> $batches */
    private static function presentAll($batches): array
    {
        $angles = AdAngle::whereIn('product_id', $batches->pluck('product_id')->unique())->whereNotNull('name')->get(['product_id', 'code', 'name'])
            ->mapWithKeys(fn ($a) => ["{$a->product_id}:{$a->code}" => $a->name]);

        return $batches->sortBy(fn ($b) => [array_search($b->status, array_keys(QueueRules::STATUSES), true), $b->position ?? PHP_INT_MAX, $b->id])
            ->values()->map(function (CreativeBatch $b) use ($angles) {
                $pieces = $b->pieces->map(fn (CreativeBatchPiece $p) => ['id' => $p->id, 'code' => $p->code(), 'change' => $p->change, 'format' => $p->format])->values()->all();
                $out = [
                    'id' => $b->id, 'code' => $b->code(), 'product_id' => $b->product_id, 'product' => $b->product?->showable_name ?: $b->product?->title,
                    'status' => $b->status, 'status_label' => QueueRules::STATUSES[$b->status] ?? $b->status, 'status_at' => $b->status_at?->toIso8601String(),
                    'status_by' => $b->statusUser?->names, 'position' => $b->position, 'source' => $b->source,
                    'kind' => $b->kind, 'angle_code' => $b->angle_code, 'angle_name' => $angles["{$b->product_id}:{$b->angle_code}"] ?? null,
                    'concept' => $b->concept, 'hypothesis' => $b->hypothesis, 'audience' => $b->audience,
                    'reference_id' => $b->reference_creative_id, 'reference' => $b->reference?->label,
                    'city_id' => $b->learning_city_id, 'city' => $b->learningCity?->name, 'format' => $b->format, 'keep' => $b->keep, 'offer' => $b->offer,
                    'deliverables' => $b->deliverables, 'urgency' => $b->urgency, 'due_date' => $b->due_date?->toDateString(),
                    'approved_at' => $b->approved_at?->toIso8601String(), 'approved_by' => $b->approver?->names,
                    'discard_reason' => $b->discard_reason, 'testing_at' => $b->testing_at?->toIso8601String(), 'tested_at' => $b->tested_at?->toIso8601String(),
                    'result' => $b->result, 'learning' => $b->learning, 'pieces' => $pieces,
                    'meta_names' => QueueRules::metaNames($b->number, $b->angle_code, $b->pieces->pluck('number')->all()),
                ];

                return $out + ['brief' => QueueRules::brief($out)];
            })->all();
    }
}
