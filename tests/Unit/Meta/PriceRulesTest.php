<?php

use App\Services\Meta\Changes\PriceChangeRules;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\PriceRules;

// .140 (dueño, 2026-11-12): "que el sistema me recomiende bajarle de precio a un producto… si vemos que el CPA de Facebook
// objetivo es muy alto debido a que el porcentaje de efectividad de ese producto es bueno… para tratar de ganar más volumen sin
// sacrificar ni CPA ni rentabilidad". Eligió: solo un producto Bueno con el CPA real 40 % o más debajo del C, un escalón de
// hasta 10 %, y después "si al final por esa decisión estamos ganando más dinero o no". FlexiStep, semana del 17 al 23-10:
// $23,14, 92 entregas por 195 compras de Meta, gastos fijos 10 % y utilidad 20 % de lo vendido: C $5,57.

function flexiVersion(array $cambios = []): array
{
    return array_merge(['target' => 5.57, 'orders_delivered' => 92, 'purchases' => 195, 'fixed_pct' => 10, 'target_type' => 'sales', 'target_pct' => 20], $cambios);
}

function bajarPrecio(array $cambios = [], array $s = []): ?array
{
    return PriceRules::suggest(array_merge(['price' => 23.14, 'version' => flexiVersion(), 'cpa' => 3.30, 'purchases' => 40.0, 'worth' => 'good', 'mode' => 'normal',
        'phase' => 'iterate', 'recent' => false, 'dismissed' => false], $cambios), array_merge(DecisionSettings::defaults(), $s));
}

describe('.140: el C con un precio más bajo', function () {
    it('baja lo que deja cada entrega (menos la utilidad y los gastos fijos), por las entregas que trae cada compra', function () {
        // $2,14 menos por entrega × 70 % × 92/195 = $0,71 menos de C
        expect(PriceRules::newTarget(flexiVersion(), 2.14))->toBe(4.86)
            // con la utilidad sobre el costo, la utilidad no baja con el precio: baja más el C
            ->and(PriceRules::newTarget(flexiVersion(['target_type' => 'cost']), 2.14))->toBe(4.66)
            ->and(PriceRules::newTarget(flexiVersion(['orders_delivered' => 0]), 2.14))->toBeNull()
            ->and(PriceRules::newTarget(flexiVersion(['target' => null]), 2.14))->toBeNull();
    });
});

describe('.140: cuándo se recomienda bajar el precio', function () {
    it('Bueno y con el CPA real 40 % o más debajo del C: un escalón de hasta 10 %, en dólares enteros', function () {
        expect(bajarPrecio())->toBe(['from' => 23.14, 'to' => 21.0, 'c' => 5.57, 'c_new' => 4.86, 'cpa' => 3.3, 'room' => 41, 'room_new' => 47]);
    });

    it('nunca tanto que el C nuevo quede a menos de 25 % encima del CPA real (aquí, con un colchón de 50 %: solo $1)', function () {
        expect(bajarPrecio(s: ['price_cushion' => 1.5])['to'])->toBe(22.0)
            ->and(bajarPrecio(s: ['price_cushion' => 1.7]))->toBeNull(); // ni con $1 menos
    });

    it('no se recomienda si falta algo', function (array $cambios) {
        expect(bajarPrecio($cambios))->toBeNull();
    })->with([
        'el CPA real está 39 % debajo (no es "muy claro")' => [['cpa' => 3.40]],
        'producto Regular' => [['worth' => 'regular']],
        'todavía sin datos' => [['worth' => 'no_data']],
        'en liquidación' => [['mode' => 'liquidation']],
        'en testeo' => [['phase' => 'testing']],
        'cambió el precio hace poco o se está midiendo' => [['recent' => true]],
        '"No estoy de acuerdo" de los últimos 14 días' => [['dismissed' => true]],
        'menos de 20 compras de Meta en 14 días' => [['purchases' => 19.0]],
        'su C todavía con pocas entregas' => [['version' => flexiVersion(['orders_delivered' => 29])]],
        'sin C calculado (estimado o del nombre de la campaña)' => [['version' => null]],
        'sin CPA real' => [['cpa' => null]],
    ]);
});

describe('.140: cuándo cambió el precio', function () {
    it('cuando dos días seguidos muestran el mismo precio nuevo', function () {
        expect(PriceChangeRules::detect(['2026-11-01' => 39.0, '2026-11-02' => 39.0, '2026-11-03' => 36.0, '2026-11-04' => 36.0, '2026-11-06' => 39.0, '2026-11-07' => 39.0]))
            ->toBe([['date' => '2026-11-03', 'from' => 39.0, 'to' => 36.0], ['date' => '2026-11-06', 'from' => 36.0, 'to' => 39.0]]);
    });

    it('un día suelto (una promoción) no cuenta, ni el último día sin confirmar, ni una diferencia de centavos', function () {
        expect(PriceChangeRules::detect(['2026-11-01' => 39.0, '2026-11-02' => 29.0, '2026-11-03' => 39.0]))->toBe([])
            ->and(PriceChangeRules::detect(['2026-11-01' => 39.0, '2026-11-02' => 39.0, '2026-11-03' => 36.0]))->toBe([])
            ->and(PriceChangeRules::detect(['2026-11-01' => 39.0, '2026-11-02' => 39.5, '2026-11-03' => 39.5]))->toBe([]);
    });
});

