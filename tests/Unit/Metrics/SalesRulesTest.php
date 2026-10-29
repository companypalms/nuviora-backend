<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\SalesRules;
use Carbon\CarbonImmutable;

// Panel del dueño (guias/DICCIONARIO-METRICAS.md, §1, §3 y §8): las reglas de "Ventas y pedidos", con pedidos
// inventados. El lunes 26/10/2026 es el día de los ejemplos.

$now = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$monday = Period::fromPreset('custom', '2026-10-26', '2026-10-26', $now);
$order = fn (string $createdAt, string $outcome = 'open', float $revenue = 0, string $kind = 'normal', ?string $deliveredDate = null) => [
    'created_at' => $createdAt, 'outcome' => $outcome, 'revenue' => $revenue, 'kind' => $kind, 'delivered_date' => $deliveredDate,
];

describe('Ventas y pedidos', function () use ($now, $monday, $order) {
    it('un pedido creado el lunes y entregado el miércoles es venta del lunes', function () use ($monday, $order) {
        $r = SalesRules::compute([$order('2026-10-26 09:00:00', 'delivered', 40, 'normal', '2026-10-28')], $monday);
        expect($r['totals']['created'])->toBe(1);
        expect($r['totals']['delivered'])->toBe(1);
        expect($r['totals']['revenue'])->toBe(40.0);
    });

    it('un pedido creado el domingo y entregado el lunes no cuenta para el lunes', function () use ($monday, $order) {
        $r = SalesRules::compute([$order('2026-10-25 22:00:00', 'delivered', 40, 'normal', '2026-10-26')], $monday);
        expect($r['totals']['created'])->toBe(0);
        expect($r['totals']['delivered'])->toBe(0);
        expect($r['totals']['revenue'])->toBe(0.0);
    });

    it('los cambios y las devoluciones no son ventas: se cuentan aparte', function () use ($monday, $order) {
        $r = SalesRules::compute([
            $order('2026-10-26 09:00:00', 'delivered', 40),
            $order('2026-10-26 10:00:00', 'delivered', 15, 'exchange'),
            $order('2026-10-26 11:00:00', 'delivered', 0, 'return'),
            $order('2026-10-26 12:00:00', 'open', 0, 'exchange'),
        ], $monday);
        expect($r['totals']['created'])->toBe(1);
        expect($r['totals']['delivered'])->toBe(1);
        expect($r['totals']['revenue'])->toBe(40.0);
        expect($r['excluded'])->toBe(['exchange' => 2, 'return' => 1]);
    });

    it('cancelados y rechazados son "no entregados"; lo demás sigue "en camino"; solo lo entregado suma dinero', function () use ($monday, $order) {
        $r = SalesRules::compute([
            $order('2026-10-26 09:00:00', 'delivered', 40),
            $order('2026-10-26 09:10:00', 'delivered', 60),
            $order('2026-10-26 09:20:00', 'not_delivered', 50),
            $order('2026-10-26 09:30:00', 'open', 30),
        ], $monday)['totals'];
        expect([$r['created'], $r['delivered'], $r['not_delivered'], $r['open']])->toBe([4, 2, 1, 1]);
        expect($r['revenue'])->toBe(100.0);
        expect($r['effectiveness'])->toBe(50.0); // 2 entregados de 4 creados
        expect($r['ticket'])->toBe(50.0);        // 100 ÷ 2
    });

    it('sin pedidos no inventa efectividad ni ticket', function () use ($monday) {
        $r = SalesRules::compute([], $monday)['totals'];
        expect($r['created'])->toBe(0);
        expect($r['effectiveness'])->toBeNull();
        expect($r['ticket'])->toBeNull();
    });

    it('"hoy" solo cuenta los pedidos creados hasta esta hora', function () use ($now, $order) {
        $today = Period::fromPreset('today', null, null, $now);
        $r = SalesRules::compute([$order('2026-10-29 08:00:00'), $order('2026-10-29 10:15:00'), $order('2026-10-29 10:16:00')], $today);
        expect($r['totals']['created'])->toBe(2);
    });

    it('los pedidos de cada día salen todos, también los días sin pedidos', function () use ($now, $order) {
        $week = Period::fromPreset('custom', '2026-10-20', '2026-10-26', $now);
        $r = SalesRules::compute([$order('2026-10-21 09:00:00', 'delivered', 40), $order('2026-10-21 18:00:00'), $order('2026-10-26 09:00:00')], $week);
        expect($r['series']['granularity'])->toBe('day');
        expect(array_column($r['series']['points'], 'date'))->toBe(['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26']);
        expect(array_column($r['series']['points'], 'created'))->toBe([0, 2, 0, 0, 0, 0, 1]);
        expect($r['series']['points'][1]['delivered'])->toBe(1);
        expect($r['series']['points'][1]['effectiveness'])->toBe(50.0);
    });

    it('en períodos largos agrupa por semana (de lunes a domingo) o por mes', function () use ($now, $order) {
        $twoMonths = Period::fromPreset('custom', '2026-09-01', '2026-10-26', $now);
        expect(SalesRules::granularity($twoMonths))->toBe('week');
        $r = SalesRules::compute([$order('2026-10-28 09:00:00'), $order('2026-10-22 09:00:00'), $order('2026-10-25 09:00:00')], $twoMonths);
        $byWeek = array_column($r['series']['points'], 'created', 'date');
        expect($byWeek['2026-10-19'])->toBe(2); // el jueves 22 y el domingo 25 son de la semana del lunes 19
        expect($r['totals']['created'])->toBe(2);  // el 28 está fuera del período

        expect(SalesRules::granularity(Period::fromPreset('custom', '2026-01-01', '2026-10-26', $now)))->toBe('month');
        expect(SalesRules::bucket('2026-10-28', 'month'))->toBe('2026-10-01');
    });
});

