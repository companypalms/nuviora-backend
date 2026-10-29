<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use App\Services\Finance\OrderEconomics;
use App\Services\Metrics\Readers\MoneyReader;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Readers\TeamReader;
use App\Services\Metrics\Rules\AlertRules;
use App\Services\Metrics\Rules\FunnelRules;
use App\Services\Metrics\Rules\GoalRules;
use App\Services\Metrics\Rules\LossRules;
use App\Services\Metrics\Rules\MoneyRules;
use App\Services\Metrics\Rules\SalesRules;
use App\Services\Metrics\Rules\TeamRules;
use App\Services\Metrics\Team\TeamCatalog;
use App\Services\Metrics\Team\TeamRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * El panel del dueño (diccionario de métricas, §7 y §8): junta lo que leen los lectores con lo que calculan las reglas.
 * Solo lee (las metas del dueño se guardan aparte, en GoalSettings). Cada sección del panel ("Ver": Ventas, Dinero,
 * Embudo, Pérdidas, Equipo) es un método; las alertas de arriba y las metas (§6), otros dos.
 */
class OwnerPanel
{
    /** "En camino" se calcula con los pedidos de estos días que ya terminaron de entregarse. */
    private const ESTIMATE_DAYS = 30;

    /** Con menos entregas que estas, el promedio de un filtro no es confiable: se usa el de todo el negocio. */
    private const ESTIMATE_MIN_DELIVERED = 10;

    /** Las alertas se recalculan como mucho cada 2 minutos (lo mismo que se actualiza el panel solo). */
    private const ALERTS_CACHE_SECONDS = 120;

    /** Las referencias de "Metas y límites" (tus números de los últimos 30 días): 10 minutos. */
    private const REFERENCES_CACHE_SECONDS = 600;

    public function __construct(
        private OrderFactsReader $orders,
        private MoneyReader $money,
        private TeamReader $team,
        private GoalSettings $goals,
    ) {
    }

    /**
     * Equipo: los KPIs de cada persona de un rol (el catálogo de Metrics\Team), su ranking y las tarjetas del rol. Avisa
     * de los roles del sistema que todavía no tienen KPIs.
     */
    public function team(Period $period, Filters $filters, string $roleKey): array
    {
        $now = CarbonImmutable::now();
        $previous = $period->previous();
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $maturing = $period->isMaturing($now, $maturityDays);
        $roles = TeamCatalog::roles();
        $role = $roles[$roleKey] ?? reset($roles);

        $current = $role->compute($role->facts($this->team, $period, $filters), $period);
        $before = $role->compute($role->facts($this->team, $previous, $filters), $previous);
        $config = $this->goals->get();
        $built = GoalRules::team($role->key(), TeamRules::build($role, $current, $before, $maturing), $config, $maturing);
        $counts = $this->team->roleCounts();
        $groups = count($this->team->salesGroups($period));

        return [
            'period' => $period->toArray() + ['compare_label' => $period->compareLabel],
            'previous' => $previous->toArray(),
            'filters' => $filters->toArray(),
            'maturing' => $maturing,
            'maturity_days' => $maturityDays,
            'pending' => $this->orders->pending($period, $filters),
            'cards' => GoalRules::teamCards($role->key(), $built['cards'], $config, $maturing),
            'roles' => array_values(array_map(fn (TeamRole $r) => [
                'key' => $r->key(),
                'label' => $r->label(),
                'people' => $r->systemRoles() === [] ? $groups : array_sum(array_map(fn ($d) => $counts[$d] ?? 0, $r->systemRoles())),
            ], $roles)),
            'coverage' => TeamCatalog::coverage(array_keys($counts)),
            'role' => array_diff_key($built, ['cards' => true]),
            'updated_at' => $now->format('H:i'),
        ];
    }

