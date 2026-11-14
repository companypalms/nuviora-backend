<?php

namespace App\Console\Commands;

use App\Constants\OrderStatus;
use App\Models\MetaAnalysis;
use App\Models\MetaChange;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Status;
use App\Services\Finance\CohortReport;
use App\Services\Finance\FinancialCpa;
use App\Services\Finance\OrderEconomics;
use App\Services\Finance\ProfitAndLoss;
use App\Services\Meta\Review\AiReview;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `.143` (dueño, 2026-11-14: "quiero saber si ese criterio realmente es bueno comparado a cómo eran nuestros resultados
 * antes"): la radiografía de la IA de Meta Ads, sin IA (lo que decidía el dueño a mano) contra con IA (desde el 2/11, "Tu
 * día en Meta Ads"). Todo por día, para comparar períodos de distinto largo. SOLO LEE: corre dentro de una transacción que
 * se deshace al final, así que aunque algo intentara escribir, no queda nada. Sin datos de clientes.
 */
class MetaRadiografia extends Command
{
    protected $signature = 'meta:radiografia
        {--antes=2026-09-10:2026-10-30 : sin IA, desde:hasta}
        {--despues=2026-11-02: : con IA, desde:hasta (sin hasta = ayer)}
        {--seccion=todo : negocio, semanas, productos, ciudades, mismos, quemado, ganadores, escalado, cambios, misiones (separadas por coma) o todo}';

    protected $description = 'Radiografía antes / después de la IA de Meta Ads: ganancia, efectividad, CPA, dinero quemado, ganadores, subidas y la nota de cada misión (solo lectura)';

    private const SECTIONS = ['negocio', 'semanas', 'productos', 'ciudades', 'mismos', 'quemado', 'ganadores', 'escalado', 'cambios', 'misiones'];

    /** Las misiones de una campaña (su clave es producto:campaña:tipo) y lo que se ve en Meta cuando se hacen (`.131`). */
    private const CAMPAIGN_KINDS = ['scale', 'decrease', 'step_down', 'undo_raise', 'pause_city', 'not_sold', 'product_off', 'relaunch', 'hold_prune',
        'intervene', 'few_batches', 'min_spend', 'stock_out'];

    private array $periods = [];

    private array $campaigns = [];

    private array $yard = [];

    private array $names = [];

    private array $effectiveness = [];

    public function handle(ProfitAndLoss $pnl, CohortReport $cohorts, FinancialCpa $cpa): int
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $lag = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        [$aFrom, $aTo] = $this->range((string) $this->option('antes'), $today);
        [$dFrom, $dTo] = $this->range((string) $this->option('despues'), $today);
        $mature = min($dTo, $today->subDays($lag + 1)->toDateString());
        $this->periods = ['antes' => [$aFrom, $aTo], 'despues' => [$dFrom, $dTo], 'maduro' => [$dFrom, $mature]];
        $wanted = $this->option('seccion') === 'todo' ? self::SECTIONS : array_intersect(self::SECTIONS, array_map('trim', explode(',', (string) $this->option('seccion'))));

        DB::beginTransaction();
        try {
            $this->campaigns = DB::table('meta_campaigns')->whereNotNull('product_id')->whereNotNull('city_id')->get(['meta_id', 'product_id', 'city_id', 'name'])
                ->mapWithKeys(fn ($c) => [(string) $c->meta_id => ['cell' => "{$c->product_id}|{$c->city_id}", 'product_id' => (int) $c->product_id,
                    'city_id' => (int) $c->city_id, 'name' => (string) $c->name]])->all();
            $this->yard = $this->yard($cpa);
            $this->names = $this->names();

            $this->line("RADIOGRAFIA IA META v1 · hoy {$today->toDateString()} · sin IA {$aFrom}..{$aTo} (" . $this->days('antes') . " d) · con IA {$dFrom}..{$dTo} ("
                . $this->days('despues') . " d; pedidos maduros hasta {$mature}: " . $this->days('maduro') . ' d)');
            $this->line('Vara única: BE y C de hoy de cada producto y ciudad (' . count(array_filter($this->yard, fn ($y) => $y['be'])) . ' con BE). Todo por día.');
            foreach ($wanted as $s) {
                $this->line('');
                $this->line("[{$s}]");
                try {
                    match ($s) {
                        'negocio' => $this->business($pnl),
                        'semanas' => $this->weeks($cohorts, $today),
                        'productos' => $this->groups($pnl, 'product'),
                        'ciudades' => $this->groups($pnl, 'city'),
                        'mismos' => $this->same($pnl),
                        'quemado' => $this->burned($today),
                        'ganadores' => $this->winners($today),
                        'escalado' => $this->scaling(),
                        'cambios' => $this->changes(),
                        'misiones' => $this->missions(),
                    };
                } catch (\Throwable $e) {
                    $this->line('error en esta sección: ' . mb_substr($e->getMessage(), 0, 300));
                }
            }
            $this->line('');
            $this->line('FIN');
        } finally {
            DB::rollBack(); // solo lee: nada de lo que pasó aquí queda guardado
        }

        return self::SUCCESS;
    }

    // ---- Secciones -------------------------------------------------------------------------------------------------------

    /** Lo de toda la empresa: la ganancia con los pedidos maduros; lo de Meta, con todos los días. */
    private function business(ProfitAndLoss $pnl): void
    {
        $before = $pnl->report(...$this->dates('antes'));
        $a = $before['company'];
        $m = $pnl->report(...$this->dates('maduro'))['company'];
        $ea = $this->effectiveness('antes', null)['all'] ?? null;
        $em = $this->effectiveness('maduro', null)['all'] ?? null;
        $metaA = $this->metaTotals('antes');
        $metaD = $this->metaTotals('despues');
        $da = $this->days('antes');
        $dm = $this->days('maduro');
        $dd = $this->days('despues');
        $this->line('qué | sin IA por día | con IA por día | cambio');
        $row = function (string $label, ?float $before, ?float $after, string $unit = '$') {
            $f = fn (?float $v) => $v === null ? '—' : ($unit === '$' ? '$' . number_format($v, 2, '.', '') : number_format($v, 1, '.', '') . $unit);
            $this->line("{$label} | {$f($before)} | {$f($after)} | " . AiReview::change($before, $after));
        };
        $row('ganancia neta (con gastos generales)', AiReview::perDay($a['operating_profit'], $da), AiReview::perDay($m['operating_profit'], $dm));
        $row('ganancia antes de gastos generales', AiReview::perDay($a['contribution'], $da), AiReview::perDay($m['contribution'], $dm));
        $row('vendido (entregado)', AiReview::perDay($a['revenue'], $da), AiReview::perDay($m['revenue'], $dm));
        $row('publicidad (pedidos maduros)', AiReview::perDay($a['advertising'], $da), AiReview::perDay($m['advertising'], $dm));
        $row('ganancia por cada $1 de publicidad', $a['advertising'] > 0 ? round($a['contribution'] / $a['advertising'], 2) : null,
            $m['advertising'] > 0 ? round($m['contribution'] / $m['advertising'], 2) : null, ' x');
        $row('pedidos creados (sin Sin Stock)', $ea ? AiReview::perDay($ea['created'], $da) : null, $em ? AiReview::perDay($em['created'], $dm) : null, ' ped');
        $row('pedidos entregados', $ea ? AiReview::perDay($ea['delivered'], $da) : null, $em ? AiReview::perDay($em['delivered'], $dm) : null, ' ped');
        $row('efectividad (maduros, sin Sin Stock)', $ea['effectiveness'] ?? null, $em['effectiveness'] ?? null, ' %');
        $row('costo de publicidad por entregado', $ea && $ea['delivered'] ? round($a['advertising'] / $ea['delivered'], 2) : null,
            $em && $em['delivered'] ? round($m['advertising'] / $em['delivered'], 2) : null);
        $this->line("Meta, todos los días (sin IA {$da} d, con IA {$dd} d):");
        $row('gasto de Meta', AiReview::perDay($metaA['spend'], $da), AiReview::perDay($metaD['spend'], $dd));
        $row('compras de Meta', AiReview::perDay($metaA['purchases'], $da), AiReview::perDay($metaD['purchases'], $dd), ' c');
        $row('CPA de Meta', AiReview::cpa($metaA['spend'], $metaA['purchases']), AiReview::cpa($metaD['spend'], $metaD['purchases']));
        $row('ganancia estimada de Meta (compras × BE − gasto)', AiReview::perDay($metaA['gain'], $da), AiReview::perDay($metaD['gain'], $dd));
        $this->line('pedidos que esperaron stock (fuera): sin IA ' . ($ea['left_out'] ?? 0) . ' · con IA ' . ($em['left_out'] ?? 0)
            . ' · estado de los números sin IA: ' . ($before['status']['label'] ?? '?'));
    }

    /** Semana por semana, desde el inicio: el mismo cálculo de las cohortes de Finanzas. */
    private function weeks(CohortReport $cohorts, CarbonImmutable $today): void
    {
        $r = $cohorts->report(Carbon::parse($this->periods['antes'][0]), Carbon::parse($this->periods['despues'][1]), 'week');
        $this->line('semana (lunes) | período | maduro | pedidos/día | efectividad | gasto/día | compras/día | CPA Meta | ganancia después de publicidad/día');
        foreach (array_reverse($r['rows']) as $w) {
            $start = max($w['period'], $this->periods['antes'][0]);
            $end = min($w['period_end'], $this->periods['despues'][1], $today->subDay()->toDateString());
            $days = max(1, (int) round((strtotime($end) - strtotime($start)) / 86400) + 1);
            $tag = $w['period'] >= $this->periods['despues'][0] ? 'con IA' : ($w['period_end'] < $this->periods['despues'][0] ? 'sin IA' : 'mezcla');
            $this->line(implode(' | ', [$w['period'], $tag, $w['mature'] ? 'sí' : 'no', AiReview::perDay($w['created'], $days), ($w['effectiveness'] ?? '—') . ' %',
                '$' . AiReview::perDay($w['spend'], $days), AiReview::perDay($w['purchases'], $days), $w['meta_cpa'] !== null ? '$' . $w['meta_cpa'] : '—',
                '$' . AiReview::perDay($w['after_ads'], $days)]));
        }
    }

    /** Por producto o por ciudad: la ganancia antes de gastos generales, la publicidad, el CPA y la efectividad. */
    private function groups(ProfitAndLoss $pnl, string $by): void
    {
        $key = $by === 'product' ? 'product_id' : 'city_id';
        $a = collect($pnl->report(...[...$this->dates('antes'), $by])['groups'])->keyBy(fn ($g) => (string) ($g[$key] ?? '-'));
        $m = collect($pnl->report(...[...$this->dates('maduro'), $by])['groups'])->keyBy(fn ($g) => (string) ($g[$key] ?? '-'));
        $ea = $this->effectiveness('antes', $key);
        $em = $this->effectiveness('maduro', $key);
        $da = $this->days('antes');
        $dm = $this->days('maduro');
        $this->line(($by === 'product' ? 'producto' : 'ciudad') . ' | ganancia/día sin IA → con IA | publicidad/día | CPA Meta | efectividad');
        $ids = $a->keys()->merge($m->keys())->unique()->filter(fn ($k) => ($a[$k]['advertising'] ?? 0) + ($m[$k]['advertising'] ?? 0) > 0);
        $rows = $ids->map(function ($k) use ($a, $m, $ea, $em, $da, $dm, $by) {
            $x = $a[$k] ?? null;
            $y = $m[$k] ?? null;
            $ga = $x ? AiReview::perDay($x['contribution'], $da) : null;
            $gm = $y ? AiReview::perDay($y['contribution'], $dm) : null;
            $name = $by === 'product' ? ($this->names[(int) $k] ?? $x['name'] ?? $y['name'] ?? $k) : ($x['name'] ?? $y['name'] ?? $k);

            return ['sort' => -($ga ?? 0) - ($gm ?? 0), 'line' => implode(' | ', [
                mb_strimwidth((string) $name, 0, 28, '…'),
                '$' . ($ga ?? '—') . ' → $' . ($gm ?? '—') . ' (' . AiReview::change($ga, $gm) . ')',
                '$' . ($x ? AiReview::perDay($x['advertising'], $da) : '—') . ' → $' . ($y ? AiReview::perDay($y['advertising'], $dm) : '—'),
                '$' . ($x ? (AiReview::cpa($x['advertising'], $x['meta_purchases']) ?? '—') : '—') . ' → $' . ($y ? (AiReview::cpa($y['advertising'], $y['meta_purchases']) ?? '—') : '—'),
                ($ea[$k]['effectiveness'] ?? '—') . ' % → ' . ($em[$k]['effectiveness'] ?? '—') . ' % (' . ($em[$k]['created'] ?? 0) . ' ped)',
            ])];
        })->sortBy('sort');
        foreach ($rows as $r) {
            $this->line($r['line']);
        }
    }

    /** Los mismos productos en los dos períodos: sin los que entraron o salieron. */
    private function same(ProfitAndLoss $pnl): void
    {
        $count = fn (string $p) => DB::table('meta_insights_daily as d')->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->where('d.spend', '>', 0)->whereBetween('d.date', $this->periods[$p])->whereNotNull('c.product_id')
            ->groupBy('c.product_id')->selectRaw('c.product_id, COUNT(DISTINCT d.date) n')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
        $ids = AiReview::sameProducts($count('antes'), $count('despues'), $this->days('antes'), $this->days('despues'));
        $this->line('productos con gasto en Meta el 80 % de los días o más en los dos períodos: '
            . (implode(', ', array_map(fn ($id) => $this->names[$id] ?? "#{$id}", $ids)) ?: 'ninguno'));
        if (!$ids) {
            return;
        }
        $sum = function (string $p) use ($pnl, $ids) {
            $g = collect($pnl->report(...[...$this->dates($p), 'product'])['groups'])->whereIn('product_id', $ids);

            return ['contribution' => (float) $g->sum('contribution'), 'advertising' => (float) $g->sum('advertising'), 'purchases' => (float) $g->sum('meta_purchases')];
        };
        $a = $sum('antes');
        $m = $sum('maduro');
        $ea = $this->effectiveness('antes', 'product_id');
        $em = $this->effectiveness('maduro', 'product_id');
        $eff = function (array $e) use ($ids) {
            $c = array_sum(array_map(fn ($id) => $e[(string) $id]['created'] ?? 0, $ids));
            $d = array_sum(array_map(fn ($id) => $e[(string) $id]['delivered'] ?? 0, $ids));

            return $c ? round($d / $c * 100, 1) : null;
        };
        $ga = AiReview::perDay($a['contribution'], $this->days('antes'));
        $gm = AiReview::perDay($m['contribution'], $this->days('maduro'));
        $this->line("ganancia antes de gastos generales/día: \${$ga} → \${$gm} (" . AiReview::change($ga, $gm) . ')');
        $this->line('publicidad/día: $' . AiReview::perDay($a['advertising'], $this->days('antes')) . ' → $' . AiReview::perDay($m['advertising'], $this->days('maduro')));
        $this->line('CPA Meta: $' . (AiReview::cpa($a['advertising'], $a['purchases']) ?? '—') . ' → $' . (AiReview::cpa($m['advertising'], $m['purchases']) ?? '—')
            . ' · efectividad: ' . ($eff($ea) ?? '—') . ' % → ' . ($eff($em) ?? '—') . ' %');
    }

    /** El dinero quemado en anuncios que perdían plata, y cuánto gastaba un perdedor antes de apagarse. */
    private function burned(CarbonImmutable $today): void
    {
        $cut = $today->subDays(3)->toDateString();
        foreach (['antes' => 'sin IA', 'despues' => 'con IA'] as $p => $label) {
            $days = $this->adDays($p);
            $b = AiReview::burned($days, $this->yard);
            // un perdedor "se apagó" si dejó de gastar al menos 3 días antes del final del período (o de hoy)
            $u = AiReview::untilOff($days, $this->yard, min($cut, date('Y-m-d', strtotime($this->periods[$p][1] . ' -2 days'))));
            $this->line("{$label}: de \${$b['spend']} en anuncios, \${$b['losing']} en semanas que perdían plata (" . ($b['pct'] ?? '—') . ' %) · $'
                . AiReview::perDay($b['losing'], $this->days($p)) . '/día · sin vara $' . $b['no_yard']);
            $this->line("  perdedores que se apagaron: {$u['losers']} · gastaron en promedio \$" . ($u['avg_spend'] ?? '—') . ' (mediana $' . ($u['median_spend'] ?? '—')
                . ') en ' . ($u['avg_days'] ?? '—') . ' días · total $' . $u['total']);
        }
    }

    /** Los anuncios nuevos de cada período, juzgados por sus primeros 7 días. */
    private function winners(CarbonImmutable $today): void
    {
        foreach (['antes' => 'sin IA', 'despues' => 'con IA'] as $p => $label) {
            $from = $this->periods[$p][0];
            $last = min(date('Y-m-d', strtotime($this->periods[$p][1] . ' -' . (AiReview::WINNER_DAYS - 1) . ' days')), $today->subDays(AiReview::WINNER_DAYS)->toDateString());
            // los 7 días de un anuncio del final del período pueden seguir después: se leen hasta 7 días más
            $days = $this->adDays($p, date('Y-m-d', strtotime($this->periods[$p][1] . ' +' . AiReview::WINNER_DAYS . ' days')));
            $w = AiReview::winners($days, $this->yard, $from, $last);
            $this->line("{$label}: {$w['new']} anuncios nuevos · juzgados (7 días cumplidos, con C) {$w['judged']} · ganadores {$w['winners']} (" . ($w['pct'] ?? '—')
                . " %) · en sus primeros 7 días gastaron \${$w['spend']} y dejaron \${$w['gain']} (estimado)");
        }
    }

    /** Las semanas en que una campaña subió su gasto un 20 % o más: si aguantó. */
    private function scaling(): void
    {
        foreach (['antes' => 'sin IA', 'despues' => 'con IA'] as $p => $label) {
            $from = date('Y-m-d', strtotime(AiReview::week($this->periods[$p][0]) . ' -7 days'));
            $s = collect(AiReview::scaleWeeks($this->campaignDays($from, $this->periods[$p][1]), $this->yard))->filter(fn ($w) => $w['week'] >= $this->periods[$p][0]);
            $this->line("{$label}: {$s->count()} subidas de 20 % o más · aguantaron (ganancia por día igual o mayor) {$s->where('held', true)->count()}");
            foreach ($s as $w) {
                $this->line('  ' . implode(' | ', [$w['week'], mb_strimwidth($this->campaigns[$w['campaign']]['name'] ?? $w['campaign'], 0, 34, '…'),
                    "gasto/día \${$w['spend_before']} → \${$w['spend_after']}", 'CPA $' . ($w['cpa_before'] ?? '—') . ' → $' . ($w['cpa_after'] ?? '—'),
                    "gana/día \${$w['gain_before']} → \${$w['gain_after']}", $w['held'] ? 'aguantó' : 'empeoró']));
            }
        }
    }

    /** El Historial de cambios (`.131`): qué se hizo en Meta, quién lo pidió y si se cumplió; las subidas, con 7 contra 7 días. */
    private function changes(): void
    {
        $rows = MetaChange::whereBetween('date', $this->periods['despues'])->orderBy('date')->get();
        $this->line('tipo | pedido por la IA | por tu cuenta | se cumplió | no se cumplió | midiendo/sin dato');
        foreach ($rows->groupBy('kind') as $kind => $list) {
            $this->line(implode(' | ', [$kind, $list->filter(fn ($c) => $c->mission)->count(), $list->filter(fn ($c) => !$c->mission)->count(),
                $list->where('result', 'met')->count(), $list->where('result', 'not_met')->count(), $list->whereNotIn('result', ['met', 'not_met'])->count()]));
        }
        $this->line('subidas y bajadas de presupuesto, 7 días antes contra 7 después (por día):');
        foreach ($rows->whereIn('kind', ['raise', 'lower']) as $c) {
            $d = $c->date->toDateString();
            $before = $this->window((string) $c->campaign_meta_id, date('Y-m-d', strtotime("{$d} -7 days")), date('Y-m-d', strtotime("{$d} -1 day")));
            $after = $this->window((string) $c->campaign_meta_id, date('Y-m-d', strtotime("{$d} +1 day")), date('Y-m-d', strtotime("{$d} +7 days")));
            $this->line('  ' . implode(' | ', [$d, $c->kind, mb_strimwidth($this->campaigns[(string) $c->campaign_meta_id]['name'] ?? (string) $c->campaign_meta_id, 0, 34, '…'),
                $c->mission ? 'pedido: ' . ($c->mission['kind'] ?? '?') : 'por tu cuenta',
                "gasto/día \${$before['spend_day']} → \${$after['spend_day']}", 'CPA $' . ($before['cpa'] ?? '—') . ' → $' . ($after['cpa'] ?? '—'),
                "gana/día \${$before['gain_day']} → \${$after['gain_day']} ({$after['days']} d)", 'Historial: ' . ($c->result ?? '—')]));
        }
    }

    /**
     * La nota de cada misión: cuántas veces la pidió la IA, cuántas se hicieron y qué pasó. Hecha = marcada como hecha o
     * vista en Meta (`.131`); un creativo, si dejó de gastar. Sin hacer: si la IA tenía razón, por lo que pasó después.
     */
    private function missions(): void
    {
        $analyses = MetaAnalysis::whereBetween('date', $this->periods['despues'])->orderBy('date')->orderBy('version')->get(['id', 'date', 'version', 'facts', 'urgent', 'marks']);
        $byDate = $analyses->groupBy(fn ($a) => $a->date->toDateString());
        $items = [];
        $dates = [];
        $marks = [];
        $facts = [];
        foreach ($byDate as $date => $versions) {
            $latest = $versions->last();
            $facts[$date] = collect($latest->facts ?? [])->keyBy(fn ($p) => (int) ($p['product_id'] ?? 0))->all();
            foreach ($versions as $v) {
                foreach ($v->marks ?? [] as $key => $m) {
                    $marks[$key][$date] = $m['status'] ?? null;
                }
            }
            foreach ($latest->urgent ?? [] as $u) {
                $kind = $u['kind'] ?? null;
                // una que solo espera (nada que hacer hoy en Ads Manager) no es una misión
                if (!in_array($kind, [...self::CAMPAIGN_KINDS, 'stop_creatives'], true) || !($u['steps'] ?? [])) {
                    continue;
                }
                $items[$u['key']] ??= $u;
                $dates[$u['key']][] = $date;
            }
        }
        $changes = MetaChange::whereNotNull('mission')->whereBetween('date', $this->periods['despues'])->get()
            ->groupBy(fn ($c) => (string) $c->campaign_meta_id);
        $yesterday = $this->periods['despues'][1];
        $byKind = [];
        $details = [];
        foreach (AiReview::streaks($dates) as $s) {
            $u = $items[$s['key']];
            $kind = $u['kind'];
            $d = $s['from'];
            $pid = (int) explode(':', $s['key'])[0];
            $status = collect(array_intersect_key($marks[$s['key']] ?? [], array_flip($s['dates'])))->filter()->values();
            $after = [date('Y-m-d', strtotime("{$d} +1 day")), min($yesterday, date('Y-m-d', strtotime("{$d} +7 days")))];
            $before = [date('Y-m-d', strtotime("{$d} -7 days")), date('Y-m-d', strtotime("{$d} -1 day"))];
            if ($kind === 'stop_creatives') {
                $ads = $this->stopAds($facts[$d][$pid] ?? [], $pid, $u['city'] ?? null);
                $soonTo = min($yesterday, date('Y-m-d', strtotime("{$d} +3 days")));
                $soon = $this->adsWindow($ads, $after[0], $soonTo);
                $campaignIds = array_keys(array_filter($this->campaigns, fn ($c) => $c['product_id'] === $pid && (!($u['city'] ?? null) || $this->cityName($c['city_id']) === $u['city'])));
                // se apagó si sus anuncios dejaron de gastar mientras la campaña seguía con datos de Meta esos días
                $done = $status->contains('done') || ($ads && $soon['spend'] < AiReview::OFF_SPEND && $this->windowMany($campaignIds, $after[0], $soonTo)['days'] > 0);
                $then = $done ? null : $this->adsWindow($ads, $after[0], $after[1]);
            } else {
                $camp = explode(':', $s['key'])[1] ?? '';
                $campaignIds = [$camp];
                $seen = ($changes[$camp] ?? collect())->contains(fn ($c) => ($c->mission['kind'] ?? null) === $kind && in_array($c->mission['date'] ?? null, $s['dates'], true));
                $done = $status->contains('done') || $seen;
                $then = $done ? null : $this->window($camp, $after[0], $after[1]);
            }
            $disagree = $status->contains('disagree');
            $b = $this->windowMany($campaignIds, $before[0], $before[1]);
            $a = $this->windowMany($campaignIds, $after[0], $after[1]);
            $k = &$byKind[$kind];
            $k ??= ['n' => 0, 'done' => 0, 'disagree' => 0, 'none' => 0, 'better' => 0, 'worse' => 0, 'delta' => 0.0, 'right' => 0, 'wrong' => 0, 'unknown' => 0, 'lost' => 0.0];
            $k['n']++;
            $verdict = '';
            if ($done) {
                $k['done']++;
                if ($a['days'] >= 3 && $b['days'] >= 3) {
                    $better = $a['gain_day'] >= $b['gain_day'];
                    $k[$better ? 'better' : 'worse']++;
                    $k['delta'] += $a['gain_day'] - $b['gain_day'];
                    $verdict = $better ? 'hecha: mejoró' : 'hecha: empeoró';
                } else {
                    $verdict = 'hecha: falta tiempo';
                }
            } else {
                $k[$disagree ? 'disagree' : 'none']++;
                $yard = $this->yardOf($campaignIds);
                $right = $then ? AiReview::rightWhenIgnored($kind, $then['spend'], $then['purchases'], $yard['c'], $yard['be']) : null;
                $k[$right === null ? 'unknown' : ($right ? 'right' : 'wrong')]++;
                if ($right && in_array($kind, AiReview::BRAKES, true) && $yard['be']) {
                    $k['lost'] += max(0, $then['spend'] - $then['purchases'] * $yard['be']);
                }
                $verdict = ($disagree ? 'no de acuerdo' : 'sin hacer') . ': ' . ($right === null ? 'sin dato' : ($right ? 'la IA tenía razón' : 'la IA se equivocó'))
                    . ($then ? ' (después: $' . round($then['spend'], 2) . ', ' . round($then['purchases'], 1) . ' compras)' : '');
            }
            unset($k);
            $details[] = implode(' | ', [$d . (count($s['dates']) > 1 ? ' (' . count($s['dates']) . ' d)' : ''), $kind, mb_strimwidth($this->names[$pid] ?? "#{$pid}", 0, 20, '…'),
                $u['city'] ?? 'todas', "gana/día \${$b['gain_day']} → \${$a['gain_day']}", $verdict]);
        }
        $this->line('misiones con IA (días ' . $byDate->keys()->first() . ' a ' . $byDate->keys()->last() . ', ' . $byDate->count() . ' análisis)');
        $this->line('tipo | pedidas | hechas | no de acuerdo | sin hacer | hechas: mejoró/empeoró, cambio de ganancia por día | sin hacer: la IA tenía razón/se equivocó/sin dato, plata perdida');
        foreach ($byKind as $kind => $k) {
            $this->line(implode(' | ', [$kind, $k['n'], $k['done'], $k['disagree'], $k['none'],
                "{$k['better']}/{$k['worse']}, " . ($k['better'] + $k['worse'] ? '$' . round($k['delta'] / ($k['better'] + $k['worse']), 2) . ' en promedio' : '—'),
                "{$k['right']}/{$k['wrong']}/{$k['unknown']}, \$" . round($k['lost'], 2)]));
        }
        $this->line('cada misión:');
        foreach ($details as $l) {
            $this->line('  ' . $l);
        }
    }

    // ---- Datos -----------------------------------------------------------------------------------------------------------

    /** La vara de cada producto|ciudad: el BE y el C de hoy de la ciudad o, si no tiene, los del producto. */
    private function yard(FinancialCpa $cpa): array
    {
        $versions = $cpa->latest();
        $out = [];
        foreach ($this->campaigns as $c) {
            $own = $versions[$c['cell']] ?? null;
            $product = $versions["{$c['product_id']}|"] ?? null;
            $out[$c['cell']] = ['be' => ($own?->break_even ?? $product?->break_even) !== null ? (float) ($own?->break_even ?? $product?->break_even) : null,
                'c' => ($own?->target ?? $product?->target) !== null ? (float) ($own?->target ?? $product?->target) : null];
        }

        return $out;
    }

    private function yardOf(array $campaignIds): array
    {
        $y = array_values(array_filter(array_map(fn ($id) => $this->yard[$this->campaigns[$id]['cell'] ?? ''] ?? null, $campaignIds)));

        return ['be' => $y ? max(array_column($y, 'be') ?: [null]) : null, 'c' => $y ? max(array_column($y, 'c') ?: [null]) : null];
    }

    private function names(): array
    {
        return Product::whereIn('id', array_unique(array_column($this->campaigns, 'product_id')) ?: [0])->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [$p->id => $p->showable_name ?: $p->title])->all();
    }

    private function cityName(int $id): ?string
    {
        static $cities = null;
        $cities ??= DB::table('cities')->pluck('name', 'id')->all();

        return $cities[$id] ?? null;
    }

    /** Lo de Meta de un período en las campañas con producto y ciudad, con la ganancia estimada por la vara. */
    private function metaTotals(string $p): array
    {
        $out = ['spend' => 0.0, 'purchases' => 0.0, 'gain' => 0.0];
        foreach ($this->campaignDays(...$this->periods[$p]) as $d) {
            $out['spend'] += $d['spend'];
            $out['purchases'] += $d['purchases'];
            $out['gain'] += ($be = $this->yard[$d['cell']]['be'] ?? null) ? AiReview::gain($d['spend'], $d['purchases'], $be) : 0;
        }

        return $out;
    }

    /** @return list<array{campaign: string, cell: string, date: string, spend: float, purchases: float}> */
    private function campaignDays(string $from, string $to): array
    {
        return DB::table('meta_insights_daily')->where('level', 'campaign')->whereBetween('date', [$from, $to])->get(['date', 'meta_id', 'spend', 'purchases'])
            ->filter(fn ($r) => isset($this->campaigns[(string) $r->meta_id]))
            ->map(fn ($r) => ['campaign' => (string) $r->meta_id, 'cell' => $this->campaigns[(string) $r->meta_id]['cell'], 'date' => substr((string) $r->date, 0, 10),
                'spend' => (float) $r->spend, 'purchases' => (float) ($r->purchases ?? 0)])->values()->all();
    }

    /** @return list<array{ad: string, campaign: string, cell: string, date: string, spend: float, purchases: float}> */
    private function adDays(string $p, ?string $to = null): array
    {
        return DB::table('meta_insights_daily')->where('level', 'ad')->whereBetween('date', [$this->periods[$p][0], $to ?? $this->periods[$p][1]])
            ->get(['date', 'meta_id', 'campaign_meta_id', 'spend', 'purchases'])
            ->filter(fn ($r) => isset($this->campaigns[(string) $r->campaign_meta_id]))
            ->map(fn ($r) => ['ad' => (string) $r->meta_id, 'campaign' => (string) $r->campaign_meta_id, 'cell' => $this->campaigns[(string) $r->campaign_meta_id]['cell'],
                'date' => substr((string) $r->date, 0, 10), 'spend' => (float) $r->spend, 'purchases' => (float) ($r->purchases ?? 0)])->values()->all();
    }

    /** Una campaña en unos días: gasto y ganancia por día, CPA. */
    private function window(string $campaign, string $from, string $to): array
    {
        return $this->windowMany([$campaign], $from, $to);
    }

    private function windowMany(array $campaignIds, string $from, string $to): array
    {
        $spend = 0.0;
        $purchases = 0.0;
        $gain = 0.0;
        $days = [];
        if ($from <= $to && $campaignIds) {
            foreach (DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaignIds)->whereBetween('date', [$from, $to])
                ->get(['date', 'meta_id', 'spend', 'purchases']) as $r) {
                $spend += (float) $r->spend;
                $purchases += (float) ($r->purchases ?? 0);
                $be = $this->yard[$this->campaigns[(string) $r->meta_id]['cell'] ?? '']['be'] ?? null;
                $gain += $be ? AiReview::gain((float) $r->spend, (float) ($r->purchases ?? 0), $be) : 0;
                $days[substr((string) $r->date, 0, 10)] = true;
            }
        }
        $n = count($days);

        return ['days' => $n, 'spend' => round($spend, 2), 'purchases' => round($purchases, 2), 'spend_day' => $n ? round($spend / $n, 2) : 0.0,
            'cpa' => AiReview::cpa($spend, $purchases), 'gain_day' => $n ? round($gain / $n, 2) : 0.0];
    }

    /** Los anuncios de los creativos que una misión mandó apagar (en esa ciudad, o en todas). */
    private function stopAds(array $fact, int $productId, ?string $city): array
    {
        $labels = $city ? array_values(array_diff($fact['stop_by_city'][$city] ?? [], $fact['stop_all'] ?? [])) : ($fact['stop_all'] ?? []);
        if (!$labels) {
            return [];
        }
        $creatives = DB::table('meta_creatives')->where('product_id', $productId)->whereIn('label', $labels)->pluck('id')->all();
        $campaignIds = array_keys(array_filter($this->campaigns, fn ($c) => $c['product_id'] === $productId && (!$city || $this->cityName($c['city_id']) === $city)));

        return $creatives && $campaignIds ? DB::table('meta_ads')->whereIn('meta_creative_ref_id', $creatives)->whereIn('campaign_meta_id', $campaignIds)->pluck('meta_id')
            ->map(fn ($v) => (string) $v)->all() : [];
    }

    private function adsWindow(array $ads, string $from, string $to): array
    {
        if (!$ads || $from > $to) {
            return ['spend' => 0.0, 'purchases' => 0.0];
        }
        $r = DB::table('meta_insights_daily')->where('level', 'ad')->whereIn('meta_id', $ads)->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(spend), 0) spend, COALESCE(SUM(purchases), 0) purchases')->first();

        return ['spend' => (float) $r->spend, 'purchases' => (float) $r->purchases];
    }

    /**
     * La efectividad de los pedidos maduros, sin los que esperaron stock (como el C y la etiqueta del producto).
     *
     * @return array<string, array{created: int, delivered: int, effectiveness: ?float, left_out: int}>
     */
    private function effectiveness(string $p, ?string $by): array
    {
        return $this->effectiveness["{$p}|{$by}"] ??= $this->countEffectiveness($p, $by);
    }

    private function countEffectiveness(string $p, ?string $by): array
    {
        $sinStock = (int) Status::where('description', OrderStatus::SIN_STOCK)->value('id');
        $rows = DB::table('order_financials')->where('kind', 'normal')->whereBetween('created_date', $this->periods[$p])
            ->get(['order_id', 'product_id', 'city_id', 'outcome', 'status_id']);
        $long = array_flip(FinancialCpa::stockoutOrderIds($rows->pluck('order_id')->map(fn ($v) => (int) $v)->all(), $sinStock));
        $out = [];
        foreach ($rows as $r) {
            $k = $by ? (string) ($r->{$by} ?? '-') : 'all';
            $out[$k] ??= ['created' => 0, 'delivered' => 0, 'effectiveness' => null, 'left_out' => 0];
            if (($sinStock && (int) $r->status_id === $sinStock) || isset($long[(int) $r->order_id])) {
                $out[$k]['left_out']++;
                continue;
            }
            $out[$k]['created']++;
            $out[$k]['delivered'] += $r->outcome === 'delivered' ? 1 : 0;
        }
        foreach ($out as $k => $e) {
            $out[$k]['effectiveness'] = $e['created'] ? round($e['delivered'] / $e['created'] * 100, 1) : null;
        }

        return $out;
    }

    // ---- Fechas ----------------------------------------------------------------------------------------------------------

    /** "2026-09-10:2026-10-30"; sin hasta, ayer. */
    private function range(string $value, CarbonImmutable $today): array
    {
        [$from, $to] = array_pad(explode(':', $value, 2), 2, '');
        $yesterday = $today->subDay()->toDateString();

        return [CarbonImmutable::parse(trim($from))->toDateString(), trim($to) !== '' ? min(CarbonImmutable::parse(trim($to))->toDateString(), $yesterday) : $yesterday];
    }

    private function days(string $p): int
    {
        [$from, $to] = $this->periods[$p];

        return $from > $to ? 0 : (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    }

    /**
     * Las fechas de un período para los reportes de Finanzas, en Carbon que se puede modificar: los gastos fijos recorren los
     * días con addDay() (RecurringExpense::accrued) y con una fecha inmutable no avanzarían nunca.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dates(string $p): array
    {
        return [Carbon::parse($this->periods[$p][0]), Carbon::parse($this->periods[$p][1])];
    }
}
