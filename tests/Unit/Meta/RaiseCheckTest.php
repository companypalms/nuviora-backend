<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\CampaignRules;

// .144 (dueño, 2026-11-14): aprobó "una subida a la vez con 3 días de espera, y 'Devuelve la subida' si la ganancia cayó
// 10 % o más". La radiografía: de 11 subidas de la IA con 7 días, 7 bajaron la ganancia por día; 15 de 27 subidas fueron
// antes de 3 días de la anterior. Los días son los reales de sus campañas (gasto/compras de Meta).

/** "10-27:46.1/19 10-28:54.4/23" → los días de la campaña. */
function diasReales(string $series): array
{
    $out = [];
    foreach (explode(' ', $series) as $tok) {
        [$md, $v] = explode(':', $tok);
        [$s, $p] = explode('/', $v);
        $out['2026-' . $md] = ['spend' => (float) $s, 'purchases' => (float) $p];
    }

    return $out;
}

function subida(string $date, float $from, float $to): array
{
    return ['date' => $date, 'at' => "{$date} 10:00:00", 'from' => $from, 'to' => $to];
}

$flexi = diasReales('10-27:46.1/19 10-28:54.4/23 10-29:40.2/16 10-30:26.9/13 10-31:78.6/19 11-01:57.3/14 11-02:52.4/19 11-03:59.4/16 11-04:59.9/16 11-05:74.9/20 11-06:82.9/27');
$zen = diasReales('10-27:37.9/13 10-28:36.4/7 10-29:29/11 10-30:24/9 10-31:22.6/4 11-01:31.6/8 11-02:28.9/9 11-03:31.5/7 11-04:29/8 11-05:30.2/7 11-06:34.1/6');
$cervimaxMcay = diasReales('10-30:33.1/6 10-31:27/7 11-01:25.7/5 11-02:26.6/9 11-03:23.9/6 11-04:23/4 11-05:30/9 11-06:34.8/8 11-07:30.3/4 11-08:37.1/5 11-09:40.6/6');

