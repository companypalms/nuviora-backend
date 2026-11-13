<?php

namespace App\Services\Meta\Changes;

use App\Constants\OrderStatus;
use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Models\MetaChange;
use App\Models\Status;
use App\Services\Finance\FinancialCpa;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `.131` "Historial de cambios", con la base de datos (las reglas, en ChangeRules). Solo lee Meta (lo que ya trajo la
 * sincronización) y guarda en `meta_changes`:
 * - cada cambio que se ve en las fotos de Meta desde que empezaron (31/10 en producción), sin que el dueño anote nada;
 * - la misión del análisis que lo pidió (la de ese día o la del anterior), o ninguna ("lo hiciste por tu cuenta");
 * - la C y el BE de ese día (los del análisis), la hipótesis, los números de antes y de después, y si se cumplió.
 * Se arma a las 00:25 (antes del análisis) y al abrir la pantalla si pasaron 30 minutos. Un cambio ya medido se vuelve a
 * medir 14 días (Meta corrige sus números unos días); después queda fijo. La nota del dueño nunca se toca.
 * `.140`: también los cambios de precio de cada producto, desde sus pedidos (PriceChangeRules): no son de una campaña.
 */
class MetaChanges
{
    /** Cuántos días hacia atrás se buscan cambios en cada vuelta. */
    public const SCAN_DAYS = 60;

    /** Cuántos días se sigue midiendo un cambio ya medido. */
    public const SETTLE_DAYS = 14;

    /** Cada cuánto se vuelve a armar al abrir la pantalla. */
    public const STALE_MINUTES = 30;

    public const CACHE_KEY = 'meta_changes_refreshed_at';

    /** Arma el historial si hace falta; nunca rompe la pantalla. @return ?string el error, si falló */
    public function refreshIfStale(bool $force = false): ?string
    {
        $at = Cache::get(self::CACHE_KEY);
        if (!$force && $at && now()->diffInMinutes(Carbon::parse($at)) < self::STALE_MINUTES) {
            return null;
        }
        // .140: los cambios de precio aparte: si algo falla ahí, lo de Meta igual se arma
        try {
            $this->refreshPrices();
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $this->refresh();

            return null;
        } catch (\Throwable $e) {
            report($e);

            return $e->getMessage();
        }
    }

