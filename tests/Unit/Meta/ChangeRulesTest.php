<?php

use App\Services\Meta\Changes\ChangeRules;

// .131 (dueño, 2026-11-10): "necesito que cada cambio de Meta Ads tenga una hipótesis que se guarda en un historial. De esta
// manera podemos ver si la hipótesis se cumplió o no se cumplió con el cambio". FlexiStep en Caracas: C $6,00, BE $9,00.

beforeEach(function () {
    $snap = fn (string $at, ?string $status, ?float $budget = null, ?float $min = null) => ['status' => $status, 'daily_budget' => $budget, 'min_spend' => $min, 'at' => $at];
    $this->campaigns = ['111' => [
        $snap('2026-11-01 08:00:00', 'ACTIVE', 30.0),
        $snap('2026-11-04 10:00:00', 'ACTIVE', 33.0),
        $snap('2026-11-04 15:00:00', 'ACTIVE', 36.0),   // dos subidas el mismo día: un solo cambio, de $30 a $36
        $snap('2026-11-08 09:00:00', 'PAUSED', 36.0),
    ]];
    $this->adsets = [
        's1' => [$snap('2026-11-01 08:00:00', 'ACTIVE', null, 5.0), $snap('2026-11-06 11:00:00', 'ACTIVE', null, null)],
        's2' => [$snap('2026-11-01 08:00:00', 'ACTIVE'), $snap('2026-11-05 12:00:00', 'PAUSED')],
    ];
    $this->ads = [
        'a1' => [$snap('2026-11-01 08:00:00', 'ACTIVE'), $snap('2026-11-05 09:30:00', 'PAUSED')],
        'a2' => [$snap('2026-11-01 08:00:00', 'ACTIVE'), $snap('2026-11-05 12:00:00', 'PAUSED')],   // su ad set se apagó ese día
        'a3' => [$snap('2026-10-20 08:00:00', 'ACTIVE'), $snap('2026-10-28 08:00:00', 'PAUSED')],   // antes de que empiece el historial
    ];
    $this->adsetInfo = [
        's1' => ['campaign' => '111', 'name' => 'B0095 FLEX', 'created' => '2026-10-20 10:00:00'],
        's2' => ['campaign' => '111', 'name' => 'B0100 FLEX', 'created' => '2026-10-25 10:00:00'],
        's3' => ['campaign' => '111', 'name' => 'B0121 FLEX', 'created' => '2026-11-07 10:00:00'],
    ];
    $this->adInfo = [
        'a1' => ['campaign' => '111', 'adset' => 's1', 'name' => 'C0410 FLEX'],
        'a2' => ['campaign' => '111', 'adset' => 's2', 'name' => 'C0411 FLEX'],
        'a3' => ['campaign' => '111', 'adset' => 's1', 'name' => 'C0399 FLEX'],
    ];
    $day = fn (float $spend, float $purchases) => ['spend' => $spend, 'purchases' => $purchases];
    $this->daily = ['2026-11-01' => $day(30, 5), '2026-11-02' => $day(30, 5), '2026-11-03' => $day(30, 5), '2026-11-04' => $day(33, 5),
        '2026-11-05' => $day(36, 7), '2026-11-06' => $day(36, 6), '2026-11-07' => $day(36, 4), '2026-11-08' => $day(10, 1)];
    $this->found = ChangeRules::detect($this->campaigns, $this->adsets, $this->ads, $this->adsetInfo, $this->adInfo, '2026-11-01');
    $this->of = fn (string $kind) => collect($this->found)->firstWhere('kind', $kind);
});

