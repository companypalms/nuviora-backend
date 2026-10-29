<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Team\LeaderKpis;
use App\Services\Metrics\Team\SellerKpis;
use Carbon\CarbonImmutable;

// Panel del dueño › Equipo (guias/DICCIONARIO-METRICAS.md, §3 y §4): las reglas de vendedoras y líderes, con pedidos
// inventados. La semana del 19 al 25/10/2026; Ana (1), Bea (2) y Caro (3) son vendedoras; 9 no es vendedora.

$now = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$week = Period::fromPreset('custom', '2026-10-19', '2026-10-25', $now);
$people = [1 => ['name' => 'Ana', 'group' => 'Norte'], 2 => ['name' => 'Bea', 'group' => 'Norte'], 3 => ['name' => 'Caro', 'group' => null]];
$log = fn (string $at, string $status, ?int $seller, bool $changed = true, bool $reassigned = false) =>
    compact('at', 'status', 'seller', 'changed', 'reassigned');
$order = fn (string $status, ?int $agent, array $logs, array $extra = []) => $extra + [
    'order_id' => 1, 'agent_id' => $agent, 'status' => $status, 'revenue' => $status === 'Entregado' ? 50.0 : 0.0, 'has_upsell' => false,
    'returns' => 0, 'commission' => $status === 'Entregado' ? 3.0 : 0.0, 'leader_commission' => 0.0, 'reason_key' => null, 'logs' => $logs,
];
$sellers = fn (array $journeys, array $groups = []) => (new SellerKpis())->compute(['journeys' => $journeys, 'people' => $people, 'groups' => $groups], $week);

describe('Equipo › Vendedoras', function () use ($order, $log, $sellers) {
    it('le entra un pedido por "Asignado a vendedor" o "Reprogramado para hoy", y cuenta una vez', function () use ($order, $log, $sellers) {
        $r = $sellers([
            $order('Entregado', 1, [
                $log('2026-10-20 09:00:00', 'Asignado a vendedor', 1),
                $log('2026-10-20 09:30:00', 'Programado para otro dia', 1),
                $log('2026-10-21 08:00:00', 'Reprogramado para hoy', 1),
                $log('2026-10-21 08:10:00', 'Asignar a agencia', 1),
                $log('2026-10-22 12:00:00', 'Entregado', 1),
            ]),
            // Le entró antes de la semana: no es de esta semana
            $order('Entregado', 1, [$log('2026-10-15 09:00:00', 'Asignado a vendedor', 1), $log('2026-10-20 12:00:00', 'Entregado', 1)]),
        ]);
        $ana = $r['people'][1]['values'];
        expect($ana['attended'])->toBe(1);
        expect($ana['effectiveness'])->toBe(100.0);
        expect($ana['confirmation'])->toBe(100.0);
        expect($ana['rescheduled'])->toBe(1);
        expect($ana['rescheduled_delivered'])->toBe(100.0);
        // Velocidad: 30 minutos la primera vez y 10 la segunda → la mediana es 20 minutos
        expect($ana['response_time'])->toBe(round(20 / 60, 2));
        expect($ana['revenue'])->toBe(50.0);
        expect($ana['commission'])->toBe(3.0);
    });

    it('si pasó por dos vendedoras, las dos lo atendieron y la entrega es de la que lo tenía al entregarse', function () use ($order, $log, $sellers) {
        $r = $sellers([$order('Entregado', 2, [
            $log('2026-10-20 09:00:00', 'Asignado a vendedor', 1),
            $log('2026-10-20 09:20:00', 'Llamado 1', 1),
            $log('2026-10-20 11:00:00', 'Llamado 1', 2, false, true), // reasignada a Bea
            $log('2026-10-20 11:30:00', 'Asignar a agencia', 2),
            $log('2026-10-21 10:00:00', 'Entregado', 2),
        ])]);
        expect($r['people'][1]['values']['attended'])->toBe(1);
        expect($r['people'][1]['values']['effectiveness'])->toBe(0.0);
        expect($r['people'][1]['values']['confirmation'])->toBe(0.0);
        expect($r['people'][2]['values']['effectiveness'])->toBe(100.0);
        expect($r['people'][2]['values']['confirmation'])->toBe(100.0);
        // Bea lo movió 30 minutos después de recibirlo
        expect($r['people'][2]['values']['response_time'])->toBe(0.5);
        // En el total del equipo el pedido cuenta una vez
        expect($r['totals']['values']['attended'])->toBe(1);
        expect($r['totals']['values']['effectiveness'])->toBe(100.0);
    });

    it('si se lo pasan antes de que lo mueva, no cuenta como velocidad de quien lo tenía', function () use ($order, $log, $sellers) {
        $r = $sellers([$order('Llamado 1', 2, [
            $log('2026-10-20 09:00:00', 'Asignado a vendedor', 1),
            $log('2026-10-20 13:00:00', 'Asignado a vendedor', 2, false, true),
            $log('2026-10-20 13:05:00', 'Llamado 1', 2),
        ])]);
        expect($r['people'][1]['values']['response_time'])->toBeNull();
        expect($r['people'][2]['values']['response_time'])->toBe(round(5 / 60, 2));
    });

    it('cancelaciones con su motivo, rechazos de lo que confirmó, upsells y devoluciones', function () use ($order, $log, $sellers) {
        $entry = $log('2026-10-20 09:00:00', 'Asignado a vendedor', 3);
        $confirm = $log('2026-10-20 10:00:00', 'Asignar a agencia', 3);
        $r = $sellers([
            $order('Cancelado', 3, [$entry, $log('2026-10-20 09:10:00', 'Cancelado', 3)], ['reason_key' => 'no_contesta']),
            $order('Cancelado', 3, [$entry, $log('2026-10-20 09:10:00', 'Cancelado', 3)], ['reason_key' => 'no_contesta']),
            $order('Rechazado', 3, [$entry, $confirm, $log('2026-10-21 10:00:00', 'Rechazado', 3)]),
            $order('Entregado', 3, [$entry, $confirm, $log('2026-10-21 10:00:00', 'Entregado', 3)], ['has_upsell' => true, 'returns' => 1]),
        ]);
        $caro = $r['people'][3]['values'];
        expect($caro['attended'])->toBe(4);
        expect($caro['cancel_rate'])->toBe(50.0);
        expect($caro['confirmation'])->toBe(50.0);
        expect($caro['rejection_rate'])->toBe(50.0);
        expect($caro['upsell_rate'])->toBe(100.0);
        expect($caro['returns'])->toBe(1);
        expect($r['reasons'][3])->toBe(['no_contesta' => 2]);
        $blocks = (new SellerKpis())->blocks(3, $r);
        $funnel = array_column($blocks[0]['rows'], 'count', 'label');
        expect($funnel['Pasó a agencia (confirmado)'])->toBe(2);
        expect($funnel['Cancelado'])->toBe(2);
        expect($blocks[1]['rows'][0])->toBe(['label' => 'No contesta / no se pudo contactar', 'count' => 2, 'of' => 2]);
    });

    it('no cuenta pedidos de usuarios que no son vendedoras', function () use ($order, $log, $sellers) {
        $r = $sellers([$order('Entregado', 9, [$log('2026-10-20 09:00:00', 'Asignado a vendedor', 9), $log('2026-10-21 09:00:00', 'Entregado', 9)])]);
        expect($r['people'])->toBe([]);
        expect($r['totals']['values']['attended'])->toBe(0);
        expect($r['totals']['values']['effectiveness'])->toBeNull();
    });
});

