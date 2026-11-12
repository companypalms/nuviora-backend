<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\Setting;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Products\ProductModes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `.88`: el pronóstico de los editores "con lo que pasó de verdad" (lo eligió el dueño): cuánto dura un batch bueno en cada
 * producto antes de cansarse (la historia de Meta), cuántos batches nuevos se lanzaron en las últimas 4 semanas y cuántos
 * entregan de verdad los editores por día. Sin historia, los ajustes, y lo dice. Las cuentas, en ForecastRules. Solo lee.
 */
class EditorForecast
{
    /** Con menos días de entregas que esto, lo que hace el equipo sale de los ajustes. */
    public const MIN_DAYS = 14;

    /** Cuántos días se miran hacia atrás (lanzados y entregas). */
    private const WINDOW = 28;

    /** Un batch "bueno": con al menos estas compras en su vida. */
    private const GOOD_PURCHASES = 3;

    public function __construct(private DecisionSettings $settings = new DecisionSettings())
    {
    }

    public function get(): array
    {
        $s = $this->settings->get();
        $today = now(config('app.timezone'))->toDateString();
        $in = fn (string $date) => min(ForecastRules::DAYS - 1, max(0, (int) round((strtotime($date) - strtotime($today)) / 86400)));
        // Lo ya pedido y sin entregar, por su fecha (lo atrasado, hoy). Lo que está en revisión ya se entregó
        $due = [];
        // .99: sin lo que está en espera (su producto está en pausa, en liquidación o abandonado)
        foreach (ProductModes::withoutHeld(CreativeBatch::whereIn('status', ['approved', 'production']))->get(['due_date']) as $b) {
            $d = $b->due_date ? $in($b->due_date->toDateString()) : 0;
            $due[$d] = ($due[$d] ?? 0) + 1;
        }
        // Y lo que hace falta pero no se pudo pedir (sin ficha o sin ciudad)
        $demand = Setting::get(QueueProposer::DEMAND);
        foreach (($demand['date'] ?? null) === $today ? ($demand['unfilled'] ?? []) : [] as $u) {
            $d = $in((string) $u['due']);
            $due[$d] = ($due[$d] ?? 0) + 1;
        }
        $use = Cache::remember("editor-forecast:use:{$today}", 3600, fn () => $this->consumption((float) $s['batch_life_days'], $today));
        $newRate = (float) $s['new_products_month'] * (float) $s['start_batches'] / 30;
        $cap = $this->capacity($s, $today);
        $reserve = (int) $s['reserve_due_days'];

        return ForecastRules::run(['due' => $due, 'rate' => $use['rate'], 'new_rate' => $newRate, 'capacity' => $cap['per_day'],
            'per_editor' => $cap['per_editor'], 'reserve_days' => $reserve, 'start' => $today]) + ['inputs' => [
                'ordered' => array_sum($due), 'replace' => round($use['rate'] * max(0, ForecastRules::DAYS - 1 - $reserve), 1),
                'new' => round($newRate * (ForecastRules::DAYS - 1), 1), 'products' => $use['products'], 'life' => $use['life'],
                'life_source' => $use['source'], 'new_products_month' => (int) $s['new_products_month'], 'start_batches' => (int) $s['start_batches'],
                'editors' => (int) $s['video_editors'], 'per_day' => round($cap['per_day'], 1), 'per_editor' => round($cap['per_editor'], 1),
                'capacity_source' => $cap['source'],
            ]];
    }

