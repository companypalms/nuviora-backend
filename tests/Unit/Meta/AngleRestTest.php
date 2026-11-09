<?php

use App\Services\Meta\Analysis\AnalysisSender;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\Products\AngleProposals;
use App\Services\Meta\Products\AngleRest;
use App\Services\Meta\Queue\ProposalRules;

// .116 (dueño, 2026-11-09): "si hemos testeado ya varios batchs de un mismo ángulo que en algún momento fue ganador, ¿hay
// alguna regla que nos diga que deberíamos testear otro ángulo?". 3 perdidos seguidos: descansa 14 días, aunque antes ganó.

function batchDescanso(string $code, string $launched, string $outcome, ?string $ended = null, ?string $angle = 'POSP'): array
{
    return ['angle' => $angle, 'code' => $code, 'launched' => $launched, 'ended' => $ended ?? $launched, 'outcome' => $outcome];
}

/** Un producto como lo devuelve MetaDecisions::compute, con lo justo para proponer. */
function productoDescanso(array $work = [], array $angles = [], array $extra = []): array
{
    return [
        'product' => ['id' => 1, 'name' => 'FlexiStep', 'mode' => 'normal'],
        'campaigns' => ['c1' => ['city' => 'Caracas', 'city_id' => 1, 'active' => true, 'stock' => 50, 'purchases7' => 20, 'action' => ['key' => 'hold', 'label' => 'Mantener']]],
        'creatives' => [],
        'plan' => ['urgency' => ['level' => 3, 'label' => 'U3', 'batches' => '1', 'reasons' => []], 'money_at_risk' => 0,
            'work' => $work + ['winner' => null, 'main_angle' => 'POSP', 'rested' => [], 'rest_batches' => 3, 'saturated' => []]],
        'profile_angles' => $angles + ['order' => ['POSP', 'NERV', 'TOBI'], 'lost' => [], 'used' => ['POSP'], 'chosen' => null],
    ] + $extra;
}

describe('Cómo terminó un batch', function () {
    $v = fn (string $key, bool $active = false) => ['key' => $key, 'active' => $active];

    it('perdió solo si perdió en todas las ciudades donde se probó', function () use ($v) {
        expect(AngleRest::outcome([$v('loser'), $v('loser')]))->toBe('lost')
            ->and(AngleRest::outcome([$v('loser'), $v('intermediate')]))->toBe('tie')
            ->and(AngleRest::outcome([$v('loser'), $v('promising')]))->toBe('won');
    });

    it('donde no gastó y ya está apagado no se probó; si sigue prendido, todavía está en prueba', function () use ($v) {
        expect(AngleRest::outcome([$v('loser'), $v('no_data')]))->toBe('lost')
            ->and(AngleRest::outcome([$v('loser'), $v('no_data', true)]))->toBeNull()
            ->and(AngleRest::outcome([$v('evaluation', true)]))->toBeNull()
            ->and(AngleRest::outcome([$v('no_data')]))->toBeNull();
    });

    it('junta las ciudades de un mismo batch y usa su primer anuncio y su último gasto', function () {
        $sets = ['a' => ['batch_code' => 'B0101', 'angle_code' => 'POSP', 'name' => 'B0101 POSP', 'active' => false], 'b' => ['batch_code' => 'B0101', 'angle_code' => 'POSP', 'name' => 'B0101 POSP', 'active' => false],
            'c' => ['batch_code' => 'B0102', 'angle_code' => 'POSP', 'name' => 'B0102 POSP', 'active' => true]];
        $ads = [['adset_meta_id' => 'a', 'created_time' => '2026-11-02 10:00:00'], ['adset_meta_id' => 'b', 'created_time' => '2026-11-01 09:00:00'], ['adset_meta_id' => 'c', 'created_time' => '2026-11-03 09:00:00']];
        $verdicts = ['a' => ['key' => 'loser', 'last_spend' => '2026-11-05'], 'b' => ['key' => 'loser', 'last_spend' => '2026-11-07'], 'c' => ['key' => 'evaluation', 'last_spend' => null]];
        expect(AngleRest::batches($sets, $ads, $verdicts))->toBe([['angle' => 'POSP', 'code' => 'B0101', 'launched' => '2026-11-01', 'ended' => '2026-11-07', 'outcome' => 'lost']]);
    });
});

