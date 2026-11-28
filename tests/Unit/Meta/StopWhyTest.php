<?php

use App\Services\Meta\Analysis\StopWhy;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\AdRules;

// .156 (dueño, 2026-11-18, al ver "Apaga CRE003-FLEX-FAS-BATCH004: aquí pierde" sin números: "quiero que me expliques el
// porqué de esta recomendación"; "ármame la ficha de los números en el porqué").

function reglas156(): AdRules
{
    return new AdRules(DecisionSettings::defaults());
}

/** El caso de verdad (18/11): FlexiStep en Distrito Capital, C $4,65 y BE $7,03. */
function flexiStep156(): array
{
    $prometedor = ['key' => 'promising', 'label' => 'Prometedor'];

    return reglas156()->recentLoser(['spend' => 4.71, 'purchases' => 0], $prometedor, 4.65, 7.03, ['spend' => 9.34, 'purchases' => 0]);
}

describe('Las reglas guardan sus números', function () {
    it('CRE003-FLEX-FAS-BATCH004: se miró la semana porque en 3 días no llegó a 2×C', function () {
        $v = flexiStep156();

        expect($v['key'])->toBe('loser')
            ->and($v['why'])->toMatchArray(['rule' => 'recent', 'days' => 7, 'spend' => 9.34, 'purchases' => 0.0, 'c' => 4.65, 'be' => 7.03,
                'estimated' => false, 'min' => 2.0, 'life' => 'prometedor', 'spend3' => 4.71, 'week' => null]);
    });

    it('toda su vida: 2×C con 0 o 1 compra, o 3×C encima del BE', function () {
        expect(reglas156()->state(10.0, 1, 4.0, 7.0, 'creativo')['why'])->toMatchArray(['rule' => 'life_spend', 'days' => null, 'spend' => 10.0, 'purchases' => 1.0])
            ->and(reglas156()->state(30.0, 3, 4.0, 7.0, 'creativo')['why'])->toMatchArray(['rule' => 'life_be', 'spend' => 30.0, 'purchases' => 3.0])
            ->and(reglas156()->state(30.0, 8, 4.0, 7.0, 'creativo'))->not->toHaveKey('why'); // prometedor: no se apaga
    });

    it('3 días malos: guarda su semana (para decir por qué no lo salvó)', function () {
        $v = reglas156()->recentLoser(['spend' => 12.0, 'purchases' => 0], ['key' => 'intermediate', 'label' => 'Intermedio'], 4.0, 7.0, ['spend' => 20.0, 'purchases' => 2]);

        expect($v['why'])->toMatchArray(['rule' => 'recent', 'days' => 3, 'week' => ['spend' => 20.0, 'purchases' => 2.0], 'life' => 'intermedio']);
    });

    it('el ganador que falla (.119)', function () {
        $v = reglas156()->failingStop(['key' => 'failing', 'label' => 'Ganador que falla', 'reading' => 'Fue ganador.'], ['spend' => 15.0, 'purchases' => 0], 4.0, 7.0);

        expect($v['stop'])->toBeTrue()->and($v['why'])->toMatchArray(['rule' => 'failing', 'days' => 3, 'spend' => 15.0, 'cap' => 3.0]);
    });
});

