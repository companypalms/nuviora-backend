<?php

use App\Services\Meta\Queue\EditorGame;
use App\Services\Meta\Queue\EditorRules;

// .94 (dueño, entrevista del rol de editor): los números de verdad del juego de los editores y a quién asignar.

describe('La racha a tiempo del editor', function () {
    it('cuenta los días seguidos con todo entregado a tiempo; un día sin vencimientos no corta', function () {
        $days = EditorGame::onTimeDays([
            ['due' => '2026-11-17', 'delivered' => '2026-11-17'],
            ['due' => '2026-11-18', 'delivered' => '2026-11-17'],
            // el 19 no vencía nada
            ['due' => '2026-11-20', 'delivered' => '2026-11-20'],
        ], '2026-11-20');
        expect(EditorGame::streak($days, '2026-11-20'))->toBe(3);
    });

    it('una entrega tarde la corta; lo de hoy sin entregar todavía no', function () {
        $days = EditorGame::onTimeDays([
            ['due' => '2026-11-17', 'delivered' => '2026-11-17'],
            ['due' => '2026-11-18', 'delivered' => '2026-11-19'],
            ['due' => '2026-11-19', 'delivered' => '2026-11-19'],
            ['due' => '2026-11-20', 'delivered' => null],
        ], '2026-11-20');
        expect($days['2026-11-20'])->toBeNull()->and($days['2026-11-18'])->toBeFalse()
            ->and(EditorGame::streak($days, '2026-11-20'))->toBe(1);
    });

    it('lo que venció ayer y no se entregó corta la racha; dos cosas el mismo día, basta una tarde', function () {
        expect(EditorGame::streak(EditorGame::onTimeDays([['due' => '2026-11-19', 'delivered' => null]], '2026-11-20'), '2026-11-20'))->toBe(0)
            ->and(EditorGame::onTimeDays([['due' => '2026-11-19', 'delivered' => '2026-11-19'], ['due' => '2026-11-19', 'delivered' => '2026-11-20']], '2026-11-20'))
            ->toBe(['2026-11-19' => false]);
    });
});

describe('La racha al día del líder', function () {
    it('con 1 día de correcciones, lo entregado el lunes se puede revisar hasta el martes', function () {
        $days = EditorGame::reviewDays([['delivered' => '2026-11-16', 'reviewed' => '2026-11-17']], '2026-11-20', 1);
        expect($days)->toBe(['2026-11-16' => true, '2026-11-17' => true])->and(EditorGame::streak($days, '2026-11-20'))->toBe(2);
    });

    it('lo que sigue esperando de más corta la racha hasta que se revise', function () {
        $days = EditorGame::reviewDays([['delivered' => '2026-11-17', 'reviewed' => null]], '2026-11-20', 1);
        expect($days['2026-11-18'])->toBeTrue()->and($days['2026-11-19'])->toBeFalse()
            ->and(EditorGame::streak($days, '2026-11-20'))->toBe(0);
    });
});

describe('A quién asignar', function () {
    it('sugiere al que tiene menos encargos, después menos atrasados; si empatan, no al líder', function () {
        expect(EditorRules::suggest([
            ['id' => 1, 'leader' => true, 'open' => 0, 'late' => 0],
            ['id' => 2, 'leader' => false, 'open' => 0, 'late' => 0],
            ['id' => 3, 'leader' => false, 'open' => 1, 'late' => 0],
        ]))->toBe(2)->and(EditorRules::suggest([
            ['id' => 2, 'leader' => false, 'open' => 2, 'late' => 1],
            ['id' => 3, 'leader' => false, 'open' => 2, 'late' => 0],
        ]))->toBe(3)->and(EditorRules::suggest([]))->toBeNull();
    });
});
