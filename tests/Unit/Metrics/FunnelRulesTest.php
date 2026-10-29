<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\FunnelRules;
use Carbon\CarbonImmutable;

// Panel del dueño (guias/DICCIONARIO-METRICAS.md, §3 y §8): las reglas del embudo, con pedidos inventados. El lunes
// 19/10/2026 es el día de los ejemplos.

$now = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$monday = Period::fromPreset('custom', '2026-10-19', '2026-10-19', $now);
$order = fn (string $createdAt, string $outcome = 'open', string $status = 'Asignado a vendedor', array $at = [], string $kind = 'normal') => [
    'created_at' => $createdAt, 'kind' => $kind, 'outcome' => $outcome, 'status' => $status,
    'assigned_at' => $at['assigned'] ?? null, 'confirmed_at' => $at['confirmed'] ?? null, 'route_at' => $at['route'] ?? null, 'delivered_at' => $at['delivered'] ?? null,
];
$steps = fn (array $r) => array_column($r['steps'], null, 'key');

describe('Embudo', function () use ($monday, $order, $steps) {
    it('cuenta cuántos llegaron a cada paso, y un paso nunca es mayor que el anterior', function () use ($monday, $order, $steps) {
        $s = $steps(FunnelRules::compute([
            $order('2026-10-19 09:00:00', 'delivered', 'Entregado', ['assigned' => '2026-10-19 09:10:00', 'confirmed' => '2026-10-19 12:00:00', 'route' => '2026-10-20 09:00:00', 'delivered' => '2026-10-20 11:00:00']),
            $order('2026-10-19 09:30:00', 'open', 'En ruta', ['assigned' => '2026-10-19 09:40:00', 'confirmed' => '2026-10-19 15:00:00', 'route' => '2026-10-20 10:00:00']),
            $order('2026-10-19 10:00:00', 'not_delivered', 'Cancelado', ['assigned' => '2026-10-19 10:05:00']),
            $order('2026-10-19 11:00:00', 'open', 'Nuevo'),
        ], $monday));
        expect(array_column($s, 'count', 'key'))->toBe(['created' => 4, 'assigned' => 3, 'confirmed' => 2, 'on_route' => 2, 'delivered' => 1]);
        expect($s['confirmed']['of_created'])->toBe(50.0);
        expect($s['confirmed']['of_previous'])->toBe(66.7);
        expect($s['created']['of_previous'])->toBeNull();
    });

    it('un pedido entregado pasó por todos los pasos, aunque alguien se haya saltado un estado', function () use ($monday, $order, $steps) {
        $s = $steps(FunnelRules::compute([$order('2026-10-19 09:00:00', 'delivered', 'Entregado', ['delivered' => '2026-10-19 18:00:00'])], $monday));
        expect(array_column($s, 'count', 'key'))->toBe(['created' => 1, 'assigned' => 1, 'confirmed' => 1, 'on_route' => 1, 'delivered' => 1]);
    });

    it('cuenta para el día en que se creó, aunque se confirme o entregue después; sin cambios ni devoluciones', function () use ($monday, $order, $steps) {
        $s = $steps(FunnelRules::compute([
            $order('2026-10-19 23:00:00', 'delivered', 'Entregado', ['confirmed' => '2026-10-20 10:00:00', 'delivered' => '2026-10-22 10:00:00']),
            $order('2026-10-18 23:00:00', 'delivered', 'Entregado', ['confirmed' => '2026-10-19 10:00:00', 'delivered' => '2026-10-19 18:00:00']),
            $order('2026-10-19 12:00:00', 'delivered', 'Entregado', [], 'exchange'),
        ], $monday));
        expect($s['created']['count'])->toBe(1);
        expect($s['delivered']['count'])->toBe(1);
    });

    it('dice dónde se quedaron los que no siguieron: cancelados, rechazados o todavía en proceso', function () use ($monday, $order, $steps) {
        $s = $steps(FunnelRules::compute([
            $order('2026-10-19 09:00:00', 'not_delivered', 'Cancelado', ['assigned' => '2026-10-19 09:10:00']),
            $order('2026-10-19 09:30:00', 'open', 'Llamado 2', ['assigned' => '2026-10-19 09:40:00']),
            $order('2026-10-19 10:00:00', 'not_delivered', 'Rechazado', ['assigned' => '2026-10-19 10:05:00', 'confirmed' => '2026-10-19 11:00:00', 'route' => '2026-10-20 09:00:00']),
            $order('2026-10-19 10:30:00', 'not_delivered', 'Cancelado', ['assigned' => '2026-10-19 10:35:00', 'confirmed' => '2026-10-19 11:00:00']),
        ], $monday));
        expect($s['assigned']['stayed'])->toBe(['cancelled' => 1, 'rejected' => 0, 'open' => 1, 'total' => 2]);
        expect($s['confirmed']['stayed'])->toBe(['cancelled' => 1, 'rejected' => 0, 'open' => 0, 'total' => 1]);
        expect($s['on_route']['stayed'])->toBe(['cancelled' => 0, 'rejected' => 1, 'open' => 0, 'total' => 1]);
        expect($s['delivered']['stayed'])->toBeNull();
    });

    it('el tiempo normal de cada paso es la mediana desde el paso anterior', function () use ($monday, $order, $steps) {
        $r = FunnelRules::compute([
            $order('2026-10-19 09:00:00', 'delivered', 'Entregado', ['assigned' => '2026-10-19 10:00:00', 'confirmed' => '2026-10-19 12:00:00', 'route' => '2026-10-20 09:00:00', 'delivered' => '2026-10-20 11:00:00']),
            $order('2026-10-19 09:00:00', 'delivered', 'Entregado', ['assigned' => '2026-10-19 12:00:00', 'confirmed' => '2026-10-19 13:00:00', 'route' => '2026-10-20 09:00:00', 'delivered' => '2026-10-20 10:00:00']),
            $order('2026-10-19 09:00:00', 'delivered', 'Entregado', ['assigned' => '2026-10-19 09:30:00', 'confirmed' => '2026-10-19 20:00:00', 'route' => '2026-10-22 09:00:00', 'delivered' => '2026-10-29 09:00:00']),
        ], $monday);
        $s = $steps($r);
        expect($s['assigned']['median_hours'])->toBe(1.0);   // 1 h, 3 h y 0,5 h → 1 h
        expect($s['confirmed']['median_hours'])->toBe(2.0);  // 2 h, 1 h y 10,5 h → 2 h
        expect($s['delivered']['median_hours'])->toBe(2.0);  // 2 h, 1 h y 7 días → 2 h: el que tardó una semana no la infla
        expect($r['totals']['time_to_deliver'])->toBe(26.0); // 26 h, 25 h y 10 días → 26 h
    });
});