    /** El detalle de una persona de Equipo: todos sus KPIs como tarjetas y los bloques de su rol. */
    public function teamPerson(Period $period, Filters $filters, string $roleKey, int $id): ?array
    {
        $role = TeamCatalog::find($roleKey);
        if (!$role) {
            return null;
        }
        $now = CarbonImmutable::now();
        $previous = $period->previous();
        $maturing = $period->isMaturing($now, (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4));
        $current = $role->compute($role->facts($this->team, $period, $filters), $period);
        $before = $role->compute($role->facts($this->team, $previous, $filters), $previous);
        $person = $current['people'][$id] ?? null;
        if (!$person) {
            return null;
        }

        $config = $this->goals->get();
        $blocks = [];
        foreach ($role->blocks($id, $current, $before) as $block) {
            if ($block['type'] === 'table') {
                $inner = TeamCatalog::find($block['role']);
                $block = GoalRules::team($inner->key(), ['type' => 'table', 'title' => $block['title'], 'help' => $block['help'],
                    'columns' => TeamRules::columns($inner), 'rank_by' => $inner->rankBy(), 'base' => $inner->base(),
                    'rows' => TeamRules::rows($inner, $block['people'], $block['previous'] ?? [])], $config, $maturing);
            }
            $blocks[] = $block;
        }

        return [
            'id' => $id,
            'name' => $person['name'],
            'group' => $person['group'] ?? null,
            'role' => $role->label(),
            'compare_label' => $previous->label,
            'cards' => GoalRules::teamCards($role->key(), TeamRules::personCards($role, $person, $before['people'][$id] ?? null, $maturing), $config, $maturing, $id),
            'blocks' => $blocks,
        ];
    }

    /** Ventas y pedidos: las tarjetas, qué pasó con los pedidos y los pedidos de cada día. */
    public function sales(Period $period, Filters $filters): array
    {
        $now = CarbonImmutable::now();
        $previous = $period->previous();
        // La misma "madurez" de Finanzas › Cohortes: los pedidos de los últimos días todavía se están entregando
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $maturing = $period->isMaturing($now, $maturityDays);

        $current = SalesRules::compute($this->orders->facts($period, $filters), $period);
        $before = SalesRules::compute($this->orders->facts($previous, $filters), $previous);

        // Las metas del negocio (sin filtros): lo que todavía pueden vender los pedidos en camino
        $config = $this->goals->get();
        $pending = [];
        if (!$filters->any() && $config['business']['sales'] !== null) {
            [$estimate] = $this->estimate($filters, $now, $maturityDays);
            $pending['sales'] = $estimate['rate'] !== null && $estimate['ticket'] !== null
                ? round($current['totals'][SalesRules::OPEN] * $estimate['rate'] * $estimate['ticket'], 2) : null;
        }

        $status = [];
        foreach (SalesRules::STATUS as $key => $s) {
            $status[] = ['key' => $key, 'label' => $s['label'], 'help' => $s['help'], 'count' => $current['totals'][$key]];
        }

        return [
            'period' => $period->toArray() + ['compare_label' => $period->compareLabel],
            'previous' => $previous->toArray(),
            'filters' => $filters->toArray(),
            'maturing' => $maturing,
            'maturity_days' => $maturityDays,
            'pending' => $this->orders->pending($period, $filters),
            'cards' => $this->businessGoals(SalesRules::cards($current['totals'], $before['totals'], $maturing), $config, $pending, $period, $filters, $now, $maturing),
            'status' => $status,
            'series' => $current['series'] + ['help' => SalesRules::SERIES_HELP],
            'excluded' => $current['excluded'] + ['help' => SalesRules::EXCLUDED_HELP],
            'updated_at' => $now->format('H:i'),
        ];
    }

