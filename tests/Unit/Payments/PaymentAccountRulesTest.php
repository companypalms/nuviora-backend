<?php

use App\Services\Payments\PaymentAccountRules;

// Varias cuentas de pago móvil, una por orden (`.60`): a cuál le paga el cliente.

$acc = fn (int $id, float $bs = 0, int $orders = 0, ?string $last = null) => ['id' => $id, 'bs' => $bs, 'orders' => $orders, 'last_at' => $last];

describe('a qué cuenta va una orden', function () use ($acc) {
    it('va a la que menos bolívares lleva hoy', function () use ($acc) {
        expect(PaymentAccountRules::pick([$acc(1, 9000, 2), $acc(2, 4500, 5), $acc(3, 12000, 1)]))->toBe(2);
    });

    it('a igual monto, la que menos órdenes lleva; después, la que hace más tiempo que no recibe una', function () use ($acc) {
        expect(PaymentAccountRules::pick([$acc(1, 0, 2), $acc(2, 0, 1)]))->toBe(2);
        expect(PaymentAccountRules::pick([$acc(1, 0, 0, '2026-10-31 09:00:00'), $acc(2, 0, 0, '2026-10-30 18:00:00')]))->toBe(2);
        // una cuenta nueva (nunca recibió) va primero
        expect(PaymentAccountRules::pick([$acc(1, 0, 0, '2026-10-30 18:00:00'), $acc(5)]))->toBe(5);
    });

    it('sin nada más que la distinga, la más vieja', function () use ($acc) {
        expect(PaymentAccountRules::pick([$acc(4), $acc(2), $acc(3)]))->toBe(2);
    });

    it('sin cuentas, ninguna', function () {
        expect(PaymentAccountRules::pick([]))->toBeNull();
    });
});

describe('varias órdenes seguidas (el comando de una vez)', function () use ($acc) {
    it('cada una se suma a su cuenta antes de elegir la siguiente: el dinero queda parejo', function () use ($acc) {
        $out = PaymentAccountRules::spread([$acc(1), $acc(2)], [10 => 5000, 11 => 1000, 12 => 1000, 13 => 2000], '2026-10-31 10:00:00');
        // 10 → 1 (5000); 11 → 2 (1000); 12 → 2 (2000); 13 → 2 (4000): la 1 sigue llevando más
        expect($out)->toBe([10 => 1, 11 => 2, 12 => 2, 13 => 2]);
    });

    it('sin montos queda por turnos', function () use ($acc) {
        expect(PaymentAccountRules::spread([$acc(1), $acc(2), $acc(3)], [1 => 0, 2 => 0, 3 => 0, 4 => 0], '2026-10-31 10:00:00'))
            ->toBe([1 => 1, 2 => 2, 3 => 3, 4 => 1]);
    });

    it('sin cuentas, las órdenes quedan sin cuenta', function () {
        expect(PaymentAccountRules::spread([], [7 => 100], '2026-10-31 10:00:00'))->toBe([7 => null]);
    });
});

describe('lo que paga una orden por pago móvil, en Bs', function () {
    it('solo los pagos por pago móvil, cada uno por su tasa (o la de Binance si no tiene)', function () {
        expect(PaymentAccountRules::orderBs([
            ['method' => 'PAGOMOVIL', 'amount' => 10, 'rate' => 300, 'binance_usd_rate' => 310],
            ['method' => 'PAGOMOVIL', 'amount' => 5, 'rate' => null, 'binance_usd_rate' => 310],
            ['method' => 'DOLARES_EFECTIVO', 'amount' => 20, 'rate' => null, 'binance_usd_rate' => 310],
        ]))->toBe(4550.0);
    });
});