describe('.131: qué cambió en Meta', function () {
    it('ve cada cambio en las fotos, en orden', function () {
        expect(array_column($this->found, 'kind'))->toBe(['raise', 'ads_off', 'min_off', 'new_batch', 'pause'])
            ->and(($this->of)('raise'))->toBe(['fingerprint' => 'raise:111::2026-11-04', 'kind' => 'raise', 'campaign' => '111', 'adset' => null,
                'date' => '2026-11-04', 'at' => '2026-11-04 10:00:00', 'detail' => ['from' => 30.0, 'to' => 36.0]])
            // el anuncio de un ad set que se apagó el mismo día va con su ad set; el de antes del historial no sale
            ->and(($this->of)('ads_off')['detail'])->toBe(['ads' => ['C0410 FLEX'], 'adsets' => ['B0100 FLEX']])
            ->and(($this->of)('ads_off')['at'])->toBe('2026-11-05 09:30:00')
            ->and(($this->of)('min_off')['detail'])->toBe(['adset' => 'B0095 FLEX', 'min' => 5.0])
            ->and(($this->of)('new_batch'))->toMatchArray(['adset' => 's3', 'date' => '2026-11-07', 'detail' => ['adset' => 'B0121 FLEX']])
            ->and(($this->of)('pause')['detail'])->toBe(['budget' => 36.0]);
    });

    it('subir y bajar el mismo día es el neto; volver al mismo presupuesto no es cambio', function () {
        $snap = fn (string $at, float $b) => ['status' => 'ACTIVE', 'daily_budget' => $b, 'min_spend' => null, 'at' => $at];
        $ida = ChangeRules::detect(['9' => [$snap('2026-11-02 08:00:00', 30), $snap('2026-11-03 08:00:00', 40), $snap('2026-11-03 09:00:00', 30)]], [], [], [], [], '2026-11-01');
        $prende = ChangeRules::detect(['9' => [['status' => 'PAUSED', 'daily_budget' => 20.0, 'min_spend' => null, 'at' => '2026-11-02 08:00:00'],
            ['status' => 'ACTIVE', 'daily_budget' => 20.0, 'min_spend' => null, 'at' => '2026-11-03 08:00:00']]], [], [], [], [], '2026-11-01');

        expect($ida)->toBe([])
            ->and(array_column($prende, 'kind'))->toBe(['resume'])
            ->and(ChangeRules::what('resume', $prende[0]['detail']))->toBe('La prendiste otra vez con $20,00 por día');
    });

    it('lo dice en una frase', function () {
        expect(ChangeRules::what('raise', ['from' => 30.0, 'to' => 36.0]))->toBe('De $30,00 a $36,00 por día (+20 %)')
            ->and(ChangeRules::what('lower', ['from' => 36.0, 'to' => 30.0]))->toBe('De $36,00 a $30,00 por día (−17 %)')
            ->and(ChangeRules::what('ads_off', ($this->of)('ads_off')['detail']))->toBe('Apagaste C0410 FLEX y el batch B0100 FLEX')
            ->and(ChangeRules::what('min_off', ['adset' => 'B0095 FLEX', 'min' => 5.0]))->toBe('Quitaste el mínimo de $5,00 de B0095 FLEX')
            ->and(ChangeRules::what('new_batch', ['adset' => 'B0121 FLEX']))->toBe('Lanzaste B0121 FLEX');
    });
});

describe('.131: la hipótesis de cada cambio', function () {
    it('con los números de los 3 días de antes', function () {
        $before = ChangeRules::numbers($this->daily, '2026-11-01', '2026-11-03', 9.0);
        expect($before)->toBe(['from' => '2026-11-01', 'to' => '2026-11-03', 'days' => 3, 'spend' => 90.0, 'purchases' => 15.0, 'cpa' => 6.0, 'per_day' => 5.0, 'gain_day' => 15.0])
            ->and(ChangeRules::hypothesis('raise', ['from' => 30.0, 'to' => 36.0], $before, 6.0, 9.0))->toBe([
                'hypothesis' => 'Si subo el presupuesto de $30,00 a $36,00 (+20 %), la ciudad deja más dinero por día que antes (dejaba $15,00) sin pasar tu BE ($9,00).',
                'criterion' => 'Se cumple si en los días después deja más dinero por día que antes, sin pasar tu BE.'])
            ->and(ChangeRules::hypothesis('raise', ['from' => 30.0, 'to' => 36.0], $before, 6.0, null)['hypothesis'])
            ->toBe('Si subo el presupuesto de $30,00 a $36,00 (+20 %), la ciudad vende más compras por día que antes (5) sin pasar tu C ($6,00).');
    });

    it('bajar busca el BE si vendía encima del BE, y la C si estaba entre C y BE', function () {
        $encima = ['cpa' => 11.0, 'gain_day' => -4.0, 'spend' => 66.0, 'days' => 3, 'per_day' => 2.0];
        $banda = ['cpa' => 7.5] + $encima;
        expect(ChangeRules::hypothesis('lower', ['from' => 36.0, 'to' => 30.0], $encima, 6.0, 9.0)['hypothesis'])
            ->toBe('Si bajo el presupuesto de $36,00 a $30,00 (−17 %), la ciudad vuelve a vender en tu BE o debajo ($9,00).')
            ->and(ChangeRules::hypothesis('lower', ['from' => 36.0, 'to' => 30.0], $banda, 6.0, 9.0)['criterion'])->toBe('Se cumple si en los días después vende en tu C o debajo.')
            ->and(ChangeRules::hypothesis('pause', [], $encima, 6.0, 9.0))->toBe(['hypothesis' => 'Si pauso la campaña, dejo de perder unos $4,00 por día.',
                'criterion' => 'No se mide: la campaña queda apagada y no hay con qué comparar.'])
            ->and(ChangeRules::hypothesis('ads_off', ['ads' => ['C0410 FLEX'], 'adsets' => []], $encima, 6.0, 9.0)['hypothesis'])
            ->toBe('Si apago C0410 FLEX, el CPA de la ciudad baja de $11,00.')
            ->and(ChangeRules::hypothesis('min_off', ['adset' => 'B0095 FLEX', 'min' => 5.0], $encima, 6.0, 9.0)['hypothesis'])
            ->toBe('Si quito el gasto mínimo de B0095 FLEX ($5,00), Meta pasa esa plata a los que más venden y el CPA de la ciudad no sube de $11,00.')
            ->and(ChangeRules::hypothesis('resume', [], $encima, 6.0, 9.0)['hypothesis'])->toBe('Si la prendo otra vez, vuelve a vender en tu BE o debajo ($9,00).');
    });
});

