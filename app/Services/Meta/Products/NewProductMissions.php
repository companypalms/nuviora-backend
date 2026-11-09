<?php

namespace App\Services\Meta\Products;

use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Models\NewProductMission;
use App\Models\Product;
use App\Models\ProductProfile;
use App\Models\Setting;
use App\Services\Meta\Queue\DemandRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `.118`: la misión de lanzar un producto nuevo, con la base de datos. Las reglas, en NewProductRules. Se revisa cada día con
 * el análisis; las etapas de la estrategia, los creativos y la publicación se marcan solas con lo que hace el sistema.
 */
class NewProductMissions
{
    /** Lo que falta para abrirla, de la última revisión (fecha y lista). */
    public const CHECK = 'meta_new_product_check';

    /**
     * La revisión de cada día: si se cumplen las condiciones, se abre la misión. Si ya hay una abierta, sigue.
     *
     * @param array<int, array> $decisions MetaDecisions::compute
     */
    public function evaluate(array $decisions, string $today, ?string $forecast): void
    {
        if (($open = $this->open()) !== null) {
            $this->sync($open);

            return;
        }
        $missing = NewProductRules::missing($this->conditions($decisions, $today, $forecast));
        Setting::set(self::CHECK, ['date' => $today, 'missing' => $missing]);
        if ($missing === []) {
            NewProductMission::create(['status' => 'open', 'opened_on' => $today]);
        }
    }

    /** Lo que muestra la tarjeta del análisis. @return array{state: string, ...} */
    public function view(): array
    {
        $today = now(config('app.timezone'))->toDateString();
        $m = $this->open();
        if ($m !== null) {
            $this->sync($m);
        }
        if ($m !== null && $m->status === 'open') {
            $stages = $this->stages($m, $today);

            return ['state' => 'open', 'id' => $m->id, 'product' => $this->name($m->product_id), 'day' => (int) $m->opened_on->diffInDays($today) + 1, 'days' => (int) array_sum(array_column(NewProductRules::STAGES, 'days')),
                'stages' => $stages, 'current' => array_values(array_filter($stages, fn ($s) => !$s['done']))[0]['key'] ?? null];
        }
        $done = NewProductMission::where('status', 'done')->orderByDesc('id')->first();
        if ($done && $done->publish_done_at?->setTimezone(config('app.timezone'))->toDateString() === $today) {
            return ['state' => 'done', 'product' => $this->name($done->product_id), 'next' => date('Y-m-d', strtotime($done->opened_on->toDateString() . ' +' . NewProductRules::GAP_DAYS . ' days'))];
        }
        $check = (array) Setting::get(self::CHECK, []);

        return ['state' => 'waiting', 'missing' => array_values($check['missing'] ?? [])];
    }

    /** La misión de "vas tarde" para la lista del día (solo una), si le toca. */
    public function lateItem(string $today): ?array
    {
        $m = $this->open();
        if ($m === null) {
            return null;
        }
        $this->sync($m);
        if ($m->status !== 'open' || ($s = NewProductRules::firstLate($this->stages($m, $today))) === null) {
            return null;
        }

        return NewProductRules::lateItem($m->id, $m->product_id, $this->name($m->product_id), $s);
    }

    /** "Hecho" en una etapa que marca el dueño. */
    public function complete(NewProductMission $m, string $stage): void
    {
        if (!(NewProductRules::STAGES[$stage]['manual'] ?? false)) {
            throw ValidationException::withMessages(['stage' => 'Esta etapa se marca sola cuando la termines.']);
        }
        if ($m->status !== 'open') {
            throw ValidationException::withMessages(['stage' => 'Esta misión ya terminó.']);
        }
        $m->forceFill(["{$stage}_done_at" => now()])->save();
        $this->markToday($m, $stage);
    }

    /** Desde la lista del día ("Hecho" en "vas tarde"). No se puede descartar: escalar es una obligación. */
    public function fromList(int $id, string $stage, ?string $status): void
    {
        if ($status === 'disagree') {
            throw ValidationException::withMessages(['status' => 'Esta misión no se puede descartar.']);
        }
        $m = NewProductMission::findOrFail($id);
        if ($status === 'done') {
            $this->complete($m, $stage);
        } elseif ($status === null && (NewProductRules::STAGES[$stage]['manual'] ?? false)) {
            $m->forceFill(["{$stage}_done_at" => null])->save();
        }
    }

