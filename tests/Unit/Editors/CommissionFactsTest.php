<?php

use App\Services\Editors\CommissionFacts;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\MoneyReader;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Rules\MoneyRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

// .153: la ganancia de cada producto para la comisión del líder de editores es la misma de Reportes del negocio (`.62`,
// MoneyRules "products_full"), con pedidos y publicidad inventados.

$order = fn (string $createdAt, string $outcome, int $product, float $revenue, float $cost) => [
    'created_at' => $createdAt, 'kind' => 'normal', 'outcome' => $outcome, 'revenue' => $revenue, 'product_cost' => $cost,
    'commissions' => 2, 'shipping' => 3, 'product_id' => $product, 'city_id' => 10, 'shop_id' => 100, 'delivered_date' => null,
    'cogs_source' => 'lots', 'cogs_missing_lines' => 0, 'delivery_trips' => 1, 'delivery_final_trips' => 1, 'delivery_missing_trips' => 0,
];

it('es la ganancia por producto de Reportes del negocio, de los pedidos creados en la quincena', function () use ($order) {
    $orders = [
        $order('2026-11-16 09:00:00', 'delivered', 1, 50, 15),
        $order('2026-11-20 09:00:00', 'not_delivered', 1, 50, 0),
        $order('2026-11-21 09:00:00', 'delivered', 2, 40, 12),
        // de la quincena anterior: no cuenta
        $order('2026-11-15 23:00:00', 'delivered', 1, 999, 0),
    ];
    $ads = [
        ['date' => '2026-11-17', 'product_id' => 1, 'city_id' => 10, 'spend' => 8.0],
        ['date' => '2026-11-18', 'product_id' => 2, 'city_id' => 10, 'spend' => 30.0],
        ['date' => '2026-11-14', 'product_id' => 1, 'city_id' => 10, 'spend' => 500.0],
    ];
    $reader = new class($orders) extends OrderFactsReader {
        public function __construct(private array $rows)
        {
        }

        public function facts(Period $period, Filters $filters): LazyCollection
        {
            return LazyCollection::make($this->rows);
        }
    };
    $money = new class($ads) extends MoneyReader {
        public function __construct(private array $rows)
        {
        }

        public function adSpend(Period $period): array
        {
            return $this->rows;
        }
    };
    $now = CarbonImmutable::parse('2026-11-25 10:00:00');

    $profits = (new CommissionFacts($reader, $money))->profitByProduct('2026-11-16', '2026-11-30', $now);

    $period = Period::fromPreset('custom', '2026-11-16', '2026-11-30', $now);
    $report = collect(MoneyRules::compute($orders, $period, new Filters(), $ads, [])['products_full'])->pluck('profit', 'product_id')->all();
    // producto 1: 50 − 15 − 2×2 − 3×2 − 8 = 17; producto 2: 40 − 12 − 2 − 3 − 30 = −7
    expect($profits)->toEqual([1 => 17.0, 2 => -7.0])
        ->and($profits)->toEqual($report);
});

it('una quincena que todavía no empieza no tiene ganancia', function () {
    $facts = new CommissionFacts(new OrderFactsReader(), (new ReflectionClass(MoneyReader::class))->newInstanceWithoutConstructor());
    expect($facts->profitByProduct('2026-12-01', '2026-12-15', CarbonImmutable::parse('2026-11-25 10:00:00')))->toBe([]);
});
