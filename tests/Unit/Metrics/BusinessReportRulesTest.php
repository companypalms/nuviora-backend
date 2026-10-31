<?php

use App\Services\Metrics\Reports\ReportFacts;
use App\Services\Metrics\Reports\ReportPeriods;
use App\Services\Metrics\Reports\ReportWriter;
use App\Services\Metrics\Reports\TextCheck;
use Carbon\CarbonImmutable;

// Los reportes del negocio (`.48b`): qué días mira cada uno, cuándo sale, qué números lleva y que la IA no invente.

$day = fn (string $d) => CarbonImmutable::parse($d, 'America/Caracas');
$range = fn (array $r) => [$r[0]->toDateString(), $r[1]->toDateString()];

describe('qué días mira', function () use ($day, $range) {
    it('el diario: los 3 días anteriores, sin hoy', function () use ($day, $range) {
        expect($range(ReportPeriods::for('daily', $day('2026-11-04'))))->toBe(['2026-11-01', '2026-11-03']);
    });

    it('el semanal: la semana pasada de lunes a domingo', function () use ($day, $range) {
        expect($range(ReportPeriods::for('weekly', $day('2026-11-05'))))->toBe(['2026-10-26', '2026-11-01']); // jueves 5/11
    });

    it('el mensual: el mes pasado completo', function () use ($day, $range) {
        expect($range(ReportPeriods::for('monthly', $day('2026-11-09'))))->toBe(['2026-10-01', '2026-10-31']);
        expect($range(ReportPeriods::for('monthly', $day('2027-03-08'))))->toBe(['2027-02-01', '2027-02-28']);
    });

    it('la etiqueta dice los días de la semana', function () use ($day) {
        expect(ReportPeriods::label($day('2026-10-26'), $day('2026-11-01')))->toBe('Del lunes 26/10 al domingo 1/11');
        expect(ReportPeriods::label($day('2026-10-26'), $day('2026-10-26')))->toBe('El lunes 26/10');
    });
});

describe('cuándo sale', function () use ($day) {
    it('el diario de lunes a sábado; el domingo no', function () use ($day) {
        expect(ReportPeriods::dueOn($day('2026-11-02'), 4))->toBe(['daily']);   // lunes
        expect(ReportPeriods::dueOn($day('2026-11-01'), 4))->toBe([]);          // domingo
    });

    it('el semanal el día de la revisión semanal del calendario', function () use ($day) {
        expect(ReportPeriods::dueOn($day('2026-11-05'), 4))->toBe(['daily', 'weekly']);  // jueves
        expect(ReportPeriods::dueOn($day('2026-11-05'), 2))->toBe(['daily']);
    });

    it('el mensual el día 8; si cae domingo, el lunes 9', function () use ($day) {
        expect(ReportPeriods::dueOn($day('2026-12-08'), 4))->toBe(['daily', 'monthly']);
        expect(ReportPeriods::dueOn($day('2026-11-08'), 4))->toBe([]);          // domingo 8/11
        expect(ReportPeriods::dueOn($day('2026-11-09'), 4))->toBe(['daily', 'monthly']);
    });

    it('si una revisión está apagada en el calendario, su reporte no sale solo', function () use ($day) {
        expect(ReportPeriods::dueOn($day('2026-11-05'), 4, ['review_3days' => false, 'review_week' => true]))->toBe(['weekly']);
    });
});

