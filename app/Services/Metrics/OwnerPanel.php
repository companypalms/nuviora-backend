<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use App\Services\Finance\OrderEconomics;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Rules\SalesRules;
use Carbon\CarbonImmutable;

/**
 * El panel del dueño (diccionario de métricas, §7 y §8): junta lo que leen los lectores con lo que calculan las reglas.
 * Solo lee. Cada sección del panel ("Ver": Ventas, Dinero, Embudo, Pérdidas, Equipo) es un método.
 */
class OwnerPanel
{
    public function __construct(private OrderFactsReader $orders)
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
}
