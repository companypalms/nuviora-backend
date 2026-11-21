<?php

use App\Services\Orders\DeliveryCase;

// .173 (dueño, 2026-11-21): el caso de cada pedido para n8n (cómo paga y cómo recibe), la foto de la guía y la encomienda
// que queda "Ya pagó" sola al registrar su pago completo.

describe('.173: el caso de cada pedido', function () {
    it('los cuatro casos: a domicilio al recibir o pagado, encomienda pagada o por pagar', function () {
        expect([DeliveryCase::of(false, false), DeliveryCase::of(false, true), DeliveryCase::of(true, true), DeliveryCase::of(true, false)])
            ->toBe(['domicilio_al_recibir', 'domicilio_pagado', 'encomienda_pagada', 'encomienda_por_pagar']);
    });

    it('una encomienda sin la foto de su guía no se entrega; a domicilio no la pide', function () {
        expect(DeliveryCase::deliveryBlock(true, null))->toBe(DeliveryCase::GUIDE_MISSING)
            ->and(DeliveryCase::deliveryBlock(true, ''))->toBe(DeliveryCase::GUIDE_MISSING)
            ->and(DeliveryCase::deliveryBlock(true, 'courier_guides/guia.jpg'))->toBeNull()
            ->and(DeliveryCase::deliveryBlock(false, null))->toBeNull();
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
