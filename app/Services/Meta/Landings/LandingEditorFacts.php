<?php

namespace App\Services\Meta\Landings;

use App\Models\LandingTask;
use App\Models\LandingTaskDelivery;
use App\Models\User;
use App\Services\Metrics\PersonName;
use App\Services\Metrics\Period;

/**
 * Lo que se lee de la base para los indicadores de los editores de landings (Etapa 5, paso 6c): sus encargos con el
 * resultado del test (los mismos anuncios antes y después) y sus entregas revisadas. Las cuentas las hace
 * LandingEditorKpis (PHP puro). Por ahora las landings las hace el dueño: él también sale, como "Dueño".
 */
class LandingEditorFacts
{
    /**
     * @return array{
     *   tasks: list<array{id: int, code: string, editor_id: int, product: ?string, landing: ?string, status: string, due_date: ?string, delivered_at: ?string,
     *     published_at: ?string, tested_at: ?string, result: ?array, versions: int, returned: int}>,
     *   reviews: list<array{editor_id: int, review: string, reviewed_at: string}>,
     *   owners: array<int, array{name: string, group: string}>
     * }
     */
    public function read(Period $period, ?array $editorIds = null): array
    {
        $tasks = LandingTask::with(['product:id,title,showable_name', 'landing', 'testLanding', 'deliveries:id,landing_task_id,review'])
            ->whereNotNull('editor_id')->whereNotNull('number')->where('status', '!=', 'proposed')
            ->when($editorIds !== null, fn ($q) => $q->whereIn('editor_id', $editorIds))->get();
        $reviews = LandingTaskDelivery::whereIn('review', ['approved', 'returned'])->whereNotNull('editor_id')
            ->whereBetween('reviewed_at', [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')])
            ->when($editorIds !== null, fn ($q) => $q->whereIn('editor_id', $editorIds))->get(['editor_id', 'review', 'reviewed_at'])
            ->map(fn ($d) => ['editor_id' => (int) $d->editor_id, 'review' => $d->review, 'reviewed_at' => $d->reviewed_at->format('Y-m-d H:i:s')])->values()->all();
        // El dueño (u otro Admin) que hizo encargos también se mide
        $owners = User::whereIn('id', $tasks->pluck('editor_id')->unique())->whereHas('role', fn ($q) => $q->where('description', LandingTaskRules::ADMIN))
            ->get(['id', 'names', 'surnames'])->mapWithKeys(fn (User $u) => [(int) $u->id => ['name' => PersonName::of($u->names, $u->surnames), 'group' => 'Dueño']])->all();

        return [
            'tasks' => $tasks->map(fn (LandingTask $t) => [
                'id' => $t->id, 'code' => (string) $t->code(), 'editor_id' => (int) $t->editor_id, 'product' => $t->product?->showable_name ?: $t->product?->title,
                'landing' => ($t->testLanding ?? $t->landing)?->label(), 'status' => $t->status, 'due_date' => $t->due_date?->toDateString(),
                'delivered_at' => $t->delivered_at?->format('Y-m-d H:i:s'), 'published_at' => $t->published_at?->format('Y-m-d H:i:s'),
                'tested_at' => $t->tested_at?->format('Y-m-d H:i:s'), 'result' => $t->result,
                'versions' => $t->deliveries->count(), 'returned' => $t->deliveries->where('review', 'returned')->count(),
            ])->values()->all(),
            'reviews' => $reviews,
            'owners' => $owners,
        ];
    }
}
