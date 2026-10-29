<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\GoalRules;
use Carbon\CarbonImmutable;

// Panel del dueño › Metas y alertas (guias/DICCIONARIO-METRICAS.md, §6): las metas que pone el dueño y cómo se comparan.

$now = fn (string $at = '2026-10-16 00:00:00') => CarbonImmutable::parse($at, 'America/Caracas');
$config = fn (array $raw = []) => GoalRules::normalize($raw);

describe('Metas › lo guardado', function () use ($config) {
    it('completa lo que falta: sin meta, con las alertas prendidas', function () use ($config) {
        $c = $config();
        expect($c['business'])->toBe(['sales' => null, 'profit' => null, 'effectiveness' => null]);
        expect($c['team']['sellers.effectiveness'])->toBe(['value' => null, 'red' => null, 'alert' => true, 'people' => []]);
        expect($c['alerts']['agency_cash'])->toBe(['yellow' => null, 'red' => null, 'on' => true]);
        expect(GoalRules::configured($c))->toBeFalse();
    });

    it('acepta las excepciones como lista (de la pantalla) o como id => meta (lo guardado)', function () use ($config) {
        $fromScreen = $config(['team' => ['sellers.effectiveness' => ['value' => '55', 'red' => 45, 'people' => [['id' => 7, 'value' => '60'], ['id' => 0, 'value' => 5], ['id' => 9, 'value' => '']]]]]);
        expect($fromScreen['team']['sellers.effectiveness'])->toBe(['value' => 55.0, 'red' => 45.0, 'alert' => true, 'people' => [7 => 60.0]]);
        $saved = $config(['team' => ['sellers.effectiveness' => ['value' => 55, 'people' => [7 => 60]]]]);
        expect($saved['team']['sellers.effectiveness']['people'])->toBe([7 => 60.0]);
        expect(GoalRules::forScreen($saved)['team']['sellers.effectiveness']['people'])->toBe([['id' => 7, 'value' => 60.0]]);
    });

    it('avisa lo que no tiene sentido', function () use ($config) {
        $p = GoalRules::problems($config([
            'business' => ['effectiveness' => 120, 'sales' => -5],
            'team' => [
                'sellers.effectiveness' => ['value' => 55, 'red' => 60],      // el rojo de un mínimo va más abajo
                'agencies.delivery_time' => ['value' => 24, 'red' => 20],     // el rojo de un máximo va más arriba
                'agencies.rejection_rate' => ['red' => 10],                   // rojo sin meta
            ],
            'alerts' => ['agency_cash' => ['yellow' => 500, 'red' => 300], 'ad_cost' => ['red' => 9]],
        ]));
        expect(array_keys($p))->toBe([
            'business.sales', 'business.effectiveness', 'team.sellers.effectiveness.red', 'team.agencies.delivery_time.red',
            'team.agencies.rejection_rate.value', 'alerts.agency_cash.red', 'alerts.ad_cost.yellow',
        ]);
        expect(GoalRules::problems($config(['team' => ['sellers.effectiveness' => ['value' => 55, 'red' => 45]]])))->toBe([]);
    });

    it('hay algo que avise solo si una alerta tiene su límite y está prendida', function () use ($config) {
        expect(GoalRules::configured($config(['team' => ['sellers.effectiveness' => ['value' => 55, 'alert' => false]]])))->toBeFalse();
        expect(GoalRules::configured($config(['team' => ['sellers.effectiveness' => ['value' => 55]]])))->toBeTrue();
        expect(GoalRules::configured($config(['alerts' => ['cancellations' => ['yellow' => 15, 'on' => false]]])))->toBeFalse();
        expect(GoalRules::configured($config(['alerts' => ['cancellations' => ['yellow' => 15]]])))->toBeTrue();
    });
});

describe('Metas › amarillo y rojo', function () {
    it('justo en la meta está cumplida; por debajo, amarillo; por debajo del rojo, rojo', function () {
        expect(GoalRules::level(55, 55.0, 45.0, 'higher'))->toBeNull();
        expect(GoalRules::level(54.9, 55.0, 45.0, 'higher'))->toBe('yellow');
        expect(GoalRules::level(44, 55.0, 45.0, 'higher'))->toBe('red');
        expect(GoalRules::level(30, 24.0, null, 'lower'))->toBe('yellow');
        expect(GoalRules::level(50, 24.0, 48.0, 'lower'))->toBe('red');
        expect(GoalRules::level(null, 24.0, 48.0, 'lower'))->toBeNull();
        expect(GoalRules::level(10, null, null, 'higher'))->toBeNull();
    });

    it('en una excepción, el rojo queda a la misma distancia de su meta', function () {
        $goal = ['value' => 55.0, 'red' => 45.0, 'people' => [7 => 65.0]];
        expect(GoalRules::personLimits($goal, 7))->toBe(['yellow' => 65.0, 'red' => 55.0, 'own' => true]);
        expect(GoalRules::personLimits($goal, 8))->toBe(['yellow' => 55.0, 'red' => 45.0, 'own' => false]);
        $max = ['value' => 24.0, 'red' => 36.0, 'people' => [3 => 48.0]];
        expect(GoalRules::personLimits($max, 3))->toBe(['yellow' => 48.0, 'red' => 60.0, 'own' => true]);
    });
});

