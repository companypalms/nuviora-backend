<?php

use App\Services\Orders\DeliveryCase;

// .173 (dueño, 2026-11-21): el caso de cada pedido para n8n (cómo paga y cómo recibe), el número de guía y la encomienda
// que queda "Ya pagó" sola al registrar su pago completo.

describe('.173: el caso de cada pedido', function () {
    it('los cuatro casos: a domicilio al recibir o pagado, encomienda pagada o por pagar', function () {
        expect([DeliveryCase::of(false, false), DeliveryCase::of(false, true), DeliveryCase::of(true, true), DeliveryCase::of(true, false)])
            ->toBe(['domicilio_al_recibir', 'domicilio_pagado', 'encomienda_pagada', 'encomienda_por_pagar']);
    });

    it('el número de guía se guarda sin espacios de más y en mayúsculas; vacío es null', function () {
        expect(DeliveryCase::normalizeGuide("  ab 12\t 34 "))->toBe('AB 12 34')
            ->and(DeliveryCase::normalizeGuide('   '))->toBeNull()
            ->and(DeliveryCase::normalizeGuide(null))->toBeNull()
            ->and(preg_match(DeliveryCase::GUIDE_PATTERN, '1234'))->toBe(1)
            ->and(preg_match(DeliveryCase::GUIDE_PATTERN, '123'))->toBe(0)
            ->and(preg_match(DeliveryCase::GUIDE_PATTERN, '-1234'))->toBe(0)
            ->and(preg_match(DeliveryCase::GUIDE_PATTERN, 'MRW-0012 3456'))->toBe(1);
    });

    it('«Ya pagó» por los pagos: solo encomiendas; se pone con el total y se quita si deja de cubrirlo; el del formulario no', function () {
        expect(DeliveryCase::prepaidByPayments(true, false, false, 50, 50))->toBeTrue()
            ->and(DeliveryCase::prepaidByPayments(true, false, false, 49.996, 50))->toBeTrue() // los centavos del redondeo
            ->and(DeliveryCase::prepaidByPayments(true, false, false, 49.9, 50))->toBeNull()
            ->and(DeliveryCase::prepaidByPayments(true, true, false, 50, 50))->toBeNull()
            ->and(DeliveryCase::prepaidByPayments(true, true, false, 20, 50))->toBeFalse()
            ->and(DeliveryCase::prepaidByPayments(true, true, true, 0, 50))->toBeNull()
            ->and(DeliveryCase::prepaidByPayments(false, false, false, 50, 50))->toBeNull()
            ->and(DeliveryCase::prepaidByPayments(true, false, false, 0, 0))->toBeNull(); // sin total no hay nada que cubrir
    });
});
