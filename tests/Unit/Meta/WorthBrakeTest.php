<?php

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
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

    it('.146: el margen del 20 %: con pocas compras, apenas encima del BE baja; 20 % encima, o con 20 compras, pausa', function () use ($malo, $zen) {
        // $3,90 por compra (6 % encima del BE de $3,67) con 10 compras: baja
        $a = $zen(['spend3' => 39.0, 'purchases3' => 10, 'worth_bad' => $malo]);
        expect($a)->toMatchArray(['key' => 'decrease', 'from' => 41.4, 'to' => 33.12])
            ->and($a['reading'])->toEndWith('Aquí está apenas encima del break-even (menos de 20 % y con menos de 20 compras): baja antes de pausar.')
            // $4,50 (23 % encima) con 10 compras, o $3,90 con 20 compras: pausa
            ->and($zen(['spend3' => 45.0, 'purchases3' => 10, 'worth_bad' => $malo])['key'])->toBe('pause_city')
            ->and($zen(['spend3' => 78.0, 'purchases3' => 20, 'worth_bad' => $malo])['key'])->toBe('pause_city');
    });

    it('sin la etiqueta de Malo (Bueno, Regular, o "No estoy de acuerdo"), las reglas de siempre', function () use ($zen) {
        expect($zen(['spend3' => 108.2, 'purchases3' => 26])['key'])->toBe('intervene');
    });
});

// .146 (dueño, 2026-11-16: "por qué ahora me está mandando a apagarla antes de bajarle el presupuesto a como estaba antes"):
// ResistBarPro Aragua el 16/11. Era Malo (44 % en 75 pedidos, por Carabobo: 28 %); Aragua entregaba el 61,5 %. El 14/11 la
// misión "Escalar" la subió de $25 a $27,50; el 16/11, con $75,83 y 11 compras en 3 días ($6,89, 7 % encima del BE de $6,45,
// 1 día encima), la .145 la mandaba a pausar. A $25 había dejado +$76 en 7 días.
describe('.146: primero se devuelve la subida', function () {
    $aragua = fn (array $in) => (new CampaignRules(DecisionSettings::defaults()))->action(array_merge(['c' => 4.13, 'be' => 6.45, 'mode' => 'normal', 'budget' => 27.5,
        'spend3' => 75.83, 'purchases3' => 11, 'cpa7' => 5.12, 'freq3' => 1.3, 'worth_bad' => ['effectiveness' => 44.0, 'orders' => 75]], $in));
    $subida = ['status' => 'waiting', 'raise' => true, 'date' => '2026-11-14', 'from' => 25.0, 'to' => 27.5, 'days' => 3, 'closed' => 1, 'until' => '2026-11-18'];

    it('ResistBarPro Aragua el 16/11: dos días después de subir, devolver la subida ($27,50 → $25), no pausar', function () use ($aragua, $subida) {
        $a = $aragua(['raise' => $subida]);

        expect($a)->toMatchArray(['key' => 'undo_raise', 'from' => 27.5, 'to' => 25.0, 'worth' => ['effectiveness' => 44.0, 'orders' => 75]])
            ->and($a['reading'])->toBe('Producto Malo (efectividad 44 % en sus últimos 75 pedidos): no van a llegar batches nuevos. Aquí está en el break-even o encima, y el 14/11 subiste el presupuesto ($25,00 → $27,50): primero se devuelve la subida.')
            ->and($a['proposal'])->toBe('Devolver la subida: $27,50 → $25,00. Si en 3 días cerrados sigue en el break-even o encima, se frena.')
            // aunque pierda claramente ($8 por compra), primero la subida
            ->and($aragua(['raise' => $subida, 'spend3' => 88.0])['key'])->toBe('undo_raise')
            // una subida que no aguantó (la revisión de la .144), también
            ->and($aragua(['raise' => ['status' => 'fell', 'before' => 10.9, 'after' => -1.7] + $subida])['key'])->toBe('undo_raise');
    });

    it('después de devolverla (o de bajar), se esperan los 3 días cerrados antes del siguiente paso', function () use ($aragua) {
        $a = $aragua(['budget' => 25.0, 'raise' => ['status' => 'waiting', 'raise' => false, 'date' => '2026-11-16', 'from' => 27.5, 'to' => 25.0, 'days' => 3, 'closed' => 0, 'until' => '2026-11-20']]);

        expect($a['key'])->toBe('hold')
            ->and($a['reading'])->toEndWith('Aquí está en el break-even o encima. El 16/11 bajaste el presupuesto ($27,50 → $25,00): se espera hasta el 20/11, con 3 días cerrados, antes del siguiente paso.');
    });

    it('sin subida reciente: apenas encima baja 20 %; si la subida aguantó, lo mismo', function () use ($aragua, $subida) {
        expect($aragua([]))->toMatchArray(['key' => 'decrease', 'from' => 27.5, 'to' => 22.0])
            ->and($aragua(['raise' => ['status' => 'held', 'before' => 10.9, 'after' => 10.2] + $subida])['key'])->toBe('decrease');
    });

    it('la misión dice cuánto baja y contra qué límite', function () use ($aragua, $subida) {
        $a = $aragua(['raise' => $subida]);
        $m = collect(UrgentList::build([['product_id' => 1178, 'product' => 'ResistBarPro® tonifica tu cuerpo', 'mode' => 'normal', 'urgency' => null, 'money_at_risk' => 0,
            'alarms' => [], 'creatives' => [], 'stop_by_city' => [], 'stop_all' => [], 'stock_out' => [], 'rests' => [], 'next_angle' => null, 'no_city' => null,
            'drop' => null, 'worth_bad' => null, 'price_down' => null, 'price_watch' => null,
            'cities' => [['campaign_meta_id' => '1', 'campaign' => 'Resistbarpro / maracay', 'city' => 'Aragua', 'action' => $a + ['label' => '', 'tone' => 'error'],
                'notes' => [], 'budget_notes' => [], 'numbers' => ['spend3' => 75.83, 'purchases3' => 11, 'c' => 4.13, 'be' => 6.45, 'budget' => 27.5, 'streak' => 1]]]]]))
            ->firstWhere('kind', 'undo_raise');

        expect($m)->toMatchArray(['group' => 'frenar', 'title' => 'Devuelve la subida: baja el presupuesto a $25,00 por día'])
            ->and($m['why'])->toBe('−9 % · $6,89 por compra; tu límite es $6,45 · producto Malo');
    });
});

