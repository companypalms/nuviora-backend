<?php

use App\Services\Orders\Cancellations;

// Dueño (2026-10-29): al cancelar se elige un motivo de la lista, además de escribirlo.

describe('Motivo de la lista al cancelar', function () {
    it('acepta solo los motivos de la lista', function () {
        expect(Cancellations::category('no_contesta'))->toBe('no_contesta');
        expect(Cancellations::category('otro'))->toBe('otro');
        expect(Cancellations::category('sistema'))->toBeNull(); // ese lo pone el cierre de la jornada, no una persona
        expect(Cancellations::category('cualquier cosa'))->toBeNull();
        expect(Cancellations::category(null))->toBeNull();
    });

    it('tiene los 8 motivos acordados con el dueño', function () {
        expect(array_keys(Cancellations::CATEGORIES))->toBe(['no_contesta', 'no_lo_quiere', 'dinero', 'duplicado', 'zona', 'sin_stock', 'demora', 'otro']);
    });
});