describe('Metas › reparto del mes', function () use ($now) {
    it('los meses enteros tienen la meta del mes; los demás períodos, la suma de cada día', function () use ($now) {
        expect(GoalRules::forPeriod(31000, Period::fromPreset('month', null, null, $now())))->toBe(31000.0);
        expect(GoalRules::forPeriod(31000, Period::fromPreset('last_month', null, null, $now())))->toBe(31000.0);
        // 7 días de octubre (31 días): 7/31 de la meta
        expect(GoalRules::forPeriod(31000, Period::fromPreset('7d', null, null, $now('2026-10-16 10:00'))))->toBe(7000.0);
        expect(GoalRules::forPeriod(31000, Period::fromPreset('today', null, null, $now('2026-10-16 10:00'))))->toBe(1000.0);
        // Del 30/10 al 02/11: 2 días de octubre (1/31 cada uno) y 2 de noviembre (1/30 cada uno)
        $p = Period::fromPreset('custom', '2026-10-30', '2026-11-02', $now('2026-11-05 10:00'));
        expect(GoalRules::forPeriod(9300, $p))->toBe(round(2 * 9300 / 31 + 2 * 9300 / 30, 2));
    });
});

describe('Metas › tarjetas de ventas y ganancia', function () use ($now) {
    it('en "Este mes" dice cuánto falta y si va al ritmo, contando lo que está en camino', function () use ($now) {
        // El 16 a las 00:00 pasaron 15 de los 31 días de octubre
        $p = Period::fromPreset('month', null, null, $now());
        $g = GoalRules::moneyGoal('sales', 31000, 10000, 2000, $p, $now(), true);
        expect($g['value'])->toBe(31000.0);
        expect($g['progress'])->toBe(32.3);
        expect($g['status'])->toBe('behind');
        expect($g['pace']['projection'])->toBe(24800.0); // 12.000 ÷ 15/31
        expect($g['text'])->toContain('faltan $21.000,00')->toContain('terminarías el mes cerca de $24.800,00');

        $ok = GoalRules::moneyGoal('sales', 31000, 14000, 2000, $p, $now(), true);
        expect($ok['status'])->toBe('on_track');
        expect($ok['text'])->toContain('vas bien');
    });

    it('cumplida en cuanto llega; muy pronto en el mes, sin ritmo; hoy, sin color', function () use ($now) {
        $month = Period::fromPreset('month', null, null, $now('2026-10-02 10:00'));
        expect(GoalRules::moneyGoal('sales', 31000, 31000, 0, Period::fromPreset('month', null, null, $now()), $now(), true)['status'])->toBe('met');
        expect(GoalRules::moneyGoal('sales', 31000, 500, 0, $month, $now('2026-10-02 10:00'), true)['status'])->toBe('neutral');
        $today = Period::fromPreset('today', null, null, $now('2026-10-16 10:00'));
        expect(GoalRules::moneyGoal('profit', 31000, 200, 100, $today, $now('2026-10-16 10:00'), true)['status'])->toBe('neutral');
    });

    it('un período que se sigue entregando va bien si con lo que está en camino llega; uno terminado, no llegó', function () use ($now) {
        $yesterday = Period::fromPreset('yesterday', null, null, $now('2026-10-16 10:00'));
        expect(GoalRules::moneyGoal('sales', 31000, 700, 400, $yesterday, $now('2026-10-16 10:00'), true)['status'])->toBe('on_track');
        expect(GoalRules::moneyGoal('sales', 31000, 500, 100, $yesterday, $now('2026-10-16 10:00'), true)['status'])->toBe('behind');
        $last = Period::fromPreset('last_month', null, null, $now('2026-11-20 10:00'));
        $g = GoalRules::moneyGoal('profit', 6000, 4500, null, $last, $now('2026-11-20 10:00'), false);
        expect($g['status'])->toBe('below');
        expect($g['text'])->toContain('Meta del mes: $6.000,00.')->toContain('faltan $1.500,00');
    });

    it('un % o un tiempo: cumplida, cuánto le falta, y gris si todavía puede mejorar', function () {
        expect(GoalRules::limitGoal('Efectividad', 'percent', 'higher', 60.0, null, 61.2, false)['status'])->toBe('met');
        $g = GoalRules::limitGoal('Efectividad', 'percent', 'higher', 60.0, 50.0, 51.8, false);
        expect($g['status'])->toBe('behind');
        expect($g['text'])->toBe('Tu meta: 60 % (rojo: 50 %). Va 8,2 puntos abajo.');
        expect(GoalRules::limitGoal('Efectividad', 'percent', 'higher', 60.0, 50.0, 40.0, false)['status'])->toBe('below');
        expect(GoalRules::limitGoal('Efectividad', 'percent', 'higher', 60.0, 50.0, 40.0, true)['status'])->toBe('neutral');
        $time = GoalRules::limitGoal('Tiempo', 'hours', 'lower', 24.0, null, 27.5, false, true);
        expect($time['text'])->toBe('Su máximo: 24 h. Va 3,5 h arriba.');
    });
});