    /**
     * Lo que hay que reponer por día: de cada producto activo (en modo normal y fuera del testeo), lo mayor entre "2 batches
     * vivos que duran lo que dura uno bueno" y los batches nuevos que se lanzaron de verdad en las últimas 4 semanas.
     *
     * @return array{rate: float, products: int, life: float, source: string}
     */
    private function consumption(float $fallback, string $today): array
    {
        $paused = DB::table('product_ad_settings')->where('mode', '!=', 'normal')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        // .139: un producto Malo por su efectividad tampoco pide creativos mientras el dueño decide
        $paused = array_merge($paused, \App\Services\Meta\Products\ProductWorthBoard::stoppedIds());
        $testing = DB::table('product_profiles')->where('phase', 'testing')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $ids = array_values(array_diff(array_map('intval', MetaAnalyzer::products()), $paused, $testing));
        if ($ids === []) {
            return ['rate' => 0.0, 'products' => 0, 'life' => $fallback, 'source' => 'settings'];
        }
        // La vida de cada batch (ad set): del primer al último día con gasto, de los buenos que ya terminaron
        $ended = date('Y-m-d', strtotime("{$today} -3 days"));
        $rows = DB::table('meta_insights_daily as d')->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'ad')->where('d.spend', '>', 0)->where('d.date', '>=', date('Y-m-d', strtotime("{$today} -120 days")))
            ->whereIn('c.product_id', $ids)->whereNotNull('d.adset_meta_id')->groupBy('c.product_id', 'd.adset_meta_id')
            ->selectRaw('c.product_id pid, MIN(d.date) first_day, MAX(d.date) last_day, SUM(d.purchases) purchases')->get();
        $lives = [];
        foreach ($rows as $r) {
            $last = substr((string) $r->last_day, 0, 10);
            if ($last < $ended && (float) $r->purchases >= self::GOOD_PURCHASES) {
                $lives[(int) $r->pid][] = (strtotime($last) - strtotime(substr((string) $r->first_day, 0, 10))) / 86400 + 1;
            }
        }
        $all = $lives === [] ? [] : array_merge(...array_values($lives));
        $global = $all === [] ? null : self::median($all);
        // Los batches nuevos de las últimas 4 semanas (uno que salió en varias ciudades cuenta una vez)
        $launched = DB::table('meta_adsets as s')->join('meta_campaigns as c', 'c.meta_id', '=', 's.campaign_meta_id')
            ->whereIn('c.product_id', $ids)->where('s.created_time', '>=', date('Y-m-d', strtotime("{$today} -" . self::WINDOW . ' days')))
            ->get(['c.product_id', 's.batch_code', 's.name'])->groupBy('product_id')
            ->map(fn ($g) => $g->map(fn ($x) => $x->batch_code ?: self::identity((string) $x->name))->unique()->count());
        $rate = 0.0;
        $used = [];
        foreach ($ids as $pid) {
            $life = isset($lives[$pid]) ? self::median($lives[$pid]) : ($global ?? $fallback);
            $used[] = $life;
            $rate += max(UrgentList::MIN_LIVE / max(1.0, $life), (float) ($launched[$pid] ?? 0) / self::WINDOW);
        }

        return ['rate' => $rate, 'products' => count($ids), 'life' => round(self::median($used), 1), 'source' => $global !== null ? 'meta' : 'settings'];
    }

    /** Lo que entrega el equipo por día: lo de verdad (lo aprobado en las últimas 4 semanas) con 2 semanas de entregas; si no, los ajustes. */
    private function capacity(array $s, string $today): array
    {
        $first = DB::table('creative_batch_deliveries')->where('review', 'approved')->min('reviewed_at');
        $days = $first ? (int) floor((strtotime($today) - strtotime(substr((string) $first, 0, 10))) / 86400) + 1 : 0;
        if ($days >= self::MIN_DAYS) {
            $window = min(self::WINDOW, $days);
            $done = DB::table('creative_batch_deliveries')->where('review', 'approved')
                ->where('reviewed_at', '>=', date('Y-m-d', strtotime("{$today} -" . ($window - 1) . ' days')))->distinct()->count('creative_batch_id');
            $perDay = $done / $window;

            return ['per_day' => $perDay, 'per_editor' => $perDay / max(1, (int) $s['video_editors']), 'source' => 'real'];
        }
        $perEditor = (float) $s['batches_per_editor'] / (1 + (float) $s['corrections_pct'] / 100);

        return ['per_day' => (int) $s['video_editors'] * $perEditor, 'per_editor' => $perEditor, 'source' => 'settings'];
    }

    /** "B0102 FASP - Copia" y "B0102 FASP" son el mismo batch. */
    private static function identity(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s*-\s*cop(ia|y)\b.*$/iu', '', $name)));
    }

    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n === 0 ? 0.0 : (float) ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