describe('.131: si se cumplió', function () {
    it('subir: deja más dinero por día; se mide hasta el siguiente cambio grande', function () {
        $raise = ($this->of)('raise');
        $before = ChangeRules::numbers($this->daily, '2026-11-01', '2026-11-03', 9.0);
        $next = ChangeRules::nextBig($raise, $this->found);
        $r = ChangeRules::measure('raise', $raise['date'], $before, $this->daily, 6.0, 9.0, $next, '2026-11-10');

        // el batch nuevo del 7/11 corta: se mide el 5 y el 6
        expect($next)->toBe(['date' => '2026-11-07', 'kind' => 'new_batch'])
            ->and($r['result'])->toBe('met')
            ->and($r['after'])->toMatchArray(['from' => '2026-11-05', 'to' => '2026-11-06', 'days' => 2, 'spend' => 72.0, 'purchases' => 13.0, 'gain_day' => 22.5])
            ->and($r['text'])->toBe('Se cumplió: del 05/11 al 06/11 deja $22,50 por día (antes dejaba $15,00) y vende a $5,54 por compra.');

        $peor = array_replace($this->daily, ['2026-11-05' => ['spend' => 36.0, 'purchases' => 4.0], '2026-11-06' => ['spend' => 36.0, 'purchases' => 3.0]]);
        expect(ChangeRules::measure('raise', $raise['date'], $before, $peor, 6.0, 9.0, $next, '2026-11-10')['text'])
            ->toBe('No se cumplió: del 05/11 al 06/11 pierde $4,50 por día (antes dejaba $15,00) y vende a $10,29 por compra.');
    });

    it('apagar anuncios: baja el CPA; quitar el mínimo con un cambio grande al día siguiente: no se puede saber', function () {
        $off = ($this->of)('ads_off');
        $before = ChangeRules::numbers($this->daily, '2026-11-02', '2026-11-04', 9.0);
        $r = ChangeRules::measure('ads_off', $off['date'], $before, $this->daily, 6.0, 9.0, ChangeRules::nextBig($off, $this->found), '2026-11-10');
        expect($r['result'])->toBe('met')->and($r['text'])->toBe('Se cumplió: el 06/11 la ciudad vende a $6,00 por compra (antes $6,20).');

        $min = ($this->of)('min_off');
        expect(ChangeRules::measure('min_off', $min['date'], $before, $this->daily, 6.0, 9.0, ChangeRules::nextBig($min, $this->found), '2026-11-10'))
            ->toBe(['after' => null, 'result' => 'unknown', 'text' => 'No se puede saber: al día siguiente hiciste otro cambio grande en esta ciudad (batch nuevo), y no quedó ni un día para medir.']);
    });

    it('pausar no se mide; dos cambios grandes el mismo día: no se puede saber', function () {
        $before = ChangeRules::numbers($this->daily, '2026-11-05', '2026-11-07', 9.0);
        expect(ChangeRules::measure('pause', '2026-11-08', $before, $this->daily, 6.0, 9.0, null, '2026-11-10')['result'])->toBe('not_measured')
            ->and(ChangeRules::measure('raise', '2026-11-08', $before, $this->daily, 6.0, 9.0, ['date' => '2026-11-08', 'kind' => 'new_batch'], '2026-11-10')['text'])
            ->toBe('No se puede saber: el mismo día hiciste otro cambio grande en esta ciudad (batch nuevo).');
    });

    it('mientras no cierran los días: midiendo; si gastó menos de una C: no se puede saber', function () {
        $before = ['cpa' => 11.0, 'gain_day' => -4.0, 'spend' => 66.0, 'days' => 3, 'per_day' => 2.0];
        expect(ChangeRules::measure('lower', '2026-11-08', $before, $this->daily, 6.0, 9.0, null, '2026-11-10'))
            ->toMatchArray(['result' => 'measuring', 'text' => 'Midiendo: faltan 2 días (se mide hasta el 11/11).'])
            ->and(ChangeRules::measure('lower', '2026-11-09', $before, $this->daily, 6.0, 9.0, null, '2026-11-10'))
            ->toBe(['after' => null, 'result' => 'measuring', 'text' => 'Midiendo: faltan 3 días (se mide hasta el 12/11).'])
            ->and(ChangeRules::measure('lower', '2026-11-05', $before, ['2026-11-06' => ['spend' => 2.0, 'purchases' => 0.0]], 6.0, 9.0, null, '2026-11-10')['text'])
            ->toBe('No se puede saber: del 06/11 al 08/11 gastó muy poco ($2,00; hace falta al menos una C, $6,00).');
    });

    it('bajar: vuelve a vender en el BE o debajo', function () {
        $before = ['cpa' => 11.0, 'gain_day' => -4.0, 'spend' => 66.0, 'days' => 3, 'per_day' => 2.0];
        $r = ChangeRules::measure('lower', '2026-11-01', $before, $this->daily, 6.0, 9.0, null, '2026-11-10');
        expect($r['result'])->toBe('met')->and($r['text'])->toBe('Se cumplió: del 02/11 al 04/11 vende a $6,20 por compra, en tu BE o debajo ($9,00).');
    });
});

