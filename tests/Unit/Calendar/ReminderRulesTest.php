<?php

use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

// Calendario del dueño (Etapa 3, paso 2): los recordatorios que pone el sistema (entrevista del 2026-10-29).
// Noviembre de 2026: lunes 2, jueves 5, domingo 8 (la revisión del mes pasa al lunes 9). Octubre termina el sábado 31.

$on = ReminderRules::normalizeSettings(null);
$dates = fn (string $key, array $settings, string $from, string $to) => array_column(
    Schedule::dates(ReminderRules::pattern($key, $settings), CarbonImmutable::parse($from), CarbonImmutable::parse($to)), 'date');

describe('Cuándo tocan', function () use ($on, $dates) {
    it('la rutina va de lunes a sábado; el domingo no aparece', function () use ($on, $dates) {
        expect($dates('rate', $on, '2026-11-01', '2026-11-08'))
            ->toBe(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);
    });

    it('la semana pasada se revisa los jueves (o el día que elija el dueño)', function () use ($on, $dates) {
        expect($dates('review_week', $on, '2026-11-01', '2026-11-30'))->toBe(['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26']);
        $friday = ReminderRules::normalizeSettings(['review_week' => ['weekday' => 5]]);
        expect($dates('review_week', $friday, '2026-11-01', '2026-11-14'))->toBe(['2026-11-06', '2026-11-13']);
    });

    it('el mes pasado se revisa el día 8; en noviembre de 2026 es domingo y pasa al lunes 9', function () use ($on, $dates) {
        expect($dates('review_month', $on, '2026-10-01', '2026-12-31'))->toBe(['2026-10-08', '2026-11-09', '2026-12-08']);
    });

    it('las metas del mes siguiente, el último día hábil del mes (el sábado 31 de octubre)', function () use ($on, $dates) {
        expect($dates('goals_month', $on, '2026-10-01', '2026-11-30'))->toBe(['2026-10-31', '2026-11-30']);
    });
});