describe('los números del reporte', function () {
    $card = fn (string $key, string $label, string $format, $value, $previous = null, $change = null, string $unit = 'percent', bool $neutral = false) => [
        'key' => $key, 'label' => $label, 'help' => '', 'detail' => null, 'format' => $format, 'value' => $value, 'previous' => $previous,
        'change' => $change, 'change_unit' => $unit, 'neutral' => $neutral, 'better' => 'higher', 'compare' => true, 'note' => null,
    ];
    $person = fn (int $id, string $name, ?float $value, ?float $previous, bool $low = false) => ['id' => $id, 'name' => $name, 'values' => ['effectiveness' => $value, 'assigned' => 20],
        'previous' => $previous !== null ? ['effectiveness' => $previous] : null, 'low_data' => $low];
    $role = fn (array $rows) => ['role' => ['label' => 'Vendedoras', 'rank_by' => 'effectiveness',
        'columns' => [['key' => 'effectiveness', 'label' => 'Efectividad', 'format' => 'percent', 'better' => 'higher']], 'rows' => $rows]];
    $panel = [
        'sales' => ['period' => ['compare_label' => 'los mismos días justo antes'], 'maturing' => true, 'cards' => [
            $card('created', 'Pedidos creados', 'count', 120, 100, 20.0),
            $card('delivered', 'Entregados', 'count', 60, 55, 9.1, 'percent', true),
            $card('effectiveness', 'Efectividad general', 'percent', 50.0, 55.0, -5.0, 'points', true),
        ]],
        'money' => ['cards' => [$card('profit', 'Ganancia neta (ya ganado)', 'money', 1234.5, 1000.0, 23.5)],
            'waterfall' => [['key' => 'revenue', 'label' => 'Vendido', 'amount' => 3000.0], ['key' => 'profit', 'label' => 'Ganancia', 'amount' => 1234.5]],
            'products' => ['rows' => [
                ['product_id' => 1, 'name' => 'Solo Brillo', 'revenue' => 2000.0, 'costs' => 800.0, 'ads' => 300.0, 'profit' => 900.0, 'delivered' => 40],
                ['product_id' => 2, 'name' => 'Audinet', 'revenue' => 100.0, 'costs' => 50.0, 'ads' => 200.0, 'profit' => -150.0, 'delivered' => 2],
                ['product_id' => null, 'others' => true, 'revenue' => 50.0, 'costs' => 0.0, 'ads' => 0.0, 'profit' => 50.0, 'delivered' => 1],
            ]]],
        'losses' => ['cards' => [$card('cancelled', 'Cancelados', 'count', 30, 20, 50.0)],
            'reasons' => ['rows' => [['label' => 'Se arrepintió', 'count' => 6], ['label' => 'No contesta', 'count' => 18], ['label' => 'Precio', 'count' => 6]]]],
        'sellers' => $role([$person(1, 'Ana', 70.0, 60.0), $person(2, 'Bea', 40.0, 65.0), $person(3, 'Cris', 90.0, null, true)]),
        'agencies' => ['role' => null],
        'alerts' => ['alerts' => [['level' => 'red', 'title' => 'Agencias con mucho dinero', 'items' => [['name' => 'PYP', 'text' => '$390,00 sin entregar']]]]],
    ];
    $check = ['verdict' => ['ok' => false, 'text' => '1 dato hace que un número salga mal.'], 'problems' => [
        ['key' => 'no_cost', 'severity' => 'grave', 'text' => 'Sin costo', 'count' => 3, 'amount' => null, 'examples' => ['Audinet (3)']],
        ['key' => 'provisional_cost', 'severity' => 'info', 'text' => 'Provisional', 'count' => 9, 'amount' => null, 'examples' => []],
    ]];
    $f = ReportFacts::build('weekly', '2026-10-26', '2026-11-01', 'Del lunes 26/10 al domingo 1/11', $panel, $check);

    it('los productos que más dejaron y los que perdieron, sin "Otros"', function () use ($f) {
        expect(array_column($f['products']['best'], 'name'))->toBe(['Solo Brillo']);
        expect(array_column($f['products']['losing'], 'name'))->toBe(['Audinet']);
    });

    it('los motivos de cancelación más comunes con su %', function () use ($f) {
        expect($f['reasons'][0])->toBe(['label' => 'No contesta', 'count' => 18, 'pct' => 60.0]);
    });

    it('equipo: las mejores y las que más bajaron, sin las de pocos datos ni repetir', function () use ($f) {
        expect(array_column($f['team']['sellers']['best'], 'name'))->toBe(['Ana', 'Bea']);
        expect($f['team']['sellers']['dropped'])->toBe([]); // Bea bajó, pero ya está entre las mejores
        expect($f['team']['agencies'])->toBeNull();
    });

    it('la revisión sin lo que es solo "info"', function () use ($f) {
        expect(array_column($f['check']['problems'], 'key'))->toBe(['no_cost']);
    });

    it('el texto para la IA trae cada número como lo tiene que repetir', function () use ($f) {
        $in = ReportFacts::input($f);
        expect($in)->toContain('Ganancia neta (ya ganado): $1.234,50 (antes $1.000,00, +23,5 %).')
            ->toContain('Efectividad general: 50 % (antes 55 %, −5 puntos) — todavía puede cambiar.')
            ->toContain('todavía se están entregando')
            ->toContain('Perdió: Audinet: ganancia −$150,00')
            ->toContain('ROJA · Agencias con mucho dinero: PYP: $390,00 sin entregar')
            ->toContain('GRAVE (3): Sin costo Ej.: Audinet (3).');
    });

    it('sin IA, la plantilla arma el mismo formato con esos números', function () use ($f) {
        $t = ReportWriter::template($f);
        expect(array_keys($t))->toBe(['resumen', 'ventas', 'dinero', 'perdidas', 'equipo', 'que_mirar']);
        expect($t['resumen'])->toContain('Ganancia neta (ya ganado): $1.234,50')->toContain('Ojo con la calidad de los números');
        expect($t['que_mirar'][0])->toBe('Sin costo');
        expect(TextCheck::unknown(json_encode($t, JSON_UNESCAPED_UNICODE), ReportFacts::input($f)))->toBe([]);
    });
});

describe('que la IA no invente', function () {
    $source = 'Ganancia: $1.234,50 (antes $1.000,00, +23,5 %). Efectividad: 50 % (antes 55 %).';

    it('acepta los montos y porcentajes que están en los datos', function () use ($source) {
        expect(TextCheck::unknown('Ganaste $1.234,50, un 23,5 % más; la efectividad fue 50 %.', $source))->toBe([]);
    });

    it('marca los que no están', function () use ($source) {
        expect(TextCheck::unknown('Ganaste $1.300,00 y la efectividad subió 12 %.', $source))->toBe(['$1.300,00', '12 %']);
    });
});
