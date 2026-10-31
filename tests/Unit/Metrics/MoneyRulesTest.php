<?php

use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\MoneyRules;
use Carbon\CarbonImmutable;

// Panel del dueño (guias/DICCIONARIO-METRICAS.md, §1 y §2): las reglas de "Dinero", con pedidos inventados. La semana
// de los ejemplos va del lunes 19 al domingo 25/10/2026.

$now = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$week = Period::fromPreset('custom', '2026-10-19', '2026-10-25', $now);
$monday = Period::fromPreset('custom', '2026-10-19', '2026-10-19', $now);
$order = fn (string $createdAt, string $outcome, float $revenue = 0, float $product = 0, float $commissions = 0, float $shipping = 0, array $extra = []) => $extra + [
    'created_at' => $createdAt, 'kind' => 'normal', 'outcome' => $outcome, 'revenue' => $revenue, 'product_cost' => $product,
    'commissions' => $commissions, 'shipping' => $shipping, 'product_id' => 1, 'city_id' => 10, 'shop_id' => 100, 'delivered_date' => null,
    'cogs_source' => 'lots', 'cogs_missing_lines' => 0, 'delivery_trips' => 1, 'delivery_final_trips' => 1, 'delivery_missing_trips' => 0,
];
$ad = fn (string $date, float $spend, ?int $product = 1, ?int $city = 10) => ['date' => $date, 'product_id' => $product, 'city_id' => $city, 'spend' => $spend];
$expense = fn (string $date, float $amount, ?int $product = null, ?int $city = null, string $kind = 'general') => ['date' => $date, 'kind' => $kind, 'product_id' => $product, 'city_id' => $city, 'amount' => $amount];
$none = new Filters();

describe('Ganancia (ya ganado)', function () use ($week, $monday, $order, $ad, $expense, $none) {
    it('un pedido creado el lunes y entregado el miércoles es ganancia del lunes', function () use ($monday, $order, $none) {
        $r = MoneyRules::compute([$order('2026-10-19 09:00:00', 'delivered', 50, 15, 5, 4, ['delivered_date' => '2026-10-21'])], $monday, $none, [], []);
        expect($r['totals']['revenue'])->toBe(50.0);
        expect($r['totals']['profit'])->toBe(26.0); // 50 − 15 − 5 − 4
    });

    it('un pedido del domingo entregado el lunes no es ganancia del lunes', function () use ($monday, $order, $none) {
        $r = MoneyRules::compute([$order('2026-10-18 21:00:00', 'delivered', 50, 15, 5, 4)], $monday, $none, [], []);
        expect($r['totals']['revenue'])->toBe(0.0);
        expect($r['totals']['profit'])->toBe(0.0);
    });

    it('el envío de una entrega que falló también se resta', function () use ($monday, $order, $none) {
        $r = MoneyRules::compute([$order('2026-10-19 09:00:00', 'not_delivered', 0, 0, 0, 4)], $monday, $none, [], []);
        expect($r['totals']['shipping'])->toBe(4.0);
        expect($r['totals']['profit'])->toBe(-4.0);
    });

    it('resta la publicidad y los gastos de esos días, y no los de otros días', function () use ($week, $order, $ad, $expense, $none) {
        $r = MoneyRules::compute(
            [$order('2026-10-19 09:00:00', 'delivered', 100, 30, 10, 5)],
            $week, $none,
            [$ad('2026-10-19', 20), $ad('2026-10-20', 10, null, null), $ad('2026-10-26', 99)],
            [$expense('2026-10-19', 3.5), $expense('2026-10-20', 3.5)],
        );
        expect($r['totals']['ads'])->toBe(30.0);       // la del 26 está fuera; la sin clasificar sí cuenta sin filtros
        expect($r['totals']['expenses'])->toBe(7.0);
        expect($r['totals']['profit'])->toBe(18.0);    // 100 − 30 − 10 − 5 − 30 − 7
        $byDay = array_column($r['series']['points'], 'profit', 'date');
        expect($byDay['2026-10-19'])->toBe(31.5);       // 55 − 20 − 3,5
        expect($byDay['2026-10-20'])->toBe(-13.5);      // sin ventas: solo publicidad y gastos
    });

    it('con filtros no resta los gastos generales, sí los de ese producto o ciudad', function () use ($week, $order, $ad, $expense) {
        $facts = [$order('2026-10-19 09:00:00', 'delivered', 100, 30, 10, 5)];
        $expenses = [$expense('2026-10-19', 50), $expense('2026-10-19', 6, 1, null, 'assigned'), $expense('2026-10-19', 4, 2, null, 'assigned'), $expense('2026-10-19', 2, 1, 10, 'shrinkage')];
        $ads = [$ad('2026-10-19', 20), $ad('2026-10-19', 8, 2, 10), $ad('2026-10-19', 30, null, null)];

        $byProduct = MoneyRules::compute($facts, $week, new Filters(null, null, 1), $ads, $expenses);
        expect($byProduct['totals']['expenses'])->toBe(8.0); // 6 del producto + 2 de merma; sin los 50 generales ni los 4 del producto 2
        expect($byProduct['totals']['ads'])->toBe(20.0);     // solo la del producto 1; sin la del 2 ni la sin clasificar

        $byShop = MoneyRules::compute($facts, $week, new Filters(100), $ads, $expenses, [['date' => '2026-10-19', 'product_id' => 1, 'city_id' => 10, 'orders' => 1]]);
        expect($byShop['totals']['expenses'])->toBe(0.0);    // con tienda no se puede asignar ningún gasto
    });

    it('con filtro de tienda, la publicidad se reparte según cuántos pedidos de ese anuncio son de la tienda', function () use ($monday, $order, $ad) {
        $r = MoneyRules::compute(
            [$order('2026-10-19 09:00:00', 'open')],
            $monday, new Filters(100),
            [$ad('2026-10-19', 30), $ad('2026-10-19', 12, 2, 10)],
            [],
            [['date' => '2026-10-19', 'product_id' => 1, 'city_id' => 10, 'orders' => 3], ['date' => '2026-10-19', 'product_id' => 2, 'city_id' => 10, 'orders' => 4]],
        );
        expect($r['totals']['ads'])->toBe(10.0); // 1 de 3 pedidos del producto 1 → un tercio de $30; del producto 2 no tiene pedidos
    });

    it('si ese día el anuncio no trajo pedidos, la publicidad se reparte con los pedidos de todo el período', function () use ($week, $order, $ad) {
        $r = MoneyRules::compute(
            [$order('2026-10-19 09:00:00', 'open')],
            $week, new Filters(100),
            [$ad('2026-10-20', 20)],
            [],
            [['date' => '2026-10-19', 'product_id' => 1, 'city_id' => 10, 'orders' => 2]],
        );
        expect($r['totals']['ads'])->toBe(10.0); // el martes no hubo pedidos: en la semana, 1 de los 2 pedidos de ese anuncio es de la tienda
    });
});

