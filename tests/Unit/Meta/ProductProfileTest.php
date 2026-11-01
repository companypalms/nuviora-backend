<?php

use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Queue\QueueRules;
use App\Services\Meta\Queue\QueueWriter;
use Carbon\CarbonImmutable;

// .64 (dueño, 2026-11-01): "la IA siempre debe tener un excelente contexto del producto" (Comprimax es un chaleco térmico
// para rebajar, no para el frío). La ficha, la tarjeta del asistente y lo que ve el editor.

$ficha = fn () => [
    'what_it_is' => 'Chaleco térmico reductor de neopreno para hombre',
    'benefits' => "Rebajar el abdomen, sudar más al entrenar y definir.\nDisimula la ginecomastia.",
    'mechanism' => 'El neopreno guarda el calor del cuerpo y hace sudar más la zona.',
    'not_this' => 'No es un chaleco para el frío. No cura la ginecomastia.',
    'key_info' => 'Tallas S a 3XL. Se usa debajo de la ropa.',
    'photos_url' => 'https://drive.google.com/drive/folders/abc',
];
$gine = fn () => ['id' => 7, 'name' => 'Hombre con ginecomastia', 'who' => 'Hombre de 25 a 45 años', 'pain' => 'Le da vergüenza quitarse la camisa',
    'wants' => 'Verse plano con ropa', 'objections' => null];

describe('La ficha', function () use ($ficha, $gine) {
    it('está completa con qué es, para qué sirve, cómo funciona y un buyer persona con quién es y qué le duele', function () use ($ficha, $gine) {
        expect(ProductProfileRules::complete($ficha(), [$gine()]))->toBeTrue();
        expect(ProductProfileRules::missing(['what_it_is' => 'Chaleco'], []))
            ->toBe(['Para qué sirve', 'Cómo funciona', 'al menos un buyer persona']);
        expect(ProductProfileRules::missing($ficha(), [['name' => 'Gym', 'who' => null, 'pain' => null]]))
            ->toBe(['un buyer persona con quién es y qué le duele']);
        expect(ProductProfileRules::missing(null, []))->toHaveCount(4);
    });

    it('no deja guardar un link que no es link ni dos buyer personas con el mismo nombre', function () use ($ficha, $gine) {
        expect(ProductProfileRules::problems(['photos_url' => 'drive carpeta'] + $ficha(), [$gine()]))
            ->toBe(['Las fotos van como un link (por ejemplo, la carpeta de Drive): https://…']);
        expect(ProductProfileRules::problems($ficha(), [$gine(), ['name' => 'hombre con GINECOMASTIA']]))
            ->toBe(['Hay dos buyer personas que se llaman "hombre con GINECOMASTIA".']);
        expect(ProductProfileRules::problems($ficha(), [['name' => null, 'who' => 'x']]))->toBe(['El buyer persona 1 no tiene nombre.']);
    });

    it('la IA lee la ficha completa, con "lo que NO es" y todos los buyer personas', function () use ($ficha, $gine) {
        $text = ProductProfileRules::forAi($ficha(), [$gine(), ['name' => 'Gym', 'who' => 'Va al gimnasio', 'pain' => 'No le baja la barriga']]);
        expect($text)->toContain('Qué es: Chaleco térmico reductor')
            ->toContain('Para qué sirve: Rebajar el abdomen, sudar más al entrenar y definir. / Disimula la ginecomastia.')
            ->toContain('Lo que NO es y nunca se dice: No es un chaleco para el frío.')
            ->toContain('- Hombre con ginecomastia: Hombre de 25 a 45 años. Le duele: Le da vergüenza quitarse la camisa. Quiere: Verse plano con ropa.')
            ->toContain('- Gym: Va al gimnasio.')
            ->not->toContain('drive.google'); // las fotos no le sirven a la IA
    });

    it('el editor ve lo básico del producto y solo el buyer persona de su encargo', function () use ($ficha, $gine) {
        expect(ProductProfileRules::forEditor($ficha()))->toHaveKeys(['what_it_is', 'benefits', 'mechanism', 'not_this', 'key_info', 'photos_url']);
        expect(ProductProfileRules::forEditor(['what_it_is' => null]))->toBeNull();
        expect(ProductProfileRules::personaForEditor($gine()))->toBe(['name' => 'Hombre con ginecomastia', 'who' => 'Hombre de 25 a 45 años',
            'pain' => 'Le da vergüenza quitarse la camisa', 'wants' => 'Verse plano con ropa']); // sin el id
        expect(ProductProfileRules::findByName([$gine()], ' hombre con  GINECOMASTÍA')['id'])->toBe(7);
    });
});

