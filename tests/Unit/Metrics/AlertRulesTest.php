<?php

use App\Services\Metrics\Rules\AlertRules;
use App\Services\Metrics\Rules\GoalRules;

// Panel del dueño › Alertas (guias/DICCIONARIO-METRICAS.md, §6): cuándo avisa el panel solo, en amarillo o rojo.

$person = fn (string $name, int $attended, ?float $eff) => ['name' => $name, 'group' => null, 'values' => ['attended' => $attended, 'effectiveness' => $eff]];
$base = ['key' => 'attended', 'min' => 10, 'label' => 'pedidos atendidos'];
$limits = fn (?float $yellow, ?float $red = null, bool $on = true) => ['yellow' => $yellow, 'red' => $red, 'on' => $on];

describe('Alertas › vendedoras y agencias', function () use ($person, $base) {
    it('junta a quienes no llegan a su meta, lo peor primero, y deja fuera a los de pocos datos', function () use ($person, $base) {
        $goal = GoalRules::normalize(['team' => ['sellers.effectiveness' => ['value' => 55, 'red' => 45, 'people' => [4 => 70]]]])['team']['sellers.effectiveness'];
        $a = AlertRules::team('sellers.effectiveness', $goal, [
            1 => $person('Ana', 40, 62.0),
            2 => $person('Bea', 30, 50.0),
            3 => $person('Caro', 25, 40.0),
            4 => $person('Dani', 20, 60.0),   // su meta es 70 %
            5 => $person('Eli', 4, 0.0),      // pocos datos
        ], $base, 'del 18/10 al 24/10');
        expect($a['level'])->toBe('red');
        expect($a['section'])->toBe('team');
        expect($a['role'])->toBe('sellers');
        expect(array_column($a['items'], 'name'))->toBe(['Caro', 'Bea', 'Dani']);
        expect(array_column($a['items'], 'level'))->toBe(['red', 'yellow', 'yellow']);
        expect($a['items'][2]['text'])->toBe('60 % · meta 70 %');
        expect($a['items'][2]['short'])->toBe('60 %');
        expect($a['help'])->toContain('pedidos que les entraron del 18/10 al 24/10');
        expect($a['help'])->toContain('al menos 10 pedidos atendidos');
    });

    it('con pedidos en camino: avisa en gris a quien todavía puede llegar, y en color a quien ya no llega', function () use ($base) {
        $goal = GoalRules::normalize(['team' => ['sellers.effectiveness' => ['value' => 55, 'red' => 45]]])['team']['sellers.effectiveness'];
        $seller = fn (string $name, float $eff, float $best, int $open) => ['name' => $name, 'group' => null,
            'values' => ['attended' => 30, 'effectiveness' => $eff, 'effectiveness_best' => $best, 'open' => $open]];
        $a = AlertRules::team('sellers.effectiveness', $goal, [
            1 => $seller('Gigi', 19.1, 70.0, 15),   // puede llegar
            2 => $seller('Roxi', 29.3, 40.0, 3),    // ya no llega: rojo
            3 => $seller('Ana', 60.0, 60.0, 0),     // llega
        ], $base, 'en este mes');
        expect(array_column($a['items'], 'name'))->toBe(['Roxi', 'Gigi']);
        expect(array_column($a['items'], 'level'))->toBe(['red', 'pending']);
        expect($a['level'])->toBe('red');
        expect($a['items'][1]['short'])->toBe('19,1 % · puede llegar');
        expect($a['items'][1]['text'])->toBe('19,1 % · meta 55 % · todavía puede llegar (15 pedidos en camino)');
        // Solo grises: la alerta es gris
        $gray = AlertRules::team('sellers.effectiveness', $goal, [1 => $seller('Gigi', 19.1, 70.0, 15)], $base, 'hoy');
        expect($gray['level'])->toBe('pending');
    });

    it('no avisa si nadie está abajo, si no hay meta o si la alerta está apagada', function () use ($person, $base) {
        $people = [1 => $person('Ana', 40, 62.0)];
        $goal = fn (array $g) => GoalRules::normalize(['team' => ['sellers.effectiveness' => $g]])['team']['sellers.effectiveness'];
        expect(AlertRules::team('sellers.effectiveness', $goal(['value' => 55]), $people, $base, ''))->toBeNull();
        expect(AlertRules::team('sellers.effectiveness', $goal([]), [1 => $person('Ana', 40, 10.0)], $base, ''))->toBeNull();
        expect(AlertRules::team('sellers.effectiveness', $goal(['value' => 55, 'alert' => false]), [1 => $person('Ana', 40, 10.0)], $base, ''))->toBeNull();
    });

    it('en un máximo (tiempo de entrega), lo peor es lo más alto', function () {
        $goal = GoalRules::normalize(['team' => ['agencies.delivery_time' => ['value' => 24, 'red' => 48]]])['team']['agencies.delivery_time'];
        $agency = fn (string $name, float $hours) => ['name' => $name, 'group' => null, 'values' => ['received' => 30, 'delivery_time' => $hours]];
        $a = AlertRules::team('agencies.delivery_time', $goal, [1 => $agency('Centro', 30), 2 => $agency('Rápido', 52), 3 => $agency('Moto', 20)],
            ['key' => 'received', 'min' => 10, 'label' => 'pedidos recibidos'], '');
        expect(array_column($a['items'], 'name'))->toBe(['Rápido', 'Centro']);
        expect($a['items'][0]['text'])->toBe('2,2 días · máximo 24 h');
    });
});