describe('Qué dicen', function () {
    it('los últimos 3 días: el lunes, de viernes a domingo, con el panel en esas fechas', function () {
        $o = ReminderRules::occurrence('review_3days', '2026-11-02', '2026-11-02', []);
        expect($o['details'])->toBe('Del viernes 30/10 al domingo 1/11.');
        expect($o['link'])->toBe('/panel-dueno?desde=2026-10-30&hasta=2026-11-01');
        expect($o['actions'])->toBe([['label' => 'Abrir panel', 'link' => '/panel-dueno?desde=2026-10-30&hasta=2026-11-01']]);
        expect($o['auto_done'])->toBeFalse();
    });

    it('la semana pasada: el jueves 5 de noviembre mira del lunes 26/10 al domingo 1/11', function () {
        $o = ReminderRules::occurrence('review_week', '2026-11-05', '2026-11-05', []);
        expect($o['details'])->toBe('Del lunes 26/10 al domingo 1/11: cuánto se ganó, cuánto se perdió y por producto.');
        expect($o['link'])->toBe('/panel-dueno?desde=2026-10-26&hasta=2026-11-01');
    });

    it('el mes pasado: octubre completo (aunque se mire el lunes 9)', function () {
        $o = ReminderRules::occurrence('review_month', '2026-11-08', '2026-11-09', []);
        expect($o['title'])->toBe('Revisar el mes pasado (octubre)');
        expect($o['details'])->toBe('Del 1/10 al 31/10: el mes completo.');
        expect($o['link'])->toBe('/panel-dueno?desde=2026-10-01&hasta=2026-10-31');
    });

    it('las metas: se tilda sola cuando están puestas las del mes siguiente', function () {
        $o = ReminderRules::occurrence('goals_month', '2026-10-31', '2026-10-29', ['goal_months' => ['2026-10']]);
        expect($o['title'])->toBe('Poner las metas de noviembre');
        expect($o['auto_done'])->toBeFalse();
        $done = ReminderRules::occurrence('goals_month', '2026-10-31', '2026-10-29', ['goal_months' => ['2026-10', '2026-11']]);
        expect($done['auto_done'])->toBeTrue();
        expect($done['auto_note'])->toBe('Ya pusiste las metas de noviembre.');
    });

    it('la tasa: muestra las de hoy y se tilda sola si se guardó una ese día', function () {
        $facts = ['rate_days' => ['2026-10-29' => '9:12'], 'rates' => ['bcv_usd' => 36.5, 'bcv_eur' => 39.8, 'binance_usd' => 40.1, 'updated_at' => '2026-10-29 09:12:00']];
        $o = ReminderRules::occurrence('rate', '2026-10-29', '2026-10-29', $facts);
        expect($o['auto_done'])->toBeTrue();
        expect($o['auto_note'])->toBe('Se guardó una tasa ese día, a las 9:12.');
        expect($o['details'])->toBe('BCV: $ 36,50 y € 39,80 · Binance: $ 40,10. Guardadas el 29/10 a las 9:12.');
        expect(array_column($o['actions'], 'label'))->toBe(['Están bien', 'Cambiar']);
        expect(ReminderRules::occurrence('rate', '2026-10-30', '2026-10-29', $facts)['auto_done'])->toBeFalse();
    });

    it('la conciliación: los días que faltan y por qué; sin nada pendiente, se tilda sola', function () {
        $pending = [['date' => '2026-10-26', 'missing_statements' => 1, 'incidents' => 0], ['date' => '2026-10-27', 'missing_statements' => 0, 'incidents' => 3]];
        $o = ReminderRules::occurrence('reconciliation', '2026-10-29', '2026-10-29', ['reconciliation' => $pending]);
        expect($o['details'])->toBe('Faltan 2 días: lunes 26/10 (falta 1 extracto), martes 27/10 (3 pagos por revisar).');
        expect($o['auto_done'])->toBeFalse();
        expect(ReminderRules::occurrence('reconciliation', '2026-10-29', '2026-10-29', ['reconciliation' => []])['auto_done'])->toBeTrue();
        // Otro día no se sabe: se muestra lo general y no se tilda sola
        expect(ReminderRules::occurrence('reconciliation', '2026-10-30', '2026-10-29', ['reconciliation' => []])['auto_done'])->toBeFalse();
    });

    it('por aprobar y el inventario, en palabras', function () {
        expect(ReminderRules::approvalsText(['receipts' => 1, 'to_validate' => 0, 'to_pay' => 2]))->toBe('1 comprobante por revisar y 2 vueltos por pagar.');
        expect(ReminderRules::approvalsText(['receipts' => 3, 'to_validate' => 1, 'to_pay' => 1]))->toBe('3 comprobantes por revisar, 1 vuelto por validar y 1 vuelto por pagar.');
        expect(ReminderRules::approvalsText(['receipts' => 0, 'to_validate' => 0, 'to_pay' => 0]))->toBeNull();
        expect(ReminderRules::inventoryText(['red' => 2, 'orange' => 1]))->toBe('2 productos en rojo y 1 en naranja.');
        expect(ReminderRules::inventoryText(['red' => 0, 'orange' => 1]))->toBe('1 producto en naranja.');
        expect(ReminderRules::inventoryText(['red' => 0, 'orange' => 0]))->toBeNull();
        $o = ReminderRules::occurrence('approvals', '2026-10-29', '2026-10-29', ['approvals' => ['receipts' => 0, 'to_validate' => 0, 'to_pay' => 0]]);
        expect($o['auto_done'])->toBeTrue();
    });
});

describe('Ajustes', function () {
    it('por defecto todos encendidos y la revisión semanal el jueves', function () {
        $s = ReminderRules::normalizeSettings(null);
        expect(array_keys($s))->toBe(array_keys(ReminderRules::RULES));
        expect(array_unique(array_column($s, 'on')))->toBe([true]);
        expect($s['review_week']['weekday'])->toBe(4);
        expect(ReminderRules::normalizeSettings(['review_week' => ['weekday' => 7], 'inventory' => ['on' => false]]))
            ->toMatchArray(['review_week' => ['on' => true, 'weekday' => 4], 'inventory' => ['on' => false]]);
    });

    it('avisa si el día no es de lunes a sábado o el recordatorio no existe', function () {
        expect(ReminderRules::settingsProblems(['review_week' => ['weekday' => 7]]))->toBe(['rules.review_week.weekday' => 'Elige un día de lunes a sábado.']);
        expect(ReminderRules::settingsProblems(['otro' => ['on' => false]]))->toBe(['rules.otro' => 'Ese recordatorio no existe.']);
        expect(ReminderRules::settingsProblems(['rate' => ['on' => false], 'review_week' => ['weekday' => 5]]))->toBe([]);
    });
});
