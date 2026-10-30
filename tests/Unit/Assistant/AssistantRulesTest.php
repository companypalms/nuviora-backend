<?php

use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Ai\ModelReply;
use App\Services\Assistant\Ai\OpenAiModel;
use App\Services\Assistant\Instructions;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;
use App\Services\Assistant\Tools\AgencyCashTool;
use App\Services\Assistant\Tools\OrderHistoryTool;
use App\Services\Assistant\Tools\PeriodArgs;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Assistant\Turn;
use Carbon\CarbonImmutable;

// El asistente del dueño (Etapa 4, paso 2): las partes que no tocan la base ni la IA.

$jueves = fn () => CarbonImmutable::parse('2026-10-29 18:00:00');

describe('El período en palabras del dueño', function () use ($jueves) {
    it('"la semana pasada" es de lunes a domingo y "esta semana" desde el lunes', function () use ($jueves) {
        $p = PeriodArgs::period(['periodo' => 'semana_pasada'], $jueves());
        expect([$p->fromDate(), $p->toDate()])->toBe(['2026-10-19', '2026-10-25']);
        $p = PeriodArgs::period(['periodo' => 'esta_semana'], $jueves());
        expect([$p->fromDate(), $p->toDate()])->toBe(['2026-10-26', '2026-10-29']);
    });

    it('sin período, este mes; y los presets del panel', function () use ($jueves) {
        expect(PeriodArgs::period([], $jueves())->preset)->toBe('month');
        expect(PeriodArgs::period(['periodo' => 'mes_pasado'], $jueves())->fromDate())->toBe('2026-09-01');
        expect(PeriodArgs::period(['periodo' => 'ayer'], $jueves())->fromDate())->toBe('2026-10-28');
    });

    it('fechas: las valida y explica el problema', function () use ($jueves) {
        $p = PeriodArgs::period(['periodo' => 'fechas', 'desde' => '2026-10-01', 'hasta' => '2026-10-15'], $jueves());
        expect([$p->fromDate(), $p->toDate()])->toBe(['2026-10-01', '2026-10-15']);
        expect(fn () => PeriodArgs::period(['periodo' => 'fechas', 'desde' => '2026-13-01', 'hasta' => '2026-10-15'], $jueves()))->toThrow(ToolProblem::class, 'no es válida');
        expect(fn () => PeriodArgs::period(['periodo' => 'fechas', 'desde' => '2026-10-15', 'hasta' => '2026-10-01'], $jueves()))->toThrow(ToolProblem::class, 'no puede ser antes');
        expect(fn () => PeriodArgs::period(['periodo' => 'fechas', 'desde' => null, 'hasta' => null], $jueves()))->toThrow(ToolProblem::class, 'Elige las dos fechas');
    });
});

describe('Buscar por nombre', function () {
    it('sin acentos ni mayúsculas', function () {
        expect(Lookup::normalize('  José   PÉREZ-Núñez '))->toBe('jose perez nunez');
    });

    it('el nombre exacto o el que empieza igual gana solo', function () {
        $people = [1 => 'Fabiola Demo', 2 => 'Fabián Pérez', 3 => 'Carla Demo'];
        expect(Lookup::match('fabiola', $people))->toBe([['id' => 1, 'name' => 'Fabiola Demo']]);
        expect(Lookup::match('Carla Demo', $people))->toBe([['id' => 3, 'name' => 'Carla Demo']]);
    });

    it('si hay varias parecidas, devuelve todas para preguntar', function () {
        $people = [1 => 'Fabiola Demo', 2 => 'Fabiola Ruiz', 3 => 'Carla Demo'];
        expect(array_column(Lookup::match('Fabiola', $people), 'id'))->toBe([1, 2]);
        expect(array_column(Lookup::match('demo', $people), 'id'))->toBe([3, 1]);
        expect(Lookup::match('Nadie', $people))->toBe([]);
    });
});

