<?php

namespace App\Services\Calendar\Readers;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchDelivery;
use App\Models\LandingTask;
use App\Models\LandingTaskDelivery;
use App\Models\ProductAdSetting;
use App\Models\ProductProfile;
use App\Models\User;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Landings\LandingTaskRules;
use App\Services\Meta\Products\ProductProfiles;
use App\Services\Meta\Products\TestingPhase;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Metrics\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `.67`: los datos de Meta Ads y de los editores que miran los recordatorios del calendario (Rules\MetaReminderRules).
 * Solo lee: lo que está por aprobar, los encargos abiertos con su última entrega, la banca, los testeos por decidir, los
 * productos activos sin ficha, lo que hizo cada editor en una semana y los batches que terminaron su test.
 */
class MetaReminderFacts
{
    /** Lo de ahora, para los recordatorios de hoy. */
    public function now(CarbonImmutable $now): array
    {
        $names = $this->productNames();
        $leaders = $this->editors([EditorRules::LEADER]);

        return [
            'now' => $now->format('Y-m-d H:i:s'),
            'proposed' => [
                'videos' => $this->countByProduct(CreativeBatch::where('status', 'proposed')->pluck('product_id'), $names),
                'landings' => $this->countByProduct(LandingTask::where('status', 'proposed')->pluck('product_id'), $names),
            ],
            'testing' => $this->testing($names),
            'no_profile' => $this->noProfile($names),
            'no_reference' => $this->noReference($names),
            'open' => array_merge($this->openBatches($names), $this->openLandings($names)),
            'leaders' => $leaders,
            'landing_editor' => $this->editors([LandingTaskRules::ROLE]) !== [],
            'bank' => CreativeBatch::where('status', 'bank')->whereNotNull('number')->orderBy('status_at')->get(['id', 'number', 'product_id', 'status_at', 'testing_round'])
                ->map(fn (CreativeBatch $b) => ['code' => (string) $b->code(), 'product' => $names[$b->product_id] ?? null,
                    'since' => $b->status_at?->setTimezone(config('app.timezone'))->toDateString(), 'round' => $b->testing_round ? (int) $b->testing_round : null])
                ->values()->all(),
        ];
    }

    /**
     * Lo que hizo cada editor (de video y de landings) en la semana que empieza ese lunes, como en Panel › Equipo: los
     * encargos que entregó por primera vez y si fue a tiempo, sus entregas revisadas y devueltas, y el resultado de lo que
     * terminó su test.
     *
     * @return list<array{name: string, landing: bool, delivered: int, on_time: int, reviewed: int, returned: int, won: int, lost: int, tie: int}>
     */
    /**
     * `.71`: la rutina de hoy (del último análisis del día): lo urgente sin tildar, cuántos creativos hay en cada decisión
     * y cuántos se le mandaron al editor líder o esperan cupo.
     *
     * @return array{ready: bool, urgent: int, calls: array<string, int>, sent: int, waiting: int}
     */
    public function routine(CarbonImmutable $now): array
    {
        $a = \App\Models\MetaAnalysis::where('date', $now->toDateString())->orderByDesc('version')->first();
        if ($a === null) {
            return ['ready' => false, 'urgent' => 0, 'calls' => [], 'sent' => 0, 'waiting' => 0];
        }
        $marks = $a->marks ?? [];
        $urgent = count(array_filter($a->urgent ?? [], fn ($u) => ($marks[$u['key']]['status'] ?? null) !== 'done'));
        $calls = [];
        foreach (array_keys(\App\Services\Meta\Analysis\CreativeCalls::CALLS) as $k) {
            $calls[\App\Services\Meta\Analysis\CreativeCalls::CALLS[$k]['label']] = 0;
        }
        foreach ($a->facts ?? [] as $p) {
            foreach ($p['creatives'] ?? [] as $cr) {
                // .80: por su clave ("PARAR EN ARAGUA" cuenta como "PARAR EN UNA CIUDAD")
                if (isset(\App\Services\Meta\Analysis\CreativeCalls::CALLS[$cr['call']['key'] ?? ''])) {
                    $calls[\App\Services\Meta\Analysis\CreativeCalls::CALLS[$cr['call']['key']]['label']]++;
                }
            }
        }
        // .78: un turno por producto; "le llegó" = el producto ya tiene su encargo en camino o en la banca
        $turns = \App\Services\Meta\Analysis\AnalysisSender::turns($a->facts ?? [], app(\App\Services\Meta\Decisions\DecisionSettings::class)->get());
        $sent = count(array_filter($turns, fn ($t) => $t['full']));

        return ['ready' => true, 'urgent' => $urgent, 'calls' => array_filter($calls), 'sent' => $sent, 'waiting' => count($turns) - $sent];
    }

