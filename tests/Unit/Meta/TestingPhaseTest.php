<?php

use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\TestingRules;
use App\Services\Meta\Queue\ProposalRules;
use App\Services\Meta\Queue\QueueWriter;
use Carbon\CarbonImmutable;

// .65 (dueño, 2026-11-01): "en testeo siempre probamos solamente 3 batch, 1 por cada ángulo principal". Con 2 ángulos, 2
// del más fuerte y 1 del otro; con 1 ángulo, 2 batches; cuando se repite el ángulo, conceptos o formatos distintos.

/** Un producto como lo devuelve MetaDecisions::compute, con lo justo para proponer (como productoCola). */
function productoTesteo(int $id, string $name, int $level, string $batches, array $work = [], array $extra = []): array
{
    return array_replace_recursive([
        'product' => ['id' => $id, 'name' => $name, 'mode' => 'normal'],
        'campaigns' => ["c{$id}" => ['city' => 'Caracas', 'city_id' => 1, 'active' => true, 'stock' => 50, 'purchases7' => 20, 'action' => ['key' => 'hold', 'label' => 'Mantener']]],
        'creatives' => [],
        'plan' => ['urgency' => ['level' => $level, 'label' => "U{$level}", 'batches' => $batches, 'reasons' => []], 'money_at_risk' => 0,
            'work' => $work + ['winner' => null, 'main_angle' => 'FASP', 'failed_batches_in_angle' => 0, 'saturated' => []]],
    ], $extra);
}

$item = fn (string $product, string $angle, int $n = 1, int $of = 1) => ['product_id' => 99, 'product' => $product, 'kind' => $n === 1 ? 'new_angle' : 'new_concept',
    'angle_code' => $angle, 'reference_id' => null, 'reference' => null, 'reference_type' => null, 'city_id' => null, 'city' => null, 'format' => 'video',
    'urgency' => null, 'persona_id' => 7, 'iterate' => "el testeo del ángulo {$angle}", 'reason' => "Testeo de {$product} (ronda 1).",
    'testing' => ['round' => 1, 'n' => $n, 'of' => $of, 'siblings' => [], 'persona' => 'Hombre con ginecomastia']];

describe('Los 3 batches del testeo', function () {
    it('3 o más ángulos: uno de cada uno de los 3 más fuertes; 2 ángulos: 2 del más fuerte y 1 del otro; 1 ángulo: 2', function () {
        expect(TestingRules::slots(['GINE', 'ABDO', 'FAJA', 'POST']))->toBe(['GINE', 'ABDO', 'FAJA'])
            ->and(TestingRules::slots(['GINE', 'ABDO']))->toBe(['GINE', 'GINE', 'ABDO'])
            ->and(TestingRules::slots(['GINE']))->toBe(['GINE', 'GINE'])
            ->and(TestingRules::slots([]))->toBe([]);
    });

    it('pide solo lo que falta, sin repetir un batch que ya existe', function () {
        expect(TestingRules::missing(['GINE', 'GINE', 'ABDO'], ['GINE']))->toBe([['angle' => 'GINE', 'n' => 2, 'of' => 2], ['angle' => 'ABDO', 'n' => 1, 'of' => 1]])
            ->and(TestingRules::missing(['GINE', 'ABDO', 'FAJA'], ['GINE', 'ABDO', 'FAJA']))->toBe([]);
    });

    it('una ronda nueva usa los ángulos que no salieron antes', function () {
        expect(TestingRules::available(['GINE', 'ABDO', 'FAJA', 'POST', 'DEPO'], ['GINE', 'ABDO', 'FAJA']))->toBe(['POST', 'DEPO']);
    });

    it('la ronda termina cuando todos sus batches se testearon: ganó si alguno ganó, perdió si todos perdieron, si no, empate', function () {
        $won = ['status' => 'tested', 'verdicts' => [['key' => 'promising']]];
        $lost = ['status' => 'tested', 'verdicts' => [['key' => 'loser']]];
        $tie = ['status' => 'tested', 'verdicts' => [['key' => 'intermediate']]];
        $slots = ['GINE', 'GINE', 'ABDO'];
        expect(TestingRules::outcome($slots, [['angle' => 'GINE'] + $won, ['angle' => 'ABDO'] + $lost]))->toBeNull(); // falta el 2.º de GINE
        expect(TestingRules::outcome($slots, [['angle' => 'GINE'] + $won, ['angle' => 'GINE', 'status' => 'testing', 'verdicts' => []], ['angle' => 'ABDO'] + $lost]))->toBeNull();
        expect(TestingRules::outcome($slots, [['angle' => 'GINE'] + $lost, ['angle' => 'GINE'] + $won, ['angle' => 'ABDO'] + $lost]))
            ->toBe(['won' => ['GINE'], 'lost' => ['ABDO'], 'tie' => []]);
        expect(TestingRules::outcome(['GINE', 'ABDO', 'FAJA'], [['angle' => 'GINE'] + $tie, ['angle' => 'ABDO'] + $lost, ['angle' => 'FAJA'] + $lost]))
            ->toBe(['won' => [], 'lost' => ['ABDO', 'FAJA'], 'tie' => ['GINE']]);
    });

    it('cada lugar dice en qué va', function () {
        expect(TestingRules::slotLabel(null))->toBe('Por pedir')
            ->and(TestingRules::slotLabel(['status' => 'production', 'code' => 'B0123', 'verdicts' => []]))->toBe('En producción (B0123)')
            ->and(TestingRules::slotLabel(['status' => 'tested', 'code' => 'B0123', 'verdicts' => [['key' => 'loser']]]))->toBe('Perdió (B0123)');
    });

    it('en palabras, para la tarjeta', function () {
        expect(ProductProfileRules::testingText(['GINE', 'ABDO']))->toBe('2 batches de GINE (con conceptos o formatos distintos) y 1 batch de ABDO')
            ->and(ProductProfileRules::testingText(['GINE']))->toBe('2 batches de GINE (con conceptos o formatos distintos)')
            ->and(ProductProfileRules::testingText(['GINE', 'ABDO', 'FAJA']))->toBe('1 batch de GINE, 1 batch de ABDO y 1 batch de FAJA');
    });
});

