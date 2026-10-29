<?php

use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

// Calendario del dueño (Etapa 3): en qué días aparece cada cosa y la regla del domingo (entrevista del 2026-10-29).
// Noviembre de 2026: el domingo 1, el domingo 8, el domingo 15; el lunes 30. Febrero de 2027 termina en domingo (28).

$d = fn (string $ymd) => CarbonImmutable::parse($ymd);
$dates = fn (array $pattern, string $from, string $to) => array_map(
    fn ($x) => $x['date'] . ($x['moved'] ? ' (' . $x['due'] . ')' : ''),
    Schedule::dates($pattern, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
);

describe('Fechas del calendario', function () use ($dates, $d) {
    it('una nota de un solo día queda en su día, aunque sea domingo', function () use ($dates) {
        $p = ['repeat' => 'none', 'start' => '2026-11-08', 'type' => 'payment'];
        expect($dates($p, '2026-11-01', '2026-11-30'))->toBe(['2026-11-08']);
        expect($dates($p, '2026-11-09', '2026-11-30'))->toBe([]);
    });

    it('cada semana: el mismo día de la semana que la fecha de inicio, y nunca antes de esa fecha', function () use ($dates) {
        $p = ['repeat' => 'weekly', 'start' => '2026-10-29', 'type' => 'task'];   // jueves
        expect($dates($p, '2026-10-26', '2026-11-15'))->toBe(['2026-10-29', '2026-11-05', '2026-11-12']);
        expect($dates($p + ['until' => '2026-11-05'], '2026-10-26', '2026-11-30'))->toBe(['2026-10-29', '2026-11-05']);
    });

    it('si se repite y cae domingo: el pago se adelanta al sábado y la tarea pasa al lunes', function () use ($dates) {
        $payment = ['repeat' => 'weekly', 'start' => '2026-11-01', 'type' => 'payment'];   // domingos
        expect($dates($payment, '2026-10-26', '2026-11-08'))->toBe(['2026-10-31 (2026-11-01)', '2026-11-07 (2026-11-08)']);
        $task = ['repeat' => 'weekly', 'start' => '2026-11-01', 'type' => 'task'];
        expect($dates($task, '2026-11-01', '2026-11-10'))->toBe(['2026-11-02 (2026-11-01)', '2026-11-09 (2026-11-08)']);
    });

    it('cada mes el día 8: en noviembre de 2026 cae domingo y la revisión pasa al lunes 9', function () use ($dates) {
        $p = ['repeat' => 'monthly', 'start' => '2026-10-08', 'type' => 'review'];
        expect($dates($p, '2026-10-01', '2026-12-31'))->toBe(['2026-10-08', '2026-11-09 (2026-11-08)', '2026-12-08']);
        // Se ve aunque el rango empiece justo el lunes, y no el domingo
        expect($dates($p, '2026-11-09', '2026-11-09'))->toBe(['2026-11-09 (2026-11-08)']);
        expect($dates($p, '2026-11-08', '2026-11-08'))->toBe([]);
    });

    it('cada mes el día 31: en los meses cortos es el último día (y si es domingo, el pago va el sábado)', function () use ($dates) {
        $p = ['repeat' => 'monthly', 'start' => '2026-10-31', 'type' => 'payment'];
        expect($dates($p, '2026-10-01', '2027-03-31'))->toBe([
            '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-30 (2027-01-31)', '2027-02-27 (2027-02-28)', '2027-03-31',
        ]);
    });

    it('el 15 y el último: el 15 de noviembre de 2026 cae domingo y el pago se adelanta al sábado 14', function () use ($dates) {
        $pay = ['repeat' => 'twice_monthly', 'start' => '2026-11-01', 'type' => 'payment'];
        expect($dates($pay, '2026-11-01', '2026-12-31'))->toBe(['2026-11-14 (2026-11-15)', '2026-11-30', '2026-12-15', '2026-12-31']);
        $task = ['repeat' => 'twice_monthly', 'start' => '2026-11-20', 'type' => 'task'];   // empieza después del 15
        expect($dates($task, '2026-11-01', '2026-12-31'))->toBe(['2026-11-30', '2026-12-15', '2026-12-31']);
    });

    it('todos los días: de lunes a sábado, sin domingo y sin correrse', function () use ($dates) {
        $p = ['repeat' => 'daily', 'start' => '2026-10-01', 'type' => 'task'];
        expect($dates($p, '2026-11-01', '2026-11-08'))->toBe(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);
    });

    it('el último día hábil del mes: si el último es domingo, el sábado de antes', function () use ($dates) {
        $p = ['repeat' => 'last_workday', 'start' => '2026-10-01', 'type' => 'task'];
        expect($dates($p, '2026-10-01', '2027-02-28'))->toBe(['2026-10-31', '2026-11-30', '2026-12-31', '2027-01-30', '2027-02-27']);
    });

    it('solo se tilda un día que le toca (con el día de verdad, no el corrido)', function () {
        $p = ['repeat' => 'monthly', 'start' => '2026-10-08', 'type' => 'review'];
        expect(Schedule::isDue($p, '2026-11-08'))->toBeTrue();
        expect(Schedule::isDue($p, '2026-11-09'))->toBeFalse();
        expect(Schedule::isDue($p, '2026-09-08'))->toBeFalse();   // antes de que existiera
        expect(Schedule::isDue(['repeat' => 'none', 'start' => '2026-11-03', 'type' => 'task'], '2026-11-03'))->toBeTrue();
    });
});

describe('Estado y textos', function () {
    it('hecho o descartado si se marcó; si no, pendiente, y si su día pasó, atrasado (o "pasó" si no se acumula)', function () {
        expect(Schedule::status('done', '2026-10-20', '2026-10-29', true))->toBe('done');
        expect(Schedule::status('dismissed', '2026-11-02', '2026-10-29', true))->toBe('dismissed');
        expect(Schedule::status(null, '2026-10-29', '2026-10-29', true))->toBe('pending');
        expect(Schedule::status(null, '2026-11-02', '2026-10-29', true))->toBe('pending');
        expect(Schedule::status(null, '2026-10-28', '2026-10-29', true))->toBe('overdue');
        expect(Schedule::status(null, '2026-10-28', '2026-10-29', false))->toBe('missed');
    });

    it('la frase dice si se corrió por un domingo y cómo está', function () {
        expect(Schedule::sentence('Pago: Editores.', '2026-11-14', '2026-11-15', 'overdue'))
            ->toBe('Pago: Editores. El 15/11 cae domingo: se adelantó al sábado. Atrasado: era del sábado 14/11.');
        expect(Schedule::sentence('Revisión: El mes pasado.', '2026-11-09', '2026-11-08', 'pending'))
            ->toBe('Revisión: El mes pasado. El 8/11 cae domingo: pasó al lunes.');
        expect(Schedule::sentence('Tarea: Llamar al banco.', '2026-10-29', '2026-10-29', 'done'))->toBe('Tarea: Llamar al banco. Hecho.');
    });

    it('cómo se repite, en palabras', function () {
        expect(Schedule::label(['repeat' => 'none', 'start' => '2026-11-03']))->toBe('Solo el 03/11/2026');
        expect(Schedule::label(['repeat' => 'weekly', 'start' => '2026-10-29']))->toBe('Cada semana, los jueves');
        expect(Schedule::label(['repeat' => 'weekly', 'start' => '2026-10-31']))->toBe('Cada semana, los sábados');
        expect(Schedule::label(['repeat' => 'monthly', 'start' => '2026-10-31', 'until' => '2027-03-31']))->toBe('Cada mes, el último día, hasta el 31/03/2027');
        expect(Schedule::label(['repeat' => 'monthly', 'start' => '2026-10-05']))->toBe('Cada mes, el día 5');
        expect(Schedule::label(['repeat' => 'twice_monthly', 'start' => '2026-11-01']))->toBe('El 15 y el último de cada mes');
    });
});
