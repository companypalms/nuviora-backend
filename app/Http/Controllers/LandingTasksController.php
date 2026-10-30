<?php

namespace App\Http\Controllers;

use App\Models\Landing;
use App\Models\LandingTask;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Landings\LandingReport;
use App\Services\Meta\Landings\LandingTaskRules;
use App\Services\Meta\Landings\LandingTaskWork;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los encargos de landing (Etapa 5, paso 6b):
 * - el dueño, en Meta Ads › Landings › Encargos (/meta/landing-tasks): crea, aprueba, asigna, revisa y descarta; y como
 *   por ahora las landings las hace él, también empieza, entrega y publica;
 * - el "Editor de landings", en "Mis encargos de landing" (/landing-editor): los suyos, empezar, entregar y publicar.
 *   RestrictLimitedRoles no lo deja llegar a ninguna otra parte de la API. Sin dinero.
 * Los permisos de cada encargo son los de LandingTaskRules. Nada de esto toca Meta ni Shopify.
 */
class LandingTasksController extends Controller
{
    /** Los terminados (testeados o descartados) que se siguen mostrando, en días. */
    private const RECENT_DAYS = 30;

    public function index(Request $request)
    {
        $user = $request->user();
        $who = EditorWork::who($user);
        $tasks = $this->visible($who)->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->get('product_id')))
            ->with(LandingTaskWork::WITH)->get();
        $s = (new DecisionSettings())->get();

        return response()->json([
            'me' => ['id' => $user->id, 'name' => trim($user->names . ' ' . ($user->surnames ?? ''))],
            'tasks' => LandingTaskWork::present($tasks, $who),
            'editors' => LandingTaskWork::editors(),
            'pending' => LandingTaskRules::pending($who, LandingTaskWork::states($tasks)),
            'statuses' => LandingTaskRules::STATUSES, 'kinds' => LandingTaskRules::KINDS,
            'products' => (new LandingReport())->products(),
            'landings' => Landing::whereNotNull('product_id')->orderBy('id')->get()->map(fn (Landing $l) => ['id' => $l->id, 'product_id' => $l->product_id,
                'label' => $l->label(), 'url' => $l->url, 'angle_code' => $l->angle_code])->values()->all(),
            'test' => array_intersect_key($s, array_flip(['landing_test_before_days', 'landing_test_min_days', 'landing_test_max_days',
                'landing_test_min_purchases', 'landing_test_min_visits', 'landing_test_win_pct', 'landing_due_days'])),
        ]);
    }

    /** "Mis encargos de landing": los del editor que mira. */
    public function mine(Request $request)
    {
        $user = $request->user();
        $who = EditorWork::who($user);
        $tasks = $this->visible($who)->with(LandingTaskWork::WITH)->get();

        return response()->json([
            'me' => ['id' => $user->id, 'name' => trim($user->names . ' ' . ($user->surnames ?? ''))],
            'tasks' => LandingTaskWork::present($tasks, $who),
            'pending' => LandingTaskRules::pending($who, LandingTaskWork::states($tasks)),
        ]);
    }

    /** El número del menú (se pide cada pocos minutos). */
    public function summary(Request $request)
    {
        $who = EditorWork::who($request->user());
        $tasks = $this->visible($who)->whereIn('status', ['approved', 'production', 'review', 'ready'])->with('deliveries:id,landing_task_id,review,version')->get();

        return response()->json(['pending' => LandingTaskRules::pending($who, LandingTaskWork::states($tasks))]);
    }

    public function store(Request $request, LandingTaskWork $work)
    {
        $data = $request->validate($this->rules(true) + ['approve' => 'sometimes|boolean', 'editor_id' => 'nullable|integer'], $this->messages());
        $this->checkLanding($data);
        $task = $work->create($data, $request->user(), (bool) ($data['approve'] ?? false), isset($data['editor_id']) ? (int) $data['editor_id'] : null);

        return $this->one($request, $task, 201);
    }

    /** Corregir lo que dice el encargo mientras no se entregó. */
    public function update(Request $request, LandingTask $task)
    {
        if (!in_array($task->status, ['proposed', 'approved', 'production'], true)) {
            throw ValidationException::withMessages(['status' => 'Este encargo ya se entregó: lo que dice ya no se cambia.']);
        }
        $data = $request->validate($this->rules(false), $this->messages());
        foreach (['angle_code', 'observed', 'hypothesis', 'change', 'keep', 'notes'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = trim((string) $data[$k]) === '' ? null : trim((string) $data[$k]);
            }
        }
        $task->fill($data)->save();

        return $this->one($request, $task);
    }

    public function approve(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $data = $request->validate(['editor_id' => 'nullable|integer']);
        $work->approve($task, $request->user(), isset($data['editor_id']) ? (int) $data['editor_id'] : null);

        return $this->one($request, $task);
    }

    public function assign(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $data = $request->validate(['editor_id' => 'required|integer'], ['editor_id.required' => 'Elige quién lo hace.']);
        $work->assign($task, (int) $data['editor_id'], $request->user());

        return $this->one($request, $task);
    }

    public function start(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $work->start($task, $request->user());

        return $this->one($request, $task);
    }

    public function deliver(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $data = $request->validate(['preview_url' => 'required|string|max:1000', 'notes' => 'required|string|max:3000'], [
            'preview_url.required' => 'Pega el enlace de vista previa de Shopify.', 'notes.required' => 'Escribe qué cambiaste.',
        ]);
        $work->deliver($task, $request->user(), $data['preview_url'], $data['notes']);

        return $this->one($request, $task);
    }

    public function review(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $data = $request->validate(['approve' => 'required|boolean', 'comment' => 'nullable|string|max:2000']);
        $work->review($task, $request->user(), (bool) $data['approve'], $data['comment'] ?? null);

        return $this->one($request, $task);
    }

    public function publish(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $data = $request->validate(['url' => 'nullable|string|max:1000']);
        $work->publish($task, $request->user(), $data['url'] ?? null);

        return $this->one($request, $task);
    }

    public function discard(Request $request, LandingTask $task, LandingTaskWork $work)
    {
        $data = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Escribe por qué se descarta.']);
        $work->discard($task, $request->user(), $data['reason']);

        return $this->one($request, $task);
    }

    /** Lo que ve cada uno: el dueño, todo; el editor, lo suyo aprobado. Lo terminado, solo lo reciente. */
    private function visible(array $who): Builder
    {
        return LandingTask::query()
            ->when($who['role'] !== LandingTaskRules::ADMIN, fn ($q) => $q->where('editor_id', $who['id'])->where('status', '!=', 'proposed'))
            ->where(fn ($q) => $q->whereIn('status', LandingTaskRules::OPEN)->orWhere('status_at', '>=', now()->subDays(self::RECENT_DAYS)));
    }

    private function one(Request $request, LandingTask $task, int $code = 200)
    {
        $task = LandingTask::with(LandingTaskWork::WITH)->findOrFail($task->id);

        return response()->json(['task' => LandingTaskWork::present(collect([$task]), EditorWork::who($request->user()))[0]], $code);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'product_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:products,id'],
            'kind' => [$req, Rule::in(array_keys(LandingTaskRules::KINDS))],
            'landing_id' => [$creating ? 'nullable' : 'prohibited', 'integer', 'exists:landings,id'],
            'angle_code' => 'sometimes|nullable|string|max:12',
            'observed' => 'sometimes|nullable|string|max:3000',
            'hypothesis' => 'sometimes|nullable|string|max:2000',
            'change' => [$req, 'string', 'max:3000'],
            'keep' => 'sometimes|nullable|string|max:2000',
            'notes' => 'sometimes|nullable|string|max:2000',
        ];
    }

    private function messages(): array
    {
        return ['change.required' => 'Escribe qué hay que cambiar en la landing.', 'kind.in' => 'Elige el tipo de encargo.'];
    }

    /** La landing tiene que ser del producto (o todavía sin producto); "landing nueva" va sin landing. */
    private function checkLanding(array $data): void
    {
        if (!empty($data['landing_id'])) {
            $productId = Landing::whereKey($data['landing_id'])->value('product_id');
            if ($productId !== null && (int) $productId !== (int) $data['product_id']) {
                throw ValidationException::withMessages(['landing_id' => 'Esa landing es de otro producto.']);
            }
        } elseif ($data['kind'] !== 'new') {
            throw ValidationException::withMessages(['landing_id' => 'Elige la landing que hay que cambiar (o el tipo "Landing nueva").']);
        }
    }
}
