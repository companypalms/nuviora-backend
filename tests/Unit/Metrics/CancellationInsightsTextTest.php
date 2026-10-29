<?php

use App\Services\Metrics\Insights\CancellationInsights;
use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\LossRules;
use Carbon\CarbonImmutable;

// Panel del dueño › Pérdidas (dueño, 2026-10-29): lo que se le envía a la IA para analizar los motivos de cancelación.

$cancel = fn (string $reason, array $extra = []) => $extra + [
    'created_at' => '2026-10-20 09:00:00', 'order_number' => 'A1', 'kind' => 'normal', 'outcome' => 'not_delivered', 'status' => 'Cancelado',
    'novedad_type' => null, 'shipping' => 0, 'refunded' => 0, 'category' => 'no_contesta', 'source' => 'user', 'reason' => $reason,
    'cancelled_at' => '2026-10-20 12:00:00', 'previous_status' => 'Llamado 2', 'user_name' => 'María José Pérez', 'user_role' => 'Vendedor',
];

describe('Lo que se envía a la IA', function () use ($cancel) {
    it('borra teléfonos y correos de los motivos', function () {
        expect(CancellationInsights::mask('Llamé al 0414-123.45.67 y al +58 412 555 1234, escribió a cliente@correo.com'))
            ->toBe('Llamé al [teléfono] y al [teléfono], escribió a [correo]');
        expect(CancellationInsights::mask('Pidió 2 unidades por $45'))->toBe('Pidió 2 unidades por $45');
    });

    it('manda solo lo escrito por personas, de lo más nuevo a lo más viejo, con el primer nombre de la vendedora', function () use ($cancel) {
        $lines = CancellationInsights::lines([
            $cancel('No contesta, lo llamé 3 veces', ['cancelled_at' => '2026-10-20 10:00:00']),
            $cancel('Dice que no tiene el dinero', ['cancelled_at' => '2026-10-21 10:00:00', 'category' => 'dinero', 'user_role' => 'Admin', 'previous_status' => 'Asignar a agencia']),
            $cancel('Sistema: cierre de la jornada', ['source' => 'system']),
            $cancel('Se canceló pero después se reactivó', ['status' => 'Llamado 1']),
            $cancel('', ['cancelled_at' => '2026-10-22 10:00:00']),
        ], 100);
        expect($lines)->toBe([
            '2026-10-21 | No tiene el dinero / precio | Después de confirmar | Administración | Dice que no tiene el dinero',
            '2026-10-20 | No contesta / no se pudo contactar | En las llamadas | María | No contesta, lo llamé 3 veces',
        ]);
        expect(CancellationInsights::lines([$cancel('uno'), $cancel('dos'), $cancel('tres')], 2))->toHaveCount(2);
    });

    it('lleva los totales del período antes de los motivos', function () use ($cancel) {
        $period = Period::fromPreset('custom', '2026-10-19', '2026-10-25', CarbonImmutable::parse('2026-10-29 10:00:00'));
        $facts = [$cancel('No contesta, lo llamé 3 veces')];
        $input = CancellationInsights::input(CancellationInsights::lines($facts, 10), LossRules::compute($facts, $period), $period, 1);
        expect($input)->toContain('Período: del 2026-10-19 al 2026-10-25.');
        expect($input)->toContain('Cancelados: 1.');
        expect($input)->toContain('Cancelados por motivo de la lista: No contesta / no se pudo contactar: 1.');
        expect($input)->toEndWith('2026-10-20 | No contesta / no se pudo contactar | En las llamadas | María | No contesta, lo llamé 3 veces');
    });
});
