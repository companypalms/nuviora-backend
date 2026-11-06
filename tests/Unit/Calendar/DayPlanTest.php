<?php

use App\Services\Calendar\Rules\DayPlan;
use App\Services\Calendar\Rules\ReminderRules;

// `.107` "Tu día" (dueño, 2026-11-06: "por orden de prioridad, urgencia y tiempo que toman"): la prioridad y los minutos de
// cada cosa, y el orden del día por etapas.

$item = fn (string $key, array $over = []) => $over + [
    'item_key' => $key, 'due_date' => '2026-11-06', 'date' => '2026-11-06', 'title' => $key, 'time' => null,
    'priority' => 'normal', 'minutes' => 15, 'rank' => null,
];
$keys = fn (array $stage) => array_column($stage['items'], 'item_key');

describe('Prioridad y minutos de cada cosa', function () {
    it('cada recordatorio del sistema trae lo que propuso Claude, y nada queda sin proponer', function () {
        expect(array_keys(DayPlan::RULE_DEFAULTS))->toEqualCanonicalizing(array_keys(ReminderRules::RULES));
        expect(DayPlan::defaults('rule:rate'))->toBe(['priority' => 'alta', 'minutes' => 5]);
        expect(DayPlan::defaults('rule:learnings_week'))->toBe(['priority' => 'baja', 'minutes' => 15]);
    });

    it('las notas empiezan en normal y 15 minutos; los gastos fijos en alta y 5, o lo que el dueño puso para todos', function () {
        expect(DayPlan::defaults('note:7'))->toBe(['priority' => 'normal', 'minutes' => 15]);
        expect(DayPlan::defaults('expense:3'))->toBe(['priority' => 'alta', 'minutes' => 5]);
        expect(DayPlan::defaults('expense:3', ['priority' => 'normal', 'minutes' => 10]))->toBe(['priority' => 'normal', 'minutes' => 10]);
    });

    it('lo que guardó el dueño gana; lo que está mal guardado vuelve a lo de siempre', function () {
        expect(DayPlan::resolve('rule:rate', ['priority' => 'baja', 'minutes' => 30, 'rank' => 2]))->toBe(['priority' => 'baja', 'minutes' => 30, 'rank' => 2]);
        expect(DayPlan::resolve('rule:rate', ['priority' => 'otra', 'minutes' => 0, 'rank' => null]))->toBe(['priority' => 'alta', 'minutes' => 5, 'rank' => null]);
        expect(DayPlan::resolve('rule:rate', null))->toBe(['priority' => 'alta', 'minutes' => 5, 'rank' => null]);
    });

    it('avisa en palabras simples si la prioridad o los minutos están mal', function () {
        expect(DayPlan::problems('alta', 30))->toBe([]);
        expect(DayPlan::problems(null, null))->toBe([]);
        expect(DayPlan::problems('urgente', null))->toBe(['priority' => 'Elige la prioridad: alta, normal o baja.']);
        expect(DayPlan::problems(null, 0))->toBe(['minutes' => 'Los minutos van de 1 a 240.']);
        expect(DayPlan::problems(null, 241))->toHaveKey('minutes');
        expect(DayPlan::problems(null, 'diez'))->toHaveKey('minutes');
        expect(DayPlan::problems(null, 7.5, 'items.0.'))->toHaveKey('items.0.minutes');
    });

    it('dice el tiempo como se lee', function () {
        expect(DayPlan::minutesLabel(45))->toBe('~45 min');
        expect(DayPlan::minutesLabel(60))->toBe('~1 h');
        expect(DayPlan::minutesLabel(85))->toBe('~1 h 25 min');
    });
});

