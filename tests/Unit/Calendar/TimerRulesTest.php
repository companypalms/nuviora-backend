<?php

use App\Services\Calendar\Rules\DayPlan;
use App\Services\Calendar\Rules\TimerRules;
use Carbon\CarbonImmutable;

// `.108` "Tu día" (dueño, 2026-11-06: "cuánto me debe tomar cada tarea y con notificaciones de que se agotó el tiempo"):
// las cuentas del reloj, de lo que sueles tardar y de la racha.

$at = fn (string $hm) => CarbonImmutable::parse("2026-11-06 {$hm}:00");

describe('El reloj', function () use ($at) {
    it('cuenta lo que ya corrió más la vuelta de ahora; en pausa no corre', function () use ($at) {
        expect(TimerRules::elapsed(0, $at('09:00'), $at('09:04')))->toBe(240);
        expect(TimerRules::elapsed(120, $at('09:00'), $at('09:01')))->toBe(180);
        expect(TimerRules::elapsed(120, null, $at('09:30')))->toBe(120);
        expect(TimerRules::elapsed(0, $at('09:05'), $at('09:00')))->toBe(0);
    });

    it('se acaba cuando corrió sus minutos más los "+5"; en pausa no tiene hora', function () use ($at) {
        expect(TimerRules::endsAt(5, 0, 0, $at('09:00'))->format('H:i'))->toBe('09:05');
        expect(TimerRules::endsAt(5, 5, 120, $at('09:00'))->format('H:i'))->toBe('09:08');
        expect(TimerRules::endsAt(5, 0, 120, null))->toBeNull();
        expect(TimerRules::limit(15, 10))->toBe(1500);
    });

    it('a tiempo es dentro de los minutos que tenía al empezar (los "+5" no cuentan)', function () {
        expect(TimerRules::onTime(300, 5))->toBeTrue();
        expect(TimerRules::onTime(301, 5))->toBeFalse();
    });

    it('sueles tardar: desde la 3.ª vez, el promedio de las últimas 5', function () {
        expect(TimerRules::usual([600, 720]))->toBeNull();
        expect(TimerRules::usual([600, 720, 840]))->toBe(12);
        expect(TimerRules::usual([60, 60, 60, 60, 60, 6000]))->toBe(1);
        expect(TimerRules::usual([10, 10, 10]))->toBe(1);
    });
});

describe('La racha', function () {
    it('cuenta los días de trabajo seguidos con todo hecho; el domingo no corta', function () {
        // viernes 6, jueves 5, miércoles 4 hechos; el domingo 1 sin fila; sábado 31 hecho; el lunes 2 y martes 3 hechos
        $days = ['2026-10-31' => true, '2026-11-02' => true, '2026-11-03' => true, '2026-11-04' => true, '2026-11-05' => true, '2026-11-06' => true];
        expect(TimerRules::streak($days, '2026-11-06'))->toBe(6);
    });

    it('si hoy todavía no está todo hecho, no corta: cuenta hasta ayer', function () {
        expect(TimerRules::streak(['2026-11-05' => true, '2026-11-06' => false], '2026-11-06'))->toBe(1);
        expect(TimerRules::streak(['2026-11-05' => true], '2026-11-06'))->toBe(1);
    });

    it('un día de trabajo sin terminar (o sin abrir el calendario) la corta', function () {
        expect(TimerRules::streak(['2026-11-03' => true, '2026-11-05' => true, '2026-11-06' => true], '2026-11-06'))->toBe(2);
        expect(TimerRules::streak(['2026-11-04' => true, '2026-11-05' => false, '2026-11-06' => true], '2026-11-06'))->toBe(1);
        expect(TimerRules::streak([], '2026-11-06'))->toBe(0);
    });

    it('la mejor racha es la más larga que hubo', function () {
        $days = ['2026-10-26' => true, '2026-10-27' => true, '2026-10-28' => false, '2026-10-29' => true, '2026-10-30' => true, '2026-10-31' => true, '2026-11-02' => true];
        expect(TimerRules::best($days))->toBe(4);
        expect(TimerRules::best([]))->toBe(0);
    });
});

describe('Más tarde', function () {
    it('lo que se dejó para después va al final de su etapa, en el orden en que se dejó', function () {
        $item = fn (string $key, array $over = []) => $over + ['item_key' => $key, 'due_date' => '2026-11-06', 'date' => '2026-11-06', 'time' => null,
            'priority' => 'normal', 'minutes' => 15, 'rank' => null];
        $plan = DayPlan::order([
            $item('a', ['minutes' => 5, 'later' => '2026-11-06T09:30:00-04:00']),
            $item('b', ['later' => '2026-11-06T09:10:00-04:00']),
            $item('c', ['minutes' => 30]),
        ], [], '09:00');

        expect(array_column($plan[0]['items'], 'item_key'))->toBe(['c', 'b', 'a']);
        expect($plan[0]['items'][2]['reason'])->toBe('La dejaste para después.');
    });
});
