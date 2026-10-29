<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Team\AgencyKpis;
use App\Services\Metrics\Team\OperatorKpis;
use App\Services\Metrics\Team\RiderKpis;
use Carbon\CarbonImmutable;

// Panel del dueño › Equipo (guias/DICCIONARIO-METRICAS.md, §3 y §4): agencias, repartidores y operadoras logísticas,
// con pedidos inventados. La semana del 19 al 25/10/2026.

$now = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$week = Period::fromPreset('custom', '2026-10-19', '2026-10-25', $now);

describe('Equipo › Agencias', function () use ($week) {
    $status = fn (string $at, string $s) => ['at' => $at, 'kind' => 'status', 'status' => $s, 'changed' => true, 'agency' => null, 'old' => null];
    $change = fn (string $at, ?int $old, ?int $new) => ['at' => $at, 'kind' => 'agency', 'status' => null, 'changed' => true, 'agency' => $new, 'old' => $old];
    $order = fn (?int $agency, string $s, array $events, array $extra = []) => $extra + [
        'order_id' => 1, 'agency_id' => $agency, 'status' => $s, 'delivered_at' => null, 'city' => 'Caracas', 'trips' => [], 'events' => $events,
    ];
    $people = [10 => ['name' => 'Rápida', 'group' => null], 20 => ['name' => 'Lenta', 'group' => null]];
    $compute = fn (array $journeys, array $cash = [], array $closures = []) => (new AgencyKpis())->compute(
        ['journeys' => $journeys, 'people' => $people, 'cash' => $cash, 'closures' => $closures], $week);

    it('recibe al pasar a "Asignar a agencia"; la entrega es de la que lo tenía al entregarse', function () use ($order, $status, $compute) {
        $r = $compute([
            $order(10, 'Entregado', [$status('2026-10-20 10:00:00', 'Asignar a agencia'), $status('2026-10-20 14:00:00', 'En ruta'), $status('2026-10-20 16:00:00', 'Entregado')],
                ['delivered_at' => '2026-10-20 16:00:00', 'trips' => [10 => 4.0]]),
            $order(10, 'Rechazado', [$status('2026-10-21 10:00:00', 'Asignar a agencia'), $status('2026-10-21 12:00:00', 'Novedades'), $status('2026-10-21 18:00:00', 'Rechazado')],
                ['trips' => [10 => 4.0]]),
            $order(10, 'En ruta', [$status('2026-10-22 10:00:00', 'Asignar a agencia'), $status('2026-10-22 12:00:00', 'En ruta')]),
        ]);
        $v = $r['people'][10]['values'];
        expect($v['received'])->toBe(3);
        expect($v['effectiveness'])->toBe(33.3);
        expect($v['delivery_time'])->toBe(6.0);
        expect($v['novelty_rate'])->toBe(33.3);
        expect($v['rejection_rate'])->toBe(33.3);
        expect($v['not_delivered'])->toBe(1); // el rechazado; el que sigue en ruta no cuenta
        expect($v['cost_per_delivery'])->toBe(8.0); // dos carreras de $4 para una entrega
    });

    it('si pasó por dos agencias, a la primera le cuenta como no entregado', function () use ($order, $status, $change, $compute) {
        $r = $compute([$order(20, 'Entregado', [
            $status('2026-10-20 10:00:00', 'Asignar a agencia'),
            $change('2026-10-20 11:00:00', 10, 20),
            $status('2026-10-21 09:00:00', 'Entregado'),
        ], ['delivered_at' => '2026-10-21 09:00:00'])]);
        expect($r['people'][10]['values']['received'])->toBe(1);
        expect($r['people'][10]['values']['effectiveness'])->toBe(0.0);
        expect($r['people'][10]['values']['not_delivered'])->toBe(1);
        expect($r['people'][20]['values']['effectiveness'])->toBe(100.0);
        expect($r['people'][20]['values']['delivery_time'])->toBe(22.0); // desde que la recibió ella
        expect($r['totals']['values']['received'])->toBe(1);
        expect($r['totals']['values']['effectiveness'])->toBe(100.0);
    });

    it('un cambio de agencia antes de confirmar no es una entrada; la ciudad va al detalle', function () use ($order, $status, $change, $compute) {
        $r = $compute([$order(20, 'Entregado', [
            $status('2026-10-20 08:00:00', 'Llamado 1'),
            $change('2026-10-20 09:00:00', 10, 20),
            $status('2026-10-20 10:00:00', 'Asignar a agencia'),
            $status('2026-10-21 09:00:00', 'Entregado'),
        ], ['delivered_at' => '2026-10-21 09:00:00', 'city' => 'Valencia'])]);
        expect(array_keys($r['people']))->toBe([20]);
        $block = (new AgencyKpis())->blocks(20, $r)[0];
        expect($block['rows'])->toBe([['label' => 'Valencia', 'count' => 1, 'of' => 1]]);
    });

    it('el dinero que te debe y los cierres, aunque no haya recibido pedidos', function () use ($compute) {
        $r = $compute([], [10 => 150.0, 20 => 0.0], [
            ['agency_id' => 20, 'on_time' => true, 'differences' => 0],
            ['agency_id' => 20, 'on_time' => false, 'differences' => 2],
        ]);
        expect($r['people'][10]['values']['cash'])->toBe(150.0);
        expect($r['people'][20]['values']['closures_on_time'])->toBe(50.0);
        expect($r['people'][20]['values']['closures_with_diff'])->toBe(1);
        expect($r['totals']['values']['cash'])->toBe(150.0);
    });
});