describe('En palabras', function () {
    it('la línea corta debajo del título', function () {
        expect(StopWhy::short(flexiStep156()['why']))->toBe('En 7 días gastó $9,34 (2×C) sin compras')
            ->and(StopWhy::short(reglas156()->state(10.0, 1, 4.0, 7.0)['why']))->toBe('Gastó $10,00 (2,5×C) con 1 compra')
            ->and(StopWhy::short(reglas156()->state(30.0, 3, 4.0, 7.0)['why']))->toBe('Gastó $30,00 (7,5×C) con 3 compras: CPA $10,00, encima de tu BE ($7,00)');
    });

    it('el porqué de CRE003-FLEX-FAS-BATCH004: sus números, tu regla y que con esto queda apagado del todo', function () {
        expect(StopWhy::lines('CRE003-FLEX-FAS-BATCH004', 'Distrito Capital', flexiStep156()['why']))->toBe([
            'CRE003-FLEX-FAS-BATCH004 en Distrito Capital, últimos 7 días: gastó $9,34 (2×C) y no tuvo compras. Tu C ahí es $4,65 y tu BE $7,03.',
            'En toda su vida ahí iba bien (prometedor), pero tu regla apaga un creativo que en una ciudad gasta 2 veces tu C sin vender, o vendiendo con el costo '
                . 'por compra más de un 10 % encima de tu BE. Se miró la semana porque en 3 días gastó solo $4,71 y no llegó a 2×C.',
        ])
            ->and(StopWhy::others('Distrito Capital', [['city' => 'Distrito Capital', 'active' => true], ['city' => 'Carabobo', 'active' => false],
                ['city' => 'Aragua', 'active' => false]]))->toBe('Ya está apagado en Carabobo y Aragua: con esto queda apagado del todo.')
            ->and(StopWhy::others('Distrito Capital', [['city' => 'Distrito Capital', 'active' => true], ['city' => 'Aragua', 'active' => true]]))
            ->toBe('En Aragua sigue prendido: ahí no lo toques.')
            ->and(StopWhy::others('Distrito Capital', [['city' => 'Distrito Capital', 'active' => true]]))->toBeNull()
            // .183: donde no está activo y no perdió, no se probó
            ->and(StopWhy::others('Carabobo', [['city' => 'Carabobo', 'active' => true, 'key' => 'loser'],
                ['city' => 'Distrito Capital', 'active' => false, 'key' => 'no_data']]))->toBe('En Distrito Capital no está activo y no se probó: no lo toques.')
            ->and(StopWhy::others('Carabobo', [['city' => 'Carabobo', 'active' => true, 'key' => 'loser'], ['city' => 'Aragua', 'active' => true, 'key' => 'no_data'],
                ['city' => 'Distrito Capital', 'active' => false, 'key' => 'no_data'], ['city' => 'Lara', 'active' => false, 'key' => 'loser']]))
            ->toBe('En Aragua sigue prendido: ahí no lo toques. En Distrito Capital no está activo y no se probó: no lo toques.');
    });

    it('.187: por qué "Vigila" no lo protegió: la campaña no está sana, o lo está pero el creativo no vende o vende muy caro', function () {
        $intermedio = ['key' => 'intermediate', 'label' => 'Intermedio'];
        $mal = reglas156()->recentLoser(['spend' => 12.0, 'purchases' => 0], $intermedio, 4.0, 7.0, ['spend' => 20.0, 'purchases' => 4], false, ['spend' => 100.0, 'purchases' => 20]);
        expect(StopWhy::lines('C0111 FASP', 'Aragua', $mal['why'])[2])
            ->toBe('La campaña no está sana: en 3 días va a $5,00 por compra (sana hasta $4,40, un 10 % encima de tu C). Por eso se apagan sus creativos en el BE, para bajarle el CPA.');
        $caro = reglas156()->recentLoser(['spend' => 12.0, 'purchases' => 0], $intermedio, 4.0, 7.0, ['spend' => 40.0, 'purchases' => 4], false, ['spend' => 100.0, 'purchases' => 25]);
        expect(StopWhy::lines('C0111 FASP', 'Aragua', $caro['why'])[2])
            ->toBe('La campaña está sana ($4,00 por compra), pero este creativo en su semana vende a $10,00, más de un 20 % encima de tu BE ($8,40): por eso se apaga igual.');
        $nada = reglas156()->recentLoser(['spend' => 12.0, 'purchases' => 0], $intermedio, 4.0, 7.0, ['spend' => 12.0, 'purchases' => 0], false, ['spend' => 100.0, 'purchases' => 25]);
        expect(StopWhy::lines('C0111 FASP', 'Aragua', $nada['why'])[2])->toEndWith('pero este creativo en su semana no vendió: por eso se apaga igual.');
    });

    it('los análisis guardados antes de la .187 siguen diciendo lo suyo', function () {
        $v = reglas156()->recentLoser(['spend' => 12.0, 'purchases' => 0], ['key' => 'intermediate', 'label' => 'Intermedio'], 4.0, 7.0, ['spend' => 20.0, 'purchases' => 2]);
        $antes = array_diff_key($v['why'], ['shield' => true]) + ['watch' => 5];
        expect(StopWhy::lines('C0111 FASP', 'Aragua', $antes)[2])
            ->toBe('En su semana gastó $20,00 con 2 compras (CPA $10,00): para no apagarlo hacían falta 5 compras en 7 días con el costo por compra en tu BE o debajo.')
            ->and(StopWhy::lines('C0111 FASP', 'Aragua', $antes + ['watch_pct' => 10.0])[2])->toEndWith('como mucho un 10 % encima de tu C.')
            ->and(StopWhy::lines('C0111 FASP', 'Aragua', $antes + ['watch_mid' => true])[2])->toEndWith('en el punto medio entre tu C y tu BE ($5,50) o debajo.')
            // sin saber cómo iba la campaña ni la semana, no hay línea de más
            ->and(StopWhy::lines('C0111 FASP', 'Aragua', array_diff_key($v['why'], ['shield' => true])))->toHaveCount(2);
    });

    it('cada regla tiene su frase', function () {
        expect(StopWhy::lines('C1', 'Aragua', reglas156()->state(10.0, 1, 4.0, 7.0)['why'])[1])
            ->toBe('Tu regla: un creativo que en una ciudad gasta 2 veces tu C con 0 o 1 compra se apaga ahí.')
            ->and(StopWhy::lines('C1', 'Aragua', reglas156()->state(30.0, 3, 4.0, 7.0)['why'])[1])
            ->toBe('Tu regla: con 2 compras o más se le da hasta 3 veces tu C; si llega con el costo por compra encima de tu BE (más de un 10 %, o con 10 compras o más), se apaga ahí.')
            ->and(StopWhy::lines('C1', 'Aragua', reglas156()->failingStop(['key' => 'failing', 'label' => 'x', 'reading' => ''], ['spend' => 15.0, 'purchases' => 0], 4.0, 7.0)['why'])[1])
            ->toBe('Fue ganador, pero tu regla apaga en una ciudad a un ganador que gasta 3 veces tu C sin vender, o vendiendo con el costo por compra más de un 10 % encima de tu BE. Se sigue iterando en batches nuevos.');
    });

    it('con un BE estimado lo dice', function () {
        $v = reglas156()->recentLoser(['spend' => 12.0, 'purchases' => 0], ['key' => 'intermediate', 'label' => 'Intermedio'], 4.0, 7.0, ['spend' => 30.0, 'purchases' => 6], true,
            ['spend' => 100.0, 'purchases' => 25]);

        expect(StopWhy::lines('C1', 'Aragua', $v['why'])[0])->toEndWith('Tu C ahí es $4,00 y tu BE $7,00 (estimado).')
            ->and(StopWhy::lines('C1', 'Aragua', $v['why'])[2])->toBe('Con un BE estimado, la campaña no lo protege.');
    });

    it('perdedor en la única ciudad donde se probó: su regla completa', function () {
        $r = StopWhy::everywhere('CREATIVO 6', ['Caracas' => reglas156()->state(108.64, 0, 11.52, 16.31)['why']]);

        expect($r['short'])->toBe('Gastó $108,64 (9,4×C) sin compras')
            ->and($r['lines'])->toBe([
                'CREATIVO 6 en Caracas, en toda su vida ahí: gastó $108,64 (9,4×C) y no tuvo compras. Tu C ahí es $11,52 y tu BE $16,31.',
                'Tu regla: un creativo que en una ciudad gasta 2 veces tu C con 0 o 1 compra se apaga ahí.',
                'Es la única ciudad donde se probó: se apaga en todas las campañas.',
            ]);
    });

    it('perdedor en todas: sus números en cada ciudad', function () {
        $r = StopWhy::everywhere('C0200 GYMF', ['Aragua' => reglas156()->state(10.0, 0, 4.0, 7.0)['why'], 'Carabobo' => reglas156()->state(9.0, 1, 4.5, 7.5)['why']]);

        expect($r['short'])->toBe('Pierde en 2 ciudades')
            ->and($r['lines'])->toBe([
                'C0200 GYMF en Aragua: gastó $10,00 (2,5×C) sin compras. Tu C ahí es $4,00 y tu BE $7,00.',
                'C0200 GYMF en Carabobo: gastó $9,00 (2×C) con 1 compra. Tu C ahí es $4,50 y tu BE $7,50.',
                'Tu regla: un creativo que pierde en todas las ciudades donde se probó se apaga en todas las campañas.',
            ]);
    });
});