describe('Publicidad, en camino y por cobrar', function () use ($week, $order, $ad, $none) {
    it('costo por pedido = publicidad ÷ creados; costo por venta = publicidad ÷ entregados', function () use ($week, $order, $ad, $none) {
        $facts = [$order('2026-10-19 09:00:00', 'delivered', 50), $order('2026-10-19 10:00:00', 'delivered', 50),
            $order('2026-10-20 09:00:00', 'not_delivered'), $order('2026-10-21 09:00:00', 'open')];
        $t = MoneyRules::compute($facts, $week, $none, [$ad('2026-10-19', 40)], [])['totals'];
        expect($t['cost_per_order'])->toBe(10.0); // 40 ÷ 4
        expect($t['cost_per_sale'])->toBe(20.0);  // 40 ÷ 2
        expect(MoneyRules::compute($facts, $week, $none, [], [])['totals']['cost_per_order'])->toBeNull(); // sin publicidad no inventa $0
    });

    it('"en camino" = pendientes × % que se entrega × lo que deja cada entrega', function () use ($week, $order, $none) {
        $history = [
            $order('2026-09-20 09:00:00', 'delivered', 50, 20, 5, 5),   // deja 20
            $order('2026-09-20 10:00:00', 'delivered', 60, 20, 5, 5),   // deja 30
            $order('2026-09-21 09:00:00', 'not_delivered', 0, 0, 0, 5),
            $order('2026-09-21 10:00:00', 'not_delivered'),
        ];
        $estimate = MoneyRules::estimate($history);
        expect($estimate['rate'])->toBe(0.5);
        expect($estimate['margin'])->toBe(25.0);

        $r = MoneyRules::compute([$order('2026-10-19 09:00:00', 'open'), $order('2026-10-19 10:00:00', 'open'), $order('2026-10-20 09:00:00', 'open'),
            $order('2026-10-20 10:00:00', 'not_delivered')], $week, $none, [], []);
        expect($r['totals']['open'])->toBe(3);
        expect(MoneyRules::pending($r['totals']['open'], $estimate))->toBe(37.5); // 3 × 50 % × $25
        $points = array_column(MoneyRules::withPending($r['series']['points'], $estimate), 'pending', 'date');
        expect($points['2026-10-19'])->toBe(25.0);
        expect($points['2026-10-20'])->toBe(12.5);
        expect(MoneyRules::pending(3, MoneyRules::estimate([])))->toBeNull();
    });

    it('las tarjetas: los costos mejoran al bajar, "en camino" y "por cobrar" no se comparan', function () use ($week, $order, $ad, $none) {
        $now = MoneyRules::compute([$order('2026-10-19 09:00:00', 'delivered', 50), $order('2026-10-19 10:00:00', 'open')], $week, $none, [$ad('2026-10-19', 10)], [])['totals'];
        $before = MoneyRules::compute([$order('2026-10-12 09:00:00', 'delivered', 50)], Period::fromPreset('custom', '2026-10-12', '2026-10-18', CarbonImmutable::parse('2026-10-29')), $none, [$ad('2026-10-12', 10)], [])['totals'];
        $cards = array_column(MoneyRules::cards($now, $before, ['rate' => 0.5, 'margin' => 20.0], [['usd' => 120.5], ['usd' => 30]], false, $none, 'texto'), null, 'key');

        expect($cards['cost_per_order']['better'])->toBe('lower');
        expect($cards['cost_per_order']['change'])->toBe(-50.0); // de $10 a $5 por pedido
        expect($cards['pending_profit']['value'])->toBe(10.0);   // 1 pendiente × 50 % × $20
        expect($cards['pending_profit']['compare'])->toBeFalse();
        expect($cards['pending_profit']['extra'])->toBe('texto');
        expect($cards['receivable']['value'])->toBe(150.5);
        expect($cards['receivable']['previous'])->toBeNull();
        expect($cards['profit']['detail'])->toBe('$50,00 vendido − $0,00 costos de los pedidos − $10,00 publicidad − $0,00 gastos');

        $filtered = array_column(MoneyRules::cards($now, $before, ['rate' => null, 'margin' => null], [], false, new Filters(null, 10)), null, 'key');
        expect($filtered['profit']['label'])->toBe('Ganancia antes de gastos generales');
        expect($filtered['pending_profit']['value'])->toBeNull();
    });
});