    /**
     * `.140`: los cambios de precio de los productos con campañas (de sus pedidos), su hipótesis y si se ganó más dinero: los
     * días de antes contra los de después (price_watch_days), con los pedidos ya entregados. Un cambio medido queda fijo
     * pasados 14 días.
     *
     * @return array{found: int, saved: int}
     */
    public function refreshPrices(?string $today = null): array
    {
        $tz = config('app.timezone');
        $today ??= now($tz)->toDateString();
        $days = (int) (new DecisionSettings())->get()['price_watch_days'];
        $since = ChangeRules::addDays($today, -self::SCAN_DAYS);
        $products = DB::table('meta_campaigns')->whereNotNull('product_id')->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        if ($products === []) {
            return ['found' => 0, 'saved' => 0];
        }
        // El precio más repetido de cada día, sin los upsells (las promociones de un día no lo mueven)
        $modal = [];
        foreach (DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')->whereIn('op.product_id', $products)
            ->where(fn ($q) => $q->whereNull('op.is_upsell')->orWhere('op.is_upsell', false))->where('op.price', '>', 0)
            ->where('o.created_at', '>=', Carbon::parse($since, $tz)->startOfDay())
            ->selectRaw('op.product_id pid, DATE(o.created_at) d, ROUND(op.price, 2) p, COUNT(*) n')->groupBy('pid', 'd', 'p')->get()->groupBy(fn ($r) => "{$r->pid}|{$r->d}") as $key => $rows) {
            if ($rows->sum('n') >= PriceChangeRules::MIN_ORDERS_DAY) {
                [$pid, $d] = explode('|', $key);
                $modal[(int) $pid][substr($d, 0, 10)] = (float) $rows->sortByDesc('n')->first()->p;
            }
        }
        $found = [];
        foreach ($modal as $pid => $byDay) {
            foreach (PriceChangeRules::detect($byDay) as $ch) {
                $found[] = $ch + ['product_id' => $pid, 'fingerprint' => "price:{$pid}:{$ch['date']}"];
            }
        }
        if ($found === []) {
            return ['found' => 0, 'saved' => 0];
        }
        $matureTo = FinancialCpa::window(now($tz))[1]->toDateString();
        $versions = app(FinancialCpa::class)->latest();
        $analyses = $this->analyses(ChangeRules::addDays(min(array_column($found, 'date')), -1));
        $existing = MetaChange::whereIn('fingerprint', array_column($found, 'fingerprint'))->get()->keyBy('fingerprint');
        $saved = 0;
        foreach ($found as $ch) {
            $row = $existing[$ch['fingerprint']] ?? null;
            if ($row && $row->result !== 'measuring' && $row->date->toDateString() < ChangeRules::addDays($today, -self::SETTLE_DAYS)) {
                continue; // ya quedó fijo
            }
            $from = ChangeRules::addDays($ch['date'], -$days);
            $to = ChangeRules::addDays($ch['date'], $days - 1);
            [$orders, $meta] = $this->priceDays($ch['product_id'], $from, $to);
            $before = PriceChangeRules::numbers($orders, $meta, $from, ChangeRules::addDays($ch['date'], -1));
            $lastAfter = min($to, ChangeRules::addDays($today, -1));
            $after = $lastAfter >= $ch['date'] ? PriceChangeRules::numbers($orders, $meta, $ch['date'], $lastAfter) : null;
            $h = PriceChangeRules::hypothesis($ch['from'], $ch['to'], $before, $days);
            $r = PriceChangeRules::measure($ch['from'], $ch['to'], $ch['date'], $before, $after, $matureTo, $today, $days, $this->bigChanges($ch['product_id'], $from, $to));
            $v = $versions["{$ch['product_id']}|"] ?? null;
            MetaChange::updateOrCreate(['fingerprint' => $ch['fingerprint']], ['kind' => 'price', 'product_id' => $ch['product_id'], 'campaign_meta_id' => null,
                'adset_meta_id' => null, 'city' => null, 'date' => $ch['date'], 'changed_at' => "{$ch['date']} 00:00:00", 'detail' => ['from' => $ch['from'], 'to' => $ch['to']],
                'mission' => $this->mission($analyses, ['date' => $ch['date'], 'kind' => 'price', 'campaign' => ''], $ch['product_id'], null),
                'target_c' => $v?->target, 'target_be' => $v?->break_even, 'hypothesis' => $h['hypothesis'], 'criterion' => $h['criterion'],
                'before' => $before, 'after' => $after, 'result' => $r['result'], 'result_text' => $r['text'],
                'measured_at' => $r['result'] === 'measuring' ? null : ($row?->measured_at ?? now())]);
            $saved++;
        }

        return ['found' => count($found), 'saved' => $saved];
    }

    /**
     * `.140`: los pedidos de un producto por el día en que entraron (sin cambios ni devoluciones, ni los que esperaron stock)
     * y lo de Meta por día.
     *
     * @return array{0: array<string, array{created: int, delivered: int, revenue: float, variable: float}>, 1: array<string, array{spend: float, purchases: float}>}
     */
    private function priceDays(int $productId, string $from, string $to): array
    {
        $sinStock = (int) Status::where('description', OrderStatus::SIN_STOCK)->value('id');
        $rows = DB::table('order_financials')->where('kind', 'normal')->where('product_id', $productId)->whereBetween('created_date', [$from, $to])
            ->get(['order_id', 'created_date', 'outcome', 'status_id', 'revenue_main', 'revenue_upsell', 'revenue_refunded', 'cogs_main', 'cogs_upsell',
                'logistics_cost', 'delivery_cost', 'commission_deliverer', 'commission_seller', 'commission_upsell', 'commission_leader', 'commission_other']);
        $long = array_flip(FinancialCpa::stockoutOrderIds($rows->pluck('order_id')->map(fn ($v) => (int) $v)->all(), $sinStock));
        $orders = [];
        foreach ($rows as $r) {
            if ((int) $r->status_id === $sinStock || isset($long[(int) $r->order_id])) {
                continue;
            }
            $d = substr((string) $r->created_date, 0, 10);
            $orders[$d] ??= ['created' => 0, 'delivered' => 0, 'revenue' => 0.0, 'variable' => 0.0];
            $orders[$d]['created']++;
            $orders[$d]['delivered'] += $r->outcome === 'delivered' ? 1 : 0;
            $orders[$d]['revenue'] += (float) $r->revenue_main + (float) $r->revenue_upsell - (float) $r->revenue_refunded;
            $orders[$d]['variable'] += (float) $r->cogs_main + (float) $r->cogs_upsell + (float) $r->logistics_cost + (float) $r->delivery_cost
                + (float) $r->commission_deliverer + (float) $r->commission_seller + (float) $r->commission_upsell + (float) $r->commission_leader + (float) $r->commission_other;
        }
        $meta = [];
        foreach (DB::table('meta_insights_daily as d')->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')->where('d.level', 'campaign')
            ->where('c.product_id', $productId)->whereBetween('d.date', [$from, $to])->groupBy('d.date')
            ->selectRaw('d.date, SUM(d.spend) s, SUM(d.purchases) p')->get() as $r) {
            $meta[substr((string) $r->date, 0, 10)] = ['spend' => (float) $r->s, 'purchases' => (float) ($r->p ?? 0)];
        }

        return [$orders, $meta];
    }