    /** Embudo: cuántos pedidos llegan a cada paso, dónde se quedan y cuánto tardan. */
    public function funnel(Period $period, Filters $filters): array
    {
        $now = CarbonImmutable::now();
        $previous = $period->previous();
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $maturing = $period->isMaturing($now, $maturityDays);

        $current = FunnelRules::compute($this->orders->funnel($period, $filters, FunnelRules::STATUSES), $period);
        $before = FunnelRules::compute($this->orders->funnel($previous, $filters, FunnelRules::STATUSES), $previous);

        return [
            'period' => $period->toArray() + ['compare_label' => $period->compareLabel],
            'previous' => $previous->toArray(),
            'filters' => $filters->toArray(),
            'maturing' => $maturing,
            'maturity_days' => $maturityDays,
            'pending' => $this->orders->pending($period, $filters),
            'cards' => FunnelRules::cards($current['totals'], $before['totals'], $maturing),
            'relation' => FunnelRules::relation($current['totals']),
            'steps' => $current['steps'],
            'previous_steps' => array_map(fn ($s) => ['key' => $s['key'], 'of_created' => $s['of_created']], $before['steps']),
            'help' => FunnelRules::HELP,
            'updated_at' => $now->format('H:i'),
        ];
    }

    /** Pérdidas: cancelaciones y sus motivos, rechazos en puerta, devoluciones y cambios, y lo que costaron. */
    public function losses(Period $period, Filters $filters): array
    {
        $now = CarbonImmutable::now();
        $previous = $period->previous();
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $maturing = $period->isMaturing($now, $maturityDays);

        $current = LossRules::compute($this->orders->losses($period, $filters), $period);
        $before = LossRules::compute($this->orders->losses($previous, $filters), $previous);

        return [
            'period' => $period->toArray() + ['compare_label' => $period->compareLabel],
            'previous' => $previous->toArray(),
            'filters' => $filters->toArray(),
            'maturing' => $maturing,
            'maturity_days' => $maturityDays,
            'pending' => $this->orders->pending($period, $filters),
            'cards' => LossRules::cards($current['totals'], $before['totals'], $maturing),
            'reasons' => ['rows' => $current['reasons'], 'help' => LossRules::REASONS_HELP],
            'stages' => ['rows' => $current['stages'], 'help' => LossRules::STAGES_HELP],
            'who' => ['rows' => $current['who'], 'help' => LossRules::WHO_HELP],
            'novelties' => ['rows' => $current['novelties'], 'help' => LossRules::REJECTED_HELP],
            'latest' => ['rows' => $current['latest'], 'help' => LossRules::LATEST_HELP],
            'updated_at' => $now->format('H:i'),
        ];
    }

    /** Dinero: ganancia (ya ganado y en camino), publicidad, por cobrar, a dónde se fue el dinero y por producto. */
    public function money(Period $period, Filters $filters): array
    {
        $now = CarbonImmutable::now();
        $previous = $period->previous();
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $maturing = $period->isMaturing($now, $maturityDays);
        $byShop = $filters->shopId !== null;
        $filtered = $byShop || $filters->cityId !== null || $filters->productId !== null;

        $current = MoneyRules::compute(
            $this->orders->facts($period, $filters), $period, $filters, $this->money->adSpend($period),
            $this->money->expenses(Buckets::ranges($period, Buckets::granularity($period))), $byShop ? $this->orders->ordersByCell($period) : [],
        );
        $before = MoneyRules::compute(
            $this->orders->facts($previous, $filters), $previous, $filters, $this->money->adSpend($previous),
            $this->money->expenses([$previous->fromDate() => [$previous->fromDate(), $previous->toDate()]]), $byShop ? $this->orders->ordersByCell($previous) : [],
        );

        [$estimate, $history, $wholeBusiness] = $this->estimate($filters, $now, $maturityDays);

        $receivables = $this->money->receivables();
        usort($receivables, fn ($a, $b) => $b['usd'] <=> $a['usd']);
        $names = $this->money->productNames(array_column($current['products'], 'product_id'));
        $products = array_map(fn ($p) => $p + [
            'name' => !empty($p['others']) ? 'Otros productos' : ($p['product_id'] ? ($names[$p['product_id']] ?? "Producto {$p['product_id']}") : 'Sin producto'),
        ], $current['products']);

        return [
            'period' => $period->toArray() + ['compare_label' => $period->compareLabel],
            'previous' => $previous->toArray(),
            'filters' => $filters->toArray(),
            'filtered' => $filtered,
            'maturing' => $maturing,
            'maturity_days' => $maturityDays,
            'pending' => $this->orders->pending($period, $filters),
            'cards' => $this->businessGoals(
                MoneyRules::cards($current['totals'], $before['totals'], $estimate, $receivables, $maturing, $filters, MoneyRules::estimateText($estimate, $history, $wholeBusiness)),
                $this->goals->get(), ['profit' => MoneyRules::pending($current['totals']['open'], $estimate)], $period, $filters, $now, $maturing,
            ),
            'waterfall' => MoneyRules::waterfall($current['totals'], $filters),
            'series' => [
                'granularity' => $current['series']['granularity'],
                'points' => MoneyRules::withPending($current['series']['points'], $estimate),
                'help' => MoneyRules::SERIES_HELP,
            ],
            'products' => ['rows' => $products, 'help' => MoneyRules::PRODUCTS_HELP],
            'receivables' => [
                'rows' => array_values(array_filter($receivables, fn ($r) => abs($r['usd']) > 0.005)),
                'total' => round(array_sum(array_column($receivables, 'usd')), 2),
                'help' => MoneyRules::RECEIVABLES_HELP,
            ],
            'warnings' => MoneyRules::warnings($current['totals'], $current['checks']),
            'updated_at' => $now->format('H:i'),
        ];
    }