describe('El descanso', function () {
    $lost3 = [batchDescanso('B1', '2026-11-01', 'lost'), batchDescanso('B2', '2026-11-03', 'lost'), batchDescanso('B3', '2026-11-05', 'lost', '2026-11-08')];

    it('3 perdidos seguidos: descansa 14 días desde el último gasto, aunque antes haya ganado', function () use ($lost3) {
        $before = [batchDescanso('B0', '2026-10-20', 'won')];
        expect(AngleRest::state([...$before, ...$lost3], 3, 14, '2026-11-09'))->toBe(['until' => '2026-11-22', 'codes' => ['B1', 'B2', 'B3']]);
    });

    it('con 2 no descansa; un ganado en el medio corta la cuenta; un empate no la corta ni suma', function () use ($lost3) {
        expect(AngleRest::state(array_slice($lost3, 0, 2), 3, 14, '2026-11-09'))->toBeNull();
        expect(AngleRest::state([$lost3[0], batchDescanso('X', '2026-11-02', 'won'), $lost3[1], $lost3[2]], 3, 14, '2026-11-09'))->toBeNull();
        expect(AngleRest::state([$lost3[0], batchDescanso('X', '2026-11-02', 'tie'), $lost3[1], $lost3[2]], 3, 14, '2026-11-09')['codes'])->toBe(['B1', 'B2', 'B3']);
    });

    it('la regla vieja saltaba con 2 perdidos en toda la historia aunque ganara en el medio: ya no', function () {
        $history = [batchDescanso('B1', '2026-06-01', 'lost'), ...array_map(fn ($i) => batchDescanso("G{$i}", "2026-07-0{$i}", 'won'), [1, 2, 3, 4]), batchDescanso('B6', '2026-11-05', 'lost')];
        expect(AngleRest::state($history, 3, 14, '2026-11-09'))->toBeNull();
    });

    it('se acaba solo a los 14 días y la cuenta empieza de cero', function () use ($lost3) {
        expect(AngleRest::state($lost3, 3, 14, '2026-11-22'))->not->toBeNull()
            ->and(AngleRest::state($lost3, 3, 14, '2026-11-23'))->toBeNull();
        $after = [...$lost3, batchDescanso('B4', '2026-11-25', 'lost'), batchDescanso('B5', '2026-11-26', 'lost')];
        expect(AngleRest::state($after, 3, 14, '2026-11-27'))->toBeNull();
    });

    it('si un batch que ya estaba en camino gana, se acaba el descanso; si pierde, no lo alarga', function () use ($lost3) {
        expect(AngleRest::state([...$lost3, batchDescanso('B4', '2026-11-09', 'won')], 3, 14, '2026-11-10'))->toBeNull();
        expect(AngleRest::state([...$lost3, batchDescanso('B4', '2026-11-09', 'lost')], 3, 14, '2026-11-10')['until'])->toBe('2026-11-22');
    });

    it('si el dueño elige ese ángulo, lo de antes ya no cuenta', function () use ($lost3) {
        expect(AngleRest::state($lost3, 3, 14, '2026-11-09', '2026-11-09'))->toBeNull();
    });

    it('cada ángulo lleva su cuenta', function () use ($lost3) {
        $other = batchDescanso('N1', '2026-11-02', 'lost', null, 'NERV');
        expect(array_keys(AngleRest::angles([...$lost3, $other], 3, 14, '2026-11-09')))->toBe(['POSP']);
    });

    it('lo que sigue: primero uno de la ficha sin probar; si no, cualquiera disponible; si no queda, nada', function () {
        expect(AngleRest::next(['POSP', 'NERV', 'TOBI'], ['POSP'], ['POSP', 'NERV']))->toBe('TOBI')
            ->and(AngleRest::next(['POSP', 'NERV'], ['POSP'], ['POSP', 'NERV']))->toBe('NERV')
            ->and(AngleRest::next(['POSP'], ['POSP'], ['POSP']))->toBeNull();
    });
});

