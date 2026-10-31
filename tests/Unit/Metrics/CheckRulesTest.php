<?php

use App\Services\Metrics\Rules\CheckRules;

// La revisión de los números del panel (`.48a`): qué cuadra, qué tiene distinto un pedido y el resultado en una frase.

describe('compare', function () {
    it('el dinero cuadra al centavo y los pedidos exactos', function () {
        expect(CheckRules::compare('revenue', 'Monto vendido', 'money', 100.004, 100.0, '')['ok'])->toBeTrue();
        expect(CheckRules::compare('revenue', 'Monto vendido', 'money', 100.0, 100.02, '')['ok'])->toBeFalse();
        $c = CheckRules::compare('created', 'Pedidos creados', 'count', 10, 11, '');
        expect($c['ok'])->toBeFalse();
        expect($c['diff'])->toBe(1);
    });

    it('la diferencia es segundo camino menos panel', function () {
        expect(CheckRules::compare('ads', 'Publicidad', 'money', 50.0, 45.5, '')['diff'])->toBe(-4.5);
    });
});

describe('orderDiff', function () {
    $row = ['outcome' => 'delivered', 'kind' => 'normal', 'product_id' => 3, 'city_id' => 1, 'created_date' => '2026-10-05',
        'delivered_date' => '2026-10-07', 'revenue_main' => 40.0, 'revenue_upsell' => 10.0, 'revenue_refunded' => 0.0, 'cogs_main' => 8.0,
        'cogs_upsell' => 2.0, 'logistics_cost' => 0.5, 'delivery_cost' => 3.0, 'commission_seller' => 1.0, 'commission_upsell' => 1.0,
        'commission_leader' => 0.1, 'commission_deliverer' => 0.0, 'commission_other' => 0.0];

    it('un pedido igual no tiene diferencias', function () use ($row) {
        expect(CheckRules::orderDiff($row, $row))->toBe(['fields' => [], 'profit_change' => 0.0]);
    });

    it('las fechas se comparan sin la hora y los números de la base como texto', function () use ($row) {
        $stored = ['created_date' => '2026-10-05 00:00:00', 'product_id' => '3', 'revenue_main' => '40.00'] + $row;
        expect(CheckRules::orderDiff($stored, $row)['fields'])->toBe([]);
    });

    it('dice qué cambió y cuánto cambia su ganancia', function () use ($row) {
        $fresh = ['commission_seller' => 3.0, 'outcome' => 'not_delivered'] + $row;
        $d = CheckRules::orderDiff($row, $fresh);
        expect($d['fields'])->toBe(['outcome', 'commission_seller']);
        expect($d['profit_change'])->toBe(-2.0);
    });

    it('la ganancia de un pedido es vendido menos devuelto menos sus costos', function () use ($row) {
        expect(CheckRules::orderProfit($row))->toBe(34.4); // 50 − 8 − 2 − 0,5 − 3 − 1 − 1 − 0,1
    });
});

describe('verdict', function () {
    $ok = CheckRules::compare('created', 'Pedidos creados', 'count', 5, 5, '');
    $bad = CheckRules::compare('ads', 'Publicidad', 'money', 50.0, 45.0, '');
    $none = ['checked' => 10, 'pending' => 0, 'different' => 0];

    it('todo cuadra sin diferencias ni datos graves', function () use ($ok, $none) {
        $info = CheckRules::problem('provisional_cost', CheckRules::INFO, 'provisional', 4);
        $v = CheckRules::verdict([$ok], $none, [$info]);
        expect($v['ok'])->toBeTrue();
        expect($v['text'])->toStartWith('Todo cuadra');
    });

    it('un aviso no cambia el resultado; un dato grave sí', function () use ($ok, $none) {
        expect(CheckRules::verdict([$ok], $none, [CheckRules::problem('zero_price', CheckRules::WARNING, 'x', 1)])['ok'])->toBeTrue();
        $v = CheckRules::verdict([$ok], $none, [CheckRules::problem('no_cost', CheckRules::GRAVE, 'x', 3)]);
        expect($v['ok'])->toBeFalse();
        expect($v['text'])->toBe('1 dato hace que un número salga mal.');
    });

    it('nombra los números que no cuadran y los pedidos distintos', function () use ($bad, $none) {
        $v = CheckRules::verdict([$bad], ['different' => 2] + $none, []);
        expect($v['text'])->toBe('1 número no cuadra (Publicidad); 2 pedidos tienen guardado algo distinto de lo que da calcularlo ahora.');
    });

    it('si había pedidos recalculándose, pide correrlo otra vez', function () use ($bad, $none) {
        $v = CheckRules::verdict([$bad], ['pending' => 3] + $none, []);
        expect($v['text'])->toContain('3 pedidos se estaban recalculando; vuelve a correrlo en unos minutos.');
    });

    it('un problema sin casos no cuenta', function () use ($ok, $none) {
        expect(CheckRules::verdict([$ok], $none, [CheckRules::problem('no_cost', CheckRules::GRAVE, 'x', 0)])['ok'])->toBeTrue();
    });
});
