<?php

namespace App\Services\Editors;

use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\MoneyReader;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Rules\MoneyRules;
use Carbon\CarbonImmutable;

/**
 * `.153`: la ganancia de cada producto en unas fechas, la misma de Reportes del negocio (`.62`, MoneyRules "products_full"):
 * lo vendido y entregado − el producto − las comisiones − los envíos − su publicidad, de los pedidos creados esos días.
 * Solo lee.
 */
class CommissionFacts
{
    public function __construct(private OrderFactsReader $orders, private MoneyReader $money)
    {
    }

    /** @return array<int, float> la ganancia de cada producto (por su id) */
    public function profitByProduct(string $from, string $to, CarbonImmutable $now): array
    {
        if ($from > $now->toDateString()) {
            return [];
        }
        $period = Period::fromPreset('custom', $from, $to, $now);
        $all = new Filters();
        $money = MoneyRules::compute($this->orders->facts($period, $all), $period, $all, $this->money->adSpend($period), []);
        $out = [];
        foreach ($money['products_full'] as $p) {
            if ($p['product_id'] !== null) {
                $out[(int) $p['product_id']] = (float) $p['profit'];
            }
        }

        return $out;
    }
}
