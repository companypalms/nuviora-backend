<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\CampaignRules;

// Etapa 5, paso 2b: qué hacer con cada campaña. C = 4 y BE = 5,70 son los del manual del dueño; los montos, los de su
// manual ($40 → $48, $40 → $32) y los de sus análisis (Caracas $39,60 → $43,56; Carabobo $25 → $30).

function campana(array $cambios = []): CampaignRules
{
    return new CampaignRules(array_merge(DecisionSettings::defaults(), $cambios));
}

function accion(array $in): array
{
    return campana()->action(array_merge(['c' => 4.0, 'be' => 5.70, 'mode' => 'normal', 'budget' => 40.0, 'spend3' => 30.0, 'purchases3' => 12,
        'cpa7' => 3.0, 'freq3' => 1.3, 'stock_ok' => true], $in));
}

describe('Escalar', function () {
    it('+20 % si el CPA de 3 días está un 25 % o más debajo de C ($40 → $48, el ejemplo del manual)', function () {
        $a = accion(['spend3' => 30, 'purchases3' => 12]);
        expect($a['key'])->toBe('scale')->and($a['to'])->toBe(48.0)->and($a['label'])->toBe('Subir $40,00 → $48,00');
    });

    it('.79: +15 % si está entre un 10 % y un 25 % debajo (Caracas $39,60 → $45,54)', function () {
        expect(accion(['budget' => 39.60, 'spend3' => 42, 'purchases3' => 12])['to'])->toBe(45.54);
    });

    it('.79: cuánto subir según qué tan debajo de C está el CPA de 3 días, nunca más del 20 % (los de hoy, 2/11)', function () {
        // FlexiStep Caracas $3,54 con C $5,39 (34 % debajo); Cervimax Caracas $3,36 con C $6,29 (47 %); ZenPulse Caracas $3,72 con C $3,96 (6 %)
        $hoy = fn (float $cpa, float $c, float $budget, int $p) => campana()->action(['c' => $c, 'be' => $c * 1.5, 'mode' => 'normal', 'budget' => $budget,
            'spend3' => $cpa * $p, 'purchases3' => $p, 'cpa7' => $cpa, 'freq3' => 1.2]);
        expect($hoy(3.54, 5.39, 51.60, 46)['to'])->toBe(61.92)
            ->and($hoy(3.36, 6.29, 30.0, 30)['to'])->toBe(36.0)
            ->and($hoy(3.72, 3.96, 30.0, 21)['to'])->toBe(33.0)
            ->and($hoy(3.36, 6.29, 30.0, 30)['reading'])->toBe('CPA 47 % debajo de C con 30 compras en 3 días.');
        // "el tope que podemos subir por 24 horas es 20 %, no más": ni cambiándolo en Reglas
        expect(CampaignRules::scalePct(1.0, 4.0, ['scale_big_pct' => 50.0] + DecisionSettings::defaults()))->toBe(20.0)
            ->and(DecisionSettings::normalize(['scale_big_pct' => 30])['scale_big_pct'])->toBe(20.0);
    });

    it('.79: el stock no frena la subida ni pide pausar: es un aviso en lo que se propone', function () {
        $a = accion(['stock_alert' => 'quedan 14 unidades (≈1,6 días de ventas): pide mercancía']);
        expect($a['key'])->toBe('scale')->and($a['proposal'])->toEndWith('Ojo: quedan 14 unidades (≈1,6 días de ventas): pide mercancía.');
        $z = accion(['spend3' => 30, 'purchases3' => 6, 'stock_alert' => 'sin stock en esta ciudad: pide al proveedor (no hace falta pausar)']);
        expect($z['key'])->toBe('hold_prune')->and($z['proposal'])->toContain('pide al proveedor (no hace falta pausar)');
        expect(accion(['mode' => 'paused', 'stock_alert' => 'sin stock'])['proposal'])->not->toContain('Ojo');
    });

    it('.79: dice cuando la C es la referencia de un producto nuevo', function () {
        expect(accion(['c_source' => 'name'])['data'])->toContain('BE $5,70 (referencia del nombre de la campaña)')
            ->and(accion(['c_source' => 'manual'])['data'])->toContain('(tu referencia)')
            ->and(accion(['c_source' => 'financial'])['data'])->not->toContain('referencia');
    });

    it('Carabobo $25 → $30', function () {
        expect(accion(['budget' => 25.0, 'spend3' => 30.8, 'purchases3' => 11])['to'])->toBe(30.0);
    });

    it('no escala sin volumen, con 7 días malos, si ya subió hoy o si entraron creativos hoy', function (array $in, string $why) {
        $a = accion($in);
        expect($a['key'])->toBe('hold')->and($a['reading'])->toContain($why);
    })->with([
        'Maracay: poco volumen' => [['purchases3' => 6, 'spend3' => 15], 'poco volumen'],
        '7 días encima del BE' => [['cpa7' => 6.0], 'Los 7 días no acompañan'],
        'ya subió' => [['increased_24h' => true], 'últimas 24 horas'],
        'creativos de hoy' => [['new_ads_today' => true], 'creativos nuevos'],
        'liquidación' => [['mode' => 'liquidation'], 'liquidación'],
        '.101: pedidos esperando stock' => [['stock_waiting' => 55], 'hay 55 pedidos esperando stock en esta ciudad: no se sube hasta que llegue'],
        '.137: la ciudad sin stock' => [['stock_out' => true], 'no hay stock en esta ciudad: no se sube hasta que llegue'],
    ]);
});