describe('Metas › Equipo', function () use ($config) {
    $role = fn () => [
        'columns' => [['key' => 'attended', 'matures' => false], ['key' => 'effectiveness', 'matures' => true]],
        'rows' => [
            ['id' => 1, 'name' => 'Ana', 'values' => ['attended' => 40, 'effectiveness' => 62.0], 'low_data' => false],
            ['id' => 2, 'name' => 'Bea', 'values' => ['attended' => 30, 'effectiveness' => 50.0], 'low_data' => false],
            ['id' => 3, 'name' => 'Caro', 'values' => ['attended' => 25, 'effectiveness' => 40.0], 'low_data' => false],
            ['id' => 4, 'name' => 'Dani', 'values' => ['attended' => 3, 'effectiveness' => 0.0], 'low_data' => true],
            ['id' => 5, 'name' => 'Eli', 'values' => ['attended' => 20, 'effectiveness' => 62.0], 'low_data' => false],
        ],
    ];

    it('marca en amarillo o rojo a quien no llega a su meta; los de pocos datos, no', function () use ($config, $role) {
        $c = $config(['team' => ['sellers.effectiveness' => ['value' => 55, 'red' => 45, 'people' => [5 => 65]]]]);
        $r = GoalRules::team('sellers', $role(), $c, false);
        expect($r['columns'][1]['goal'])->toBe(['value' => 55.0, 'red' => 45.0]);
        expect(array_key_exists('goal', $r['columns'][0]))->toBeFalse();
        expect(array_column($r['rows'], 'goal_levels', 'name'))->toBe([
            'Ana' => ['effectiveness' => 'ok'],
            'Bea' => ['effectiveness' => 'yellow'],
            'Caro' => ['effectiveness' => 'red'],
            'Dani' => [],
            'Eli' => ['effectiveness' => 'yellow'], // su meta es 65 %
        ]);
        // Otro rol sin metas: no cambia nada
        expect(GoalRules::team('agencies', $role(), $c, false))->toBe($role());
    });

    it('mientras el período se sigue entregando, la efectividad no se marca', function () use ($config, $role) {
        $c = $config(['team' => ['sellers.effectiveness' => ['value' => 55]]]);
        expect(array_column(GoalRules::team('sellers', $role(), $c, true)['rows'], 'goal_levels'))->each->toBe([]);
    });

    it('las tarjetas del rol y de una persona llevan su meta', function () use ($config) {
        $c = $config(['team' => ['sellers.effectiveness' => ['value' => 55, 'red' => 45, 'people' => [5 => 65]]]]);
        $cards = [['key' => 'attended', 'value' => 40, 'neutral' => false], ['key' => 'effectiveness', 'value' => 60.0, 'neutral' => false]];
        $all = GoalRules::teamCards('sellers', $cards, $c, false);
        expect(isset($all[0]['goal']))->toBeFalse();
        expect($all[1]['goal']['status'])->toBe('met');
        $eli = GoalRules::teamCards('sellers', $cards, $c, false, 5);
        expect($eli[1]['goal']['value'])->toBe(65.0);
        expect($eli[1]['goal']['red'])->toBe(55.0);
        expect($eli[1]['goal']['text'])->toStartWith('Su meta: 65 %');
    });
});
