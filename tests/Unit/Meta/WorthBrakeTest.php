<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\CampaignRules;

// .145 (dueño, 2026-11-14: "sí, hazla"): mientras un producto sea Malo, la IA frena su gasto en cada ciudad. ZenPulse quemaba
// $55 por día (el 64 % de su gasto) y era Malo desde el 10/11 (42,6 % en 94 pedidos); sus ciudades seguían esperando un
// batch que la .139 ya no deja pedir. ZenPulse Carabobo: C $2,01, BE $3,67.

$malo = ['effectiveness' => 42.6, 'orders' => 94];
$zen = fn (array $in) => (new CampaignRules(DecisionSettings::defaults()))->action(array_merge(['c' => 2.01, 'be' => 3.67, 'mode' => 'normal', 'budget' => 41.4,
    'cpa7' => 3.5, 'freq3' => 1.3], $in));

describe('.145: un producto Malo frena su gasto', function () use ($malo, $zen) {
    it('ZenPulse Carabobo el 10/11 ($108,20 en 3 días con 26 compras, $4,16 por compra: encima del BE): pausar la ciudad de una vez', function () use ($malo, $zen) {
        $a = $zen(['spend3' => 108.2, 'purchases3' => 26, 'worth_bad' => $malo]);

        expect($a['key'])->toBe('pause_city')
            ->and($a['reading'])->toBe('Producto Malo (efectividad 42,6 % en sus últimos 94 pedidos): no van a llegar batches nuevos. Aquí está en el break-even o encima.')
            ->and($a['proposal'])->toBe('Pausar la ciudad. Si decides seguir con el producto, marca «No estoy de acuerdo» en su misión.')
            ->and($a['worth'])->toBe($malo);
    });

    it('entre C y BE: bajar 20 %, una vez cada 3 días cerrados', function () use ($malo, $zen) {
        $a = $zen(['spend3' => 90.0, 'purchases3' => 30, 'worth_bad' => $malo]);  // $3 por compra
        expect($a)->toMatchArray(['key' => 'decrease', 'from' => 41.4, 'to' => 33.12])
            ->and($a['proposal'])->toBe('Bajar 20 %: $41,40 → $33,12.');

        $waiting = $zen(['spend3' => 90.0, 'purchases3' => 30, 'budget' => 33.12, 'worth_bad' => $malo,
            'raise' => ['status' => 'waiting', 'raise' => false, 'date' => '2026-11-10', 'from' => 41.4, 'to' => 33.12, 'days' => 3, 'closed' => 1, 'until' => '2026-11-14']]);
        expect($waiting['key'])->toBe('hold')->and($waiting['reading'])->toEndWith('El 10/11 bajaste el presupuesto ($41,40 → $33,12): una subida a la vez. Se puede volver a subir el 14/11, con 3 días cerrados.');
    });

    it('debajo de C todavía paga: se mantiene, y nunca se sube', function () use ($malo, $zen) {
        $a = $zen(['spend3' => 45.0, 'purchases3' => 30, 'worth_bad' => $malo]);  // $1,50 por compra: con un Bueno, se subiría

        expect($a['key'])->toBe('hold')->and($a['reading'])->toEndWith('Aquí todavía paga (debajo de C), pero no se sube.')
            ->and($zen(['spend3' => 45.0, 'purchases3' => 30])['key'])->toBe('scale');
    });

    it('sin la etiqueta de Malo (Bueno, Regular, o "No estoy de acuerdo"), las reglas de siempre', function () use ($zen) {
        expect($zen(['spend3' => 108.2, 'purchases3' => 26])['key'])->toBe('intervene');
    });
});