describe('Qué se pide', function () {
    $winner = ['id' => 7, 'label' => 'C004 POSP', 'iterate' => 'el hook', 'angle' => 'POSP', 'type' => 'video'];

    it('si el ángulo principal descansa, el encargo es de otro de la ficha, primero uno sin probar', function () {
        $p = ProposalRules::plan([1 => productoDescanso(['rested' => ['POSP']])], [1 => ['2026-11-10']])[0];
        expect($p)->toMatchArray(['kind' => 'new_angle', 'angle_code' => 'NERV', 'needs_angle' => false])
            ->and($p['iterate'])->toContain('de tu ficha que todavía no se probó (NERV)')->toContain('POSP descansa porque perdieron sus últimos 3 batches');
    });

    it('sin descanso: un concepto nuevo dentro de su ángulo, aunque haya perdido alguna vez', function () {
        $p = ProposalRules::plan([1 => productoDescanso()], [1 => ['2026-11-10']])[0];
        expect($p)->toMatchArray(['kind' => 'new_concept', 'angle_code' => 'POSP', 'needs_angle' => false]);
    });

    it('un ganador de otro ángulo se sigue iterando', function () use ($winner) {
        $p = ProposalRules::plan([1 => productoDescanso(['winner' => ['angle' => 'NERV'] + $winner, 'rested' => ['POSP']])], [1 => ['2026-11-10']])[0];
        expect($p)->toMatchArray(['kind' => 'iteration', 'angle_code' => 'NERV']);
    });

    it('sin ángulos disponibles no se inventa ninguno: el encargo queda para que decida el dueño', function () {
        $p = ProposalRules::plan([1 => productoDescanso(['rested' => ['POSP']], ['order' => ['POSP'], 'used' => ['POSP']])], [1 => ['2026-11-10']])[0];
        expect($p)->toMatchArray(['kind' => 'new_angle', 'angle_code' => null, 'needs_angle' => true]);
    });

    it('un concepto nuevo tampoco sale sin ángulo', function () {
        $d = productoDescanso(['main_angle' => null], ['order' => ['POSP'], 'lost' => ['POSP']]);
        $d['plan']['work']['main_angle'] = null;
        expect(ProposalRules::plan([1 => $d], [1 => ['2026-11-10']])[0])->toMatchArray(['angle_code' => null, 'needs_angle' => true]);
    });

    it('lo que eligió el dueño va primero, aunque descanse, haya perdido o haya un ganador', function () use ($winner) {
        $p = ProposalRules::plan([1 => productoDescanso(['winner' => $winner, 'rested' => ['POSP']], ['chosen' => 'POSP', 'lost' => ['POSP']])], [1 => ['2026-11-10']])[0];
        expect($p)->toMatchArray(['kind' => 'new_angle', 'angle_code' => 'POSP', 'needs_angle' => false, 'iterate' => 'el ángulo que eligió el dueño (POSP)']);
    });

    it('el trabajo siguiente lo dice en palabras', function () {
        $r = new CampaignRules((new \App\Services\Meta\Decisions\DecisionSettings())->defaults());
        expect($r->nextWork(['main_angle' => 'FASP', 'rested' => ['FASP'], 'rest_batches' => 3], '1')[0])->toBe('Otro ángulo: FASP descansa porque perdieron sus últimos 3 batches.')
            ->and($r->nextWork(['main_angle' => 'FASP', 'rested' => []], '1')[0])->toBe('Un concepto nuevo dentro de FASP.');
    });
});