describe('.144: la revisión de la última subida', function () use ($flexi, $zen, $cervimaxMcay) {
    it('FlexiStep Caracas (3/11, $51,60 → $61,92): a los 3 días deja $78,84 por día contra $75,85 antes: aguantó', function () use ($flexi) {
        expect(CampaignRules::raiseCheck($flexi, [subida('2026-11-03', 51.6, 61.92)], 61.92, 7.21, '2026-11-06', 3, 10))
            ->toMatchArray(['status' => 'held', 'before' => 75.85, 'after' => 78.84, 'closed' => 3]);
    });

    it('ZenPulse Caracas (3/11, $30 → $33): bajó de $22,40 a $11,04 por día: devolverla', function () use ($zen) {
        expect(CampaignRules::raiseCheck($zen, [subida('2026-11-03', 30, 33)], 33.0, 6.02, '2026-11-06', 3, 10))
            ->toMatchArray(['status' => 'fell', 'before' => 22.4, 'after' => 11.04, 'from' => 30.0, 'to' => 33.0]);
    });

    it('Cervimax Maracay: la segunda subida (6/11) bajó de $31,51 a $8,55 por día', function () use ($cervimaxMcay) {
        $events = [subida('2026-11-04', 25, 30), subida('2026-11-06', 30, 34.5)];
        expect(CampaignRules::raiseCheck($cervimaxMcay, $events, 34.5, 8.91, '2026-11-09', 3, 10))->toMatchArray(['status' => 'fell', 'before' => 31.51, 'after' => 8.55]);
    });

    it('una subida a la vez: hasta 3 días cerrados después, se espera (también después de bajar)', function () use ($flexi) {
        expect(CampaignRules::raiseCheck($flexi, [subida('2026-11-03', 51.6, 61.92)], 61.92, 7.21, '2026-11-04', 3, 10))
            ->toMatchArray(['status' => 'waiting', 'raise' => true, 'closed' => 1, 'until' => '2026-11-07'])
            ->and(CampaignRules::raiseCheck($flexi, [subida('2026-11-03', 51.6, 61.92), subida('2026-11-05', 61.92, 51.6)], 51.6, 7.21, '2026-11-06', 3, 10))
            ->toMatchArray(['status' => 'waiting', 'raise' => false])
            // ya pasaron los días desde que bajó: nada que revisar
            ->and(CampaignRules::raiseCheck($flexi, [subida('2026-11-01', 61.92, 51.6)], 51.6, 7.21, '2026-11-06', 3, 10))->toBeNull();
    });

    it('se mira lo último: si después de caer se recupera, ya no se pide devolverla', function () use ($zen) {
        // ZenPulse Caracas: del 4/11 al 6/11 cayó; si del 7/11 al 9/11 dejara $30 por día (más que los $22,40 de antes), aguantó
        $dias = $zen + ['2026-11-07' => ['spend' => 30.0, 'purchases' => 10.0], '2026-11-08' => ['spend' => 30.0, 'purchases' => 10.0],
            '2026-11-09' => ['spend' => 30.0, 'purchases' => 10.0]];
        expect(CampaignRules::raiseCheck($dias, [subida('2026-11-03', 30, 33)], 33.0, 6.02, '2026-11-06', 3, 10)['status'])->toBe('fell')
            ->and(CampaignRules::raiseCheck($dias, [subida('2026-11-03', 30, 33)], 33.0, 6.02, '2026-11-09', 3, 10))->toMatchArray(['status' => 'held', 'after' => 30.2]);
    });

    it('el margen: una caída chica (−1,7 %, Cervimax Caracas 9/11) no se devuelve; sin margen, sí', function () {
        $dias = [];
        foreach (range(1, 7) as $i) {
            $dias[date('Y-m-d', strtotime("2026-11-09 -{$i} days"))] = ['spend' => 42.0, 'purchases' => 10.0];  // $58 por día con BE $10
        }
        foreach (range(1, 3) as $i) {
            $dias[date('Y-m-d', strtotime("2026-11-09 +{$i} days"))] = ['spend' => 43.0, 'purchases' => 10.0];  // $57
        }
        expect(CampaignRules::raiseCheck($dias, [subida('2026-11-09', 36, 43.2)], 43.2, 10, '2026-11-12', 3, 10)['status'])->toBe('held')
            ->and(CampaignRules::raiseCheck($dias, [subida('2026-11-09', 36, 43.2)], 43.2, 10, '2026-11-12', 3, 0)['status'])->toBe('fell');
    });

    it('sin nada que revisar: sin cambios, sin BE, una subida que ya se devolvió, o una vieja (después mandan las otras reglas)', function () use ($zen) {
        expect(CampaignRules::raiseCheck($zen, [], 33.0, 6.02, '2026-11-06', 3, 10))->toBeNull()
            ->and(CampaignRules::raiseCheck($zen, [subida('2026-11-03', 30, 33)], 33.0, null, '2026-11-06', 3, 10))->toBeNull()
            ->and(CampaignRules::raiseCheck($zen, [subida('2026-11-03', 30, 33)], 30.0, 6.02, '2026-11-06', 3, 10))->toBeNull()
            // una que sigue cayendo, pero ya hace más de 7 días de la revisión
            ->and(CampaignRules::raiseCheck($zen + array_fill_keys(['2026-11-12', '2026-11-13', '2026-11-14'], ['spend' => 30.0, 'purchases' => 1.0]),
                [subida('2026-11-03', 30, 33)], 33.0, 6.02, '2026-11-14', 3, 10))->toBeNull();
    });
});