describe('Tarjetas y comparación con el período anterior', function () {
    $totals = fn (int $created, int $delivered, float $revenue) => [
        'created' => $created, 'delivered' => $delivered, 'revenue' => $revenue,
        'effectiveness' => $created ? round($delivered / $created * 100, 1) : null, 'ticket' => $delivered ? round($revenue / $delivered, 2) : null,
    ];

    it('cada tarjeta trae su explicación para el cursor', function () use ($totals) {
        $cards = SalesRules::cards($totals(10, 5, 200), $totals(10, 5, 200), false);
        expect(array_column($cards, 'key'))->toBe(['created', 'delivered', 'revenue', 'effectiveness', 'ticket']);
        foreach ($cards as $c) {
            expect($c['help'])->not->toBeEmpty();
            expect($c['label'])->not->toBeEmpty();
        }
        expect($cards[1]['help'])->toContain('aunque se hayan entregado días después');
    });

    it('cantidades y dinero cambian en %; la efectividad en puntos', function () use ($totals) {
        $cards = array_column(SalesRules::cards($totals(120, 66, 2640), $totals(100, 50, 2000), false), null, 'key');
        expect($cards['created']['change'])->toBe(20.0);
        expect($cards['revenue']['change'])->toBe(32.0);
        expect($cards['effectiveness']['value'])->toBe(55.0);
        expect($cards['effectiveness']['change'])->toBe(5.0);
        expect($cards['effectiveness']['change_unit'])->toBe('points');
        expect($cards['ticket']['change'])->toBe(0.0); // 40 y 40
    });

    it('si antes no hubo nada, no inventa un porcentaje', function () use ($totals) {
        $cards = array_column(SalesRules::cards($totals(10, 5, 200), $totals(0, 0, 0), false), null, 'key');
        expect($cards['created']['change'])->toBeNull();
        expect($cards['effectiveness']['previous'])->toBeNull();
        expect($cards['effectiveness']['change'])->toBeNull();
    });

    it('mientras los pedidos se siguen entregando, entregados, monto y efectividad se comparan sin rojo ni verde', function () use ($totals) {
        $cards = array_column(SalesRules::cards($totals(10, 2, 80), $totals(10, 6, 240), true), null, 'key');
        expect($cards['created']['neutral'])->toBeFalse();
        expect($cards['delivered']['neutral'])->toBeTrue();
        expect($cards['revenue']['neutral'])->toBeTrue();
        expect($cards['effectiveness']['neutral'])->toBeTrue();
        expect($cards['ticket']['neutral'])->toBeFalse();
    });
});
