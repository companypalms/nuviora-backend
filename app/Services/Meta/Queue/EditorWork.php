<?php

namespace App\Services\Meta\Queue;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchDelivery;
use App\Models\CreativeBatchPiece;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que hacen los editores con la cola (Etapa 5, paso 5a): asignar, empezar, entregar y revisar, con los permisos de
 * EditorRules; y el encargo como lo ven ellos (todo el encargo, sin dinero). Lo usan sus pantallas (EditorQueueController)
 * y la Cola y banca del dueño (MetaQueueController).
 */
class EditorWork
{
    public const REVIEWS = ['pending' => 'Por revisar', 'approved' => 'Aprobada', 'returned' => 'Devuelta'];

    /** Lo que se carga para mostrar un encargo. */
    public const WITH = ['pieces', 'product:id,title,showable_name', 'reference:id,label', 'learningCity:id,name', 'editor:id,names,surnames',
        'deliveries.editor:id,names,surnames', 'deliveries.reviewer:id,names,surnames'];

    /** @return array{id: int, role: ?string} */
    public static function who(User $user): array
    {
        return ['id' => (int) $user->id, 'role' => $user->role?->description];
    }

    /** Los editores activos, para asignar: el líder primero. */
    public static function editors(): array
    {
        return User::with('role:id,description')->whereNull('deactivated_at')
            ->whereHas('role', fn ($q) => $q->whereIn('description', EditorRules::ROLES))->orderBy('names')->get(['id', 'names', 'surnames', 'role_id'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u), 'leader' => $u->role?->description === EditorRules::LEADER])
            ->sortByDesc('leader')->values()->all();
    }

    /** Asignar (o cambiar de editor). Sin editor solo puede quedar lo que todavía no se empezó. */
    public function assign(CreativeBatch $batch, ?int $editorId, User $actor): CreativeBatch
    {
        return DB::transaction(function () use ($batch, $editorId, $actor) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize('assign', $actor, $batch);
            if ($editorId !== null && !in_array($editorId, array_column(self::editors(), 'id'), true)) {
                throw ValidationException::withMessages(['editor_id' => 'Elige un editor activo (los usuarios con el rol "Editor líder" o "Editor de video").']);
            }
            if ($editorId === null && $batch->status !== 'approved') {
                throw ValidationException::withMessages(['editor_id' => 'Un encargo que ya se empezó tiene que quedar con un editor: elige a quién pasárselo.']);
            }
            $batch->forceFill(['editor_id' => $editorId, 'assigned_at' => $editorId ? now() : null, 'assigned_by' => $editorId ? $actor->id : null])->save();

            return $batch;
        });
    }

    public function start(CreativeBatch $batch, User $actor): CreativeBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize('start', $actor, $batch);
            $now = now();
            $batch->forceFill(['status' => 'production', 'started_at' => $batch->started_at ?? $now, 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $batch;
        });
    }

    /**
     * Entregar: un enlace de Drive de un archivo y su hook por cada pieza, y la ficha (concepto y ángulo). Pasa a "En
     * revisión" como la versión siguiente (V01, V02…).
     *
     * @param array{concept: string, angle: string, notes?: ?string, pieces: list<array{id: int, link: ?string, hook: ?string}>} $data
     */
    public function deliver(CreativeBatch $batch, User $actor, array $data): CreativeBatchDelivery
    {
        return DB::transaction(function () use ($batch, $actor, $data) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize('deliver', $actor, $batch);
            $pieces = $batch->pieces()->get();
            $given = collect($data['pieces'])->keyBy(fn ($p) => (int) $p['id']);
            $errors = [];
            if ($given->keys()->diff($pieces->pluck('id'))->isNotEmpty()) {
                $errors['pieces'] = 'Hay piezas que no son de este encargo: vuelve a abrirlo.';
            }
            foreach ($pieces->values() as $i => $p) {
                $g = $given->get($p->id);
                $name = $p->code() ?? 'Pieza ' . ($i + 1);
                if (EditorRules::driveFileId($g['link'] ?? null) === null) {
                    $errors["pieces.{$i}.link"] = "{$name}: " . EditorRules::linkProblem($g['link'] ?? null);
                }
                if (trim((string) ($g['hook'] ?? '')) === '') {
                    $errors["pieces.{$i}.hook"] = "{$name}: escribe el hook (o el texto principal, si es una imagen).";
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $rows = $pieces->map(fn (CreativeBatchPiece $p) => ['piece_id' => $p->id, 'code' => $p->code(),
                'link' => trim($given[$p->id]['link']), 'hook' => trim($given[$p->id]['hook'])])->values()->all();
            $delivery = CreativeBatchDelivery::create([
                'creative_batch_id' => $batch->id, 'version' => (int) $batch->deliveries()->max('version') + 1, 'editor_id' => $actor->id,
                'concept' => trim($data['concept']), 'angle' => trim($data['angle']), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'pieces' => $rows,
            ]);
            foreach ($pieces as $i => $p) {
                $p->forceFill(['link' => $rows[$i]['link'], 'hook' => $rows[$i]['hook']])->save();
            }
            $now = now();
            $batch->forceFill(['status' => 'review', 'status_at' => $now, 'status_by' => $actor->id,
                'started_at' => $batch->started_at ?? $now, 'delivered_at' => $batch->delivered_at ?? $now])->save();

            return $delivery;
        });
    }

    /** Revisar la entrega pendiente: aprobada pasa a la banca; devuelta, con un comentario, vuelve a producción. */
    public function review(CreativeBatch $batch, User $actor, bool $approve, ?string $comment): CreativeBatch
    {
        return DB::transaction(function () use ($batch, $actor, $approve, $comment) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $pending = $batch->deliveries()->where('review', 'pending')->first();
            $this->authorize('review', $actor, $batch, $pending?->editor_id);
            $comment = trim((string) $comment);
            if (!$approve && mb_strlen($comment) < 10) {
                throw ValidationException::withMessages(['comment' => 'Escribe qué hay que cambiar con algo que se vea: el segundo, el texto o la imagen (por ejemplo, "C0457, seg. 0–3: el texto tapa la cara").']);
            }
            $now = now();
            $pending->forceFill(['review' => $approve ? 'approved' : 'returned', 'reviewed_by' => $actor->id, 'reviewed_at' => $now, 'comment' => $comment ?: null])->save();
            $batch->forceFill(['status' => $approve ? 'bank' : 'production', 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $batch;
        });
    }

    /**
     * Los encargos como los ven los editores: todo lo del encargo, sin dinero (ni el gasto, ni el resultado, ni el
     * aprendizaje, que puede traer montos), con la miniatura y los textos de la referencia y lo que puede hacer quien mira.
     *
     * @param Collection<int, CreativeBatch> $batches con self::WITH
     * @param array{id: int, role: ?string} $who
     */
    public static function present(Collection $batches, array $who): array
    {
        $angles = AdAngle::whereIn('product_id', $batches->pluck('product_id')->unique())->whereNotNull('name')->get(['product_id', 'code', 'name'])
            ->mapWithKeys(fn ($a) => ["{$a->product_id}:{$a->code}" => $a->name]);
        $refs = self::references($batches->pluck('reference_creative_id')->filter()->unique()->values()->all());

        return self::sorted($batches)->map(function (CreativeBatch $b) use ($angles, $refs, $who) {
            $out = [
                'id' => $b->id, 'code' => $b->code(), 'product' => $b->product?->showable_name ?: $b->product?->title,
                'status' => $b->status, 'status_label' => QueueRules::STATUSES[$b->status] ?? $b->status, 'status_at' => $b->status_at?->toIso8601String(),
                'position' => $b->position, 'kind' => $b->kind, 'kind_label' => QueueRules::KINDS[$b->kind] ?? $b->kind,
                'angle_code' => $b->angle_code, 'angle_name' => $angles["{$b->product_id}:{$b->angle_code}"] ?? null,
                'concept' => $b->concept, 'hypothesis' => $b->hypothesis, 'audience' => $b->audience,
                'reference' => $b->reference?->label, 'reference_thumbnail' => $refs[$b->reference_creative_id]['thumbnail'] ?? null,
                'reference_texts' => $refs[$b->reference_creative_id]['texts'] ?? [],
                'city' => $b->learningCity?->name, 'format' => $b->format, 'keep' => $b->keep, 'offer' => $b->offer, 'deliverables' => $b->deliverables,
                'urgency' => $b->urgency, 'due_date' => $b->due_date?->toDateString(),
                'pieces' => $b->pieces->map(fn (CreativeBatchPiece $p) => ['id' => $p->id, 'code' => $p->code(), 'change' => $p->change, 'format' => $p->format,
                    'link' => $p->link, 'hook' => $p->hook])->values()->all(),
                'meta_names' => QueueRules::metaNames($b->number, $b->angle_code, $b->pieces->pluck('number')->all()),
            ] + self::fields($b, $who);

            return $out + ['brief' => QueueRules::brief($out)];
        })->values()->all();
    }

    /**
     * Lo de los editores en un encargo, para sus pantallas y para la del dueño: quién lo hace, sus entregas, si se lo
     * devolvieron y lo que puede hacer quien mira.
     *
     * @param array{id: int, role: ?string} $who
     */
    public static function fields(CreativeBatch $b, array $who): array
    {
        $pending = $b->deliveries->firstWhere('review', 'pending');
        $state = ['status' => $b->status, 'editor_id' => $b->editor_id, 'pending_by' => $pending?->editor_id];
        $last = $b->deliveries->first();

        return [
            'editor_id' => $b->editor_id, 'editor' => $b->editor ? self::name($b->editor) : null,
            'assigned_at' => $b->assigned_at?->toIso8601String(), 'started_at' => $b->started_at?->toIso8601String(),
            'delivered_at' => $b->delivered_at?->toIso8601String(),
            'overdue' => $b->due_date !== null && $b->delivered_at === null && in_array($b->status, ['approved', 'production'], true)
                && $b->due_date->toDateString() < now(config('app.timezone'))->toDateString(),
            'returned' => $b->status === 'production' && $last?->review === 'returned' ? ['version' => $last->label(), 'comment' => $last->comment,
                'by' => $last->reviewer ? self::name($last->reviewer) : null] : null,
            'deliveries' => $b->deliveries->map(fn (CreativeBatchDelivery $d) => [
                'id' => $d->id, 'version' => $d->label(), 'editor' => $d->editor ? self::name($d->editor) : null, 'delivered_at' => $d->created_at?->toIso8601String(),
                'concept' => $d->concept, 'angle' => $d->angle, 'notes' => $d->notes, 'pieces' => $d->pieces,
                'review' => $d->review, 'review_label' => self::REVIEWS[$d->review] ?? $d->review, 'reviewed_by' => $d->reviewer ? self::name($d->reviewer) : null,
                'reviewed_at' => $d->reviewed_at?->toIso8601String(), 'comment' => $d->comment,
            ])->values()->all(),
            'can' => array_combine(EditorRules::ACTIONS, array_map(fn ($a) => EditorRules::can($a, $who, $state), EditorRules::ACTIONS)),
            // La entrega del líder espera al dueño: se lo dice en vez de esconder el botón sin explicar
            'review_by_owner' => $b->status === 'review' && $pending !== null && $who['role'] === EditorRules::LEADER && (int) $pending->editor_id === $who['id'],
        ];
    }

    /** El estado de cada encargo para contar lo pendiente (EditorRules::pending). */
    public static function states(Collection $batches): array
    {
        return $batches->map(fn (CreativeBatch $b) => ['status' => $b->status, 'editor_id' => $b->editor_id,
            'pending_by' => $b->deliveries->firstWhere('review', 'pending')?->editor_id])->all();
    }

    /** En el orden de la cola: por estado, después la posición. */
    public static function sorted(Collection $batches): Collection
    {
        return $batches->sortBy(fn ($b) => [array_search($b->status, array_keys(QueueRules::STATUSES), true), $b->position ?? PHP_INT_MAX, $b->id])->values();
    }

    /** La miniatura y hasta dos textos del creativo de referencia, del anuncio más nuevo que lo usa. */
    private static function references(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $ads = DB::table('meta_ads')->whereIn('meta_creative_ref_id', $ids)->orderByDesc('created_time')
            ->get(['meta_creative_ref_id', 'thumbnail_url', 'image_url', 'ad_title', 'ad_body']);
        foreach ($ads->groupBy('meta_creative_ref_id') as $id => $rows) {
            $pic = $rows->first(fn ($a) => $a->image_url || $a->thumbnail_url);
            $out[$id] = [
                'thumbnail' => $pic ? ($pic->image_url ?: $pic->thumbnail_url) : null,
                'texts' => $rows->map(fn ($a) => implode(' — ', array_filter([trim((string) $a->ad_title), mb_strimwidth(trim((string) $a->ad_body), 0, 400, '…')])))
                    ->filter()->unique()->take(2)->values()->all(),
            ];
        }

        return $out;
    }

    /** Si no puede, por qué: no es suyo (403) o el encargo está en otro estado (422). */
    private function authorize(string $action, User $actor, CreativeBatch $batch, ?int $pendingBy = null): void
    {
        $who = self::who($actor);
        $state = ['status' => $batch->status, 'editor_id' => $batch->editor_id, 'pending_by' => $pendingBy];
        if (EditorRules::can($action, $who, $state)) {
            return;
        }
        $deny = fn (string $message) => new HttpResponseException(response()->json(['status' => false, 'message' => $message], 403));
        if (in_array($action, ['assign', 'review'], true) && !in_array($who['role'], [EditorRules::ADMIN, EditorRules::LEADER], true)) {
            throw $deny('Eso lo hace el editor líder.');
        }
        if (!EditorRules::canSee($who, $state) || in_array($action, ['start', 'deliver'], true) && (int) $batch->editor_id !== $who['id']) {
            throw $deny('Este encargo no está asignado a ti.');
        }
        if ($action === 'review' && $pendingBy !== null && $pendingBy === $who['id']) {
            throw $deny(EditorRules::ownReviewMessage());
        }
        $verbs = ['assign' => 'asignar', 'start' => 'empezar', 'deliver' => 'entregar', 'review' => 'revisar'];
        throw ValidationException::withMessages(['status' => 'Este encargo está "' . (QueueRules::STATUSES[$batch->status] ?? $batch->status)
            . '": ahora no se puede ' . $verbs[$action] . '.']);
    }

    private static function name(User $u): string
    {
        return trim($u->names . ' ' . ($u->surnames ?? ''));
    }
}