describe('.185: toda la escalera de subidas, el techo y bajar como mucho 20 %', function () {
    // Antes de la primera subida dejaba $40 por día (compras × BE − gasto). Cada subida, sola, "aguantó" (perdió menos de 10 %),
    // pero la escalera completa deja $34,50: lo extra no paga (como FlexiStep del 26/10 al 16/11)
    $dias = [];
    foreach (range(1, 21) as $d) {
        $dias[sprintf('2026-11-%02d', $d)] = match (true) {
            $d <= 7 => ['spend' => 50.0, 'purchases' => 15.0],   // 15 × 6 − 50 = 40
            $d <= 11 => ['spend' => 59.0, 'purchases' => 16.0],  // 96 − 59 = 37
            default => ['spend' => 67.5, 'purchases' => 17.0],   // 102 − 67,5 = 34,5
        };
    }
    $escalera = [subida('2026-11-08', 50, 60), subida('2026-11-12', 60, 72)];

    it('desde la primera subida deja menos por día: se devuelve la última que sigue puesta, aunque cada una sola "aguantó"', function () use ($dias, $escalera) {
        $r = CampaignRules::raiseCheck($dias, $escalera, 72.0, 6.0, '2026-11-21', 3, 10, 21);
        expect($r)->toMatchArray(['status' => 'fell', 'date' => '2026-11-12', 'from' => 60.0, 'to' => 72.0, 'before' => 40.0, 'after' => 34.5])
            ->and($r['climb'])->toBe(['since' => '2026-11-08', 'from' => 50.0, 'top' => 72.0, 'raises' => 2, 'window' => 7]);
        // sin mirar la escalera (como antes de la .185): la última subida sola "aguantó"
        expect(CampaignRules::raiseCheck($dias, $escalera, 72.0, 6.0, '2026-11-21', 3, 10))->toMatchArray(['status' => 'held', 'after' => 34.5]);
    });

    it('después de devolver una: se espera, y si sigue dejando menos, la anterior', function () use ($dias, $escalera) {
        $back = [...$escalera, subida('2026-11-21', 72, 60)];
        expect(CampaignRules::raiseCheck($dias, $back, 60.0, 6.0, '2026-11-22', 3, 10, 21)['status'])->toBe('waiting');
        $dias2 = $dias + array_fill_keys(['2026-11-22', '2026-11-23', '2026-11-24'], ['spend' => 70.0, 'purchases' => 13.0]);
        expect(CampaignRules::raiseCheck($dias2, $back, 60.0, 6.0, '2026-11-24', 3, 10, 21))->toMatchArray(['status' => 'fell', 'date' => '2026-11-08', 'from' => 50.0, 'to' => 60.0]);
    });

    it('si la escalera deja más que antes, o todavía no pasaron 7 días, no se toca', function () use ($dias, $escalera) {
        $gana = array_map(fn ($d) => $d['spend'] > 50 ? ['spend' => 80.0, 'purchases' => 25.0] : $d, $dias);
        expect(CampaignRules::raiseCheck($gana, $escalera, 72.0, 6.0, '2026-11-21', 3, 10, 21)['status'] ?? null)->not->toBe('fell')
            ->and(CampaignRules::raiseCheck($dias, [subida('2026-11-17', 50, 60)], 60.0, 6.0, '2026-11-21', 3, 10, 21)['status'] ?? null)->not->toBe('fell');
    });

    it('el techo: una subida devuelta no se repite hasta un batch nuevo con el CPA 10 % o más debajo de C (sin plazo)', function () {
        $events = [subida('2026-11-08', 50, 60), subida('2026-11-12', 60, 72), subida('2026-11-21', 72, 60)];
        $c = CampaignRules::ceiling($events, ['2026-11-10']);
        expect($c)->toBe(['level' => 72.0, 'date' => '2026-11-21', 'batch_after' => false])
            ->and(CampaignRules::ceiling($events, ['2026-11-23'])['batch_after'])->toBeTrue()
            ->and(CampaignRules::ceiling([subida('2026-11-08', 50, 60)]))->toBeNull()
            // una subida devuelta hace mucho sigue siendo techo: no tiene plazo
            ->and(CampaignRules::ceiling([subida('2026-10-20', 50, 60), subida('2026-10-22', 60, 50)])['level'])->toBe(60.0);

        $rules = new CampaignRules(DecisionSettings::defaults());
        $in = ['c' => 4.0, 'be' => 5.70, 'mode' => 'normal', 'budget' => 60.0, 'spend3' => 30.0, 'purchases3' => 12, 'cpa7' => 3.0]; // CPA $2,50: 37 % debajo de C
        $a = $rules->action($in + ['ceiling' => $c]);
        expect($a['key'])->toBe('hold')->and($a['reading'])->toBe('Va bien, pero el 21/11 se devolvió la subida a $72,00: lo extra no dejó dinero. Para volver a subir hasta ahí: un batch nuevo y el CPA de 3 días un 10 % o más debajo de C.');
        // con el batch nuevo y el CPA bien debajo de C, se sube otra vez
        expect($rules->action($in + ['ceiling' => ['batch_after' => true] + $c])['key'])->toBe('scale');
        // con el batch, pero el CPA apenas debajo de C (5 %), todavía no ($66 + 10 % llega al techo de $72)
        expect($rules->action(['spend3' => 45.6, 'budget' => 66.0] + $in + ['ceiling' => ['batch_after' => true] + $c])['reading'])->toEndWith('(el batch ya entró; falta el CPA).');
        // una subida chica que no llega al techo sí se puede
        expect($rules->action(['budget' => 50.0] + $in + ['ceiling' => $c])['key'])->toBe('scale');
    });

    it('devolver la escalera lo dice con sus números, y baja como mucho 20 %', function () {
        $a = (new CampaignRules(DecisionSettings::defaults()))->action(['c' => 4.0, 'be' => 6.0, 'mode' => 'normal', 'budget' => 90.0, 'spend3' => 30.0, 'purchases3' => 12, 'cpa7' => 3.0,
            'raise' => ['status' => 'fell', 'raise' => true, 'date' => '2026-11-12', 'from' => 60.0, 'to' => 90.0, 'days' => 3, 'closed' => 9, 'until' => '2026-11-16',
                'before' => 40.0, 'after' => 10.0, 'climb' => ['since' => '2026-11-08', 'from' => 50.0, 'top' => 90.0, 'raises' => 2, 'window' => 7]]]);
        expect($a)->toMatchArray(['key' => 'undo_raise', 'from' => 90.0, 'to' => 72.0])
            ->and($a['reading'])->toBe('Desde el 08/11 subiste el presupuesto 2 veces (de $50,00 a $90,00) y lo que deja bajó: $40,00 por día antes de la primera subida, $10,00 en los últimos 7 días cerrados. Lo extra no está dejando dinero.')
            ->and($a['proposal'])->toContain('Devolver la última subida (la del 12/11): $90,00 → $72,00. Como mucho un 20 % de una vez')
            ->and(CampaignRules::backTo(39.6, 33.0))->toBe(33.0)->and(CampaignRules::backTo(100.0, 50.0))->toBe(80.0)
            ->and(DecisionSettings::defaults()['climb_days'])->toBe(21.0)->and(DecisionSettings::defaults()['ceiling_below_pct'])->toBe(10.0);
    });
});