describe('La tarjeta del asistente', function () use ($ficha, $gine) {
    $args = fn (array $over = []) => $over + ['producto' => 'Comprimax', 'que_es' => null, 'para_que_sirve' => null, 'como_funciona' => null,
        'lo_que_no_es' => null, 'info_clave' => null, 'fotos' => null, 'buyer_personas' => [], 'quitar_buyer_personas' => []];

    it('llena una ficha nueva completa y muestra todo lo que se va a guardar', function () use ($args) {
        $p = ProductProfileTool::proposal(5, 'Comprimax', null, [], $args([
            'que_es' => 'Chaleco térmico reductor', 'para_que_sirve' => 'Rebajar, sudar y definir', 'como_funciona' => 'Guarda el calor del cuerpo',
            'lo_que_no_es' => 'No es para el frío',
            'buyer_personas' => [['nombre' => 'Hombre con ginecomastia', 'quien_es' => 'Hombre de 25 a 45', 'que_le_duele' => 'La vergüenza', 'que_quiere' => null, 'que_lo_frena' => null]],
        ]));
        expect($p->title)->toBe('Llenar la ficha de Comprimax')->and($p->warnings)->toBe([]);
        expect(array_column($p->lines, 'label'))->toBe(['Producto', 'Qué es', 'Para qué sirve', 'Cómo funciona', 'Lo que NO es y nunca se dice', 'Buyer persona: Hombre con ginecomastia', 'Fase']);
        expect($p->lines[5]['value'])->toBe("Quién es: Hombre de 25 a 45\nLe duele: La vergüenza");
        expect($p->payload['profile']['not_this'])->toBe('No es para el frío')->and($p->payload['personas'][0])->not->toHaveKey('id');
    });

    it('si falta algo, avisa que la IA no escribirá encargos de ese producto', function () use ($args) {
        $p = ProductProfileTool::proposal(5, 'Comprimax', null, [], $args(['que_es' => 'Chaleco térmico reductor']));
        expect($p->warnings[0])->toBe('La ficha queda incompleta: falta para qué sirve, cómo funciona y al menos un buyer persona. Mientras falte, la IA no escribe encargos ni landings de Comprimax.');
    });

    it('al cambiar una parte, lo demás queda; muestra lo de antes; el buyer persona que ya existe se cambia por su nombre', function () use ($args, $ficha, $gine) {
        $p = ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args([
            'para_que_sirve' => 'Rebajar el abdomen y definir la cintura',
            'buyer_personas' => [['nombre' => 'hombre con ginecomastia', 'quien_es' => null, 'que_le_duele' => null, 'que_quiere' => null, 'que_lo_frena' => 'Cree que se nota debajo de la camisa']],
        ]));
        expect($p->title)->toBe('Cambiar la ficha de Comprimax');
        expect($p->summary)->toBe('Cambiar la ficha de Comprimax: para qué sirve y el buyer persona Hombre con ginecomastia');
        $byLabel = array_column($p->lines, null, 'label');
        expect($byLabel['Para qué sirve']['before'])->toBe($ficha()['benefits']);
        expect($byLabel['Qué es'])->not->toHaveKey('before');
        expect($byLabel['Buyer persona: Hombre con ginecomastia']['value'])->toContain('Lo frena: Cree que se nota debajo de la camisa');
        expect($p->payload['personas'][0]['id'])->toBe(7)->and($p->payload['personas'][0]['who'])->toBe('Hombre de 25 a 45 años');
        expect($p->payload['profile']['what_it_is'])->toBe($ficha()['what_it_is']);
    });

    it('quita un buyer persona por su nombre; si no existe o nada cambia, se lo explica a la IA', function () use ($args, $ficha, $gine) {
        $p = ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['quitar_buyer_personas' => ['Hombre con ginecomastia']]));
        expect(array_column($p->lines, 'value', 'label')['Se quita de la ficha'])->toBe('Hombre con ginecomastia');
        expect($p->payload['personas'])->toBe([])->and($p->warnings)->toHaveCount(1);
        expect(fn () => ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['quitar_buyer_personas' => ['Mujer']])))
            ->toThrow(ToolProblem::class, 'no hay ningún buyer persona que se llame "Mujer". Los que hay: Hombre con ginecomastia.');
        expect(fn () => ProductProfileTool::proposal(5, 'Comprimax', $ficha(), [$gine()], $args(['que_es' => $ficha()['what_it_is']])))
            ->toThrow(ToolProblem::class, 'queda igual');
    });

    it('en el modo ejemplo, "Ficha de X: qué es: …; buyer: …" propone la ficha', function () {
        $c = ExampleModel::change('Ficha de Comprimax: qué es: Chaleco térmico; para qué sirve: Rebajar; no es: Para el frío; buyer: Gym / Va al gimnasio / La barriga',
            CarbonImmutable::parse('2026-11-01 10:00'));
        expect($c['name'])->toBe('proponer_ficha_producto');
        expect($c['arguments'])->toMatchArray(['producto' => 'Comprimax', 'que_es' => 'Chaleco térmico', 'para_que_sirve' => 'Rebajar', 'lo_que_no_es' => 'Para el frío', 'como_funciona' => null]);
        expect($c['arguments']['buyer_personas'][0])->toBe(['nombre' => 'Gym', 'quien_es' => 'Va al gimnasio', 'que_le_duele' => 'La barriga', 'que_quiere' => null, 'que_lo_frena' => null]);
    });
});