    /** Las etapas que se marcan solas: qué producto es, su ficha, sus batches y si ya salió en Meta. */
    public function sync(NewProductMission $m): void
    {
        $changes = [];
        $productId = $m->product_id ?? $this->newProductSince($m);
        if ($productId !== null && $m->product_id === null) {
            $changes['product_id'] = $productId;
            $changes['search_done_at'] = $m->search_done_at ?? now();
        }
        if ($productId !== null) {
            $status = $this->batchStatuses($productId);
            $ficha = $m->strategy_done_at === null && ProductProfiles::completeIds([$productId]) !== [];
            if ($ficha) {
                $changes['strategy_done_at'] = now();
            }
            if ($m->creatives_done_at === null && $status !== [] && !array_diff($status, ['bank', 'testing', 'tested'])) {
                $changes['creatives_done_at'] = now();
            }
            if (array_intersect($status, ['testing', 'tested'])) {
                $changes += ['publish_done_at' => now(), 'status' => 'done'];
                // Si ya se publicó, lo anterior se hizo aunque no se haya marcado
                foreach (['search', 'strategy', 'creatives', 'landing'] as $s) {
                    if ($m->{"{$s}_done_at"} === null && !isset($changes["{$s}_done_at"])) {
                        $changes["{$s}_done_at"] = now();
                    }
                }
            }
        }
        if ($changes !== []) {
            $m->forceFill($changes)->save();
            foreach (array_keys(NewProductRules::STAGES) as $s) {
                if (isset($changes["{$s}_done_at"])) {
                    $this->markToday($m, $s);
                }
            }
        }
    }

    /** `.118`: ¿todavía falta la landing de este producto? Para avisarlo antes de lanzar el testeo. */
    public static function landingPending(int $productId): bool
    {
        return NewProductMission::where('status', 'open')->where('product_id', $productId)->whereNull('landing_done_at')->exists();
    }

    private function open(): ?NewProductMission
    {
        return NewProductMission::where('status', 'open')->orderByDesc('id')->first();
    }

    private function stages(NewProductMission $m, string $today): array
    {
        $done = [];
        foreach (array_keys(NewProductRules::STAGES) as $s) {
            $done[$s] = $m->{"{$s}_done_at"} !== null;
        }

        return NewProductRules::stages($m->opened_on->toDateString(), $done, $today);
    }

    /** Lo que se mira para abrirla. */
    private function conditions(array $decisions, string $today, ?string $forecast): array
    {
        $testing = ProductProfile::where('phase', 'testing')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $active = array_filter($decisions, fn ($d, $pid) => ($d['product']['mode'] ?? 'normal') === 'normal' && ($d['plan']['urgency']['level'] ?? 5) < 5 && !in_array((int) $pid, $testing, true), ARRAY_FILTER_USE_BOTH);
        $ready = CreativeBatch::whereIn('product_id', array_keys($active) ?: [0])->where('status', 'bank')->whereNull('testing_round')->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $noReady = $needToday = [];
        foreach ($active as $pid => $d) {
            if (!in_array((int) $pid, $ready, true)) {
                $noReady[] = (string) $d['product']['name'];
            }
            $t = DemandRules::today($d);
            foreach (array_keys($t['today'] > 0 ? $t['cities'] : []) as $city) {
                $needToday[] = "{$d['product']['name']} en {$city}";
            }
        }
        $lastMission = NewProductMission::max('opened_on');
        $lastProduct = ProductProfile::where('testing_round', '>', 0)->max('created_at');
        $last = max(substr((string) $lastMission, 0, 10), substr((string) $lastProduct, 0, 10)) ?: null;

        return ['no_ready' => $noReady, 'need_today' => $needToday, 'last' => $last, 'today' => $today, 'forecast' => $forecast];
    }

    /** El producto nuevo que entró a testeo después de abrir la misión: sin campañas en Meta todavía, o sus batches ya salieron. */
    private function newProductSince(NewProductMission $m): ?int
    {
        $ids = ProductProfile::where('phase', 'testing')->where('testing_round', '>', 0)->where('phase_at', '>=', $m->created_at)->orderBy('phase_at')->pluck('product_id')->all();
        foreach ($ids as $id) {
            $before = DB::table('meta_campaigns')->where('product_id', $id)->where('created_at', '<', $m->created_at)->exists();
            if (!$before) {
                return (int) $id;
            }
        }

        return null;
    }

    /** En qué va cada batch de su ronda de testeo. */
    private function batchStatuses(int $productId): array
    {
        $ids = array_column(TestingPhase::round($productId)['slots'], 'batch_id');
        if ($ids === [] || in_array(null, $ids, true)) {
            return [];
        }

        return CreativeBatch::whereIn('id', $ids)->pluck('status')->all();
    }

    private function name(?int $productId): ?string
    {
        $p = $productId ? Product::find($productId, ['id', 'title', 'showable_name']) : null;

        return $p ? ($p->showable_name ?: $p->title) : null;
    }

    /** Si "vas tarde" está en la lista de hoy, queda resuelta al terminar la etapa. */
    private function markToday(NewProductMission $m, string $stage): void
    {
        $a = MetaAnalysis::where('date', now(config('app.timezone'))->toDateString())->orderByDesc('version')->first();
        $key = "new_product:{$m->id}:{$stage}";
        if ($a !== null && in_array($key, array_column($a->urgent ?? [], 'key'), true) && !isset(($a->marks ?? [])[$key])) {
            $a->forceFill(['marks' => ($a->marks ?? []) + [$key => ['status' => 'done', 'note' => null, 'by' => null, 'at' => now()->toIso8601String()]]])->save();
        }
    }
}