describe('.187: la escalera se juzga cuando ya es grande, y el techo que se cruzaría', function () {
    // La revisión del 2026-11-28: con una subida sola, la suerte de cada día pesa más que lo que cambió (sin margen se devolvía la
    // mitad de las subidas, buenas o malas); y una vez pasado, el techo seguía frenando cada subida
    $dias = [];
    foreach (range(1, 21) as $d) {
        $dias[sprintf('2026-11-%02d', $d)] = $d <= 7 ? ['spend' => 50.0, 'purchases' => 15.0] : ['spend' => 59.0, 'purchases' => 16.0]; // $40 → $37 por día
    }

    it('con la escalera chica (+20 %) no se juzga: queda la revisión de esa subida (.144)', function () use ($dias) {
        $r = CampaignRules::raiseCheck($dias, [subida('2026-11-08', 50, 60)], 60.0, 6.0, '2026-11-21', 3, 10, 21, 40);
        expect($r['status'])->toBe('held')->and($r)->not->toHaveKey('climb');
    });

    it('con la escalera grande, se devuelve solo si deja un 10 % menos o más', function () use ($dias) {
        $escalera = [subida('2026-11-08', 50, 60), subida('2026-11-12', 60, 72)];
        // $40 → $37 por día: 7,5 % menos, dentro del margen
        expect(CampaignRules::raiseCheck($dias, $escalera, 72.0, 6.0, '2026-11-21', 3, 10, 21, 40))->not->toHaveKey('climb');
        // $40 → $34,50 por día: 14 % menos
        $peor = array_map(fn ($d) => $d['spend'] > 50 ? ['spend' => 67.5, 'purchases' => 17.0] : $d, $dias);
        expect(CampaignRules::raiseCheck($peor, $escalera, 72.0, 6.0, '2026-11-21', 3, 10, 21, 40))->toMatchArray(['status' => 'fell', 'date' => '2026-11-12'])
            ->and(DecisionSettings::defaults()['climb_min_pct'])->toBe(40.0);
    });

    it('el techo: solo el que la próxima subida cruzaría; si la campaña ya lo pasó, no frena', function () {
        $events = [subida('2026-11-01', 30, 36), subida('2026-11-05', 36, 30), subida('2026-11-12', 30, 36), subida('2026-11-16', 36, 43)];
        expect(CampaignRules::ceiling($events, ['2026-11-08'], 43.0))->toBeNull()
            ->and(CampaignRules::ceiling($events, ['2026-11-08'], 30.0))->toBe(['level' => 36.0, 'date' => '2026-11-05', 'batch_after' => true]);
        // después de devolver la de $43: el techo es $43, el más cercano encima
        expect(CampaignRules::ceiling([...$events, subida('2026-11-20', 43, 36)], ['2026-11-08'], 36.0))->toBe(['level' => 43.0, 'date' => '2026-11-20', 'batch_after' => false]);
    });
});