describe('Los números en palabras', function () {
    it('con el formato del panel', function () {
        expect(Present::value(1234.5, 'money'))->toBe('$1.234,50');
        expect(Present::value(57.14, 'percent'))->toBe('57,1 %');
        expect(Present::value(12.25, 'hours_total'))->toBe('12,3 h');
        expect(Present::value(null, 'count'))->toBe('—');
    });

    it('el cambio: los porcentajes en puntos', function () {
        expect(Present::change(['change' => 5.0, 'change_unit' => 'points']))->toBe('+5 pts');
        expect(Present::change(['change' => -12.5, 'change_unit' => 'percent']))->toBe('−12,5 %');
        expect(Present::change(['change' => null]))->toBeNull();
    });

    it('las tarjetas para la IA: lo que todavía cambia, la meta y lo que no se compara', function () {
        $cards = Present::cards([
            ['label' => 'Efectividad', 'format' => 'percent', 'value' => 60.8, 'previous' => 58.9, 'change' => 1.9, 'change_unit' => 'points',
                'neutral' => true, 'compare' => true, 'detail' => '93 de 153', 'note' => null, 'goal' => ['text' => 'Meta 55 %. Cumplida.']],
            ['label' => 'En camino', 'format' => 'money', 'value' => 100.0, 'previous' => null, 'change' => null, 'change_unit' => 'percent',
                'neutral' => false, 'compare' => false, 'detail' => null, 'note' => 'Ganancia esperada'],
        ]);
        expect($cards[0])->toBe(['dato' => 'Efectividad', 'valor' => '60,8 %', 'antes' => '58,9 %', 'cambio' => '+1,9 pts',
            'todavia_puede_cambiar' => true, 'detalle' => '93 de 153', 'meta' => 'Meta 55 %. Cumplida.']);
        expect($cards[1])->toBe(['dato' => 'En camino', 'valor' => '$100,00', 'nota' => 'Ganancia esperada']);
    });

    it('tapa teléfonos y correos, pero no fechas, horas ni montos', function () {
        expect(Present::mask("Actualizó 'shipped_at' de '' a '2026-10-29 07:37:00'"))->toBe("Actualizó 'shipped_at' de '' a '2026-10-29 07:37:00'");
        expect(Present::mask('Llamar al 0414-123.45.67 o a ana@correo.com'))->toBe('Llamar al [teléfono] o a [correo]');
        expect(Present::mask('Pagó Bs 1.250.000,00 y $1.234.567 el 29/10/2026 07:37'))->toBe('Pagó Bs 1.250.000,00 y $1.234.567 el 29/10/2026 07:37');
    });

    it('el nombre de cada punto de una gráfica', function () {
        expect(Present::bucket('2026-10-05', 'day'))->toBe('5/10');
        expect(Present::bucket('2026-10-19', 'week'))->toBe('sem. 19/10');
        expect(Present::bucket('2026-10-01', 'month'))->toBe('oct 26');
    });
});

describe('El modo ejemplo entiende frases simples', function () {
    it('la efectividad de una persona', function () {
        $c = ExampleModel::understand('¿Cuál es la efectividad de Fabiola este mes?');
        expect($c['name'])->toBe('persona');
        expect($c['arguments'])->toMatchArray(['rol' => 'vendedoras', 'nombre' => 'Fabiola', 'periodo' => 'este_mes']);
        expect(ExampleModel::understand('Cómo va la agencia Rápido Demo la semana pasada')['arguments'])
            ->toMatchArray(['rol' => 'agencias', 'nombre' => 'Rápido Demo', 'periodo' => 'semana_pasada']);
    });

    it('rankings, ventas, dinero, metas y el calendario', function () {
        expect(ExampleModel::understand('Ranking de agencias del mes pasado'))->toMatchArray(['name' => 'equipo']);
        expect(ExampleModel::understand('Ranking de agencias del mes pasado')['arguments'])->toMatchArray(['rol' => 'agencias', 'periodo' => 'mes_pasado']);
        expect(ExampleModel::understand('¿Cuánto vendimos esta semana?')['name'])->toBe('ventas');
        expect(ExampleModel::understand('¿Cuánto gané ayer?')['arguments']['periodo'])->toBe('ayer');
        expect(ExampleModel::understand('¿Cómo voy con mis metas?')['name'])->toBe('metas');
        expect(ExampleModel::understand('¿Qué tengo pendiente hoy?')['name'])->toBe('calendario');
        expect(ExampleModel::understand('Hola, ¿cómo estás?'))->toBeNull();
    });

    it('órdenes, inventario y lo demás del paso 3', function () {
        expect(ExampleModel::understand('¿Qué pasó con la orden #P9010?'))->toBe(['name' => 'historial_orden', 'arguments' => ['numero' => 'P9010', 'id' => null]]);
        expect(ExampleModel::understand('¿Qué productos se están acabando?')['name'])->toBe('stock_bajo');
        expect(ExampleModel::understand('¿Cuánto stock queda?')['name'])->toBe('stock');
        expect(ExampleModel::understand('Comisiones de la semana pasada')['arguments']['periodo'])->toBe('semana_pasada');
        expect(ExampleModel::understand('¿Cómo van los cierres de las agencias?')['name'])->toBe('cierres_agencias');
        expect(ExampleModel::understand('¿Cuánto efectivo me deben las agencias de la semana pasada?'))
            ->toBe(['name' => 'efectivo_agencias', 'arguments' => ['periodo' => 'semana_pasada', 'desde' => null, 'hasta' => null, 'agencia' => null, 'grafico' => false]]);
        expect(ExampleModel::understand('¿Cuánto hay en caja?')['name'])->toBe('caja');
        expect(ExampleModel::understand('¿Cuál es la tasa de hoy?')['name'])->toBe('tasas');
    });

    it('sin herramientas que pedir, explica qué puede preguntar', function () {
        $reply = (new ExampleModel())->next(new Turn('', [], 'Hola', []));
        expect($reply->calls)->toBe([]);
        expect($reply->text)->toContain('Modo ejemplo')->toContain('Prueba con');
    });

    it('pide la herramienta y después muestra sus datos tal cual', function () {
        $model = new ExampleModel();
        $turn = new Turn('', [], '¿Cuánto vendimos esta semana?', []);
        $first = $model->next($turn);
        expect($first->calls[0]['name'])->toBe('ventas');

        $data = ['periodo' => ['nombre' => 'Esta semana', 'desde' => '26/10/2026', 'hasta' => '29/10/2026'],
            'tarjetas' => [['dato' => 'Entregados', 'valor' => '12', 'antes' => '10', 'cambio' => '+20 %']]];
        $second = $model->next($turn->withStep($first, ['ejemplo_1' => json_encode($data)]));
        expect($second->calls)->toBe([]);
        expect($second->text)->toContain('**Esta semana**')->toContain('- Entregados: **12** (antes 10, +20 %)');
    });
});