describe('Los creativos que descansan', function () {
    $cr = fn (string $verdict, ?string $rest) => ['verdict' => ['key' => $verdict, 'causes' => []], 'video' => ['iterate' => 'hook', 'stages' => []], 'cities' => [], 'rest' => $rest];

    it('sigue prendido, pero no se piden más versiones y al editor no le llega nada', function () use ($cr) {
        $call = CreativeCalls::call($cr('proven', 'No pidas más versiones: el ángulo POSP descansa hasta el 22/11.'));
        expect($call['key'])->toBe('keep')->and($call['editor'])->toBeNull()
            ->and($call['todo'])->toBe('Va ganando: no lo toques. No pidas más versiones: el ángulo POSP descansa hasta el 22/11.');
    });

    it('sin descanso, todo como antes', function () use ($cr) {
        $call = CreativeCalls::call($cr('proven', null));
        expect($call['todo'])->toBe('Va ganando: no lo toques y pide iteraciones de él.')->and($call['editor'])->not->toBeNull();
    });

    it('uno flojo que descansa no se manda a iterar', function () use ($cr) {
        expect(CreativeCalls::call($cr('intermediate', 'No pidas más versiones.'))['key'])->toBe('keep');
        $facts = [['product_id' => 1, 'mode' => 'normal', 'urgency' => ['level' => 3], 'creatives' => [['id' => 7, 'verdict_key' => 'proven', 'call' => CreativeCalls::call($cr('proven', 'x'))]]]];
        expect(AnalysisSender::picks($facts))->toBe([]);
    });
});

describe('El aviso del análisis', function () {
    $facts = fn (?string $next) => [['product_id' => 1, 'product' => 'FlexiStep', 'mode' => 'normal', 'urgency' => null, 'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null,
        'alarms' => [], 'tracking' => null, 'cities' => [], 'creatives' => [], 'batches' => [], 'stop_by_city' => [], 'stop_all' => [],
        'rests' => [['angle' => 'POSP', 'until' => '2026-11-22', 'codes' => ['B0101', 'B0102', 'B0103']]], 'next_angle' => $next]];

    it('dice qué perdió, hasta cuándo descansa y qué sigue, y no cuenta como misión', function () use ($facts) {
        $i = \App\Services\Meta\Analysis\UrgentList::build($facts('NERV'))[0];
        expect($i)->toMatchArray(['kind' => 'angle_rest', 'group' => 'aviso', 'where' => 'FlexiStep'])
            ->and($i['title'])->toBe('El ángulo POSP descansa hasta el 22/11: perdieron sus últimos 3 batches (B0101, B0102 y B0103). El próximo encargo será de NERV.')
            ->and(\App\Services\Meta\Analysis\UrgentList::tally([$i], [])['tasks'])->toBe(0);
    });

    it('si ya no quedan ángulos, avisa que llegará la propuesta', function () use ($facts) {
        expect(\App\Services\Meta\Analysis\UrgentList::build($facts(null))[0]['title'])->toContain('te llegará una propuesta a tu calendario');
    });
});

describe('El código de un ángulo nuevo', function () {
    it('sale del nombre, sin acentos, y no repite uno que ya existe', function () {
        expect(AngleProposals::code('Dolor de espalda en la oficina', []))->toBe('DOLESP')
            ->and(AngleProposals::code('Tobillo', []))->toBe('TOBILL')
            ->and(AngleProposals::code('Ángulo óptimo', []))->toBe('ANGOPT')
            ->and(AngleProposals::code('Dolor de espalda', ['DOLESP']))->toBe('DOLES2');
    });

    it('lo de la IA se limpia y nunca repite un código', function () {
        $a = ['codigo' => 'pos p', 'nombre' => 'Posparto reciente', 'de_que_trata' => ' x ', 'por_que' => ''];
        expect(AngleProposals::clean($a, []))->toMatchArray(['code' => 'POSP', 'name' => 'Posparto reciente', 'about' => 'x', 'why' => null])
            ->and(AngleProposals::clean($a, ['POSP'])['code'])->toBe('POSREC');
    });
});