    /**
     * Las alertas de ahora, arriba del panel (diccionario §6). No dependen de los filtros ni del período elegido: cada
     * una mira siempre lo mismo (AlertRules). Se guardan unos minutos; al cambiar una meta se calculan de nuevo.
     */
    public function alerts(): array
    {
        $config = $this->goals->get();
        if (!GoalRules::configured($config)) {
            return ['configured' => false, 'alerts' => [], 'window' => null, 'updated_at' => CarbonImmutable::now()->format('H:i')];
        }

        return Cache::remember('owner_panel_alerts:' . md5(json_encode($config)), self::ALERTS_CACHE_SECONDS,
            fn () => $this->computeAlerts($config, CarbonImmutable::now()));
    }

    /** Lo que necesita la ventana "Metas y límites": lo guardado, qué es cada cosa, las personas y tus números de referencia. */
    public function goals(): array
    {
        $names = fn (string $role) => collect($this->team->people(TeamCatalog::find($role)->systemRoles()))
            ->map(fn ($p, $id) => ['id' => (int) $id, 'name' => $p['name']])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [
            'config' => GoalRules::forScreen($this->goals->get()),
            'catalog' => GoalRules::catalog(),
            'people' => ['sellers' => $names('sellers'), 'agencies' => $names('agencies')],
            'references' => Cache::remember('owner_panel_goal_references', self::REFERENCES_CACHE_SECONDS, fn () => $this->references(CarbonImmutable::now())),
        ];
    }