describe('Los encargos', function () use ($ficha, $gine) {
    it('la IA recibe la ficha en cada encargo y elige a qué buyer persona le habla', function () use ($ficha, $gine) {
        $plan = [['key' => 'E1', 'product' => 'Comprimax', 'urgency' => 2, 'kind' => 'new_concept', 'pieces' => 3, 'reason' => 'Faltan creativos', 'iterate' => 'un concepto nuevo',
            'angle_code' => 'GINE', 'reference' => null, 'reference_type' => null, 'city' => 'Caracas', 'format' => 'video',
            'context' => ['price' => '$30,00', 'proof' => null, 'angles' => [], 'profile' => ProductProfileRules::forAi($ficha(), [$gine()]), 'personas' => [$gine()]]]];
        expect(QueueWriter::input($plan, []))->toContain("Ficha del producto:\n  · Qué es: Chaleco térmico reductor")->toContain('No es un chaleco para el frío');
        expect(QueueWriter::example($plan, [])['encargos'][0]['buyer'])->toBe('Hombre con ginecomastia');
        $plan[0]['context']['profile'] = '';
        expect(QueueWriter::input($plan, []))->toContain("Ficha del producto:\n  · (sin ficha)");
    });

    it('el texto para el editor trae lo del producto y su buyer persona, nada más', function () use ($ficha, $gine) {
        $b = ['code' => 'B0101', 'product' => 'Comprimax', 'kind' => 'new_concept', 'reference' => null, 'angle_code' => 'GINE', 'angle_name' => 'Ginecomastia',
            'goal' => '3 versiones distintas', 'format' => 'video', 'pieces' => [], 'deliverables' => null, 'due_date' => null, 'editor' => null,
            'product_info' => ProductProfileRules::forEditor($ficha()), 'persona' => ProductProfileRules::personaForEditor($gine())];
        expect(QueueRules::brief($b))->toContain("Sobre el producto:\n- Qué es: Chaleco térmico reductor de neopreno para hombre")
            ->toContain('- Para qué sirve: Rebajar el abdomen, sudar más al entrenar y definir. / Disimula la ginecomastia.')
            ->toContain('- Lo que NO es (nunca lo digas): No es un chaleco para el frío. No cura la ginecomastia.')
            ->toContain('- Fotos: https://drive.google.com/drive/folders/abc')
            ->toContain('A quién le hablas: Hombre con ginecomastia: Hombre de 25 a 45 años. Le duele: Le da vergüenza quitarse la camisa.');
    });
});