describe('Equipo › Repartidores', function () use ($week) {
    it('recibe cuando se lo asignan; entregas, tiempo, lo que acepta y el dinero en mano', function () use ($week) {
        $people = [5 => ['name' => 'Luis', 'group' => 'Logística propia'], 6 => ['name' => 'Pedro', 'group' => 'Logística propia']];
        $j = fn (?int $now, string $s, array $events, ?string $delivered = null, float $earning = 0) => [
            'order_id' => 1, 'deliverer_id' => $now, 'status' => $s, 'delivered_at' => $delivered, 'earning' => $earning, 'events' => $events,
        ];
        $r = (new RiderKpis())->compute(['people' => $people, 'offers' => [5 => ['sent' => 4, 'confirmed' => 3]], 'cash' => [5 => 35.0], 'journeys' => [
            $j(5, 'Entregado', [['at' => '2026-10-20 10:00:00', 'deliverer' => 5]], '2026-10-20 10:45:00', 3.0),
            $j(6, 'Entregado', [['at' => '2026-10-21 10:00:00', 'deliverer' => 5], ['at' => '2026-10-21 10:20:00', 'deliverer' => 6]], '2026-10-21 11:00:00', 3.0),
            $j(5, 'En ruta', [['at' => '2026-10-22 10:00:00', 'deliverer' => 5]]),
            $j(5, 'Entregado', [['at' => '2026-10-10 10:00:00', 'deliverer' => 5]], '2026-10-20 10:00:00', 3.0), // de otra semana
        ]], $week);
        $luis = $r['people'][5]['values'];
        expect($luis['received'])->toBe(3);
        expect($luis['delivered'])->toBe(1);
        expect($luis['effectiveness'])->toBe(33.3);
        expect($luis['delivery_time'])->toBe(0.75);
        expect($luis['not_delivered'])->toBe(1); // el que pasó a Pedro
        expect($luis['acceptance'])->toBe(75.0);
        expect($luis['earnings'])->toBe(3.0);
        expect($luis['cash'])->toBe(35.0);
        expect($r['people'][6]['values']['delivery_time'])->toBe(round(40 / 60, 2));
        expect($r['totals']['values']['received'])->toBe(3);
        expect($r['totals']['values']['delivered'])->toBe(2);
    });
});