describe('El orden del día', function () use ($item, $keys) {
    it('va por etapas: urgente, alta, normal, baja y a su hora; las que no tienen nada no salen', function () use ($item, $keys) {
        $plan = DayPlan::order([
            $item('rule:inventory', ['minutes' => 10]),
            $item('rule:rate', ['priority' => 'alta', 'minutes' => 5]),
            $item('note:1', ['time' => '15:00']),
            $item('note:2', ['priority' => 'baja']),
        ], [$item('note:9', ['date' => '2026-11-04', 'due_date' => '2026-11-04'])], '09:00');

        expect(array_column($plan, 'key'))->toBe(['urgent', 'alta', 'normal', 'baja', 'later']);
        expect(array_column($plan, 'label'))->toBe(['Urgente', 'Alta', 'Normal', 'Baja', 'A su hora']);
        expect($plan[0]['items'][0])->toBe(['item_key' => 'note:9', 'due_date' => '2026-11-04', 'reason' => 'Atrasada: era del 4/11.']);
        expect($plan[4]['items'][0]['reason'])->toBe('A las 15:00 (hora de Venezuela).');
        expect(DayPlan::order([$item('rule:rate', ['priority' => 'alta'])], [], '09:00'))->toHaveCount(1);
        expect(DayPlan::order([], [], '09:00'))->toBe([]);
    });

    it('lo que tiene hora pasa a urgente 15 minutos antes, y dice si ya era la hora', function () use ($item, $keys) {
        $plan = DayPlan::order([
            $item('note:1', ['time' => '09:10']),
            $item('note:2', ['time' => '08:30']),
            $item('note:3', ['time' => '09:16']),
        ], [], '09:00');

        expect($plan[0]['key'])->toBe('urgent');
        expect($keys($plan[0]))->toBe(['note:2', 'note:1']);
        expect(array_column($plan[0]['items'], 'reason'))->toBe(['Era a las 08:30.', 'Es a las 09:10.']);
        expect($plan[1]['key'])->toBe('later');
        expect($keys($plan[1]))->toBe(['note:3']);
    });

    it('lo atrasado va primero, de lo más viejo a lo más nuevo, antes de lo que ya es su hora', function () use ($item, $keys) {
        $plan = DayPlan::order([$item('note:1', ['time' => '08:00'])], [
            $item('note:8', ['date' => '2026-11-05']),
            $item('note:7', ['date' => '2026-11-02']),
        ], '09:00');

        expect($keys($plan[0]))->toBe(['note:7', 'note:8', 'note:1']);
    });

    it('dentro de una prioridad: primero el orden que puso el dueño, después la más corta', function () use ($item, $keys) {
        $plan = DayPlan::order([
            $item('rule:review_3days', ['minutes' => 15]),
            $item('rule:meta_flow', ['minutes' => 5]),
            $item('rule:meta_bank', ['minutes' => 10, 'rank' => 2]),
            $item('rule:meta_queue', ['minutes' => 30, 'rank' => 1]),
        ], [], '09:00');

        expect($keys($plan[0]))->toBe(['rule:meta_queue', 'rule:meta_bank', 'rule:meta_flow', 'rule:review_3days']);
        expect($plan[0]['items'][0]['reason'])->toBe('Prioridad normal: en el orden que pusiste.');
        expect($plan[0]['items'][2]['reason'])->toBe('Prioridad normal.');
    });

    it('si nadie la movió, dice que va primero por ser la más corta (con empate, no)', function () use ($item) {
        $corta = DayPlan::order([$item('a', ['minutes' => 20]), $item('b', ['minutes' => 5])], [], '09:00');
        expect($corta[0]['items'][0])->toMatchArray(['item_key' => 'b', 'reason' => 'Prioridad normal: es la más corta.']);

        $empate = DayPlan::order([$item('a', ['minutes' => 5]), $item('b', ['minutes' => 5])], [], '09:00');
        expect(array_column($empate[0]['items'], 'item_key'))->toBe(['a', 'b']);
        expect($empate[0]['items'][0]['reason'])->toBe('Prioridad normal.');
    });

    it('cada etapa suma sus minutos', function () use ($item) {
        $plan = DayPlan::order([$item('a', ['minutes' => 20]), $item('b', ['minutes' => 5])], [$item('c', ['minutes' => 10])], '09:00');

        expect(array_column($plan, 'minutes'))->toBe([10, 25]);
    });

    it('un día que no es hoy no tiene "ya es la hora": lo que tiene hora va a su hora', function () use ($item, $keys) {
        $plan = DayPlan::order([$item('note:1', ['time' => '07:00']), $item('note:2')], [], null);

        expect(array_column($plan, 'key'))->toBe(['normal', 'later']);
        expect($keys($plan[1]))->toBe(['note:1']);
    });
});