describe('A dónde se fue el dinero', function () use ($week, $order, $ad, $expense, $none) {
    it('la cascada va de lo vendido a la ganancia y cuadra', function () use ($week, $order, $ad, $expense, $none) {
        $t = MoneyRules::compute([$order('2026-10-19 09:00:00', 'delivered', 100, 30, 10, 5)], $week, $none, [$ad('2026-10-19', 20)], [$expense('2026-10-19', 7)])['totals'];
        $steps = array_column(MoneyRules::waterfall($t, $none), 'amount', 'key');
        expect($steps)->toBe(['revenue' => 100.0, 'product' => 30.0, 'commissions' => 10.0, 'shipping' => 5.0, 'ads' => 20.0, 'expenses' => 7.0, 'profit' => 28.0]);
    });

    it('la ganancia por producto resta su publicidad, y avisa de los productos sin costo', function () use ($week, $order, $ad, $none) {
        $r = MoneyRules::compute([
            $order('2026-10-19 09:00:00', 'delivered', 100, 30, 10, 5),
            $order('2026-10-19 10:00:00', 'delivered', 40, 0, 5, 5, ['product_id' => 2, 'cogs_source' => 'catalog', 'cogs_missing_lines' => 1]),
        ], $week, $none, [$ad('2026-10-19', 20), $ad('2026-10-20', 15, 3, 10)], []);
        $byProduct = array_column($r['products'], 'profit', 'product_id');
        expect($byProduct[1])->toBe(35.0);  // 100 − 45 − 20
        expect($byProduct[2])->toBe(30.0);
        expect($byProduct[3])->toBe(-15.0); // solo publicidad, sin ventas
        $warnings = MoneyRules::warnings($r['totals'], $r['checks']);
        expect($warnings[0])->toContain('1 producto entregado no tiene costo')->toContain('Registra su compra en Inventario');
        expect($warnings[1])->toContain('1 pedido entregado usa el costo de la última compra registrada');
    });
});
