<?php

use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Instructions;
use App\Services\Assistant\Tools\MetaTool;
use Carbon\CarbonImmutable;

// Etapa 5, paso 3b: las preguntas de Meta Ads en el chat del asistente (las partes que no tocan la base ni la IA).

describe('El modo ejemplo entiende las preguntas de Meta', function () {
    it('"¿cómo van las campañas?" es el análisis de la mañana', function () {
        expect(ExampleModel::understand('¿Cómo van las campañas?'))->toBe(['name' => 'analisis_campanas', 'arguments' => ['fecha' => null]]);
        expect(ExampleModel::understand('Muéstrame el análisis del día')['name'])->toBe('analisis_campanas');
    });

    it('con un producto, la campaña de ese producto con su gráfica', function () {
        expect(ExampleModel::understand('¿Cómo va la campaña de FlexiStep?'))
            ->toBe(['name' => 'campana_producto', 'arguments' => ['producto' => 'FlexiStep', 'grafico' => true]]);
        expect(ExampleModel::understand('¿Cómo va FlexiStep en Meta?')['arguments']['producto'])->toBe('FlexiStep');
    });

    it('los creativos y los ángulos de un producto', function () {
        expect(ExampleModel::understand('Creativos de FlexiStep'))->toBe(['name' => 'creativos', 'arguments' => ['producto' => 'FlexiStep', 'estado' => null]]);
        expect(ExampleModel::understand('creativos ganadores de FlexiStep')['arguments']['estado'])->toBe('ganadores');
        expect(ExampleModel::understand('Ángulos de Cervimax'))->toBe(['name' => 'angulos', 'arguments' => ['producto' => 'Cervimax']]);
    });

    it('la cola de producción: lo que hay, lo de un producto, lo que falta aprobar y un batch por su número', function () {
        expect(ExampleModel::understand('¿Qué hay en la cola?'))->toBe(['name' => 'cola_produccion', 'arguments' => ['producto' => null, 'estado' => null, 'numero' => null]]);
        expect(ExampleModel::understand('¿Qué hay en banca de FlexiStep?')['arguments']['producto'])->toBe('FlexiStep');
        expect(ExampleModel::understand('¿Qué encargos hay para aprobar?')['arguments']['estado'])->toBe('para_aprobar');
        expect(ExampleModel::understand('¿Cómo le fue al B0100?')['arguments'])->toBe(['producto' => null, 'estado' => null, 'numero' => 'B0100']);
    });

    it('los editores de video (paso 5b): el equipo, el ranking y uno por su nombre', function () {
        expect(ExampleModel::understand('¿Cómo van los editores este mes?'))->toMatchArray(['name' => 'equipo'])
            ->and(ExampleModel::understand('¿Cómo van los editores este mes?')['arguments'])->toMatchArray(['rol' => 'editores', 'periodo' => 'este_mes']);
        expect(ExampleModel::understand('Ranking de editores')['arguments']['rol'])->toBe('editores');
        expect(ExampleModel::understand('¿Cómo va el editor Leo este mes?'))->toMatchArray(['name' => 'persona'])
            ->and(ExampleModel::understand('¿Cómo va el editor Leo este mes?')['arguments']['rol'])->toBe('editores');
        expect(App\Services\Assistant\Lookup::ROLES['editores'])->toBe('editors');
    });

    it('las landings (paso 6c): las de un producto, un encargo por su número y el editor de landings con el equipo', function () {
        expect(ExampleModel::understand('¿Cómo van las landings de FlexiStep?'))->toBe(['name' => 'landings', 'arguments' => ['producto' => 'FlexiStep']]);
        expect(ExampleModel::understand('¿Qué landing está lenta?')['name'])->toBe('landings');
        expect(ExampleModel::understand('¿Cómo le fue al L0100?')['name'])->toBe('landings');
        expect(ExampleModel::understand('¿Cómo va el editor de landings este mes?'))->toMatchArray(['name' => 'equipo'])
            ->and(ExampleModel::understand('¿Cómo va el editor de landings este mes?')['arguments']['rol'])->toBe('editores_landing');
        expect(ExampleModel::understand('¿Cómo van los editores este mes?')['arguments']['rol'])->toBe('editores');
        expect(App\Services\Assistant\Lookup::ROLES['editores_landing'])->toBe('landing_editors');
    });

    it('lo de antes sigue igual: las metas no son Meta y una persona sigue siendo una persona', function () {
        expect(ExampleModel::understand('¿Cómo voy con mis metas?')['name'])->toBe('metas');
        expect(ExampleModel::understand('¿Cuál es la efectividad de Fabiola este mes?')['name'])->toBe('persona');
        expect(ExampleModel::understand('¿Cuánto gastamos en publicidad este mes?')['name'])->toBe('dinero');
    });
});

describe('Cómo se muestra lo que dicen las reglas', function () {
    it('el color de cada punto sigue el orden de la lista urgente: rojo, naranja, amarillo, verde y gris', function () {
        expect(array_map(fn ($k) => MetaTool::level($k), ['drop', 'pause_city', 'decrease', 'intervene', 'hold_prune', 'alarm', 'scale', 'price', 'hold', 'wait']))
            ->toBe(['red', 'red', 'orange', 'orange', 'yellow', 'yellow', 'green', 'pending', 'pending', 'pending']);
    });

    it('el CPA contra el objetivo (C) y el break-even (BE)', function () {
        expect(MetaTool::against(4.0, 5.0, 8.0))->toBe('debajo del objetivo (bien)');
        expect(MetaTool::against(5.0, 5.0, 8.0))->toBe('debajo del objetivo (bien)');
        expect(MetaTool::against(6.5, 5.0, 8.0))->toBe('entre el objetivo y el break-even (cuidado)');
        expect(MetaTool::against(8.0, 5.0, 8.0))->toBe('en el break-even o encima (pierde dinero)');
        expect(MetaTool::against(null, 5.0, 8.0))->toBe('sin compras');
        expect(MetaTool::against(6.0, null, null))->toBeNull();
    });
});

describe('Las instrucciones del asistente', function () {
    it('dicen que solo recomienda en Meta, con los montos de las reglas', function () {
        $text = Instructions::build('Francisco', CarbonImmutable::parse('2026-10-30 08:00'));
        expect($text)->toContain('Meta Ads (el análisis de campañas de la mañana')
            ->toContain('Nunca digas que algo se cambió en Meta')
            ->toContain('no inventes otros montos');
    });
});