describe('.131: un batch nuevo', function () {
    it('se cumple si queda prometedor o PROVEN en esa ciudad, y dice lo que quería mejorar', function () {
        $batch = ['code' => 'B0121', 'status' => 'tested', 'hypothesis' => 'Queremos probar si variando el hook aumentamos la efectividad de este creativo.',
            'verdicts' => [['city' => 'Distrito Capital', 'key' => 'promising', 'label' => 'Prometedor'], ['city' => 'Carabobo', 'key' => 'loser', 'label' => 'Perdedor']],
            'metric' => ['key' => 'hook_rate', 'mine' => 28.4, 'original' => 21.0]];

        expect(ChangeRules::batch($batch, 'B0121 FLEX', 'Distrito Capital', '2026-11-01', '2026-11-10'))->toBe([
            'hypothesis' => 'Queremos probar si variando el hook aumentamos la efectividad de este creativo.',
            'criterion' => 'Se cumple si queda prometedor o PROVEN en esta ciudad.',
            'result' => 'met', 'text' => 'Se cumplió: Prometedor en Distrito Capital. Hook rate: 28,4 % (el original, 21 %), mejoró.'])
            ->and(ChangeRules::batch($batch, 'B0121 FLEX', 'Carabobo', '2026-11-01', '2026-11-10'))->toMatchArray(['result' => 'not_met',
                'text' => 'No se cumplió: Perdedor en Carabobo. Hook rate: 28,4 % (el original, 21 %), mejoró.']);
    });

    it('esperando el veredicto hasta 14 días; un ad set que no es de la cola no se mide', function () {
        $batch = ['code' => 'B0121', 'status' => 'testing', 'hypothesis' => null, 'verdicts' => [], 'metric' => null];
        expect(ChangeRules::batch($batch, 'B0121 FLEX', 'Carabobo', '2026-11-07', '2026-11-10'))->toBe([
            'hypothesis' => 'Si lanzo B0121 en Carabobo, queda prometedor o PROVEN.', 'criterion' => 'Se cumple si queda prometedor o PROVEN en esta ciudad.',
            'result' => 'measuring', 'text' => 'Midiendo: esperando su veredicto en Carabobo (salió el 07/11).'])
            ->and(ChangeRules::batch($batch, 'B0121 FLEX', 'Carabobo', '2026-10-25', '2026-11-10')['result'])->toBe('unknown')
            ->and(ChangeRules::batch(null, 'PRUEBA FLEX', 'Carabobo', '2026-11-07', '2026-11-10')['result'])->toBe('not_measured');
    });
});
