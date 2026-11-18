<?php

use App\Services\Editors\CommissionRules;

// .153 (dueño, 2026-11-17): la comisión del líder de editores. "de la ganancia total descuento un 10 %… y del resultado le
// pago 1 %… cada 15 y último"; "el 15 le paso del 1 al 15, y el 30 le pago del 15 al 30".

describe('Las quincenas', function () {
    it('del 1 al 15 se paga el 15, y del 16 al último, el último día', function () {
        expect(CommissionRules::quincena('2026-11-03'))->toBe(['start' => '2026-11-01', 'end' => '2026-11-15', 'payday' => '2026-11-14'])
            ->and(CommissionRules::quincena('2026-11-15')['start'])->toBe('2026-11-01')
            ->and(CommissionRules::quincena('2026-11-16'))->toBe(['start' => '2026-11-16', 'end' => '2026-11-30', 'payday' => '2026-11-30'])
            // un mes de 31 días: el 31
            ->and(CommissionRules::quincena('2026-12-20'))->toBe(['start' => '2026-12-16', 'end' => '2026-12-31', 'payday' => '2026-12-31'])
            ->and(CommissionRules::quincena('2026-12-01')['payday'])->toBe('2026-12-15');
    });

    it('en febrero, el 28 (o el 29 en un año bisiesto)', function () {
        expect(CommissionRules::quincena('2027-02-20')['end'])->toBe('2027-02-28')
            ->and(CommissionRules::quincena('2028-02-20')['end'])->toBe('2028-02-29');
    });

    it('un día de pago en domingo se adelanta al sábado, como todos los pagos', function () {
        // el 15/11/2026 cae domingo
        expect(CommissionRules::quincena('2026-11-10')['payday'])->toBe('2026-11-14')
            // el 31/01/2027 cae domingo
            ->and(CommissionRules::quincena('2027-01-20')['payday'])->toBe('2027-01-30');
    });

    it('la siguiente y la anterior', function () {
        $q = CommissionRules::quincena('2026-11-20');
        expect(CommissionRules::next($q)['start'])->toBe('2026-12-01')
            ->and(CommissionRules::previous($q)['start'])->toBe('2026-11-01')
            ->and(CommissionRules::next(CommissionRules::quincena('2026-12-31'))['start'])->toBe('2027-01-01');
    });
});

describe('La cuenta', function () {
    it('la ganancia − 10 %, × 1 %', function () {
        expect(CommissionRules::own(3000, 10, 1))->toBe(27.0)
            ->and(CommissionRules::own(1234.56, 10, 1))->toBe(11.11);
    });

    it('una quincena que pierde es $0 (no queda deuda)', function () {
        expect(CommissionRules::own(-500, 10, 1))->toBe(0.0)
            ->and(CommissionRules::amount(0.0, -3.0))->toBe(0.0)
            ->and(CommissionRules::amount(10.0, -3.0))->toBe(7.0)
            // lo que creció la anterior se suma
            ->and(CommissionRules::amount(10.0, 2.5))->toBe(12.5);
    });

    it('los ajustes guardados quedan en su rango; "desde" es el 14/11 (.157: el dueño pagó a mano hasta el 13)', function () {
        expect(CommissionRules::settings(null))->toBe(['reserve_pct' => 10.0, 'pct' => 1.0, 'since' => '2026-11-14'])
            ->and(CommissionRules::settings(['reserve_pct' => 150, 'pct' => -2, 'since' => '2026-12-20']))
            ->toBe(['reserve_pct' => 90.0, 'pct' => 0.0, 'since' => '2026-12-20']);
    });

    it('.157: el primer pago cubre del 14 al 30/11 (el 14 y el 15 se suman a la quincena siguiente)', function () {
        expect(CommissionRules::first('2026-11-14'))->toBe(['start' => '2026-11-14', 'end' => '2026-11-30', 'payday' => '2026-11-30'])
            // si empieza el primer día de una quincena, es esa quincena
            ->and(CommissionRules::first('2026-11-16'))->toBe(['start' => '2026-11-16', 'end' => '2026-11-30', 'payday' => '2026-11-30'])
            ->and(CommissionRules::first('2026-12-01'))->toBe(['start' => '2026-12-01', 'end' => '2026-12-15', 'payday' => '2026-12-15']);
    });

    it('.157: qué se paga en cada quincena: nada el 15/11, del 14 al 30 el 30/11 y desde diciembre, normal', function () {
        expect(CommissionRules::period('2026-11-15', '2026-11-14'))->toBeNull()
            ->and(CommissionRules::period('2026-10-31', '2026-11-14'))->toBeNull()
            ->and(CommissionRules::period('2026-11-30', '2026-11-14'))->toBe(['start' => '2026-11-14', 'end' => '2026-11-30', 'payday' => '2026-11-30'])
            ->and(CommissionRules::period('2026-12-15', '2026-11-14'))->toBe(['start' => '2026-12-01', 'end' => '2026-12-15', 'payday' => '2026-12-15'])
            ->and(CommissionRules::period('2026-12-31', '2026-11-14'))->toBe(['start' => '2026-12-16', 'end' => '2026-12-31', 'payday' => '2026-12-31']);
    });
});

describe('La barra y la línea verde', function () {
    $q = ['start' => '2026-11-16', 'end' => '2026-11-30', 'payday' => '2026-11-30'];

    it('se llena contra lo que cobró la quincena anterior y está "pasada" al superarla', function () use ($q) {
        expect(CommissionRules::bar(5.0, 10.0, $q, '2026-11-20'))->toBe(['fill' => 0.5, 'beat' => false, 'target' => 'previous', 'days_left' => 10])
            ->and(CommissionRules::bar(12.0, 10.0, $q, '2026-11-20'))->toMatchArray(['fill' => 1.0, 'beat' => true]);
    });

    it('sin quincena anterior, se llena con los días que ya pasaron', function () use ($q) {
        expect(CommissionRules::bar(5.0, null, $q, '2026-11-16'))->toMatchArray(['target' => 'days', 'beat' => false, 'days_left' => 14])
            ->and(CommissionRules::bar(5.0, 0.0, $q, '2026-11-30'))->toMatchArray(['fill' => 1.0, 'days_left' => 0]);
    });

    it('hoy cobra; ya cobró (unos días); o ya pasó la anterior', function () {
        $last = ['amount' => 12.4, 'payday' => '2026-11-30', 'paid' => false];
        expect(CommissionRules::notice($last, false, '2026-11-30'))->toBe(['tone' => 'green', 'text' => 'Hoy cobras $12,40'])
            ->and(CommissionRules::notice(['paid' => true] + $last, false, '2026-11-30'))->toBe(['tone' => 'green', 'text' => '¡Cobrada! $12,40'])
            ->and(CommissionRules::notice(['paid' => true] + $last, false, '2026-12-03')['text'])->toBe('¡Cobrada! $12,40')
            ->and(CommissionRules::notice(['paid' => true] + $last, false, '2026-12-04'))->toBeNull()
            ->and(CommissionRules::notice($last, true, '2026-12-05'))->toBe(['tone' => 'green', 'text' => '¡Ya pasaste la quincena anterior!'])
            ->and(CommissionRules::notice(null, false, '2026-11-20'))->toBeNull();
    });

    it('los textos: "lun 30/11" y "Del 16/11 al 30/11"', function () use ($q) {
        expect(CommissionRules::dayLabel('2026-11-30'))->toBe('lun 30/11')
            ->and(CommissionRules::dayLabel('2026-11-14'))->toBe('sáb 14/11')
            ->and(CommissionRules::periodLabel($q))->toBe('Del 16/11 al 30/11');
    });
});