    private function computeAlerts(array $config, CarbonImmutable $now): array
    {
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $all = new Filters();
        $a = $config['alerts'];
        $alerts = [];

        // Vendedoras y agencias: los pedidos que les entraron en los 7 días que ya terminaron de entregarse
        $windowEnd = $now->startOfDay()->subDays($maturityDays + 1);
        $window = Period::fromPreset('custom', $windowEnd->subDays(AlertRules::TEAM_DAYS - 1)->toDateString(), $windowEnd->toDateString(), $now);
        $windowText = self::range($window);
        $computed = [];
        foreach (GoalRules::TEAM as $key => $def) {
            $goal = $config['team'][$key];
            if (!$goal['alert'] || $goal['value'] === null) {
                continue;
            }
            $role = TeamCatalog::find($def['role']);
            $computed[$def['role']] ??= $role->compute($role->facts($this->team, $window, $all), $window);
            $alerts[] = AlertRules::team($key, $goal, $computed[$def['role']]['people'], $role->base(), $windowText);
        }

        // El dinero que tienen ahora mismo
        if ($a['agency_cash']['on'] && $a['agency_cash']['yellow'] !== null) {
            $alerts[] = AlertRules::cash('agency_cash', $a['agency_cash'], $this->agencyCash());
        }
        if ($a['rider_cash']['on'] && $a['rider_cash']['yellow'] !== null) {
            $riders = $this->team->people(TeamCatalog::find('riders')->systemRoles());
            $holders = [];
            foreach ($this->team->riderCash(array_keys($riders)) as $id => $usd) {
                $holders[] = ['id' => (int) $id, 'name' => $riders[$id]['name'], 'usd' => $usd];
            }
            $alerts[] = AlertRules::cash('rider_cash', $a['rider_cash'], $holders);
        }

        // La publicidad de los últimos 3 días completos
        if ($a['ad_cost']['on'] && $a['ad_cost']['yellow'] !== null) {
            $yesterday = $now->startOfDay()->subDay();
            $days = Period::fromPreset('custom', $yesterday->subDays(AlertRules::AD_DAYS - 1)->toDateString(), $yesterday->toDateString(), $now);
            $t = MoneyRules::compute($this->orders->facts($days, $all), $days, $all, $this->money->adSpend($days), [])['totals'];
            [$estimate] = $this->estimate($all, $now, $maturityDays);
            $alerts[] = AlertRules::adCost($a['ad_cost'], (float) $t['ads'], (int) $t['delivered'], (int) $t['open'], $estimate['rate'], self::range($days));
        }

        // Las cancelaciones de hoy
        if ($a['cancellations']['on'] && $a['cancellations']['yellow'] !== null) {
            $today = Period::fromPreset('today', null, null, $now);
            $t = LossRules::compute($this->orders->losses($today, $all), $today)['totals'];
            $alerts[] = AlertRules::cancellations($a['cancellations'], $t['created'], $t['cancelled']);
        }

        return ['configured' => true, 'alerts' => AlertRules::sort($alerts), 'window' => $windowText, 'updated_at' => $now->format('H:i')];
    }

    /**
     * Tus números de referencia para poner las metas: los 30 días que ya terminaron de entregarse, y el dinero de ahora
     * mismo. Cada uno es un texto corto que se ve debajo de su casilla.
     */
    private function references(CarbonImmutable $now): array
    {
        $maturityDays = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $end = $now->startOfDay()->subDays($maturityDays + 1);
        $w = Period::fromPreset('custom', $end->subDays(self::ESTIMATE_DAYS - 1)->toDateString(), $end->toDateString(), $now);
        $all = new Filters();
        $facts = $this->orders->facts($w, $all)->all();
        $sales = SalesRules::compute($facts, $w)['totals'];
        $money = MoneyRules::compute($facts, $w, $all, $this->money->adSpend($w), $this->money->expenses(Buckets::ranges($w, Buckets::granularity($w))))['totals'];
        $losses = LossRules::compute($this->orders->losses($w, $all), $w)['totals'];
        $team = [];
        foreach (['sellers', 'agencies'] as $key) {
            $role = TeamCatalog::find($key);
            $team[$key] = $role->compute($role->facts($this->team, $w, $all), $w)['totals']['values'];
        }
        $top = function (array $holders): string {
            usort($holders, fn ($x, $y) => $y['usd'] <=> $x['usd']);
            $first = $holders[0] ?? null;

            return $first && $first['usd'] > 0.005
                ? 'Ahora, quien más tiene: ' . GoalRules::fmt($first['usd'], 'money') . " ({$first['name']})."
                : 'Ahora nadie tiene dinero pendiente.';
        };
        $riders = $this->team->people(TeamCatalog::find('riders')->systemRoles());
        $riderCash = [];
        foreach ($this->team->riderCash(array_keys($riders)) as $id => $usd) {
            $riderCash[] = ['name' => $riders[$id]['name'], 'usd' => $usd];
        }
        $together = fn (?float $v, string $format) => 'Todas juntas: ' . GoalRules::fmt($v, $format) . '.';

        return [
            'label' => 'Tus números de los 30 días que ya terminaron de entregarse (' . self::range($w) . ').',
            'business' => [
                'sales' => 'En esos 30 días vendiste ' . GoalRules::fmt($sales['revenue'], 'money') . '.',
                'profit' => 'En esos 30 días ganaste ' . GoalRules::fmt($money['profit'], 'money') . ' (después de publicidad y gastos).',
                'effectiveness' => 'Tu efectividad general: ' . GoalRules::fmt($sales['effectiveness'], 'percent') . '.',
            ],
            'team' => [
                'sellers.effectiveness' => $together($team['sellers']['effectiveness'] ?? null, 'percent'),
                'agencies.effectiveness' => $together($team['agencies']['effectiveness'] ?? null, 'percent'),
                'agencies.delivery_time' => $together($team['agencies']['delivery_time'] ?? null, 'hours'),
                'agencies.rejection_rate' => $together($team['agencies']['rejection_rate'] ?? null, 'percent'),
            ],
            'alerts' => [
                'agency_cash' => $top($this->agencyCash()),
                'rider_cash' => $top($riderCash),
                'ad_cost' => 'Tu costo por venta: ' . GoalRules::fmt($money['cost_per_sale'], 'money') . '.',
                'cancellations' => 'En promedio se cancela el '
                    . GoalRules::fmt($losses['created'] > 0 ? $losses['cancelled'] * 100 / $losses['created'] : null, 'percent') . ' de los pedidos.',
            ],
        ];
    }