/** 7 días con $orders pedidos por día ($delivered entregados a $price), $10 de costo por pedido y $20 de Meta con $buys compras. */
function semanaDePrecio(string $from, int $orders, int $delivered, float $price, float $buys): array
{
    $o = [];
    $m = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("{$from} +{$i} days"));
        $o[$d] = ['created' => $orders, 'delivered' => $delivered, 'revenue' => $delivered * $price, 'variable' => $orders * 10.0];
        $m[$d] = ['spend' => 20.0, 'purchases' => $buys];
    }

    return [$o, $m];
}

describe('.140: si con el precio nuevo se gana más dinero', function () {
    $antes = PriceChangeRules::numbers(...semanaDePrecio('2026-11-01', 5, 3, 39.0, 5.0), ...['2026-11-01', '2026-11-07']);

    it('los números de una semana: pedidos por día, efectividad, CPA y la ganancia por día en dólares', function () use ($antes) {
        // 3 × $39 − 5 × $10 − $20 = $47 por día
        expect($antes)->toMatchArray(['orders' => 35, 'orders_day' => 5.0, 'effectiveness' => 60.0, 'cpa' => 4.0, 'gain_day' => 47.0]);
    });

    it('la hipótesis, con lo que ganaba antes', function () use ($antes) {
        expect(PriceChangeRules::hypothesis(39.0, 36.0, $antes, 7))->toBe([
            'hypothesis' => 'Si bajo el precio de $39,00 a $36,00, entran más pedidos y gano más dólares por día que antes (ganabas $47,00 por día, con 5 pedidos por día).',
            'criterion' => 'Se cumple si en los 7 días después, con esos pedidos ya entregados, gano más dólares por día que en los 7 días antes.']);
    });

    it('se cumplió: con $36 entran 6 pedidos por día y deja más', function () use ($antes) {
        // 4 × $36 − 6 × $10 − $20 = $64 por día
        $despues = PriceChangeRules::numbers(...semanaDePrecio('2026-11-08', 6, 4, 36.0, 6.0), ...['2026-11-08', '2026-11-14']);
        expect(PriceChangeRules::measure(39.0, 36.0, '2026-11-08', $antes, $despues, '2026-11-15', '2026-11-20', 7, []))->toBe(['result' => 'met',
            'text' => 'Se cumplió: ganas $17,00 más por día (con $36,00, ganas $64,00 por día; antes, con $39,00, ganabas $47,00). 6 pedidos por día (antes 5), '
                . 'efectividad 66,7 % (antes 60 %), CPA $3,33 (antes $4,00).']);
    });

    it('no se cumplió: vuelve al precio de antes, y avisa si hubo otros cambios grandes esos días', function () use ($antes) {
        // 3 × $36 − 5 × $10 − $20 = $38 por día
        $despues = PriceChangeRules::numbers(...semanaDePrecio('2026-11-08', 5, 3, 36.0, 5.0), ...['2026-11-08', '2026-11-14']);
        expect(PriceChangeRules::measure(39.0, 36.0, '2026-11-08', $antes, $despues, '2026-11-15', '2026-11-20', 7, ['subiste el presupuesto en Carabobo (10/11)'])['text'])
            ->toBe('No se cumplió: ganas $9,00 menos por día (con $36,00, ganas $38,00 por día; antes, con $39,00, ganabas $47,00). 5 pedidos por día (antes 5), '
                . 'efectividad 60 % (antes 60 %), CPA $4,00 (antes $4,00). Vuelve a $39,00. Ojo: en esos días también subiste el presupuesto en Carabobo (10/11).');
    });

    it('mientras esos pedidos no se hayan entregado: midiendo, con lo que va', function () use ($antes) {
        $despues = PriceChangeRules::numbers(...semanaDePrecio('2026-11-08', 6, 1, 36.0, 6.0), ...['2026-11-08', '2026-11-14']);
        expect(PriceChangeRules::measure(39.0, 36.0, '2026-11-08', $antes, $despues, '2026-11-10', '2026-11-15', 7, []))->toBe(['result' => 'measuring',
            'text' => 'Midiendo: el resultado sale cuando los pedidos de esos 7 días ya se hayan entregado (hacia el 19/11). Por ahora: 6 pedidos por día (antes 5).']);
    });
});