describe('.146: la ciudad que entrega bien', function () {
    // ResistBarPro el 16/11: Distrito Capital 18 de 37, Carabobo 7 de 25, Aragua 8 de 13 (y una con 37,5 % en 40)
    $worth = ['cities' => [['city_id' => 1, 'city' => 'Distrito Capital', 'orders' => 37, 'delivered' => 18], ['city_id' => 2, 'city' => 'Carabobo', 'orders' => 25, 'delivered' => 7],
        ['city_id' => 3, 'city' => 'Aragua', 'orders' => 13, 'delivered' => 8], ['city_id' => 4, 'city' => 'Lara', 'orders' => 40, 'delivered' => 15]]];

    it('con 30 pedidos o más y 40 % o más (.147), sigue las reglas de siempre; con menos pedidos, o entregando menos, el freno del producto', function () use ($worth) {
        $s = DecisionSettings::defaults();

        expect(MetaDecisions::cityDelivers($worth, 1, $s))->toMatchArray(['city' => 'Distrito Capital', 'orders' => 37, 'effectiveness' => 48.6])
            ->and(MetaDecisions::cityDelivers($worth, 2, $s))->toBeNull()     // 28 %
            ->and(MetaDecisions::cityDelivers($worth, 3, $s))->toBeNull()     // 61,5 %, pero solo 13 pedidos
            ->and(MetaDecisions::cityDelivers($worth, 4, $s))->toBeNull()     // 37,5 % en 40
            ->and(MetaDecisions::cityDelivers($worth, null, $s))->toBeNull();
    });
});
