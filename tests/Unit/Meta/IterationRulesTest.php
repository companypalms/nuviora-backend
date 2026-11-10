<?php

use App\Services\Meta\Queue\IterationRules;
use App\Services\Meta\Queue\PieceRules;
use App\Services\Meta\Queue\ProposalRules;

// .133 (dueño, 2026-11-10, B0104 y B0108 de Cervimax: "me parece raro que el sistema pida dos veces que haga iteraciones de
// hook de un mismo creativo, creo que eso no es correcto"; eligió "una a la vez y otro tipo"). El 4/11 se pidió B0108 (hook
// de CRE002) cuando B0104 (hook del mismo CRE002) todavía estaba en producción: la variación del análisis lo saltó, pero las
// reglas lo volvieron a pedir.

function iteracionProducto(array $winner): array
{
    return [
        'product' => ['id' => 1, 'name' => 'Cervimax', 'mode' => 'normal'],
        'campaigns' => ['c1' => ['city' => 'Caracas', 'city_id' => 1, 'active' => true, 'stock' => 50, 'purchases7' => 20, 'action' => ['key' => 'hold', 'label' => 'Mantener']]],
        'creatives' => [],
        'plan' => ['urgency' => ['level' => 2, 'label' => 'U2', 'batches' => '1', 'reasons' => []], 'money_at_risk' => 0,
            'work' => ['winner' => $winner, 'main_angle' => 'NERV', 'rested' => [], 'rest_batches' => 3, 'saturated' => []]],
        'profile_angles' => ['order' => ['NERV', 'CUEL'], 'lost' => [], 'used' => ['NERV'], 'chosen' => null],
    ];
}

describe('.133: el tipo de cada variación', function () {
    it('todo lo que cambia los primeros segundos es hook; el formato y cada tramo, aparte', function () {
        expect(array_map([IterationRules::class, 'family'], ['hook', 'fatigue', 'winner_hook', 'iteration', null, 'format', 'after_hook', 'click']))
            ->toBe(['hook', 'hook', 'hook', 'hook', 'hook', 'format', 'after_hook', 'click'])
            ->and(IterationRules::families(['winner_hook', 'format', 'format']))->toBe(['hook', 'format']);
    });

    it('la siguiente no repite el tipo de la anterior', function () {
        expect(IterationRules::nextTest('hook', []))->toBe('hook')
            ->and(IterationRules::nextTest('hook', ['hook']))->toBe('format')
            ->and(IterationRules::nextTest('after_hook', ['hook']))->toBe('after_hook')
            ->and(IterationRules::nextTest('format', ['format']))->toBe('winner_hook')
            // ya probó hook y formato: este creativo no se itera ahora
            ->and(IterationRules::nextTest('winner_hook', ['hook', 'format']))->toBeNull();
    });

    it('las piezas: las de otro tipo se quedan y las demás pasan a otro formato (o a hooks, si la anterior fue de formato)', function () {
        $formats = [['style' => 'testimonio', 'reason' => 'No se probó.'], ['style' => 'ugc', 'reason' => 'Ganó en otros.'], ['style' => 'entrevista', 'reason' => 'No se probó.']];
        $hooks = PieceRules::plan('hook', 3);
        // un ganador sano: 1 hook + 2 formatos
        $sano = PieceRules::plan(null, 3, array_slice($formats, 0, 2));

        expect(array_column(IterationRules::avoidRepeat($hooks, ['hook'], $formats, 'video'), 'style'))->toBe(['testimonio', 'ugc', 'entrevista'])
            ->and(array_column(IterationRules::avoidRepeat($hooks, ['hook'], $formats, 'video'), 'test'))->toBe(['format', 'format', 'format'])
            // del sano se quedan sus 2 formatos y el hook pasa a otro formato que no lleve ya otra pieza
            ->and(array_column(IterationRules::avoidRepeat($sano, ['hook'], $formats, 'video'), 'style'))->toBe(['testimonio', 'ugc', 'entrevista'])
            ->and(array_column(IterationRules::avoidRepeat($sano, ['format'], $formats, 'video'), 'test'))->toBe(['winner_hook', 'winner_hook', 'winner_hook'])
            ->and(IterationRules::avoidRepeat($hooks, [], $formats, 'video'))->toBe($hooks)
            ->and(IterationRules::avoidRepeat($sano, ['hook', 'format'], $formats, 'video'))->toBeNull();
    });
});

describe('.133: las reglas no vuelven a pedir el mismo creativo', function () {
    $winner = ['id' => 346, 'label' => 'CRE002-CERV-NERV-REF009- BATCH005', 'iterate' => 'el hook', 'iterate_key' => 'hook', 'angle' => 'NERV', 'type' => 'video'];

    it('si su variación anterior todavía no tiene resultado, se pide un concepto nuevo', function () use ($winner) {
        $p = ProposalRules::plan([1 => iteracionProducto($winner)], [1 => ['2026-11-06']], [], [], ['skip' => [346], 'prev' => []])[0];

        expect($p)->toMatchArray(['kind' => 'new_concept', 'reference_id' => null, 'angle_code' => 'NERV']);
    });

    it('con el resultado de la anterior: otra variación, pero de otro tipo', function () use ($winner) {
        $libre = ProposalRules::plan([1 => iteracionProducto($winner)], [1 => ['2026-11-06']])[0];
        $despues = ProposalRules::plan([1 => iteracionProducto($winner)], [1 => ['2026-11-06']], [], [], ['skip' => [], 'prev' => [346 => ['hook']]])[0];
        $todo = ProposalRules::plan([1 => iteracionProducto($winner)], [1 => ['2026-11-06']], [], [], ['skip' => [], 'prev' => [346 => ['hook', 'format']]])[0];

        expect($libre)->toMatchArray(['kind' => 'iteration', 'reference_id' => 346, 'piece_test' => 'hook'])
            ->and($despues)->toMatchArray(['kind' => 'iteration', 'reference_id' => 346, 'piece_test' => 'format'])
            ->and($despues['iterate'])->toBe('el formato: el mismo mensaje en otro formato')
            ->and($todo)->toMatchArray(['kind' => 'new_concept', 'reference_id' => null]);
    });
});