describe('Equipo › Líderes', function () use ($order, $log, $people, $week) {
    it('suma las vendedoras de cada grupo (un pedido una vez) con la comisión de la líder', function () use ($order, $log, $people, $week) {
        $groups = [7 => ['name' => 'Norte', 'leader_id' => 1, 'leader' => 'Ana', 'members' => [1, 2]]];
        $journeys = [
            $order('Entregado', 2, [
                $log('2026-10-20 09:00:00', 'Asignado a vendedor', 1),
                $log('2026-10-20 11:00:00', 'Llamado 1', 2, false, true),
                $log('2026-10-21 10:00:00', 'Entregado', 2),
            ], ['leader_commission' => 0.5]),
            $order('Cancelado', 1, [$log('2026-10-20 09:00:00', 'Asignado a vendedor', 1), $log('2026-10-20 09:10:00', 'Cancelado', 1)]),
            $order('Entregado', 3, [$log('2026-10-20 09:00:00', 'Asignado a vendedor', 3), $log('2026-10-21 10:00:00', 'Entregado', 3)]),
        ];
        $r = (new LeaderKpis())->compute(['journeys' => $journeys, 'people' => $people, 'groups' => $groups], $week);
        $norte = $r['people'][7];
        expect($norte['name'])->toBe('Norte');
        expect($norte['group'])->toBe('Líder: Ana');
        expect($norte['values']['sellers'])->toBe(2);
        expect($norte['values']['attended'])->toBe(2);
        expect($norte['values']['effectiveness'])->toBe(50.0);
        expect($norte['values']['leader_commission'])->toBe(0.5);
        // El total de las líderes no incluye a Caro, que no está en ningún grupo
        expect($r['totals']['values']['attended'])->toBe(2);
        $table = (new LeaderKpis())->blocks(7, $r)[0];
        expect(array_keys($table['people']))->toBe([1, 2]);
    });
});
