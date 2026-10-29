<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use App\Services\Finance\OrderEconomics;
use App\Services\Metrics\Readers\MoneyReader;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Rules\FunnelRules;
use App\Services\Metrics\Rules\MoneyRules;
use App\Services\Metrics\Rules\SalesRules;
use Carbon\CarbonImmutable;

/**
 * El panel del dueño (diccionario de métricas, §7 y §8): junta lo que leen los lectores con lo que calculan las reglas.
 * Solo lee. Cada sección del panel ("Ver": Ventas, Dinero, Embudo, Pérdidas, Equipo) es un método.
 */
class OwnerPanel
{
    /** "En camino" se calcula con los pedidos de estos días que ya terminaron de entregarse. */
    private const ESTIMATE_DAYS = 30;

    /** Con menos entregas que estas, el promedio de un filtro no es confiable: se usa el de todo el negocio. */
    private const ESTIMATE_MIN_DELIVERED = 10;

    public function __construct(private OrderFactsReader $orders, private MoneyReader $money)
    {
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
            'cards' => SalesRules::cards($current['totals'], $before['totals'], $maturing),
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

        // "En camino": el % que se entrega y lo que deja cada entrega, de pedidos que ya terminaron de entregarse
        $historyEnd = $now->startOfDay()->subDays($maturityDays + 1);
        $history = Period::fromPreset('custom', $historyEnd->subDays(self::ESTIMATE_DAYS - 1)->toDateString(), $historyEnd->toDateString(), $now);
        $estimate = MoneyRules::estimate($this->orders->facts($history, $filters));
        $wholeBusiness = false;
        if ($filtered && $estimate['delivered'] < self::ESTIMATE_MIN_DELIVERED) {
            $estimate = MoneyRules::estimate($this->orders->facts($history, new Filters()));
            $wholeBusiness = true;
        }

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
            'cards' => MoneyRules::cards($current['totals'], $before['totals'], $estimate, $receivables, $maturing, $filters,
                MoneyRules::estimateText($estimate, $history, $wholeBusiness)),
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
}