    /** `.140`: los otros cambios grandes de un producto en esos días ("subiste el presupuesto en Caracas (10/11)"). @return list<string> */
    private function bigChanges(int $productId, string $from, string $to): array
    {
        return MetaChange::where('product_id', $productId)->whereIn('kind', ChangeRules::BIG)->whereBetween('date', [$from, $to])->orderBy('date')->get(['kind', 'city', 'date'])
            ->map(fn (MetaChange $c) => ChangeLessons::VERBS[$c->kind] . ($c->city ? " en {$c->city}" : '') . ' (' . ChangeRules::short($c->date->toDateString()) . ')')
            ->unique()->values()->all();
    }

    /** @return array{found: int, saved: int} */
    public function refresh(?string $today = null): array
    {
        $tz = config('app.timezone');
        $today ??= now($tz)->toDateString();
        $first = DB::table('meta_entity_snapshots')->min('recorded_at');
        $campaigns = DB::table('meta_campaigns as c')->leftJoin('cities as y', 'y.id', '=', 'c.city_id')->whereNotNull('c.product_id')
            ->get(['c.meta_id', 'c.product_id', 'y.name as city'])->keyBy(fn ($r) => (string) $r->meta_id);
        if ($first === null || $campaigns->isEmpty()) {
            Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addDay());

            return ['found' => 0, 'saved' => 0];
        }
        $since = max(Carbon::parse($first)->setTimezone($tz)->toDateString(), ChangeRules::addDays($today, -self::SCAN_DAYS));
        $local = fn ($v) => $v !== null ? Carbon::parse($v)->setTimezone($tz)->toDateTimeString() : null;
        $ids = $campaigns->keys()->map(fn ($v) => (string) $v)->all();

        $adsets = DB::table('meta_adsets')->whereIn('campaign_meta_id', $ids)->get(['meta_id', 'campaign_meta_id', 'name', 'batch_code', 'created_time']);
        $adsetInfo = $adsets->mapWithKeys(fn ($s) => [(string) $s->meta_id => ['campaign' => (string) $s->campaign_meta_id, 'name' => (string) $s->name,
            'created' => $local($s->created_time), 'batch' => $s->batch_code]])->all();
        $ads = DB::table('meta_ads')->whereIn('campaign_meta_id', $ids)->get(['meta_id', 'campaign_meta_id', 'adset_meta_id', 'name']);
        $adInfo = $ads->mapWithKeys(fn ($a) => [(string) $a->meta_id => ['campaign' => (string) $a->campaign_meta_id,
            'adset' => $a->adset_meta_id !== null ? (string) $a->adset_meta_id : null, 'name' => (string) $a->name]])->all();
        // Las fotos desde un mes antes, para tener la anterior a cada cambio
        $snaps = function (string $level, array $metaIds) use ($since, $local): array {
            $out = [];
            foreach (array_chunk($metaIds, 1000) as $chunk) {
                foreach (DB::table('meta_entity_snapshots')->where('level', $level)->whereIn('meta_id', $chunk)
                    ->where('recorded_at', '>=', ChangeRules::addDays($since, -30))->orderBy('recorded_at')->orderBy('id')
                    ->get(['meta_id', 'status', 'daily_budget', 'min_spend', 'recorded_at']) as $r) {
                    $out[(string) $r->meta_id][] = ['status' => $r->status, 'daily_budget' => $r->daily_budget !== null ? (float) $r->daily_budget : null,
                        'min_spend' => $r->min_spend !== null ? (float) $r->min_spend : null, 'at' => $local($r->recorded_at)];
                }
            }

            return $out;
        };
        $found = ChangeRules::detect($snaps('campaign', $ids), $snaps('adset', array_keys($adsetInfo)), $snaps('ad', array_keys($adInfo)), $adsetInfo, $adInfo, $since);
        if (!$found) {
            Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addDay());