    public function editorWeek(string $monday): array
    {
        [$from, $to] = $this->bounds($monday, 6);
        $rows = [];
        $bump = function (?int $id, bool $landing, string $key, int $n = 1) use (&$rows) {
            if ($id === null) {
                return;
            }
            $rows["{$landing}|{$id}"] ??= ['id' => $id, 'landing' => $landing, 'delivered' => 0, 'on_time' => 0, 'reviewed' => 0, 'returned' => 0, 'won' => 0, 'lost' => 0, 'tie' => 0];
            $rows["{$landing}|{$id}"][$key] += $n;
        };

        foreach (CreativeBatch::whereNotNull('editor_id')->whereBetween('delivered_at', [$from, $to])->get(['editor_id', 'delivered_at', 'due_date']) as $b) {
            $bump((int) $b->editor_id, false, 'delivered');
            $bump((int) $b->editor_id, false, 'on_time', EditorRules::onTime($this->local($b->delivered_at), $b->due_date?->toDateString()) ? 1 : 0);
        }
        foreach (CreativeBatchDelivery::whereIn('review', ['approved', 'returned'])->whereNotNull('editor_id')->whereBetween('reviewed_at', [$from, $to])->get(['editor_id', 'review']) as $d) {
            $bump((int) $d->editor_id, false, 'reviewed');
            $bump((int) $d->editor_id, false, 'returned', $d->review === 'returned' ? 1 : 0);
        }
        foreach (CreativeBatch::where('status', 'tested')->whereNotNull('editor_id')->whereBetween('tested_at', [$from, $to])->get(['editor_id', 'status', 'result']) as $b) {
            $bump((int) $b->editor_id, false, ['Ganó' => 'won', 'Perdió' => 'lost'][EditorRules::result($b->status, array_values($b->result['verdicts'] ?? []))] ?? 'tie');
        }
        foreach (LandingTask::whereNotNull('editor_id')->whereBetween('delivered_at', [$from, $to])->get(['editor_id', 'delivered_at', 'due_date']) as $t) {
            $bump((int) $t->editor_id, true, 'delivered');
            $bump((int) $t->editor_id, true, 'on_time', LandingTaskRules::onTime($this->local($t->delivered_at), $t->due_date?->toDateString()) ? 1 : 0);
        }
        foreach (LandingTaskDelivery::whereIn('review', ['approved', 'returned'])->whereNotNull('editor_id')->whereBetween('reviewed_at', [$from, $to])->get(['editor_id', 'review']) as $d) {
            $bump((int) $d->editor_id, true, 'reviewed');
            $bump((int) $d->editor_id, true, 'returned', $d->review === 'returned' ? 1 : 0);
        }
        foreach (LandingTask::where('status', 'tested')->whereNotNull('editor_id')->whereBetween('tested_at', [$from, $to])->get(['editor_id', 'result']) as $t) {
            $bump((int) $t->editor_id, true, ['won' => 'won', 'lost' => 'lost'][$t->result['state'] ?? ''] ?? 'tie');
        }

        $users = User::whereIn('id', array_unique(array_column($rows, 'id')) ?: [0])->get(['id', 'names', 'surnames'])->keyBy('id');
        $out = array_map(fn (array $r) => ['name' => PersonName::of($users->get($r['id'])?->names, $users->get($r['id'])?->surnames) ?: "Editor {$r['id']}"]
            + array_diff_key($r, ['id' => true]), array_values($rows));
        usort($out, fn ($a, $b) => [$a['landing'], $a['name']] <=> [$b['landing'], $b['name']]);

        return $out;
    }

    /**
     * Los batches que terminaron su test en los 7 días anteriores a ese día, con su resultado y lo que aprendimos.
     *
     * @return list<array{code: string, product: ?string, result: string, learning: ?string}>
     */
    public function learnings(string $due): array
    {
        $start = CarbonImmutable::parse($due)->subDays(7)->toDateString();
        [$from, $to] = $this->bounds($start, 6);
        $names = $this->productNames();

        return CreativeBatch::where('status', 'tested')->whereBetween('tested_at', [$from, $to])->orderBy('tested_at')
            ->get(['id', 'number', 'product_id', 'status', 'result', 'learning'])
            ->map(fn (CreativeBatch $b) => ['code' => (string) $b->code(), 'product' => $names[$b->product_id] ?? null,
                'result' => EditorRules::result($b->status, array_values($b->result['verdicts'] ?? [])), 'learning' => $b->learning])
            ->values()->all();
    }

    /** Los encargos de video abiertos (aprobados, en producción o en revisión), con su última entrega y la que espera. */
    private function openBatches(array $names): array
    {
        return CreativeBatch::with(['editor:id,names,surnames', 'deliveries:id,creative_batch_id,version,editor_id,review,reviewed_at,created_at', 'deliveries.editor:id,names,surnames'])
            ->whereIn('status', ['approved', 'production', 'review'])->whereNotNull('number')->orderBy('number')->get()
            ->map(fn (CreativeBatch $b) => $this->open((string) $b->code(), $names[$b->product_id] ?? null, false, $b, $b->deliveries))
            ->values()->all();
    }