describe('.123: la subida primero', function () {
    // Dueño, 2026-11-09: "la subida de presupuesto siempre va primero antes de meterle batches"; la frecuencia, "solo aviso"
    it('la frecuencia alta ya no frena la subida: es un aviso en lo que se propone', function () {
        $a = accion(['freq3' => 1.7]);
        expect($a['key'])->toBe('scale')->and($a['to'])->toBe(48.0)
            ->and($a['proposal'])->toBe('Subir 20 %: $40,00 → $48,00. La frecuencia está alta (1,7): es solo un aviso. Vigilar el CPA.')
            ->and(accion(['freq3' => 1.3])['proposal'])->toBe('Subir 20 %: $40,00 → $48,00. Vigilar CPA y frecuencia.');
    });

    it('Solo Brillo Caracas el 9/11 (CPA $4,13 con C $8,31, frecuencia 6,3): decía "Mantener: saturada", ahora sube $106 → $127,20', function () {
        $a = campana()->action(['c' => 8.31, 'be' => 13.76, 'mode' => 'normal', 'budget' => 106.0, 'spend3' => 450.0, 'purchases3' => 109, 'cpa7' => 4.9, 'freq3' => 6.26]);
        expect($a['key'])->toBe('scale')->and($a['label'])->toBe('Subir $106,00 → $127,20')
            ->and($a['proposal'])->toContain('La frecuencia está alta (6,3): es solo un aviso.');
    });

    it('lo demás sigue frenando: los pedidos esperando stock van antes que la frecuencia', function () {
        expect(accion(['freq3' => 6.26, 'stock_waiting' => 8])['key'])->toBe('hold');
    });
});

