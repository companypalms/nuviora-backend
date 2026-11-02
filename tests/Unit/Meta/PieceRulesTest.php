<?php

use App\Services\Meta\Analysis\AnalysisSender;
use App\Services\Meta\Queue\PieceRules;
use App\Services\Meta\Queue\QueueRules;

// .81 (dueño, 2026-11-02): "2 formatos distintos + 1 cambio de hook. Pero el sistema siempre debe saber qué hipótesis se
// está probando en cada creativo, para que aprendamos de lo que funciona y lo que no". El formato lo elige el sistema.

$stat = fn (int $won, int $lost = 0, int $tie = 0) => ['won' => $won, 'lost' => $lost, 'tie' => $tie, 'total' => $won + $lost + $tie];

describe('Qué formatos probar en un ganador sano', function () use ($stat) {
    it('primero los que nunca se probaron en el producto, en el orden del dueño', function () {
        expect(PieceRules::pickFormats(2, [], []))->toBe([
            ['style' => 'imagen', 'reason' => 'Nunca se probó en este producto.'],
            ['style' => 'ugc', 'reason' => 'Nunca se probó en este producto.'],
        ]);
    });

    it('entre los no probados, primero el que más ganó en tus otros productos', function () use ($stat) {
        $f = PieceRules::pickFormats(2, ['imagen' => $stat(0, 1)], ['testimonio' => $stat(3), 'ugc' => $stat(1, 2)]);
        expect(array_column($f, 'style'))->toBe(['testimonio', 'ugc'])
            ->and($f[0]['reason'])->toBe('Nunca se probó en este producto y ganó 3 veces en tus otros productos.');
    });

    it('nunca el del creativo original ni uno en camino; si ya se probaron todos, los que ganaron (nunca los que solo perdieron)', function () use ($stat) {
        $all = array_fill_keys(PieceRules::FORMAT_ORDER, $stat(0, 1));
        $all['ugc'] = $stat(2, 1);
        $all['testimonio'] = $stat(1);
        expect(PieceRules::pickFormats(2, $all, []))->toBe([
            ['style' => 'ugc', 'reason' => 'Ya ganó en este producto (2 de 3).'],
            ['style' => 'testimonio', 'reason' => 'Ya ganó en este producto (1 de 1).'],
        ]);
        expect(array_column(PieceRules::pickFormats(2, [], [], ['imagen', 'testimonio']), 'style'))->toBe(['ugc', 'entrevista'])
            ->and(PieceRules::pickFormats(2, $all, [], ['ugc', 'testimonio']))->toBe([]);
    });
});

describe('Las piezas de un encargo del análisis', function () {
    it('un ganador sano: 1 hook nuevo + 2 formatos, cada pieza con qué hacer y su hipótesis', function () {
        $plan = PieceRules::plan(null, 3, [['style' => 'imagen', 'reason' => 'Nunca se probó en este producto.'], ['style' => 'ugc', 'reason' => 'x']]);
        expect(array_column($plan, 'test'))->toBe(['winner_hook', 'format', 'format'])
            ->and(array_column($plan, 'format'))->toBe(['video', 'image', 'video'])
            ->and($plan[0]['change'])->toBe('Un hook distinto (los primeros 3 segundos), con el mismo cuerpo.')
            ->and($plan[0]['hypothesis'])->toBe('Probamos si un hook nuevo, con el mismo cuerpo, hace que este ganador dure más.')
            ->and($plan[1]['change'])->toBe('El mismo mensaje y la misma promesa en imagen.')
            ->and($plan[1]['hypothesis'])->toBe('Probamos si el mismo mensaje en imagen también vende.')
            ->and($plan[1]['reason'])->toBe('Nunca se probó en este producto.')
            ->and($plan[2]['hypothesis'])->toBe('Probamos si el mismo mensaje en formato UGC también vende.');
    });

    it('sin formatos para probar, más hooks nuevos', function () {
        expect(array_column(PieceRules::plan(null, 3, []), 'test'))->toBe(['winner_hook', 'winner_hook', 'winner_hook']);
    });

    it('un tramo flojo: 3 variaciones de ese tramo, cada una con su hipótesis', function () {
        $plan = PieceRules::plan('middle', 3);
        expect(array_column($plan, 'test'))->toBe(['middle', 'middle', 'middle'])
            ->and($plan[1]['change'])->toBe('Variación 2: un medio más corto y con más ritmo, distinto al de las otras piezas.')
            ->and($plan[0]['hypothesis'])->toBe('Probamos si un medio más corto y con más ritmo hace que más gente llegue al final.');
        expect(PieceRules::plan('format', 2)[1])->toMatchArray(['test' => 'format', 'style' => 'imagen', 'format' => 'image'])
            ->and(PieceRules::plan('fatigue', 1)[0]['test'])->toBe('fatigue');
    });

    it('el encargo de un ganador sano dice 1 hook + los formatos', function () {
        $b = AnalysisSender::brief(['key' => 'scale', 'editor' => 'Qué hacer: …', 'fix' => null], null, 3, ['Imagen', 'UGC']);
        expect($b['goal'])->toBe('1 hook nuevo con el mismo cuerpo y 2 versiones del mismo mensaje en otro formato (imagen y UGC). Mira qué hacer en cada pieza.')
            ->and($b['keep'])->toBe('El mensaje y la promesa: funcionan.');
    });
});

describe('Lo que lee el editor y lo que se aprende', function () {
    it('la hoja del encargo dice qué hacer y qué probamos en cada pieza, sin plata', function () {
        $brief = QueueRules::brief(['code' => 'B0110', 'product' => 'ZenPulse', 'kind' => 'iteration', 'reference' => 'CREATIVO 2', 'angle_code' => null,
            'angle_name' => null, 'format' => 'mixed', 'style' => null, 'due_date' => null, 'pieces' => [
                ['code' => 'C0461', 'change' => 'Un hook distinto (los primeros 3 segundos), con el mismo cuerpo.', 'format' => 'video', 'test_label' => 'Hook nuevo en un ganador',
                    'hypothesis' => 'Probamos si un hook nuevo, con el mismo cuerpo, hace que este ganador dure más.'],
                ['code' => 'C0462', 'change' => 'El mismo mensaje y la misma promesa en imagen.', 'format' => 'image', 'test_label' => 'Otro formato',
                    'hypothesis' => 'Probamos si el mismo mensaje en imagen también vende a $25.'],
            ]]);
        expect($brief)->toContain("Qué hacer en cada pieza:\n1. C0461 — Hook nuevo en un ganador: Un hook distinto (los primeros 3 segundos), con el mismo cuerpo. (video) Qué probamos: Probamos si un hook nuevo")
            ->toContain('2. C0462 — Otro formato: El mismo mensaje y la misma promesa en imagen. (imagen)')
            ->not->toContain('$25');
    });

    it('el resultado de cada pieza: ganó si en alguna ciudad ganó, perdió si perdió en todas', function () {
        expect(PieceRules::outcome(['loser', 'proven']))->toBe('Ganó')
            ->and(PieceRules::outcome(['loser', 'loser']))->toBe('Perdió')
            ->and(PieceRules::outcome(['loser', 'intermediate']))->toBe('Empate')
            ->and(PieceRules::outcome([]))->toBeNull()
            ->and(PieceRules::groupLabel('format', 'ugc'))->toBe('Formato UGC')
            ->and(PieceRules::groupLabel('new_angle', null))->toBe('Ángulo nuevo');
    });
});