describe('Equipo › Operadoras logísticas', function () use ($week) {
    it('turnos, despachos a tiempo, preparación, agencia en su turno, novedades, alertas y caja', function () use ($week) {
        $ev = fn (?int $actor, string $action, string $at, array $extra = []) => $extra + [
            'actor' => $actor, 'action' => $action, 'at' => $at, 'center' => 1, 'rider_confirmed_at' => null,
            'sla_started_at' => null, 'delivered_at' => null, 'target' => 45,
        ];
        $r = (new OperatorKpis())->compute([
            'people' => [7 => ['name' => 'Olga', 'group' => 'Centro'], 8 => ['name' => 'Rosa', 'group' => 'Centro']],
            'now' => '2026-10-29 10:15:00',
            'shifts' => [
                ['user' => 7, 'center' => 1, 'started_at' => '2026-10-20 08:00:00', 'ended_at' => '2026-10-20 18:00:00', 'close_type' => 'limpio'],
                ['user' => 8, 'center' => 1, 'started_at' => '2026-10-21 08:00:00', 'ended_at' => '2026-10-21 14:00:00', 'close_type' => 'con_pendientes'],
            ],
            'events' => [
                $ev(7, 'salida', '2026-10-20 09:00:00', ['sla_started_at' => '2026-10-20 08:30:00', 'delivered_at' => '2026-10-20 09:10:00']),
                $ev(7, 'salida', '2026-10-20 10:00:00', ['sla_started_at' => '2026-10-20 09:00:00', 'delivered_at' => '2026-10-20 10:30:00']),
                $ev(7, 'salida', '2026-10-20 11:00:00', ['sla_started_at' => '2026-10-20 10:30:00']), // sigue en camino
                $ev(7, 'listo_despacho', '2026-10-20 08:55:00', ['rider_confirmed_at' => '2026-10-20 08:40:00']),
                $ev(null, 'a_agencia', '2026-10-20 12:00:00'),   // la pasó el sistema en el minuto 5, con Olga de turno
                $ev(8, 'a_agencia', '2026-10-21 09:00:00'),      // la pasó Rosa a mano
                $ev(8, 'asignada_a_mano', '2026-10-21 10:00:00'),
                $ev(8, 'asignada_a_mano', '2026-10-21 10:30:00'),
            ],
            'released' => [
                ['center' => 1, 'at' => '2026-10-20 08:30:00'], ['center' => 1, 'at' => '2026-10-20 09:00:00'],
                ['center' => 1, 'at' => '2026-10-20 10:30:00'], ['center' => 1, 'at' => '2026-10-20 11:55:00'],
                ['center' => 1, 'at' => '2026-10-21 08:55:00'],
            ],
            'novelties' => [
                ['resolved_by' => 7, 'escalated_by' => null, 'opened_at' => '2026-10-20 09:00:00', 'done_at' => '2026-10-20 09:06:00'],
                ['resolved_by' => null, 'escalated_by' => 8, 'opened_at' => '2026-10-21 09:00:00', 'done_at' => '2026-10-21 09:30:00'],
            ],
            'alerts' => [['resolved_by' => 7, 'created_at' => '2026-10-20 12:00:00', 'resolved_at' => '2026-10-20 12:03:00']],
            'handovers' => [['actor' => 8, 'has_difference' => true], ['actor' => 8, 'has_difference' => false]],
        ], $week);
        $olga = $r['people'][7]['values'];
        $rosa = $r['people'][8]['values'];
        expect($olga['shifts'])->toBe(1);
        expect($olga['shift_hours'])->toBe(10.0);
        expect($olga['clean_close'])->toBe(100.0);
        expect($rosa['clean_close'])->toBe(0.0);
        expect($olga['dispatched'])->toBe(3);
        expect($olga['on_time'])->toBe(50.0); // 40 minutos a tiempo, 90 minutos tarde
        expect($olga['prep_time'])->toBe(0.25);
        expect($olga['agency_rate'])->toBe(25.0); // 1 de los 4 que entraron en su turno
        expect($olga['forced'])->toBe(0);
        expect($rosa['agency_rate'])->toBe(100.0);
        expect($rosa['forced'])->toBe(1);
        expect($rosa['manual'])->toBe(2);
        expect($olga['novelty_resolved'])->toBe(100.0);
        expect($rosa['novelty_resolved'])->toBe(0.0);
        expect($olga['novelty_time'])->toBe(0.1);
        expect($olga['alerts'])->toBe(1);
        expect($olga['alert_time'])->toBe(0.05);
        expect($rosa['cash_diff'])->toBe(1);
        expect($r['totals']['values']['agency_rate'])->toBe(40.0); // 2 de 5 en todo el centro
    });
});