describe('Alertas › dinero, publicidad y cancelaciones', function () use ($limits, $base) {
    it('avisa quién tiene más dinero sin entregar que el límite, de más a menos', function () use ($limits) {
        $a = AlertRules::cash('agency_cash', $limits(500, 1000), [
            ['id' => 1, 'name' => 'Centro', 'usd' => 650.0],
            ['id' => 2, 'name' => 'Rápido', 'usd' => 1200.0],
            ['id' => 3, 'name' => 'Moto', 'usd' => 500.0], // justo en el límite: no avisa
        ]);
        expect(array_column($a['items'], 'name'))->toBe(['Rápido', 'Centro']);
        expect(array_column($a['items'], 'short'))->toBe(['$1.200,00', '$650,00']);
        expect($a['level'])->toBe('red');
        expect($a['role'])->toBe('agencies');
        expect(AlertRules::cash('rider_cash', $limits(500, null, false), [['id' => 1, 'name' => 'Luis', 'usd' => 900.0]]))->toBeNull();
    });

    it('la publicidad: lo gastado ÷ (entregados + lo que se espera de lo que sigue en camino)', function () use ($limits) {
        // $300 ÷ (20 + 20 × 50 %) = $10 por venta
        $a = AlertRules::adCost($limits(8, 12), 300, 20, 20, 0.5, 'del 26/10 al 28/10');
        expect($a['level'])->toBe('yellow');
        expect($a['section'])->toBe('money');
        expect($a['items'][0]['value'])->toBe(10.0);
        expect($a['items'][0]['text'])->toContain('$10,00')->toContain('~30 ventas');
        expect($a['items'][0]['short'])->toBe('$10,00 por venta · límite $8,00');
        expect(AlertRules::adCost($limits(12), 300, 20, 20, 0.5, ''))->toBeNull();
        // Sin ventas esperadas, cuenta como una: lo gastado entero
        expect(AlertRules::adCost($limits(8, 12), 50, 0, 0, null, '')['items'][0]['value'])->toBe(50.0);
        expect(AlertRules::adCost($limits(8), 0, 0, 0, null, ''))->toBeNull();
    });

    it('las cancelaciones de los pedidos del período: desde 10 pedidos', function () use ($limits) {
        expect(AlertRules::cancellations($limits(15, 20), 9, 5, 'hoy'))->toBeNull();
        $a = AlertRules::cancellations($limits(15, 20), 40, 9, 'en este mes');
        expect($a['level'])->toBe('red');
        expect($a['items'][0]['text'])->toBe('Se cancelaron 9 de los 40 pedidos que entraron en este mes (22,5 %; límite 15 %).');
        expect($a['items'][0]['short'])->toBe('9 de 40 (22,5 %) · límite 15 %');
        expect(AlertRules::cancellations($limits(15, 20), 40, 6, 'hoy'))->toBeNull();
    });

    it('las rojas van primero, después las amarillas y al final las grises', function () use ($limits, $base) {
        $yellow = AlertRules::cancellations($limits(15, 30), 40, 8, 'hoy');
        $red = AlertRules::cash('agency_cash', $limits(100, 200), [['id' => 1, 'name' => 'Centro', 'usd' => 300.0]]);
        $goal = GoalRules::normalize(['team' => ['sellers.effectiveness' => ['value' => 55]]])['team']['sellers.effectiveness'];
        $gray = AlertRules::team('sellers.effectiveness', $goal, [1 => ['name' => 'Gigi', 'group' => null,
            'values' => ['attended' => 30, 'effectiveness' => 19.1, 'effectiveness_best' => 70.0, 'open' => 15]]], $base, 'hoy');
        expect(array_column(AlertRules::sort([$gray, $yellow, null, $red]), 'type'))->toBe(['agency_cash', 'cancellations', 'sellers.effectiveness']);
    });
});