    /** Los encargos de landing abiertos (aprobados, en producción, en revisión o para publicar). */
    private function openLandings(array $names): array
    {
        return LandingTask::with(['editor:id,names,surnames', 'deliveries:id,landing_task_id,version,editor_id,review,reviewed_at,created_at', 'deliveries.editor:id,names,surnames'])
            ->whereIn('status', ['approved', 'production', 'review', 'ready'])->whereNotNull('number')->orderBy('number')->get()
            ->map(fn (LandingTask $t) => $this->open(LandingTaskRules::code((int) $t->number), $names[$t->product_id] ?? null, true, $t, $t->deliveries))
            ->values()->all();
    }

    private function open(string $code, ?string $product, bool $landing, CreativeBatch|LandingTask $x, $deliveries): array
    {
        $last = $deliveries->sortByDesc('version')->first();
        $pending = $deliveries->firstWhere('review', 'pending');

        return [
            'code' => $code, 'product' => $product, 'landing' => $landing, 'status' => $x->status,
            'editor_id' => $x->editor_id !== null ? (int) $x->editor_id : null, 'editor' => $x->editor ? PersonName::of($x->editor->names, $x->editor->surnames) : null,
            'due_date' => $x->due_date?->toDateString(), 'approved_at' => $this->local($x->approved_at), 'status_at' => $this->local($x->status_at),
            'last_review' => $last?->review, 'last_reviewed_at' => $this->local($last?->reviewed_at),
            'pending_by' => $pending?->editor_id !== null ? (int) $pending->editor_id : null,
            'pending_editor' => $pending?->editor ? PersonName::of($pending->editor->names, $pending->editor->surnames) : null,
            'pending_at' => $this->local($pending?->created_at),
        ];
    }

    /** Los productos en testeo con algo por decidir: la ronda terminó sin ganador, o la ficha no tiene ángulos. */
    private function testing(array $names): array
    {
        $out = [];
        foreach (ProductProfile::where('phase', 'testing')->orderBy('phase_at')->pluck('product_id') as $pid) {
            $st = TestingPhase::round((int) $pid);
            if ($st['no_angles'] || ($st['round'] > 0 && $st['closed'] !== null)) {
                $out[] = ['product' => $names[$pid] ?? "Producto {$pid}", 'round' => $st['round'], 'can_next' => $st['next'] !== [], 'no_angles' => $st['no_angles']];
            }
        }

        return $out;
    }

    /**
     * Los productos a los que la IA no les propone encargos porque les falta la ficha (como QueueProposer): los que tienen
     * campañas activas o gasto en los últimos 7 días, en modo normal, y los que están en testeo.
     */
    private function noProfile(array $names): array
    {
        $stopped = ProductAdSetting::where('mode', '!=', 'normal')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $testing = ProductProfile::where('phase', 'testing')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $ids = array_values(array_unique(array_merge(array_diff(MetaAnalyzer::products(), $stopped), $testing)));
        $missing = array_diff($ids, ProductProfiles::completeIds($ids));
        $out = array_map(fn ($id) => $names[$id] ?? "Producto {$id}", $missing);
        sort($out);

        return $out;
    }

    /**
     * `.79`: los productos activos sin CPA objetivo (nuevos sin referencia en el nombre de la campaña ni en Productos y
     * objetivos, o sin la compra de inventario con su costo): el análisis no los puede juzgar.
     *
     * @return list<array{product: string, no_cost: bool}>
     */
    private function noReference(array $names): array
    {
        $stopped = ProductAdSetting::where('mode', '!=', 'normal')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $report = new \App\Services\Meta\MetaAdsReport([], 'last_3d');
        $out = [];
        foreach (array_diff(MetaAnalyzer::products(), $stopped) as $id) {
            $t = $report->targetFor((int) $id, null);
            if ($t['target_cpa'] === null) {
                $out[] = ['product' => $names[$id] ?? "Producto {$id}", 'no_cost' => (bool) ($t['financial']['no_cost'] ?? false)];
            }
        }
        usort($out, fn ($a, $b) => $a['product'] <=> $b['product']);

        return $out;
    }

    /** Los usuarios activos con esos roles (id => nombre). */
    private function editors(array $roles): array
    {
        return User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->whereIn('description', $roles))->orderBy('names')->get(['id', 'names', 'surnames'])
            ->mapWithKeys(fn (User $u) => [(int) $u->id => PersonName::of($u->names, $u->surnames)])->all();
    }

    /** @return array<string, int> nombre del producto => cuántos */
    private function countByProduct($productIds, array $names): array
    {
        $out = [];
        foreach ($productIds as $pid) {
            $name = $names[$pid] ?? "Producto {$pid}";
            $out[$name] = ($out[$name] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    private function productNames(): array
    {
        return DB::table('products')->get(['id', 'title', 'showable_name'])->mapWithKeys(fn ($p) => [(int) $p->id => $p->showable_name ?: $p->title])->all();
    }

    /** Desde el comienzo de un día hasta el final de otro N días después, en la hora del negocio. */
    private function bounds(string $day, int $days): array
    {
        $from = CarbonImmutable::parse($day, config('app.timezone'))->startOfDay();

        return [$from, $from->addDays($days)->endOfDay()];
    }

    /** Una fecha y hora en la hora del negocio ("Y-m-d H:i:s"). */
    private function local($at): ?string
    {
        return $at?->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
