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
