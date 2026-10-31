<?php

use App\Services\Finance\PurchaseCost;

// El costo sale solo de las compras registradas (regla del dueño, 2026-10-31), con compras inventadas de FlexiStep.

$compras = [['2026-09-10 10:00:00', 2.0], ['2026-10-05 15:30:00', 2.4], ['2026-10-20 09:00:00', 2.1]];

describe('El costo de una unidad', function () use ($compras) {
    it('es la última compra hasta ese día (el día entero cuenta)', function () use ($compras) {
        expect(PurchaseCost::pick($compras, '2026-10-05'))->toBe(2.4)
            ->and(PurchaseCost::pick($compras, '2026-10-04'))->toBe(2.0)
            ->and(PurchaseCost::pick($compras, '2026-10-19 23:00:00'))->toBe(2.4)
            ->and(PurchaseCost::pick($compras, '2026-10-31'))->toBe(2.1);
    });

    it('sin fecha, la última compra: un producto que se quedó sin stock sigue con su costo', function () use ($compras) {
        expect(PurchaseCost::pick($compras, null))->toBe(2.1);
    });

    it('antes de la primera compra, el de la primera', function () use ($compras) {
        expect(PurchaseCost::pick($compras, '2026-08-01'))->toBe(2.0);
    });

    it('nunca tuvo una compra registrada: no tiene costo', function () {
        expect(PurchaseCost::pick([], '2026-10-31'))->toBeNull()
            ->and(PurchaseCost::pick([], null))->toBeNull();
    });

    it('cuentan las compras y lo completado a mano; nunca la ficha del producto', function () {
        expect(PurchaseCost::REFERENCE)->toBe(['purchase', 'manual'])
            ->and(PurchaseCost::OWN)->not->toContain('catalog')->not->toContain(PurchaseCost::DERIVED);
    });
});