describe('.123: entre C y BE, un cambio grande a la vez', function () {
    // Dueño, 2026-11-09: "prioriza siempre el batch nuevo, si con el batch nuevo no vuelve a la normalidad entonces sí se le baja
    // el presupuesto. Pero solo un cambio grande a la vez. Meter un batch y tocar el presupuesto son dos cambios grandes".
    // ZenPulse Caracas: C $4,44, BE $7,61, $39,60 por día; la subió el 6/11 ($33,00 → $39,60) y el 9/11 entró B0118.
    $zen = fn (array $band, array $in = []) => campana()->action(array_merge(['c' => 4.44, 'be' => 7.61, 'mode' => 'normal', 'budget' => 39.60,
        'spend3' => 117.9, 'purchases3' => 18, 'cpa7' => 4.85, 'freq3' => 1.49, 'band' => $band], $in));
    $undo = ['date' => '2026-11-06', 'from' => 33.0, 'to' => 39.6];
    $batch = fn (int $closed) => ['date' => '2026-11-09', 'name' => 'B0118', 'closed' => $closed];

    it('primero el batch nuevo, como siempre', function () use ($zen, $undo) {
        $a = $zen(['batch' => null, 'lowered' => null, 'undo' => $undo]);
        expect($a['key'])->toBe('hold_prune')->and($a['proposal'])->toBe('Mantener, podar los caros y meter creativos.')->and($a)->not->toHaveKey('ladder');
        // un análisis de antes de la .123 (sin "band") sigue igual
        expect(accion(['spend3' => 30, 'purchases3' => 6])['proposal'])->toBe('Mantener, podar los caros y meter creativos.');
    });

    it('con el batch adentro, 3 días cerrados sin otro cambio: solo se apagan los que pierden', function () use ($zen, $undo, $batch) {
        $a = $zen(['batch' => $batch(1), 'lowered' => null, 'undo' => $undo], ['prune' => ['C0457 ZEN']]);
        expect($a['key'])->toBe('hold_prune')
            ->and($a['reading'])->toBe('Rentable pero no escalable: CPA entre C y el break-even. El 09/11 entró el batch «B0118».')
            ->and($a['proposal'])->toBe('Esperar: se le dan 3 días cerrados para ver si vuelve a C. Si el 13/11 sigue encima, toca devolver la subida del 06/11: $39,60 → $33,00. Apagar: C0457 ZEN.')
            ->and($a['ladder'])->toMatchArray(['hold' => true, 'since' => '2026-11-09', 'until' => '2026-11-13', 'days' => 3]);
    });

    it('la espera de 24 horas no pisa la de los 3 días', function () use ($zen, $undo, $batch) {
        $cool = ['what' => 'entró el batch «B0118»', 'at' => '2026-11-09 07:30:00', 'until' => '2026-11-10 07:30:00', 'hours' => 24.0];
        $a = $zen(['batch' => $batch(0), 'lowered' => null, 'undo' => $undo], ['recent_change' => $cool]);
        expect($a['proposal'])->toStartWith('Esperar: se le dan 3 días cerrados')->and($a)->not->toHaveKey('cooldown');
    });

    it('a los 3 días cerrados sigue encima de C: devolver la última subida ($39,60 → $33,00)', function () use ($zen, $undo, $batch) {
        $a = $zen(['batch' => $batch(3), 'lowered' => null, 'undo' => $undo]);
        expect($a['key'])->toBe('step_down')->and($a['label'])->toBe('Devolver la subida $39,60 → $33,00')
            ->and($a['from'])->toBe(39.6)->and($a['to'])->toBe(33.0)
            ->and($a['reading'])->toBe('Pasaron 3 días cerrados desde que entró el batch «B0118» (09/11) y sigue entre C y el break-even.')
            ->and($a['proposal'])->toBe('Devolver la subida del 06/11: $39,60 → $33,00. Si vuelve a C, se sube otra vez.');
    });

    it('sin una subida reciente que devolver: otro batch (lo eligió el dueño)', function () use ($zen, $batch) {
        $a = $zen(['batch' => $batch(3), 'lowered' => null, 'undo' => null]);
        expect($a['key'])->toBe('hold_prune')->and($a)->not->toHaveKey('ladder')
            ->and($a['reading'])->toBe('Rentable pero no escalable: CPA entre C y el break-even. Pasaron 3 días cerrados desde que entró el batch «B0118» (09/11) y no hay una subida reciente que devolver.')
            ->and($a['proposal'])->toBe('Mantener, podar los caros y meter otro batch.');
        // mientras espera, lo que sigue es otro batch
        expect($zen(['batch' => $batch(2), 'lowered' => null, 'undo' => null])['proposal'])->toContain('Si el 13/11 sigue encima, toca otro batch.');
    });

    it('después de devolver la subida: otros 3 días cerrados y, si sigue igual, otro batch', function () use ($zen) {
        $low = fn (int $closed) => ['date' => '2026-11-13', 'from' => 39.6, 'to' => 33.0, 'closed' => $closed];
        $a = $zen(['batch' => null, 'lowered' => $low(1), 'undo' => null], ['budget' => 33.0]);
        expect($a['key'])->toBe('hold_prune')->and($a['ladder']['hold'])->toBeTrue()
            ->and($a['reading'])->toEndWith('El 13/11 bajaste el presupuesto de $39,60 a $33,00.')
            ->and($a['proposal'])->toBe('Esperar: se le dan 3 días cerrados para ver si vuelve a C. Si el 17/11 sigue encima, toca otro batch.');
        $b = $zen(['batch' => null, 'lowered' => $low(3), 'undo' => null], ['budget' => 33.0]);
        expect($b)->not->toHaveKey('ladder')->and($b['proposal'])->toBe('Mantener, podar los caros y meter creativos.')
            ->and($b['reading'])->toEndWith('El 13/11 bajaste el presupuesto y sigue encima de C.');
    });

    it('devolver la subida también espera las 24 horas de otro cambio, y no es "en el break-even"', function () use ($zen, $undo, $batch) {
        $cool = ['what' => 'volviste a prender la campaña', 'at' => '2026-11-12 20:00:00', 'until' => '2026-11-13 20:00:00', 'hours' => 24.0];
        $a = $zen(['batch' => $batch(3), 'lowered' => null, 'undo' => $undo], ['recent_change' => $cool]);
        expect($a['key'])->toBe('wait')->and($a['cooldown']['held'])->toBe('devolver la subida');
        $u = campana()->urgency(['mode' => 'normal', 'healthy' => 1, 'cities' => ['step_down', 'scale']]);
        expect($u['level'])->toBe(2)->and($u['reasons'])->toBe([]);
    });

    it('en liquidación y en el BE o encima no cambia nada', function () use ($zen, $undo, $batch) {
        expect($zen(['batch' => $batch(3), 'lowered' => null, 'undo' => $undo], ['mode' => 'liquidation'])['key'])->toBe('hold')
            ->and($zen(['batch' => $batch(3), 'lowered' => null, 'undo' => $undo], ['spend3' => 150, 'purchases3' => 18])['key'])->toBe('intervene');
    });

    it('en Reglas se cambian los 3 días y los 14 días', function () use ($zen, $undo, $batch) {
        expect(DecisionSettings::defaults()['band_wait_days'])->toBe(3.0)->and(DecisionSettings::defaults()['band_undo_days'])->toBe(14.0);
        $a = campana(['band_wait_days' => 2.0])->action(['c' => 4.44, 'be' => 7.61, 'mode' => 'normal', 'budget' => 39.60, 'spend3' => 117.9, 'purchases3' => 18,
            'band' => ['batch' => $batch(2), 'lowered' => null, 'undo' => $undo]]);
        expect($a['key'])->toBe('step_down');
    });
});