describe('Las propuestas de la mañana', function () use ($item) {
    it('el testeo va después del mínimo de lo que pierde dinero (U1 y U2) y antes de lo demás', function () use ($item) {
        $d = [1 => productoTesteo(1, 'Urgente', 1, '2–3'), 2 => productoTesteo(2, 'Escalando', 3, '1')];
        $testing = [[$item('Nuevo', 'GINE'), $item('Nuevo', 'GINE', 2, 2), $item('Nuevo', 'ABDO')]];
        expect(array_column(ProposalRules::plan($d, [], 4, $testing), 'product'))->toBe(['Urgente', 'Urgente', 'Nuevo', 'Nuevo']);
        expect(array_column(ProposalRules::plan($d, [], 10, $testing), 'product'))->toBe(['Urgente', 'Urgente', 'Nuevo', 'Nuevo', 'Nuevo', 'Escalando', 'Urgente']);
        // Sin testeo, todo igual que antes
        expect(array_column(ProposalRules::plan($d, [], 10), 'product'))->toBe(['Urgente', 'Urgente', 'Escalando', 'Urgente']);
    });

    it('un ángulo que perdió en el testeo no recibe más encargos; el ángulo nuevo es primero uno de la ficha sin probar', function () {
        $pa = ['order' => ['FASP', 'NERV', 'TOBI'], 'lost' => ['FASP'], 'used' => ['FASP']];
        $concept = ProposalRules::plan([1 => productoTesteo(1, 'Prod', 4, '1', [], ['profile_angles' => $pa])], [], 3)[0];
        expect($concept['kind'])->toBe('new_concept')->and($concept['angle_code'])->toBe('NERV');
        $angle = ProposalRules::plan([1 => productoTesteo(1, 'Prod', 4, '1', ['failed_batches_in_angle' => 2],
            ['profile_angles' => ['order' => ['FASP', 'NERV', 'TOBI'], 'lost' => [], 'used' => ['FASP', 'NERV']]])], [], 3)[0];
        expect($angle['kind'])->toBe('new_angle')->and($angle['angle_code'])->toBe('TOBI')->and($angle['iterate'])->toContain('de tu ficha que todavía no se probó (TOBI)');
        // Sin ficha, como antes: la IA propone el ángulo nuevo
        expect(ProposalRules::plan([1 => productoTesteo(1, 'Prod', 4, '1', ['failed_batches_in_angle' => 2])], [], 3)[0]['angle_code'])->toBeNull();
    });

    it('la IA sabe que es el testeo y que dos batches del mismo ángulo tienen que ser distintos', function () use ($item) {
        $p = $item('Nuevo', 'GINE', 2, 2);
        $p['testing']['siblings'] = ['Antes y después en el espejo'];
        $plan = [['key' => 'E1', 'pieces' => 3, 'context' => ['price' => null, 'proof' => null, 'angles' => ['GINE' => 'Ginecomastia'], 'profile' => '', 'personas' => []]] + $p];
        expect(QueueWriter::input($plan, []))->toContain('== ENCARGO E1 · Nuevo · Testeo · concepto nuevo · 3 versiones')
            ->toContain('Testeo (ronda 1): batch 2 de 2 de este ángulo. Cada batch de este ángulo prueba una hipótesis distinta: otro concepto u otro formato.')
            ->toContain('ya hay otro batch de este ángulo con el concepto «Antes y después en el espejo»: este tiene que ser distinto')
            ->toContain('Buyer persona del ángulo: Hombre con ginecomastia')
            ->toContain('Ángulo: GINE — Ginecomastia');
    });
});

