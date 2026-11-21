<?php

use App\Services\Orders\CourierZone;

// .174 (dueño, 2026-11-21): todas las encomiendas cuentan en la zona "Resto de Venezuela", con lo que cobra MRW o Zoom; su
// stock es el de la ciudad de donde salen.

describe('.174: la zona «Resto de Venezuela»', function () {
    it('toda encomienda cuenta en la zona; los demás pedidos, en su ciudad; sin la zona creada, como siempre', function () {
        expect(CourierZone::financeCity(true, 1, 9))->toBe(9)
            ->and(CourierZone::financeCity(true, null, 9))->toBe(9)
            ->and(CourierZone::financeCity(false, 1, 9))->toBe(1)
            ->and(CourierZone::financeCity(true, 1, null))->toBe(1);
    });

    it('lo que cobra MRW o Zoom: solo en la encomienda despachada; sin el promedio puesto, 0', function () {
        expect(CourierZone::fee(true, true, 4.5))->toBe(4.5)
            ->and(CourierZone::fee(true, false, 4.5))->toBe(0.0)
            ->and(CourierZone::fee(false, true, 4.5))->toBe(0.0)
            ->and(CourierZone::fee(true, true, null))->toBe(0.0);
    });

    it('el stock de la zona es el de la ciudad de donde salen las encomiendas', function () {
        $byCity = [1 => [10, 11], 2 => [12]];

        expect(CourierZone::withWarehouses($byCity, 9, 2))->toBe([1 => [10, 11], 2 => [12], 9 => [12]])
            ->and(CourierZone::withWarehouses($byCity, 9, null))->toBe([1 => [10, 11], 2 => [12], 9 => []])
            ->and(CourierZone::withWarehouses($byCity, null, 2))->toBe($byCity);
    });
});