describe('.125: campañas que no dejan dinero', function () {
    // Dueño, 2026-11-09: "sí debemos establecer un límite… dependiendo de su CPA y si hicimos cambios y no mejoró"; "puede ser que
    // la campaña se esté comenzando a recuperar e igual me la mande a apagar". FlexiStep Aragua: C $5,01, BE $7,49, $25 por día.
    $flexi = fn (array $in) => campana()->action(array_merge(['c' => 5.01, 'be' => 7.49, 'mode' => 'normal', 'budget' => 25.0, 'spend3' => 95.3, 'purchases3' => 6,
        'cpa7' => 11.68, 'freq3' => 1.68, 'streak_days' => 3], $in));
    $catorce = ['days' => 14, 'spend' => 290.1, 'purchases' => 34.0, 'batches' => 4, 'lowered' => 0];

    it('14 días en el BE o encima con 10×C gastado, y los últimos 3 también: pausar la ciudad', function () use ($flexi, $catorce) {
        $a = $flexi(['chronic' => $catorce]);
        expect($a['key'])->toBe('pause_city')->and($a['chronic'])->toBe($catorce)
            ->and($a['reading'])->toBe('14 días sin dejar dinero: gastó $290,10 y vendió a $8,53 por compra, en tu BE ($7,49) o encima; y en los últimos 3 días tampoco. '
                . 'En ese tiempo entraron anuncios nuevos 4 veces, y no mejoró.');
        // lo que se intentó: también las bajadas de presupuesto; y si no se hizo nada, lo dice
        expect($flexi(['chronic' => ['batches' => 1, 'lowered' => 2] + $catorce])['reading'])->toEndWith('En ese tiempo entraron anuncios nuevos 1 vez y bajaste el presupuesto 2 veces, y no mejoró.')
            ->and($flexi(['chronic' => ['batches' => 0] + $catorce])['reading'])->toEndWith('En ese tiempo no se le hizo ningún cambio.');
    });

    it('tu protección: si en los últimos 3 días vende debajo del BE, no se pausa', function () use ($flexi, $catorce) {
        // 27/10: $78,90 y 11 compras en 3 días = $7,17 (BE $7,49): entre C y BE
        expect($flexi(['spend3' => 78.9, 'purchases3' => 11, 'chronic' => $catorce])['key'])->toBe('hold_prune');
    });

    it('no aplica con poco gasto en los 14 días, si esos 14 días dejaron dinero, o sin 14 días de historia', function () use ($flexi, $catorce) {
        expect($flexi(['chronic' => ['spend' => 45.0, 'purchases' => 5.0] + $catorce])['key'])->toBe('intervene')
            ->and($flexi(['chronic' => ['purchases' => 45.0] + $catorce])['key'])->toBe('intervene')
            ->and($flexi(['chronic' => null])['key'])->toBe('intervene');
        // en Reglas se cambia el 10×C
        expect(campana(['chronic_min_cpa' => 60.0])->action(['c' => 5.01, 'be' => 7.49, 'mode' => 'normal', 'budget' => 25.0, 'spend3' => 95.3, 'purchases3' => 6, 'chronic' => $catorce])['key'])->toBe('intervene')
            ->and(DecisionSettings::defaults()['chronic_days'])->toBe(14.0)->and(DecisionSettings::defaults()['chronic_min_cpa'])->toBe(10.0);
    });

    it('sin batch para lanzar, la cuenta corre: 2 días cerrados, bajar 20 %; 5, pausar ("sí, que corra")', function () use ($flexi) {
        $sin = fn (int $days, array $in = []) => $flexi(['intervention' => '2026-11-06', 'closed_since_intervention' => $days, 'no_batch' => true] + $in);
        expect($sin(1)['key'])->toBe('intervene')
            ->and($sin(1)['proposal'])->toBe('Intervenir: podar y lanzar un batch nuevo. No tocar el presupuesto hoy. Si no llega un batch y sigue igual 2 días cerrados, toca bajar 20 %.')
            ->and($sin(2)['key'])->toBe('decrease')->and($sin(2)['to'])->toBe(20.0)
            ->and($sin(2)['reading'])->toBe('Sigue en el break-even o encima 2 días cerrados y sin batch para lanzar.')
            ->and($sin(3, ['decreased_since_intervention' => true])['key'])->toBe('wait')
            ->and($sin(3, ['decreased_since_intervention' => true])['reading'])->toBe('En el break-even o encima hace 3 días cerrados, sin batch para lanzar.')
            ->and($sin(5, ['decreased_since_intervention' => true])['key'])->toBe('pause_city')
            ->and($sin(5)['reading'])->toBe('5 días cerrados en el break-even o encima, sin batch para lanzar.');
        // con un batch para lanzar, como siempre: la cuenta empieza con la intervención
        expect($flexi(['intervention' => null])['key'])->toBe('intervene')->and($flexi(['intervention' => null])['proposal'])->not->toContain('Si no llega un batch');
    });

    it('los dos textos que confundían', function () use ($flexi) {
        // "Rentable" con 0 compras
        $a = $flexi(['spend3' => 8.0, 'purchases3' => 0]);
        expect($a['key'])->toBe('hold_prune')->and($a['reading'])->toBe('Sin compras en 3 días: gastó $8,00, menos de 2×C. Todavía no se puede juzgar.');
        // "Por ahora, nada" mientras otra misión mandaba a apagar un creativo ahí
        $cool = ['what' => 'subiste el presupuesto de $20,00 a $25,00', 'at' => '2026-11-08 20:00:00', 'until' => '2026-11-09 20:00:00', 'hours' => 24.0];
        expect($flexi(['spend3' => 78.9, 'purchases3' => 11, 'recent_change' => $cool])['proposal'])->toStartWith('Por ahora, ningún cambio grande aquí. Los creativos nuevos, desde el 09/11 a las 20:00');
    });
});

