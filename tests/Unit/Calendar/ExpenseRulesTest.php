<?php

use App\Services\Calendar\Rules\ExpenseRules;
use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

// Calendario del dueño (Etapa 3, paso 3): los gastos fijos de Finanzas (Gastos recurrentes) en el calendario.

$due = fn (array $over = []) => $over + ['due_date' => '2026-11-05', 'amount' => 39.0, 'currency' => 'USD', 'amount_usd' => 39.0, 'paid' => false];

describe('Gastos fijos en el calendario', function () use ($due) {
    it('un vencimiento sin pagar: cuánto es y "Registrar pago"', function () use ($due) {
        $o = ExpenseRules::occurrence(2, 'Shopify', 'Software y herramientas', $due(), '2026-10-29');
        expect($o['title'])->toBe('Pagar Shopify');
        expect($o['details'])->toBe('$39,00 · Software y herramientas.');
        expect($o['actions'])->toBe([['label' => 'Registrar pago', 'pay' => 2], ['label' => 'Ver en Finanzas', 'link' => '/finanzas?tab=recurring']]);
        expect($o['auto_done'])->toBeFalse();
    });

    it('pagado: se tilda solo y ya no ofrece pagar', function () use ($due) {
        $o = ExpenseRules::occurrence(2, 'Shopify', null, $due(['paid' => true]), '2026-10-29');
        expect($o['auto_done'])->toBeTrue();
        expect($o['auto_note'])->toBe('El pago está registrado en la caja.');
        expect(array_column($o['actions'], 'label'))->toBe(['Ver en Finanzas']);
    });

    it('"Registrar pago" solo hasta 30 días antes (lo que muestra la ventana de Finanzas)', function () use ($due) {
        expect(array_column(ExpenseRules::occurrence(2, 'Hostinger', null, $due(['due_date' => '2026-12-15']), '2026-10-29')['actions'], 'label'))
            ->toBe(['Ver en Finanzas']);
    });

    it('en bolívares o USDT, con su moneda', function () use ($due) {
        expect(ExpenseRules::occurrence(3, 'Internet', null, $due(['amount' => 1234.5, 'currency' => 'VES', 'amount_usd' => 3.4]), '2026-10-29')['details'])
            ->toBe('Bs 1.234,50 (≈ $3,40).');
        expect(ExpenseRules::amount(25, 'USDT'))->toBe('25,00 USDT');
    });

    it('si vence un domingo, se muestra el sábado antes (como todos los pagos)', function () {
        expect(Schedule::shift(CarbonImmutable::parse('2027-02-28'), 'monthly', 'payment')->toDateString())->toBe('2027-02-27');
        expect(Schedule::shift(CarbonImmutable::parse('2026-11-06'), 'weekly', 'payment')->toDateString())->toBe('2026-11-06');
    });
});