describe('.144: lo que pide la regla', function () {
    $campana = fn (array $in) => (new CampaignRules(DecisionSettings::defaults()))->action(array_merge(['c' => 4.0, 'be' => 5.70, 'mode' => 'normal', 'budget' => 33.0,
        'spend3' => 30.0, 'purchases3' => 12, 'cpa7' => 3.0, 'freq3' => 1.3], $in));

    it('si la subida no aguantó: "Devolver la subida", antes que subir otra vez (aunque el CPA siga debajo de C)', function () use ($campana) {
        $a = $campana(['raise' => ['status' => 'fell', 'raise' => true, 'date' => '2026-11-03', 'from' => 30.0, 'to' => 33.0, 'days' => 3, 'closed' => 3,
            'until' => '2026-11-07', 'before' => 22.4, 'after' => 11.04]]);

        expect($a)->toMatchArray(['key' => 'undo_raise', 'from' => 33.0, 'to' => 30.0])
            ->and($a['reading'])->toBe('El 03/11 subiste el presupuesto de $30,00 a $33,00 y lo que deja bajó: $22,40 por día antes de subir, $11,04 en los últimos 3 días cerrados.')
            ->and($a['proposal'])->toBe('Devolver la subida: $33,00 → $30,00. Si vuelve a ir bien, se puede subir otra vez en 3 días.');
    });

    it('mientras se espera, no se sube; cuando aguantó, sí', function () use ($campana) {
        $waiting = $campana(['raise' => ['status' => 'waiting', 'raise' => true, 'date' => '2026-11-04', 'from' => 25.0, 'to' => 30.0, 'days' => 3, 'closed' => 1, 'until' => '2026-11-08']]);
        expect($waiting['key'])->toBe('hold')
            ->and($waiting['reading'])->toBe('El 04/11 subiste el presupuesto ($25,00 → $30,00): una subida a la vez. Se revisa el 08/11, con 3 días cerrados.')
            ->and($campana(['raise' => ['status' => 'held', 'raise' => true, 'date' => '2026-11-03', 'from' => 30.0, 'to' => 33.0, 'days' => 3, 'closed' => 3,
                'until' => '2026-11-07', 'before' => 22.4, 'after' => 25.0]])['key'])->toBe('scale');
    });

    it('devolver la subida es un cambio grande: espera las 24 horas después de otro', function () use ($campana) {
        $a = $campana(['raise' => ['status' => 'fell', 'raise' => true, 'date' => '2026-11-03', 'from' => 30.0, 'to' => 33.0, 'days' => 3, 'closed' => 3,
            'until' => '2026-11-07', 'before' => 22.4, 'after' => 11.04], 'recent_change' => ['at' => '2026-11-07 09:00:00', 'until' => '2026-11-08 09:00:00',
            'hours' => 24, 'what' => 'entró el batch «B0120»']]);
        expect($a['key'])->toBe('wait')->and($a['cooldown']['held'])->toBe('devolver la subida');
    });
});