describe('El historial de una orden para la IA', function () {
    it('no pasa los datos de contacto ni la ubicación del cliente, solo que cambiaron', function () {
        $text = OrderHistoryTool::text("Estado cambió de 'Nuevo' a 'Confirmado' | Actualizó 'phone' de '04141234567' a '04241234567' | Actualizó 'location' de 'Calle 1' a 'Calle 2'");
        expect($text)->toBe("Estado cambió de 'Nuevo' a 'Confirmado' | Cambió datos de contacto o de ubicación del cliente");
    });

    it('corta los textos largos', function () {
        expect(mb_strlen(OrderHistoryTool::text(str_repeat('a', 500), 100)))->toBe(100);
    });
});

describe('Las instrucciones', function () use ($jueves) {
    it('llevan las reglas y, al final, quién es el dueño y la fecha de Venezuela', function () use ($jueves) {
        $text = Instructions::build('Francisco', $jueves());
        expect($text)->toContain('Nunca inventes números')->toContain('Venta = pedido entregado');
        expect($text)->toEndWith('El dueño se llama Francisco. Hoy es jueves 29 de octubre de 2026, 18:00 (hora de Venezuela).');
    });
});

describe('Lo que se manda y se lee de OpenAI', function () {
    it('lee el razonamiento, las llamadas y el texto, y guarda lo que hay que devolverle', function () {
        $reply = OpenAiModel::reply(['model' => 'm', 'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'xyz', 'summary' => []],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ventas', 'arguments' => '{"periodo":"hoy"}'],
        ], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'input_tokens_details' => ['cached_tokens' => 60]]], 'otro');

        expect($reply->text)->toBeNull();
        expect($reply->calls)->toBe([['call_id' => 'call_1', 'name' => 'ventas', 'arguments' => ['periodo' => 'hoy']]]);
        expect($reply->carry)->toBe([
            ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'xyz', 'summary' => []],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ventas', 'arguments' => '{"periodo":"hoy"}'],
        ]);
        expect($reply->usage)->toBe(['input' => 100, 'cached' => 60, 'output' => 20]);
        expect($reply->model)->toBe('m');
    });

    it('arma la entrada: lo que se habló, la pregunta y, de cada vuelta, lo de la IA y lo de las herramientas', function () {
        $step = new ModelReply(null, [['call_id' => 'call_1', 'name' => 'ventas', 'arguments' => []]],
            [['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ventas', 'arguments' => '{}']], ['input' => 0, 'cached' => 0, 'output' => 0], 'm');
        $turn = (new Turn('instrucciones', [['role' => 'user', 'text' => 'antes'], ['role' => 'assistant', 'text' => 'respuesta']], 'ahora', []))
            ->withStep($step, ['call_1' => '{"ok":1}']);

        expect(OpenAiModel::input($turn))->toBe([
            ['role' => 'user', 'content' => 'antes'],
            ['role' => 'assistant', 'content' => 'respuesta'],
            ['role' => 'user', 'content' => 'ahora'],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ventas', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ok":1}'],
        ]);
    });
});

