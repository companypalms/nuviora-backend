<?php

namespace App\Services\Meta\Landings;

use App\Models\AdAngle;
use App\Models\Landing;
use App\Models\LandingTask;
use App\Models\LandingTaskDelivery;
use App\Models\User;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que se hace con un encargo de landing (Etapa 5, paso 6b), con los permisos de LandingTaskRules: crear, aprobar,
 * asignar, empezar, entregar, revisar, publicar y descartar; y el encargo como lo ve cada uno (el editor, sin dinero). Lo
 * usan Meta Ads › Landings del dueño (MetaLandingTasksController) y "Mis encargos de landing" (LandingEditorController).
 */
class LandingTaskWork
{
    public const REVIEWS = ['pending' => 'Por revisar', 'approved' => 'Aprobada', 'returned' => 'Devuelta'];

    public const WITH = ['product:id,title,showable_name', 'landing', 'testLanding', 'editor:id,names,surnames', 'deliveries.editor:id,names,surnames',
        'deliveries.reviewer:id,names,surnames'];

    public function __construct(private DecisionSettings $settings = new DecisionSettings())
    {
    }

    /** Los editores de landings activos, para asignar. */
    public static function editors(): array
    {
        return User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->where('description', LandingTaskRules::ROLE))
            ->orderBy('names')->get(['id', 'names', 'surnames'])->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u)])->values()->all();
    }

    /** @param array{product_id: int, kind: string, landing_id?: ?int, angle_code?: ?string, observed?: ?string, hypothesis?: ?string, change?: ?string, keep?: ?string, notes?: ?string} $data */
    public function create(array $data, User $actor, bool $approve = false, ?int $editorId = null): LandingTask
    {
        return DB::transaction(function () use ($data, $actor, $approve, $editorId) {
            if (!empty($data['landing_id'])) {
                $this->ensureFree((int) $data['landing_id']);
            }
            $clean = fn ($k) => isset($data[$k]) && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;
            $task = LandingTask::create([
                'product_id' => $data['product_id'], 'kind' => $data['kind'], 'landing_id' => $data['landing_id'] ?? null, 'angle_code' => $clean('angle_code'),
                'observed' => $clean('observed'), 'hypothesis' => $clean('hypothesis'), 'change' => $clean('change'), 'keep' => $clean('keep'),
                'notes' => $clean('notes'), 'status' => 'proposed', 'source' => $data['source'] ?? 'owner',
                'proposed_on' => now(config('app.timezone'))->toDateString(), 'created_by' => $actor->id, 'status_at' => now(), 'status_by' => $actor->id,
            ]);

            return $approve ? $this->approve($task, $actor, $editorId) : $task;
        });
    }

    /** Aprobar: su número L####, el plazo y quién lo hace (el editor de landings si hay uno solo; si no, el dueño). */
    public function approve(LandingTask $task, User $actor, ?int $editorId = null): LandingTask
    {
        return DB::transaction(function () use ($task, $actor, $editorId) {
            $task = LandingTask::lockForUpdate()->findOrFail($task->id);
            $this->authorize('approve', $actor, $task);
            $editors = array_column(self::editors(), 'id');
            $editorId ??= count($editors) === 1 ? $editors[0] : $actor->id;
            $this->validEditor($editorId, $actor);
            $now = now();
            $number = LandingTaskRules::next(LandingTask::lockForUpdate()->whereNotNull('number')->pluck('number'));
            $task->forceFill(['number' => $number, 'status' => 'approved', 'editor_id' => $editorId, 'approved_at' => $now, 'approved_by' => $actor->id,
                'due_date' => now(config('app.timezone'))->addDays((int) $this->settings->get()['landing_due_days'])->toDateString(),
                'status_at' => $now, 'status_by' => $actor->id])->save();

            return $task;
        });
    }

    public function assign(LandingTask $task, int $editorId, User $actor): LandingTask
    {
        return DB::transaction(function () use ($task, $editorId, $actor) {
            $task = LandingTask::lockForUpdate()->findOrFail($task->id);
            $this->authorize('assign', $actor, $task);
            $this->validEditor($editorId, $actor);
            $task->forceFill(['editor_id' => $editorId])->save();

            return $task;
        });
    }

    public function start(LandingTask $task, User $actor): LandingTask
    {
        return DB::transaction(function () use ($task, $actor) {
            $task = LandingTask::lockForUpdate()->findOrFail($task->id);
            $this->authorize('start', $actor, $task);
            $now = now();
            $task->forceFill(['status' => 'production', 'started_at' => $task->started_at ?? $now, 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $task;
        });
    }

    /** Entregar: la vista previa de Shopify y qué cambió. Pasa a "En revisión" como la versión siguiente. */
    public function deliver(LandingTask $task, User $actor, string $previewUrl, string $notes): LandingTaskDelivery
    {
        return DB::transaction(function () use ($task, $actor, $previewUrl, $notes) {
            $task = LandingTask::lockForUpdate()->findOrFail($task->id);
            $this->authorize('deliver', $actor, $task);
            $errors = [];
            if (!LandingTaskRules::validUrl($previewUrl)) {
                $errors['preview_url'] = 'Pega el enlace de vista previa de Shopify (en el editor del tema › Compartir vista previa › Copiar enlace). Tiene que empezar con https://.';
            }
            if (mb_strlen(trim($notes)) < 10) {
                $errors['notes'] = 'Escribe qué cambiaste (por ejemplo, "fotos más livianas y el título habla del dolor de rodilla").';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $delivery = LandingTaskDelivery::create(['landing_task_id' => $task->id, 'version' => (int) $task->deliveries()->max('version') + 1,
                'editor_id' => $actor->id, 'preview_url' => trim($previewUrl), 'notes' => trim($notes)]);
            $now = now();
            $task->forceFill(['status' => 'review', 'status_at' => $now, 'status_by' => $actor->id, 'started_at' => $task->started_at ?? $now,
                'delivered_at' => $task->delivered_at ?? $now])->save();

            return $delivery;
        });
    }

    /** Revisar la entrega pendiente: aprobada pasa a "Para publicar"; devuelta, con un comentario, vuelve a producción. */
    public function review(LandingTask $task, User $actor, bool $approve, ?string $comment): LandingTask
    {
        return DB::transaction(function () use ($task, $actor, $approve, $comment) {
            $task = LandingTask::lockForUpdate()->findOrFail($task->id);
            $pending = $task->deliveries()->where('review', 'pending')->first();
            $this->authorize('review', $actor, $task, $pending !== null);
            $comment = trim((string) $comment);
            if (!$approve && mb_strlen($comment) < 10) {
                throw ValidationException::withMessages(['comment' => 'Escribe qué hay que cambiar con algo que se vea: la sección, el texto o la foto (por ejemplo, "el título de arriba no habla de la rodilla").']);
            }
            $now = now();
            $pending->forceFill(['review' => $approve ? 'approved' : 'returned', 'reviewed_by' => $actor->id, 'reviewed_at' => $now, 'comment' => $comment ?: null])->save();
            $task->forceFill(['status' => $approve ? 'ready' : 'production', 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $task;
        });
    }

    /**
     * "Publicada": la dirección de la landing ya publicada (la misma que se cambió o una nueva). Empieza el test con los
     * mismos anuncios; una URL nueva crea su landing.
     */
    public function publish(LandingTask $task, User $actor, ?string $url): LandingTask
    {
        return DB::transaction(function () use ($task, $actor, $url) {
            $task = LandingTask::lockForUpdate()->with('landing')->findOrFail($task->id);
            $this->authorize('publish', $actor, $task);
            $url = trim((string) ($url ?: $task->landing?->url));
            $normalized = LandingTaskRules::validUrl($url) ? LandingUrl::normalize($url) : null;
            if ($normalized === null) {
                throw ValidationException::withMessages(['url' => 'Pega la dirección de la landing publicada, como la abre un cliente (empieza con https://).']);
            }
            $now = now();
            $landing = Landing::firstOrCreate(['url_hash' => LandingUrl::hash($normalized)], ['url' => $normalized, 'product_id' => $task->product_id, 'first_seen_at' => $now]);
            $task->forceFill(['status' => 'testing', 'published_at' => $now, 'published_by' => $actor->id, 'published_url' => $url, 'test_landing_id' => $landing->id,
                'result' => null, 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $task;
        });
    }

    public function discard(LandingTask $task, User $actor, string $reason): LandingTask
    {
        return DB::transaction(function () use ($task, $actor, $reason) {
            $task = LandingTask::lockForUpdate()->findOrFail($task->id);
            $this->authorize('discard', $actor, $task);
            if (mb_strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['reason' => 'Escribe por qué se descarta.']);
            }
            $task->forceFill(['status' => 'discarded', 'discard_reason' => mb_substr(trim($reason), 0, 255), 'status_at' => now(), 'status_by' => $actor->id])->save();

            return $task;
        });
    }

    /**
     * Los encargos como los ve quien mira: todo el encargo, lo que puede hacer y el test; el editor, sin dinero.
     *
     * @param Collection<int, LandingTask> $tasks con self::WITH
     * @param array{id: int, role: ?string} $who
     */
    public static function present(Collection $tasks, array $who): array
    {
        $angles = AdAngle::whereIn('product_id', $tasks->pluck('product_id')->unique())->whereNotNull('name')->get(['product_id', 'code', 'name'])
            ->mapWithKeys(fn ($a) => ["{$a->product_id}:{$a->code}" => $a->name]);
        $admin = $who['role'] === LandingTaskRules::ADMIN;
        $today = now(config('app.timezone'))->toDateString();

        return self::sorted($tasks)->map(function (LandingTask $t) use ($angles, $admin, $who, $today) {
            $pending = $t->deliveries->firstWhere('review', 'pending');
            $state = ['status' => $t->status, 'editor_id' => $t->editor_id, 'pending' => $pending !== null];
            $last = $t->deliveries->first();
            $result = $t->result;
            if ($result && !$admin) {
                foreach (['before', 'after'] as $side) {
                    unset($result[$side]['totals']['spend'], $result[$side]['metrics']['cpa']);
                }
            }

            return [
                'id' => $t->id, 'code' => $t->code(), 'product_id' => $t->product_id, 'product' => $t->product?->showable_name ?: $t->product?->title,
                'status' => $t->status, 'status_label' => LandingTaskRules::STATUSES[$t->status] ?? $t->status, 'status_at' => $t->status_at?->toIso8601String(),
                'kind' => $t->kind, 'kind_label' => LandingTaskRules::KINDS[$t->kind] ?? $t->kind, 'source' => $t->source,
                'landing_id' => $t->landing_id, 'landing' => $t->landing?->label(), 'landing_url' => $t->landing?->url,
                'angle_code' => $t->angle_code, 'angle_name' => $angles["{$t->product_id}:{$t->angle_code}"] ?? null,
                'observed' => $t->observed, 'hypothesis' => $t->hypothesis, 'change' => $t->change, 'keep' => $t->keep, 'notes' => $t->notes,
                'due_date' => $t->due_date?->toDateString(), 'approved_at' => $t->approved_at?->toIso8601String(),
                'editor_id' => $t->editor_id, 'editor' => $t->editor ? self::name($t->editor) : null,
                'started_at' => $t->started_at?->toIso8601String(), 'delivered_at' => $t->delivered_at?->toIso8601String(),
                'published_at' => $t->published_at?->toIso8601String(), 'published_url' => $t->published_url,
                'test_landing' => $t->testLanding?->label(), 'tested_at' => $t->tested_at?->toIso8601String(), 'result' => $result,
                'discard_reason' => $t->discard_reason,
                'overdue' => $t->due_date !== null && $t->delivered_at === null && in_array($t->status, ['approved', 'production'], true) && $t->due_date->toDateString() < $today,
                'returned' => $t->status === 'production' && $last?->review === 'returned'
                    ? ['version' => $last->label(), 'comment' => $last->comment, 'by' => $last->reviewer ? self::name($last->reviewer) : null] : null,
                'deliveries' => $t->deliveries->map(fn (LandingTaskDelivery $d) => [
                    'id' => $d->id, 'version' => $d->label(), 'editor' => $d->editor ? self::name($d->editor) : null, 'delivered_at' => $d->created_at?->toIso8601String(),
                    'preview_url' => $d->preview_url, 'notes' => $d->notes, 'review' => $d->review, 'review_label' => self::REVIEWS[$d->review] ?? $d->review,
                    'reviewed_by' => $d->reviewer ? self::name($d->reviewer) : null, 'reviewed_at' => $d->reviewed_at?->toIso8601String(), 'comment' => $d->comment,
                ])->values()->all(),
                'can' => array_combine(LandingTaskRules::ACTIONS, array_map(fn ($a) => LandingTaskRules::can($a, $who, $state), LandingTaskRules::ACTIONS)),
            ];
        })->values()->all();
    }

    /** El estado de cada encargo para contar lo pendiente (LandingTaskRules::pending). */
    public static function states(Collection $tasks): array
    {
        return $tasks->map(fn (LandingTask $t) => ['status' => $t->status, 'editor_id' => $t->editor_id,
            'pending' => $t->deliveries->contains('review', 'pending')])->all();
    }

    /** Por estado (en el orden del camino), después el plazo. */
    public static function sorted(Collection $tasks): Collection
    {
        return $tasks->sortBy(fn (LandingTask $t) => [array_search($t->status, array_keys(LandingTaskRules::STATUSES), true),
            $t->due_date?->toDateString() ?? '9999', $t->id])->values();
    }

    /** Una landing con un encargo abierto (o en test) no recibe otro hasta que termine. */
    private function ensureFree(int $landingId): void
    {
        $open = LandingTask::where('landing_id', $landingId)->orWhere('test_landing_id', $landingId)->get()
            ->first(fn (LandingTask $t) => in_array($t->status, LandingTaskRules::OPEN, true));
        if ($open) {
            $name = $open->code() ?? 'un encargo propuesto';
            throw ValidationException::withMessages(['landing_id' => "Esa landing ya tiene {$name} (" . LandingTaskRules::STATUSES[$open->status]
                . '): espera a que termine para no cambiar dos cosas a la vez.']);
        }
    }

    private function validEditor(int $editorId, User $actor): void
    {
        if ($editorId !== (int) $actor->id && !in_array($editorId, array_column(self::editors(), 'id'), true)) {
            throw ValidationException::withMessages(['editor_id' => 'Elige un editor de landings activo, o hazlo tú.']);
        }
    }

    /** Si no puede, por qué: no es suyo (403) o el encargo está en otro estado (422). */
    private function authorize(string $action, User $actor, LandingTask $task, bool $pending = false): void
    {
        $who = EditorWork::who($actor);
        $state = ['status' => $task->status, 'editor_id' => $task->editor_id, 'pending' => $pending];
        if (LandingTaskRules::can($action, $who, $state)) {
            return;
        }
        $deny = fn (string $message) => new HttpResponseException(response()->json(['status' => false, 'message' => $message], 403));
        if (in_array($action, ['approve', 'assign', 'review', 'discard'], true) && $who['role'] !== LandingTaskRules::ADMIN) {
            throw $deny('Eso lo hace el dueño.');
        }
        if (!LandingTaskRules::canSee($who, $state)) {
            throw $deny('Este encargo no está asignado a ti.');
        }
        $verbs = ['approve' => 'aprobar', 'assign' => 'asignar', 'start' => 'empezar', 'deliver' => 'entregar', 'review' => 'revisar',
            'publish' => 'marcar como publicada', 'discard' => 'descartar'];
        throw ValidationException::withMessages(['status' => 'Este encargo está "' . (LandingTaskRules::STATUSES[$task->status] ?? $task->status)
            . '": ahora no se puede ' . $verbs[$action] . '.']);
    }

    private static function name(User $u): string
    {
        return trim($u->names . ' ' . ($u->surnames ?? ''));
    }
}