describe('En la misión de apagar', function () {
    $producto = fn (array $whys, array $all = []) => ['product_id' => 7, 'product' => 'FlexiStep plantillas', 'mode' => 'normal', 'urgency' => null,
        'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [], 'creatives' => [],
        'batches' => [], 'stop_by_city' => ['Distrito Capital' => array_keys($whys)], 'stop_all' => array_keys($all), 'stop_why' => ['Distrito Capital' => $whys],
        'stop_all_why' => $all];

    it('debajo del título, la razón corta; en el porqué, los números, tu regla y las otras ciudades', function () use ($producto) {
        $why = flexiStep156()['why'];
        $items = UrgentList::build([$producto(['CRE003-FLEX-FAS-BATCH004' => ['short' => StopWhy::short($why),
            'lines' => StopWhy::lines('CRE003-FLEX-FAS-BATCH004', 'Distrito Capital', $why),
            'others' => 'Ya está apagado en Carabobo y Aragua: con esto queda apagado del todo.']])]);
        $m = collect($items)->firstWhere('kind', 'stop_creatives');

        expect($m['title'])->toBe('Apaga CRE003-FLEX-FAS-BATCH004: aquí pierde')
            ->and($m['why'])->toBe('En 7 días gastó $9,34 (2×C) sin compras')
            ->and($m['detail'][0])->toStartWith('CRE003-FLEX-FAS-BATCH004 en Distrito Capital, últimos 7 días: gastó $9,34')
            ->and(end($m['detail']))->toBe('Ya está apagado en Carabobo y Aragua: con esto queda apagado del todo.');
    });

    it('un análisis de antes (sin los números) sale como siempre', function () use ($producto) {
        $p = $producto([]);
        $p['stop_by_city'] = ['Distrito Capital' => ['CRE003-FLEX-FAS-BATCH004']];
        unset($p['stop_why'], $p['stop_all_why']);
        $m = collect(UrgentList::build([$p]))->firstWhere('kind', 'stop_creatives');

        expect($m['why'])->toBe('ahí pierde')
            ->and($m['detail'])->toBe(['CRE003-FLEX-FAS-BATCH004', 'Apágalo solo en Distrito Capital: en las otras ciudades no lo toques.']);
    });
});
