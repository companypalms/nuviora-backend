<?php

use App\Services\Assistant\Actions\Tools\ProductNoteTool;
use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Meta\Products\ProductMemoryRules;
use App\Services\Meta\Queue\QueueRules;
use App\Services\Meta\Queue\QueueWriter;
use Carbon\CarbonImmutable;

// .66 (dueño, 2026-11-01): "la IA debe ser capaz de aprender de cada hipótesis positiva o negativa que estamos probando…
// sabiendo qué funciona y qué no, tanto en ángulos, formatos, conceptos, buyers personas". Lo que sabemos de cada producto.

$b = fn (string $code, ?string $angle, ?string $persona, ?string $style, string $outcome, ?string $learning = null) => ['code' => $code, 'angle' => $angle,
    'angle_name' => $angle === 'GINE' ? 'Ginecomastia' : null, 'persona' => $persona, 'style' => $style, 'concept' => null, 'outcome' => $outcome,
    'learning' => $learning, 'tested_at' => '2026-10-30'];

describe('Los números de lo que sabemos', function () use ($b) {
    $batches = fn () => [
        $b('B0103', 'GINE', 'Hombre con ginecomastia', 'testimonio', 'Ganó', 'El testimonio de un hombre real engancha.'),
        $b('B0102', 'GINE', 'Hombre con ginecomastia', 'animado', 'Perdió', 'El animado no genera confianza.'),
        $b('B0101', 'ABDO', 'Gym', 'testimonio', 'Ganó'),
        $b('B0100', 'ABDO', 'Gym', 'venta_directa', 'Empate'),
    ];

    it('por ángulo, buyer persona y estilo: cuántos ganaron, perdieron o empataron, con sus aprendizajes', function () use ($batches) {
        $f = ProductMemoryRules::facts($batches(), ['GINE', 'ABDO', 'FAJA'], ['Hombre con ginecomastia', 'Gym', 'Mujer'], []);
        expect($f['tested'])->toBe(4)->and($f['totals'])->toBe(['won' => 2, 'lost' => 1, 'tie' => 1]);
        $gine = collect($f['groups']['angle'])->firstWhere('subject', 'GINE');
        expect($gine)->toMatchArray(['label' => 'GINE · Ginecomastia', 'won' => 1, 'lost' => 1, 'tie' => 0, 'total' => 2])
            ->and($gine['lessons'])->toBe(['B0103: El testimonio de un hombre real engancha.', 'B0102: El animado no genera confianza.']);
        $styles = collect($f['groups']['style'])->keyBy('subject');
        expect($styles['testimonio'])->toMatchArray(['label' => 'Testimonio', 'won' => 2, 'total' => 2])
            ->and($styles['animado'])->toMatchArray(['won' => 0, 'lost' => 1]);
        expect($f['groups']['style'][0]['subject'])->toBe('testimonio'); // el que más ganó, primero
    });

    it('lo que falta probar: ángulos de la ficha, buyer personas y estilos sin ningún batch testeado', function () use ($batches) {
        $f = ProductMemoryRules::facts($batches(), ['GINE', 'ABDO', 'FAJA'], ['Hombre con ginecomastia', 'Gym', 'Mujer'], []);
        expect($f['untested']['angle'])->toBe(['FAJA'])->and($f['untested']['persona'])->toBe(['Mujer'])
            ->and($f['untested']['style'])->toBe(['demostracion', 'ugc', 'antes_despues', 'problema_solucion', 'entrevista']);
    });

    it('.81 pieza por pieza: qué probó cada una y si ganó; un formato probado en una pieza ya no falta probar', function () use ($batches) {
        $pieces = [
            ['code' => 'C0461', 'test' => 'winner_hook', 'style' => null, 'outcome' => 'Ganó'],
            ['code' => 'C0462', 'test' => 'format', 'style' => 'imagen', 'outcome' => 'Perdió'],
            ['code' => 'C0463', 'test' => 'format', 'style' => 'ugc', 'outcome' => 'Ganó'],
            ['code' => 'C0470', 'test' => 'winner_hook', 'style' => null, 'outcome' => 'Empate'],
            ['code' => 'C0480', 'test' => 'new_concept', 'style' => null, 'outcome' => 'Perdió'],
        ];
        $f = ProductMemoryRules::facts($batches(), ['GINE', 'ABDO', 'FAJA'], [], [], $pieces);
        expect(array_column($f['piece_groups'], 'label'))->toBe(['Formato UGC', 'Hook nuevo en un ganador', 'Concepto nuevo', 'Formato Imagen'])
            ->and(ProductMemoryRules::pieceScore($f['piece_groups'][1]))->toBe('ganó 1 de 2 piezas (empate en 1)')
            ->and($f['untested']['style'])->not->toContain('ugc');
        $s = ProductMemoryRules::example($f);
        expect($s['works'])->toContain('Formato UGC: ganó 1 de 1 pieza.')->and($s['fails'])->toContain('Formato Imagen: ganó 0 de 1 pieza (perdió 1).')
            ->and(ProductMemoryRules::input('Comprimax', $f))->toContain("PIEZA POR PIEZA (qué probó cada una):
- Formato UGC: ganó 1 de 1 pieza");
        // Si cambian las piezas con resultado, el resumen se vuelve a escribir
        expect(ProductMemoryRules::hash($f))->not->toBe(ProductMemoryRules::hash(ProductMemoryRules::facts($batches(), ['GINE', 'ABDO', 'FAJA'], [], [])));
    });

    it('sin IA, el resumen sale de los números', function () use ($batches) {
        $s = ProductMemoryRules::example(ProductMemoryRules::facts($batches(), ['GINE', 'ABDO', 'FAJA'], [], []));
        expect($s['works'])->toContain('Estilo Testimonio: ganó 2 de 2 batches.')->toContain('Ángulo GINE · Ginecomastia: ganó 1 de 2 batches (perdió 1).')
            ->and($s['fails'])->toBe(['Estilo Video animado: ganó 0 de 1 batch (perdió 1).'])
            ->and($s['untested'][0])->toStartWith('ángulos FAJA; estilos demostración, ugc');
    });

    it('la IA del resumen recibe los números, los aprendizajes y las notas del dueño', function () use ($batches) {
        $f = ProductMemoryRules::facts($batches(), ['GINE'], [], [['id' => 1, 'kind' => 'fails', 'text' => 'Con modelo masculino no funciona']]);
        expect(ProductMemoryRules::input('Comprimax', $f))->toContain('Batches testeados: 4 (ganaron 2, perdieron 1, empate 1).')
            ->toContain('- GINE · Ginecomastia: ganó 1 de 2 batches (perdió 1)')
            ->toContain('  · aprendizaje: B0103: El testimonio de un hombre real engancha.')
            ->toContain('- No funciona: Con modelo masculino no funciona');
    });

    it('la IA de los encargos lo recibe como información interna, y la huella cambia con un batch o una nota nueva', function () use ($batches, $b) {
        $f = ProductMemoryRules::facts($batches(), [], [], []);
        $text = ProductMemoryRules::forWriter($f, ['works' => ['El testimonio funciona'], 'fails' => [], 'untested' => []]);
        expect($text)->toContain('  · Funciona: El testimonio funciona')->toContain('  · Estilo Testimonio: ganó 2 de 2 batches');
        expect(ProductMemoryRules::forWriter(ProductMemoryRules::facts([], [], [], []), null))->toBe('');
        $more = ProductMemoryRules::facts([...$batches(), $b('B0099', 'FAJA', null, null, 'Perdió')], [], [], []);
        expect(ProductMemoryRules::hash($more))->not->toBe(ProductMemoryRules::hash($f));
    });
});

describe('En los encargos', function () {
    it('la IA lee lo que sabemos (sin contárselo al editor) y cada encargo trae su estilo', function () {
        $plan = [['key' => 'E1', 'product' => 'Comprimax', 'urgency' => 3, 'kind' => 'new_concept', 'pieces' => 3, 'reason' => 'U3.', 'iterate' => 'un concepto nuevo',
            'angle_code' => 'GINE', 'reference' => null, 'reference_type' => null, 'city' => 'Caracas', 'format' => 'video',
            'context' => ['price' => null, 'proof' => null, 'angles' => [], 'profile' => '', 'personas' => [], 'memory' => '  · Funciona: El testimonio funciona']]];
        expect(QueueWriter::input($plan, []))->toContain("Lo que sabemos de este producto (interno: úsalo para decidir, nunca se lo cuentes al editor):\n  · Funciona: El testimonio funciona");
        expect(QueueWriter::example($plan, [])['encargos'][0]['estilo'])->toBe('otro');
        $tested = [['key' => 'T1', 'product' => 'Comprimax', 'code' => 'B0103', 'kind' => 'new_angle', 'angle' => 'GINE', 'hypothesis' => null,
            'verdicts' => [['city' => 'Caracas', 'label' => 'Prometedor']], 'spend' => 30, 'purchases' => 4, 'cpa' => 7.5,
            'test_idea' => 'Probamos un testimonio real', 'persona' => 'Hombre con ginecomastia', 'style' => 'testimonio', 'concept' => 'Mi historia']];
        expect(QueueWriter::input([], $tested))->toContain('Qué se probaba: Probamos un testimonio real')->toContain('Buyer persona: Hombre con ginecomastia')
            ->toContain('Estilo: Testimonio')->toContain('Concepto: Mi historia');
    });

    it('el texto del encargo dice su estilo', function () {
        $brief = QueueRules::brief(['code' => 'B0104', 'product' => 'Comprimax', 'kind' => 'new_concept', 'reference' => null, 'angle_code' => null, 'angle_name' => null,
            'format' => 'video', 'style' => 'testimonio', 'pieces' => [], 'deliverables' => null, 'due_date' => null, 'editor' => null]);
        expect($brief)->toContain("Formato: Video\nEstilo: Testimonio");
    });
});

describe('El asistente', function () {
    it('anota una nota del dueño con su tarjeta', function () {
        $p = ProductNoteTool::proposal(5, 'Comprimax', ['producto' => 'Comprimax', 'tipo' => 'no_funciona', 'texto' => 'Con modelo masculino no funciona']);
        expect($p->title)->toBe('Anotar en lo que sabemos de Comprimax')
            ->and(array_column($p->lines, 'value', 'label'))->toBe(['Producto' => 'Comprimax', 'Tipo' => 'No funciona', 'Nota' => 'Con modelo masculino no funciona'])
            ->and($p->payload)->toBe(['product_id' => 5, 'kind' => 'fails', 'text' => 'Con modelo masculino no funciona']);
        expect(fn () => ProductNoteTool::proposal(5, 'Comprimax', ['tipo' => 'idea', 'texto' => ' ']))->toThrow(ToolProblem::class);
    });

    it('en el modo ejemplo: "¿qué sabemos de X?" y "anota en lo que sabemos de X que …"', function () {
        expect(ExampleModel::understand('¿Qué sabemos de Comprimax?'))->toBe(['name' => 'lo_que_sabemos', 'arguments' => ['producto' => 'Comprimax']]);
        expect(ExampleModel::change('Anota en lo que sabemos de Comprimax que con modelo masculino no funciona', CarbonImmutable::parse('2026-11-01 10:00')))
            ->toBe(['name' => 'proponer_nota_producto', 'arguments' => ['producto' => 'Comprimax', 'tipo' => 'no_funciona', 'texto' => 'Con modelo masculino no funciona']]);
    });
});