describe('Tarjetas del embudo', function () use ($monday, $order) {
    it('confirmación = confirmados ÷ creados; entrega = entregados ÷ confirmados; y las dos dan la efectividad general', function () use ($monday, $order) {
        $facts = [];
        for ($i = 0; $i < 10; $i++) {
            $outcome = $i < 4 ? 'delivered' : ($i < 6 ? 'open' : 'not_delivered');
            $facts[] = $order('2026-10-19 09:0' . $i . ':00', $outcome, $i < 4 ? 'Entregado' : 'Cancelado', $i < 6 ? ['confirmed' => '2026-10-19 12:00:00'] : []);
        }
        $t = FunnelRules::compute($facts, $monday)['totals'];
        expect($t['confirmation_rate'])->toBe(60.0); // 6 de 10
        expect($t['delivery_rate'])->toBe(66.7);     // 4 de 6
        expect(FunnelRules::relation($t))->toStartWith('60,0 % confirmados × 66,7 % de lo confirmado entregado = 40,0 % de efectividad general');

        $cards = array_column(FunnelRules::cards($t, $t, true), null, 'key');
        expect($cards['confirmation_rate']['detail'])->toBe('6 confirmados ÷ 10 creados');
        expect($cards['time_to_deliver']['better'])->toBe('lower');
        expect($cards['time_to_deliver']['format'])->toBe('hours');
        expect($cards['delivery_rate']['neutral'])->toBeTrue();
    });

    it('sin pedidos no inventa porcentajes', function () use ($monday) {
        $t = FunnelRules::compute([], $monday)['totals'];
        expect($t['confirmation_rate'])->toBeNull();
        expect($t['time_to_deliver'])->toBeNull();
        expect(FunnelRules::relation($t))->toBeNull();
    });
});