    /**
     * Las metas del negocio sobre las tarjetas de Ventas y Dinero. Solo sin filtros: son del negocio entero.
     *
     * @param array<string, ?float> $pending lo que todavía pueden sumar los pedidos en camino, por meta
     */
    private function businessGoals(array $cards, array $config, array $pending, Period $period, Filters $filters, CarbonImmutable $now, bool $maturing): array
    {
        if ($filters->any()) {
            return $cards;
        }
        foreach ($cards as &$card) {
            foreach (GoalRules::BUSINESS as $key => $def) {
                $goal = $config['business'][$key];
                if ($goal === null || $def['card'] !== $card['key']) {
                    continue;
                }
                $card['goal'] = !empty($def['monthly'])
                    ? GoalRules::moneyGoal($key, $goal, $card['value'], $pending[$key] ?? null, $period, $now, $maturing)
                    : GoalRules::limitGoal($def['label'], $def['format'], $def['better'], $goal, null, $card['value'], (bool) $card['neutral']);
            }
        }
        unset($card);

        return $cards;
    }

    /**
     * "En camino": el % que se entrega y lo que deja (y vende) cada entrega, de los pedidos de 30 días que ya terminaron
     * de entregarse. Con filtros y pocas entregas, los de todo el negocio.
     *
     * @return array{0: array, 1: Period, 2: bool} el estimado, de qué días sale y si es de todo el negocio
     */
    private function estimate(Filters $filters, CarbonImmutable $now, int $maturityDays): array
    {
        $historyEnd = $now->startOfDay()->subDays($maturityDays + 1);
        $history = Period::fromPreset('custom', $historyEnd->subDays(self::ESTIMATE_DAYS - 1)->toDateString(), $historyEnd->toDateString(), $now);
        $estimate = MoneyRules::estimate($this->orders->facts($history, $filters));
        $wholeBusiness = false;
        if ($filters->any() && $estimate['delivered'] < self::ESTIMATE_MIN_DELIVERED) {
            $estimate = MoneyRules::estimate($this->orders->facts($history, new Filters()));
            $wholeBusiness = true;
        }

        return [$estimate, $history, $wholeBusiness];
    }

    /**
     * El dinero que tiene cada agencia ahora mismo (el "Por cobrar" de Dinero), con su nombre completo como en Equipo.
     *
     * @return array<int, array{id: int, name: string, usd: float}>
     */
    private function agencyCash(): array
    {
        $agencies = $this->team->people(TeamCatalog::find('agencies')->systemRoles());
        $out = [];
        foreach ($this->team->agencyCash() as $id => $usd) {
            $out[] = ['id' => $id, 'name' => $agencies[$id]['name'] ?? "Agencia {$id}", 'usd' => $usd];
        }

        return $out;
    }

    /** "del 18/10 al 24/10". */
    private static function range(Period $p): string
    {
        return 'del ' . $p->start->format('d/m') . ' al ' . $p->end->format('d/m');
    }
}