describe('La ficha con sus ángulos, en la tarjeta del asistente', function () {
    $ficha = fn () => ['what_it_is' => 'Chaleco térmico reductor', 'benefits' => 'Rebajar y definir', 'mechanism' => 'Guarda el calor', 'not_this' => null, 'key_info' => null, 'photos_url' => null];
    $gine = fn () => ['id' => 7, 'name' => 'Hombre con ginecomastia', 'who' => 'Hombre de 25 a 45', 'pain' => 'Vergüenza', 'wants' => null, 'objections' => null];
    $args = fn (array $over = []) => $over + ['producto' => 'Comprimax', 'que_es' => null, 'para_que_sirve' => null, 'como_funciona' => null,
        'lo_que_no_es' => null, 'info_clave' => null, 'fotos' => null, 'buyer_personas' => [], 'quitar_buyer_personas' => [], 'angulos' => [], 'fase' => 'sin_cambio'];

    it('ángulos del más fuerte al más débil, la fase En testeo y lo que va a pedir el testeo', function () use ($ficha, $gine, $args) {
        $p = ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args([
            'angulos' => [['codigo' => 'gine', 'nombre' => 'Ginecomastia', 'hipotesis' => 'El que más vergüenza da', 'buyer' => 'Hombre con ginecomastia'],
                ['codigo' => 'ABDO', 'nombre' => 'Abdomen plano', 'hipotesis' => null, 'buyer' => null]],
            'fase' => 'testeo',
        ]));
        $lines = array_column($p->lines, null, 'label');
        expect($lines['Ángulos (del más fuerte al más débil)']['value'])->toBe("1. GINE · Ginecomastia → Hombre con ginecomastia\n2. ABDO · Abdomen plano");
        expect($lines['Hipótesis de los ángulos (solo tú)']['value'])->toBe('GINE: El que más vergüenza da');
        expect($lines['Fase'])->toBe(['label' => 'Fase', 'value' => 'En testeo', 'before' => 'Iterar lo que funciona']);
        expect($lines['El testeo pide']['value'])->toBe('2 batches de GINE (con conceptos o formatos distintos) y 1 batch de ABDO');
        expect($p->summary)->toBe('Cambiar la ficha de Comprimax: los ángulos y la fase');
        expect($p->payload['phase'])->toBe('testing')->and($p->payload['angles'][0]['code'])->toBe('GINE');
    });

    it('sin ángulos nuevos los de antes quedan; avisa si el testeo no tiene ángulos; no deja un buyer que no está o un código malo', function () use ($ficha, $gine, $args) {
        $before = [['code' => 'GINE', 'name' => 'Ginecomastia', 'hypothesis' => null, 'persona' => 'Hombre con ginecomastia']];
        $p = ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['que_es' => 'Chaleco térmico reductor para hombre']), $before);
        expect($p->payload['angles'])->toBeNull()->and($p->payload['phase'])->toBeNull();
        expect(array_column($p->lines, 'value', 'label')['Ángulos (del más fuerte al más débil)'])->toBe('1. GINE · Ginecomastia → Hombre con ginecomastia');
        expect(ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['fase' => 'testeo']))->warnings)
            ->toContain('En testeo, pero sin ángulos: el testeo no puede empezar hasta que la ficha tenga sus ángulos.');
        expect(fn () => ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['angulos' => [['codigo' => 'GINE', 'nombre' => 'Gine', 'hipotesis' => null, 'buyer' => 'Mujer']]])))
            ->toThrow(ToolProblem::class, 'GINE: no hay ningún buyer persona que se llame "Mujer" en la ficha.');
        expect(fn () => ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['angulos' => [['codigo' => 'G', 'nombre' => 'Gine', 'hipotesis' => null, 'buyer' => null]]])))
            ->toThrow(ToolProblem::class, 'el código va con 2 a 12 letras o números');
    });

    it('una ronda nueva no repite los ángulos que ya se probaron, y la tarjeta lo dice', function () use ($ficha, $gine, $args) {
        $before = [['code' => 'GINE', 'name' => 'Ginecomastia', 'hypothesis' => null, 'persona' => null], ['code' => 'ABDO', 'name' => 'Abdomen', 'hypothesis' => null, 'persona' => null],
            ['code' => 'FAJA', 'name' => 'Faja', 'hypothesis' => null, 'persona' => null]];
        $p = ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['fase' => 'testeo']), $before, 'iterate', ['GINE']);
        expect(array_column($p->lines, 'value', 'label')['El testeo pide'])
            ->toBe('2 batches de ABDO (con conceptos o formatos distintos) y 1 batch de FAJA (los ángulos que ya se probaron no se repiten)');
    });

    it('en el modo ejemplo, "angulo: …" y "fase: testeo"', function () {
        $c = ExampleModel::change('Ficha de Comprimax: angulo: GINE / Ginecomastia / Hombre con ginecomastia; angulo: ABDO / Abdomen plano; fase: testeo',
            CarbonImmutable::parse('2026-11-01 10:00'));
        expect($c['arguments']['fase'])->toBe('testeo');
        expect($c['arguments']['angulos'])->toBe([
            ['codigo' => 'GINE', 'nombre' => 'Ginecomastia', 'buyer' => 'Hombre con ginecomastia', 'hipotesis' => null],
            ['codigo' => 'ABDO', 'nombre' => 'Abdomen plano', 'buyer' => null, 'hipotesis' => null],
        ]);
    });
});