describe('Entre C y BE, y en el break-even o encima', function () {
    it('entre C y BE: mantener y podar los perdedores', function () {
        $a = accion(['spend3' => 30, 'purchases3' => 6, 'prune' => ['C003 FASP']]);
        expect($a['key'])->toBe('hold_prune')->and($a['proposal'])->toContain('Apagar: C003 FASP');
    });

    it('en el BE o encima, sin intervención: intervenir, sin tocar el presupuesto', function () {
        $a = accion(['spend3' => 36, 'purchases3' => 6, 'streak_days' => 2, 'history_since' => '2026-10-30']);
        expect($a['key'])->toBe('intervene')->and($a['proposal'])->toContain('No tocar el presupuesto hoy');
        expect(accion(['spend3' => 36, 'purchases3' => 6, 'budget_changed_today' => true])['key'])->toBe('wait');
    });

    it('2 días cerrados después de intervenir: −20 % ($40 → $32, el ejemplo del manual)', function () {
        $a = accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-27', 'closed_since_intervention' => 2]);
        expect($a['key'])->toBe('decrease')->and($a['to'])->toBe(32.0);
        expect(accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-28', 'closed_since_intervention' => 1])['key'])->toBe('wait');
        expect(accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-26', 'closed_since_intervention' => 3, 'decreased_since_intervention' => true])['key'])->toBe('wait');
    });

    it('5 días cerrados: pausar la ciudad; se prende otra vez con 3 batches nunca publicados ahí (.117)', function () {
        $a = accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-24', 'closed_since_intervention' => 5, 'decreased_since_intervention' => true]);
        expect($a['key'])->toBe('pause_city')->and($a['proposal'])->toBe('Pausar la ciudad. Se vuelve a prender cuando haya 3 batches que nunca se publicaron ahí.');
    });

    it('producto en pausa, sin objetivo o sin gasto suficiente', function () {
        expect(accion(['mode' => 'paused'])['key'])->toBe('none')
            ->and(accion(['c' => null])['key'])->toBe('no_target')
            ->and(accion(['spend3' => 3, 'purchases3' => 0])['key'])->toBe('wait');
    });

    it('cuenta los días seguidos con el CPA de 3 días en el break-even o encima', function () {
        $daily = ['2026-10-24' => ['spend' => 10, 'purchases' => 4], '2026-10-25' => ['spend' => 10, 'purchases' => 4],
            '2026-10-26' => ['spend' => 10, 'purchases' => 2], '2026-10-27' => ['spend' => 12, 'purchases' => 1],
            '2026-10-28' => ['spend' => 12, 'purchases' => 1], '2026-10-29' => ['spend' => 12, 'purchases' => 2]];
        expect(CampaignRules::streak($daily, 5.70, '2026-10-29'))->toBe(['days' => 2, 'start' => '2026-10-28']);
    });
});

describe('Precio', function () {
    // FlexiStep, semana del 17 al 23-10: por entrega $23,14 + $4,26 de upsell, costos $7,37; 92 entregas por 195 compras de Meta
    $flexi = ['revenue' => 27.40 * 92, 'variable_costs' => 7.37 * 92, 'cogs' => 2.72 * 92, 'orders_delivered' => 92, 'purchases' => 195,
        'fixed_pct' => 10, 'target_type' => 'sales', 'target_pct' => 20];

    it('el precio mínimo con el CPA de hoy deja tu utilidad después de gastos fijos', function () use ($flexi) {
        expect(CampaignRules::minPrice($flexi, 23.14, 3.96))->toBe(18.26)
            ->and(CampaignRules::minPrice(['orders_delivered' => 0] + $flexi, 23.14, 3.96))->toBeNull();
    });

    it('se ofrece de a $2 junto al escalado, nunca debajo del mínimo', function () {
        $o = campana()->priceOption(23.14, 18.26, true);
        expect($o['to'])->toBe(21.14)->and($o['text'])->toContain('$23,14 → $21,14')->toContain('$18,26');
        expect(campana()->priceOption(23.14, 18.26, false))->toBeNull()
            ->and(campana()->priceOption(23.14, 22.0, true))->toBeNull();
    });

    it('después de bajar: vigilar, y volver si quedó debajo del mínimo', function () {
        expect(campana()->priceWatch(['from' => 23.14, 'to' => 21.14], 18.26))->toContain('vigilar')->not->toContain('volver')
            ->and(campana()->priceWatch(['from' => 23.14, 'to' => 17.99], 18.26))->toContain('volver al precio anterior')
            ->and(campana()->priceWatch(null, 18.26))->toBeNull();
    });
});

describe('Presupuesto, urgencia y trabajo siguiente', function () {
    it('los mínimos de los ad sets y el presupuesto para batches nuevos', function () {
        expect(campana()->budgetNotes(40, 24, 0, 4)[0])->toContain('demasiado')
            ->and(campana()->budgetNotes(40, 18, 0, 4)[0])->toContain('revisar')
            ->and(campana()->budgetNotes(15, 0, 2, 4.0)[0])->toContain('entre $20,00 y $24,00')
            ->and(campana()->budgetNotes(40, 12, 0, 4))->toBe([])
            // .124: con los batches probados sin mínimo, "mínimos bajos" ya no es un aviso; sí un batch que se prueba sin su mínimo
            ->and(campana()->budgetNotes(106.8, 5, 1, 4.87))->toBe([])
            ->and(campana()->budgetNotes(106.8, 5, 1, 4.87, ['B0121 FLEX']))->toBe(['B0121 FLEX todavía se prueba y no tiene gasto mínimo: puede que Meta no lo muestre. '
                . 'Tu regla: el CPA del nombre de la campaña (o tu C, $4,87) por día, mientras se prueba.'])
            ->and(DecisionSettings::defaults())->not->toHaveKey('mins_low_pct');
    });

    it('U1 a U5', function () {
        $u = fn (array $p) => campana()->urgency($p + ['mode' => 'normal', 'healthy' => 1]);
        expect($u(['cities' => ['intervene', 'scale']])['level'])->toBe(1)
            ->and($u(['cities' => ['hold_prune']])['level'])->toBe(2)
            ->and($u(['cities' => ['hold'], 'failing' => 1])['level'])->toBe(2)
            ->and($u(['cities' => ['scale', 'hold']])['level'])->toBe(3)
            ->and($u(['cities' => ['hold']])['level'])->toBe(4)
            ->and(campana()->urgency(['mode' => 'normal', 'healthy' => 0, 'cities' => ['hold']])['level'])->toBe(1)
            ->and(campana()->urgency(['mode' => 'paused', 'cities' => ['intervene']])['level'])->toBe(5)
            ->and($u(['cities' => ['hold'], 'stock_all_out' => true])['level'])->toBe(5);
        expect(campana()->urgency(['mode' => 'liquidation', 'healthy' => 0, 'cities' => ['hold_prune']])['batches'])->toBe('0')
            // .100: un batch a la vez, también en U1
            ->and($u(['cities' => ['intervene']])['batches'])->toBe('1');
    });

    it('.79: un producto sin C ni BE no es urgente ni pide batches (Termafit, nuevo, salía "U1 · sin ganadores sanos")', function () {
        $u = campana()->urgency(['mode' => 'normal', 'healthy' => 0, 'cities' => ['no_target', 'no_target', 'no_target']]);
        expect($u['level'])->toBe(5)->and($u['label'])->toBe('U5 · sin CPA de referencia')->and($u['batches'])->toBe('0')
            ->and($u['reasons'])->toBe(['Sin CPA objetivo ni break-even: no se puede juzgar']);
        expect(campana()->urgency(['mode' => 'normal', 'healthy' => 0, 'cities' => ['no_target', 'intervene']])['level'])->toBe(1);
    });

    it('iteraciones de un ganador, un concepto nuevo o un ángulo nuevo, y creativos de arriba si está saturada', function () {
        $r = campana();
        expect($r->nextWork(['winner' => ['label' => 'C004 GINE', 'iterate' => 'el hook (los primeros segundos)']], '1')[0])->toContain('Iteraciones de C004 GINE')
            ->and($r->nextWork(['main_angle' => 'FASP', 'rested' => ['FASP'], 'rest_batches' => 3], '2–3')[0])->toContain('Otro ángulo')
            ->and($r->nextWork(['main_angle' => 'FASP', 'rested' => []], '1–2')[0])->toContain('Un concepto nuevo dentro de FASP')
            ->and($r->nextWork(['saturated' => ['Caracas']], '1'))->toHaveCount(2)
            ->and($r->nextWork(['mode' => 'liquidation'], '0')[0])->toContain('liquidación');
    });
});

describe('Comprimax en Aragua (2026-11-02)', function () {
    it('$7,51 por compra con el límite de su producto (BE $7,31): intervenir, no "va bien"', function () {
        $a = accion(['c' => 4.66, 'be' => 7.31, 'budget' => 30.0, 'spend3' => 90.08, 'purchases3' => 12, 'cpa7' => 8.0, 'freq3' => 2.2, 'streak_days' => 1]);
        expect($a['key'])->toBe('intervene');
    });

    it('con el límite inflado de antes (C $16,22, BE $26,05) no intervenía (decía mantener; desde la .123, sin el freno de la frecuencia, subir)', function () {
        $a = accion(['c' => 16.22, 'be' => 26.05, 'budget' => 30.0, 'spend3' => 90.08, 'purchases3' => 12, 'cpa7' => 8.0, 'freq3' => 2.2]);
        expect($a['key'])->toBe('scale');
    });
});