            return ['found' => 0, 'saved' => 0];
        }

        $daily = [];
        foreach (DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $ids)->where('date', '>=', ChangeRules::addDays($since, -ChangeRules::DAYS))
            ->get(['meta_id', 'date', 'spend', 'purchases']) as $r) {
            $daily[(string) $r->meta_id][substr((string) $r->date, 0, 10)] = ['spend' => (float) $r->spend, 'purchases' => (float) ($r->purchases ?? 0)];
        }
        $analyses = $this->analyses(ChangeRules::addDays($since, -1));
        $batches = $this->batches($found, $adsetInfo, $campaigns->all());
        $existing = MetaChange::whereIn('fingerprint', array_column($found, 'fingerprint'))->get()->keyBy('fingerprint');

        $saved = 0;
        foreach ($found as $ch) {
            $row = $existing[$ch['fingerprint']] ?? null;
            if ($row && $row->result !== 'measuring' && $row->date->toDateString() < ChangeRules::addDays($today, -self::SETTLE_DAYS)) {
                continue; // ya quedó fijo
            }
            $camp = $campaigns[$ch['campaign']];
            [$c, $be] = $this->targets($analyses, $ch['campaign'], $ch['date']);
            $fields = ['kind' => $ch['kind'], 'product_id' => (int) $camp->product_id, 'campaign_meta_id' => $ch['campaign'], 'adset_meta_id' => $ch['adset'],
                'city' => $camp->city, 'date' => $ch['date'], 'changed_at' => $ch['at'], 'detail' => $ch['detail'], 'target_c' => $c, 'target_be' => $be,
                'mission' => $this->mission($analyses, $ch, (int) $camp->product_id, $camp->city)];
            if ($ch['kind'] === 'new_batch') {
                $code = $adsetInfo[$ch['adset']]['batch'] ?? null;
                $bd = $code ? ($batches["{$camp->product_id}:{$code}"] ?? null) : null;
                $b = ChangeRules::batch($bd, (string) $ch['detail']['adset'], $camp->city, $ch['date'], $today);
                // .132: lo que quería mejorar, para "Lo que sabemos" (cuántas variaciones lo mejoraron)
                $fields += ['hypothesis' => $b['hypothesis'], 'criterion' => $b['criterion'], 'before' => null, 'after' => ($bd['metric'] ?? null) ? ['metric' => $bd['metric']] : null,
                    'result' => $b['result'], 'result_text' => $b['text']];
            } else {
                $d = $daily[$ch['campaign']] ?? [];
                $before = ChangeRules::numbers($d, ChangeRules::addDays($ch['date'], -ChangeRules::DAYS), ChangeRules::addDays($ch['date'], -1), $be);
                $h = ChangeRules::hypothesis($ch['kind'], $ch['detail'], $before, $c, $be);
                $r = ChangeRules::measure($ch['kind'], $ch['date'], $before, $d, $c, $be, ChangeRules::nextBig($ch, $found), $today);
                $fields += ['hypothesis' => $h['hypothesis'], 'criterion' => $h['criterion'], 'before' => $before, 'after' => $r['after'], 'result' => $r['result'], 'result_text' => $r['text']];
            }
            $fields['measured_at'] = in_array($fields['result'], ['measuring'], true) ? null : ($row?->measured_at ?? now());
            MetaChange::updateOrCreate(['fingerprint' => $ch['fingerprint']], $fields);
            $saved++;
        }
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addDay());

        return ['found' => count($found), 'saved' => $saved];
    }

    /**
     * Lo que ve el dueño: el marcador, los cambios (los últimos 300, con los filtros) y las opciones de los filtros.
     *
     * @param array{product_id?: ?int, city?: ?string, kind?: ?string, result?: ?string} $f
     */
    public function page(array $f): array
    {
        $scope = fn ($q) => $q->when($f['product_id'] ?? null, fn ($w, $v) => $w->where('product_id', $v))->when($f['city'] ?? null, fn ($w, $v) => $w->where('city', $v));
        $rows = $scope(MetaChange::query()->with('product:id,title,showable_name'))
            ->when($f['kind'] ?? null, fn ($w, $v) => $w->where('kind', $v))->when($f['result'] ?? null, fn ($w, $v) => $w->where('result', $v))
            ->orderByDesc('changed_at')->orderByDesc('id')->limit(300)->get();
        $names = DB::table('meta_campaigns')->whereIn('meta_id', $rows->pluck('campaign_meta_id')->unique()->all())->pluck('name', 'meta_id');

        $all = $scope(MetaChange::query())->get(['kind', 'result']);
        $count = fn ($rs, string $r) => $rs->where('result', $r)->count();
        $byKind = [];
        foreach (ChangeRules::KINDS as $k => $label) {
            $rs = $all->where('kind', $k);
            if ($rs->isNotEmpty()) {
                $byKind[] = ['kind' => $k, 'label' => $label, 'met' => $count($rs, 'met'), 'not_met' => $count($rs, 'not_met'), 'measuring' => $count($rs, 'measuring'),
                    'unknown' => $count($rs, 'unknown') + $count($rs, 'not_measured'), 'total' => $rs->count()];
            }
        }

        return [
            'score' => ['met' => $count($all, 'met'), 'not_met' => $count($all, 'not_met'), 'measuring' => $count($all, 'measuring'),
                'unknown' => $count($all, 'unknown'), 'not_measured' => $count($all, 'not_measured'), 'total' => $all->count(), 'by_kind' => $byKind],
            'changes' => $rows->map(fn (MetaChange $c) => [
                'id' => $c->id, 'kind' => $c->kind, 'kind_label' => ChangeRules::KINDS[$c->kind] ?? $c->kind, 'big' => in_array($c->kind, ChangeRules::BIG, true),
                'date' => $c->date->toDateString(), 'changed_at' => $c->changed_at?->toIso8601String(),
                'product_id' => $c->product_id, 'product' => $c->product ? ($c->product->showable_name ?: $c->product->title) : null, 'city' => $c->city, 'campaign' => $names[$c->campaign_meta_id] ?? null,
                'what' => ChangeRules::what($c->kind, $c->detail ?? []), 'mission' => $c->mission,
                'hypothesis' => $c->hypothesis, 'criterion' => $c->criterion, 'c' => $c->target_c, 'be' => $c->target_be,
                'before' => $c->before, 'after' => $c->kind === 'new_batch' ? null : $c->after, 'result' => $c->result, 'result_label' => ChangeRules::RESULTS[$c->result] ?? $c->result,
                'result_text' => $c->result_text, 'note' => $c->note, 'metric' => $c->kind === 'new_batch' ? ($c->after['metric'] ?? null) : null,
            ])->values()->all(),
            'products' => MetaChange::query()->join('products as p', 'p.id', '=', 'meta_changes.product_id')->distinct()
                ->get(['p.id', 'p.title', 'p.showable_name'])->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->showable_name ?: $p->title])->sortBy('name')->values()->all(),
            'cities' => MetaChange::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city')->values()->all(),
            'kinds' => ChangeRules::KINDS, 'results' => ChangeRules::RESULTS,
            'refreshed_at' => Cache::get(self::CACHE_KEY),
        ];
    }

    /** `.132`: lo medido de los últimos 60 días (se cumplió o no), para el análisis de la mañana (ChangeLessons::build). */
    public function lessons(?string $today = null): array
    {
        $today ??= now(config('app.timezone'))->toDateString();
        $rows = MetaChange::whereIn('result', ['met', 'not_met'])->where('date', '>=', ChangeRules::addDays($today, -ChangeLessons::DAYS))
            ->orderByDesc('changed_at')->orderByDesc('id')->get()
            ->map(fn (MetaChange $c) => ['kind' => $c->kind, 'campaign' => $c->campaign_meta_id, 'city' => $c->city, 'product_id' => (int) $c->product_id,
                'date' => $c->date->toDateString(), 'what' => ChangeRules::what($c->kind, $c->detail ?? []), 'result' => $c->result, 'result_text' => $c->result_text])
            ->all();

        return ChangeLessons::build($rows);
    }

    /**
     * `.132`: para "Lo que sabemos" de un producto: cuántas veces se cumplió cada tipo de cambio y, de sus batches nuevos que
     * buscaban mejorar algo frente al original (hook rate, hold rate o CTR único), cuántos lo mejoraron.
     *
     * @return array{kinds: list<array{kind: string, label: string, met: int, not_met: int}>, metrics: list<array{key: string, label: string, better: int, total: int}>}
     */
    public static function forProduct(int $productId): array
    {
        $rows = MetaChange::where('product_id', $productId)->whereIn('result', ['met', 'not_met'])->get(['kind', 'result', 'after']);
        $kinds = [];
        foreach (ChangeRules::KINDS as $k => $label) {
            $rs = $rows->where('kind', $k);
            if ($rs->isNotEmpty()) {
                $kinds[] = ['kind' => $k, 'label' => $label, 'met' => $rs->where('result', 'met')->count(), 'not_met' => $rs->where('result', 'not_met')->count()];
            }
        }
        $metrics = [];
        foreach ($rows->where('kind', 'new_batch') as $r) {
            $m = $r->after['metric'] ?? null;
            if (!$m || ($m['mine'] ?? null) === null || ($m['original'] ?? null) === null) {
                continue;
            }
            $metrics[$m['key']] ??= ['key' => $m['key'], 'label' => ChangeRules::METRIC_LABELS[$m['key']] ?? $m['key'], 'better' => 0, 'total' => 0];
            $metrics[$m['key']]['total']++;
            if ($m['mine'] > $m['original']) {
                $metrics[$m['key']]['better']++;
            }
        }

        return ['kinds' => $kinds, 'metrics' => array_values($metrics)];
    }

    /** La última versión del análisis de cada día desde $since: su C y BE por campaña, sus misiones y lo que marcó el dueño. */
    private function analyses(string $since): array
    {
        $ids = MetaAnalysis::where('date', '>=', $since)->groupBy('date')->selectRaw('MAX(id) as id')->pluck('id');
        $out = [];
        foreach (MetaAnalysis::whereIn('id', $ids)->orderBy('date')->get(['id', 'date', 'facts', 'urgent', 'marks']) as $a) {
            $targets = [];
            foreach ($a->facts ?? [] as $p) {
                foreach ($p['cities'] ?? [] as $city) {
                    // los análisis de antes de la .82 no traen los números de cada ciudad: no cuentan
                    $n = $city['numbers'] ?? [];
                    if (isset($city['campaign_meta_id']) && (isset($n['c']) || isset($n['be']))) {
                        $targets[(string) $city['campaign_meta_id']] = [isset($n['c']) ? (float) $n['c'] : null, isset($n['be']) ? (float) $n['be'] : null];
                    }
                }
            }
            $out[$a->date->toDateString()] = ['targets' => $targets, 'urgent' => $a->urgent ?? [], 'marks' => $a->marks ?? []];
        }

        return $out;
    }

    /** La C y el BE de una campaña ese día: del análisis de ese día, si no del anterior más cercano, si no del siguiente. */
    private function targets(array $analyses, string $campaign, string $date): array
    {
        $before = array_filter($analyses, fn ($a, $d) => $d <= $date && isset($a['targets'][$campaign]), ARRAY_FILTER_USE_BOTH);
        if ($before) {
            return $before[max(array_keys($before))]['targets'][$campaign];
        }
        $after = array_filter($analyses, fn ($a) => isset($a['targets'][$campaign]));

        return $after ? $after[min(array_keys($after))]['targets'][$campaign] : [null, null];
    }

    /** La misión que pidió el cambio: del análisis de ese día o del anterior, de esa campaña (o de su ciudad, o del producto). */
    private function mission(array $analyses, array $ch, int $productId, ?string $city): ?array
    {
        foreach ([$ch['date'], ChangeRules::addDays($ch['date'], -1)] as $d) {
            $a = $analyses[$d] ?? null;
            if (!$a) {
                continue;
            }
            $items = array_values(array_filter($a['urgent'], fn ($i) => in_array($i['kind'] ?? null, ChangeRules::MISSIONS[$ch['kind']], true) && (int) ($i['product_id'] ?? 0) === $productId));
            $pick = collect($items)->first(fn ($i) => str_contains((string) ($i['key'] ?? ''), ":{$ch['campaign']}:"))
                ?? collect($items)->first(fn ($i) => $city !== null && ($i['city'] ?? null) === $city)
                ?? collect($items)->first(fn ($i) => ($i['city'] ?? null) === null);
            if ($pick) {
                return ['date' => $d, 'kind' => $pick['kind'], 'title' => $pick['title'] ?? ($pick['text'] ?? null), 'done' => $a['marks'][$pick['key']]['status'] ?? null];
            }
        }

        return null;
    }

    /**
     * Los encargos de los batches nuevos: su hipótesis (la fija de una variación de las reglas, o la del encargo), su veredicto
     * por ciudad y lo que quería mejorar frente al original (hook rate, hold rate o CTR único), con los números de toda su vida.
     *
     * @return array<string, array> "producto:B0123" => lo que pide ChangeRules::batch
     */
    private function batches(array $found, array $adsetInfo, array $campaigns): array
    {
        $codes = [];
        foreach ($found as $ch) {
            if ($ch['kind'] === 'new_batch' && ($code = $adsetInfo[$ch['adset']]['batch'] ?? null) && preg_match('/^B(\d+)$/', $code, $m)) {
                $codes[(int) $campaigns[$ch['campaign']]->product_id][(int) $m[1]] = $code;
            }
        }
        $out = [];
        foreach ($codes as $productId => $numbers) {
            foreach (CreativeBatch::with(['pieces', 'reference'])->where('product_id', $productId)->whereIn('number', array_keys($numbers))->get() as $b) {
                // .128: en una variación de las reglas, la hipótesis fija (la que leen el líder y el editor); si no, la del encargo
                $task = EditorWork::task($b, null);
                $fixed = $b->source === 'rules' && $b->kind === 'iteration';
                $test = $b->pieces->pluck('test')->filter()->first();
                $key = ChangeRules::PIECE_METRIC[$test] ?? null;
                $out["{$productId}:{$b->code()}"] = ['code' => (string) $b->code(), 'status' => $b->status, 'verdicts' => array_values($b->result['verdicts'] ?? []),
                    'hypothesis' => $fixed ? ($task['hypothesis'] ?? null) : ($b->hypothesis ?: ($task['hypothesis'] ?? null)),
                    'metric' => $key && $b->iteratesId() ? ['key' => $key, 'adsets' => array_keys(array_filter($adsetInfo, fn ($s) => $s['batch'] === $b->code()
                        && (int) $campaigns[$s['campaign']]->product_id === $productId)), 'reference' => (int) $b->reference_creative_id] : null];
            }
        }
        // Lo que quería mejorar: los anuncios del batch contra los del creativo original, en toda su vida
        foreach ($out as $k => $b) {
            if (!$b['metric']) {
                continue;
            }
            $sum = fn ($q) => MetaMetrics::compute(MetaMetrics::sum($q->get(['spend', 'impressions', 'reach', 'unique_link_clicks', 'video_3s_plays', 'thruplays'])
                ->map(fn ($r) => (array) $r)->all()))[$b['metric']['key']] ?? null;
            $mine = $b['metric']['adsets'] ? $sum(DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('adset_meta_id', $b['metric']['adsets'])) : null;
            $refAds = DB::table('meta_ads')->where('meta_creative_ref_id', $b['metric']['reference'])->pluck('meta_id')->map(fn ($v) => (string) $v)->all();
            $original = $refAds ? $sum(DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('ad_meta_id', $refAds)) : null;
            $out[$k]['metric'] = ['key' => $b['metric']['key'], 'mine' => $mine !== null ? round((float) $mine, 1) : null, 'original' => $original !== null ? round((float) $original, 1) : null];
        }

        return $out;
    }
}