describe('El efectivo de las agencias (como Liquidación de Agencias)', function () {
    $agencia = fn (int $id, string $name, array $o) => $o + [
        'agency_id' => $id, 'agency_name' => $name, 'count_delivered' => 0, 'count_trips' => 0, 'total_shipping_cost' => 0,
        'total_collected_usd' => 0, 'total_collected_ves' => 0, 'total_change_usd' => 0, 'total_change_ves' => 0,
        'balance_usd' => 0, 'balance_ves' => 0, 'order_details' => [],
    ];

    it('dólares y bolívares por separado, de mayor a menor saldo, con el total y lo que tienen hoy', function () use ($agencia) {
        $s = AgencyCashTool::summarize([
            $agencia(7, 'Rápido', ['count_delivered' => 2, 'total_collected_usd' => 50, 'total_collected_ves' => 1000, 'total_change_usd' => 5,
                'total_change_ves' => 100, 'balance_usd' => 45, 'balance_ves' => 900, 'count_trips' => 3, 'total_shipping_cost' => 9,
                'order_details' => [['order_id' => 1], ['order_id' => 2]]]),
            $agencia(8, 'Centro', ['count_delivered' => 4, 'total_collected_usd' => 120, 'balance_usd' => 120, 'order_details' => [['order_id' => 3]]]),
        ], [], [
            ['agency_id' => 8, 'currency' => 'USD', 'balance' => 80.5],
            ['agency_id' => 8, 'currency' => 'VES', 'balance' => 1200],
            ['agency_id' => 7, 'currency' => 'VES', 'balance' => 0],
            ['agency_id' => null, 'currency' => 'USD', 'balance' => 85],
        ]);

        expect(array_column($s['agencies'], 'agencia'))->toBe(['Centro', 'Rápido']);
        expect($s['agencies'][1])->toMatchArray([
            'entregadas' => 2,
            'efectivo_cobrado' => '$50,00 y Bs 1.000,00',
            'vueltos_que_dio' => '$5,00 y Bs 100,00',
            'saldo_del_periodo' => '$45,00 y Bs 900,00',
            'carreras' => 3,
            'se_le_paga_por_carreras' => '$9,00',
        ]);
        expect($s['agencies'][1])->not->toHaveKey('tiene_hoy_sin_entregar'); // su cuenta en Bs está en cero
        expect($s['agencies'][0]['tiene_hoy_sin_entregar'])->toBe('$80,50 y Bs 1.200,00');
        expect($s['total'])->toMatchArray(['entregadas' => 6, 'saldo_del_periodo' => '$165,00 y Bs 900,00', 'carreras' => 3]);
        expect($s['today'])->toBe(['todas_las_agencias' => '$80,50 y Bs 1.200,00', 'repartidores_propios' => '$85,00']);
        expect($s['rows'][0])->toMatchArray(['name' => 'Centro', 'group' => 'Hoy tiene $80,50 y Bs 1.200,00']);
        expect($s['rows'][1]['values'])->toBe(['delivered' => 2, 'usd' => 45.0, 'ves' => 900.0, 'trips' => 9.0]);
    });

    it('los euros en efectivo van aparte, con su aviso, y sin ellos no se mencionan', function () use ($agencia) {
        $s = AgencyCashTool::summarize([
            $agencia(7, 'Rápido', ['count_delivered' => 2, 'order_details' => [['order_id' => 1], ['order_id' => 2]]]),
            $agencia(8, 'Centro', ['count_delivered' => 1, 'order_details' => [['order_id' => 3]]]),
        ], [2 => 20.0, 9 => 99.0], []);

        expect($s['agencies'][0]['euros_en_efectivo'])->toStartWith('$20,00 registrados como "Euros efectivo"');
        expect($s['agencies'][1])->not->toHaveKey('euros_en_efectivo');
        expect($s['total']['euros_en_efectivo'])->toStartWith('$20,00');
        expect($s['today'])->toBe([]);
    });

    it('sin agencias en esas fechas, nada que mostrar', function () {
        expect(AgencyCashTool::summarize([], [], []))->toBe(['agencies' => [], 'total' => ['entregadas' => 0, 'efectivo_cobrado' => '$0,00 y Bs 0,00',
            'vueltos_que_dieron' => '$0,00 y Bs 0,00', 'saldo_del_periodo' => '$0,00 y Bs 0,00'], 'rows' => [], 'today' => []]);
    });
});
