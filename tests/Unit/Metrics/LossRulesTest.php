<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\LossRules;
use Carbon\CarbonImmutable;

// Panel del dueño (guias/DICCIONARIO-METRICAS.md, §4 y §8): las reglas de "Pérdidas", con pedidos inventados.

$now = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$week = Period::fromPreset('custom', '2026-10-19', '2026-10-25', $now);
$order = fn (string $status, array $extra = []) => $extra + [
    'created_at' => '2026-10-20 09:00:00', 'order_number' => 'A1', 'kind' => 'normal',
    'outcome' => match ($status) { 'Entregado' => 'delivered', 'Cancelado', 'Rechazado' => 'not_delivered', default => 'open' },
    'status' => $status, 'novedad_type' => null, 'shipping' => 0, 'refunded' => 0,
    'category' => null, 'source' => null, 'reason' => null, 'cancelled_at' => null, 'previous_status' => null, 'user_name' => null, 'user_role' => null,
];
$cancel = fn (string $category, string $previous, string $role = 'Vendedor', string $source = 'user', array $extra = []) => $order('Cancelado', $extra + [
    'category' => $category, 'source' => $source, 'reason' => 'Motivo escrito de prueba', 'cancelled_at' => '2026-10-20 12:00:00',
    'previous_status' => $previous, 'user_name' => 'María José Pérez', 'user_role' => $role,
]);

describe('Pérdidas', function () use ($week, $order, $cancel) {
    it('cuenta cancelados por motivo, momento y quién, solo de los pedidos creados en el período', function () use ($week, $order, $cancel) {
        $r = LossRules::compute([
            $cancel('no_contesta', 'Llamado 2'),
            $cancel('no_contesta', 'Asignado a vendedor'),
            $cancel('dinero', 'En ruta', 'Admin'),
            $cancel('sin_stock', 'Llamado 1', 'Vendedor', 'user', ['created_at' => '2026-10-26 09:00:00']), // de otra semana
            $order('Entregado'),
        ], $week);
        expect($r['totals']['created'])->toBe(4);
        expect($r['totals']['cancelled'])->toBe(3);
        expect(array_column($r['reasons'], 'count', 'key'))->toBe(['no_contesta' => 2, 'dinero' => 1]);
        expect($r['reasons'][0]['label'])->toBe('No contesta / no se pudo contactar');
        expect(array_column($r['stages'], 'count', 'key'))->toBe(['before_contact' => 1, 'calls' => 1, 'street' => 1]);
        expect(array_column($r['who'], 'count', 'key'))->toBe(['seller' => 2, 'admin' => 1]);
    });

    it('las cancelaciones de antes de la lista, las del cierre y las sin registro salen aparte', function () use ($week, $order, $cancel) {
        $r = LossRules::compute([
            $order('Cancelado', ['source' => 'user', 'reason' => 'motivo viejo sin lista', 'user_role' => 'Vendedor', 'previous_status' => 'Llamado 1']),
            $order('Cancelado', ['source' => 'system', 'category' => 'sistema', 'reason' => 'Sistema: ...', 'previous_status' => 'Llamado 1']),
            $order('Cancelado'),
        ], $week);
        expect(array_column($r['reasons'], 'count', 'key'))->toBe(['sin_lista' => 1, 'sistema' => 1, 'sin_registro' => 1]);
        expect(array_column($r['who'], 'count', 'key'))->toBe(['seller' => 1, 'system' => 1, 'unknown' => 1]);
        expect(count($r['latest']))->toBe(1); // solo lo que escribió una persona
    });

    it('agrupa los rechazos por su última novedad, y suma lo perdido en envíos y lo devuelto', function () use ($week, $order) {
        $r = LossRules::compute([
            $order('Rechazado', ['novedad_type' => 'No atendió', 'shipping' => 4]),
            $order('Rechazado', ['novedad_type' => 'No atendió', 'shipping' => 3.5]),
            $order('Rechazado', ['shipping' => 4]),
            $order('Entregado', ['shipping' => 4, 'refunded' => 25]),
            $order('Asignar a agencia', ['shipping' => 4]),
        ], $week);
        expect($r['totals']['rejected'])->toBe(3);
        expect(array_column($r['novelties'], 'count', 'label'))->toBe(['No atendió' => 2, 'Sin novedad registrada' => 1]);
        expect($r['totals']['failed_shipping'])->toBe(11.5); // solo de los que no se entregaron
        expect($r['totals']['refunded'])->toBe(25.0);
    });

    it('cuenta cambios y devoluciones aparte, sin mezclarlos con los pedidos', function () use ($week, $order) {
        $r = LossRules::compute([$order('Entregado', ['kind' => 'exchange']), $order('Entregado', ['kind' => 'return']), $order('Cancelado', ['kind' => 'exchange'])], $week);
        expect($r['totals']['returns'])->toBe(3);
        expect($r['totals']['created'])->toBe(0);
        expect($r['totals']['cancelled'])->toBe(0);
    });

    it('la lista de motivos escritos va de la más nueva a la más vieja, con el primer nombre de la vendedora', function () use ($week, $cancel) {
        $r = LossRules::compute([
            $cancel('no_contesta', 'Llamado 1', 'Vendedor', 'user', ['cancelled_at' => '2026-10-20 10:00:00', 'order_number' => 'VIEJA']),
            $cancel('dinero', 'Llamado 1', 'Vendedor', 'user', ['cancelled_at' => '2026-10-21 10:00:00', 'order_number' => 'NUEVA']),
        ], $week);
        expect(array_column($r['latest'], 'order_number'))->toBe(['NUEVA', 'VIEJA']);
        expect($r['latest'][0]['who'])->toBe('María');
        expect($r['latest'][0]['category'])->toBe('No tiene el dinero / precio');
    });

    it('las tarjetas: bajar es lo bueno, y muestran la cuenta', function () use ($week, $cancel, $order) {
        $t = LossRules::compute([$cancel('no_contesta', 'Llamado 1'), $order('Entregado'), $order('Entregado'), $order('Entregado')], $week)['totals'];
        $cards = array_column(LossRules::cards($t, $t, false), null, 'key');
        expect($cards['cancelled']['better'])->toBe('lower');
        expect($cards['cancelled']['detail'])->toBe('1 cancelado de 4 creados (25,0 %)');
        expect($cards['returns']['detail'])->toBe('0 cambios y 0 devoluciones');
    });
});
