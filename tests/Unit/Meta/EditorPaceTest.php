<?php

use App\Services\Meta\Queue\AdvanceRules;
use App\Services\Meta\Queue\EditorPaceRules as R;

// .175 (dueño, 2026-11-23): "la meta mínima diaria por editor son 2 batchs al día. Si resulta que terminan esos batchs antes de
// su horario laboral…"; el horario del líder lo pone el dueño y el de sus editores el líder; cuando uno queda libre, al líder le
// sale la misión de asignarle otro; el líder ve todo lo que se puede adelantar. 2026-11-23 es lunes.

$week = ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '17:00'];

describe('Quién pone el horario', function () use ($week) {
    it('el dueño pone el de todos; el líder, solo el de sus editores de video (no el suyo)', function () {
        expect(R::canEdit('Admin', 1, 'Editor líder', 2))->toBeTrue()
            ->and(R::canEdit('Master', 1, 'Editor de video', 3))->toBeTrue()
            ->and(R::canEdit('Editor líder', 2, 'Editor de video', 3))->toBeTrue()
            ->and(R::canEdit('Editor líder', 2, 'Editor líder', 2))->toBeFalse()
            ->and(R::canEdit('Editor de video', 3, 'Editor de video', 3))->toBeFalse()
            ->and(R::canEdit('Admin', 1, 'Vendedor', 9))->toBeFalse();
    });

    it('un horario necesita días y la salida después de la entrada', function () {
        expect(R::invalid([1, 2], '08:00', '17:00'))->toBeNull()
            ->and(R::invalid([], '08:00', '17:00'))->toBe('Elige al menos un día.')
            ->and(R::invalid([7], '08:00', '17:00'))->toBe('Los días van de domingo a sábado.')
            ->and(R::invalid([1], '8:00', '17:00'))->toBe('Escribe la hora así: 08:00.')
            ->and(R::invalid([1], '17:00', '08:00'))->toBe('La hora de salida tiene que ser después de la de entrada.');
    });

    it('se describe en palabras', function () use ($week) {
        expect(R::describe($week))->toBe('lunes a viernes, de 08:00 a 17:00')
            ->and(R::describe(['days' => [1, 3, 5], 'start' => '09:00', 'end' => '13:00']))->toBe('lunes, miércoles y viernes, de 09:00 a 13:00')
            ->and(R::describe(null))->toBeNull();
    });
});

describe('Cómo está ahora', function () use ($week) {
    it('trabajando, antes de entrar, ya salió, no le toca, de permiso o sin horario', function () use ($week) {
        expect(R::now($week, [], '2026-11-23 14:10:00'))->toBe(['state' => 'in', 'left' => 170, 'start' => '08:00', 'end' => '17:00'])
            ->and(R::now($week, [], '2026-11-23 07:00:00')['state'])->toBe('before')
            ->and(R::now($week, [], '2026-11-23 17:00:00')['state'])->toBe('after')
            ->and(R::now($week, [], '2026-11-22 10:00:00')['state'])->toBe('rest') // domingo
            ->and(R::now($week, ['2026-11-23'], '2026-11-23 10:00:00')['state'])->toBe('day_off')
            ->and(R::now(null, [], '2026-11-23 10:00:00')['state'])->toBe('no_schedule');
    });

    it('libre = en su horario (o sin horario) y sin nada por entregar', function () {
        expect(R::free('in', 0))->toBeTrue()
            ->and(R::free('no_schedule', 0))->toBeTrue()
            ->and(R::free('in', 1))->toBeFalse()
            ->and(R::free('after', 0))->toBeFalse()
            ->and(R::free('day_off', 0))->toBeFalse();
    });

    it('libre desde su última entrega de hoy, o desde que entró', function () use ($week) {
        expect(R::freeSince($week, '2026-11-23 14:10:00', '2026-11-23 13:05:00'))->toBe('2026-11-23 13:05:00')
            ->and(R::freeSince($week, '2026-11-23 14:10:00', '2026-11-20 16:00:00'))->toBe('2026-11-23 08:00:00')
            ->and(R::freeSince($week, '2026-11-23 14:10:00', null))->toBe('2026-11-23 08:00:00')
            ->and(R::freeSince(null, '2026-11-23 14:10:00', null))->toBeNull();
    });
});

describe('Las horas y la meta', function () use ($week) {
    it('cuenta solo las horas de su horario', function () use ($week) {
        // del viernes 16:00 al lunes 10:00: 1 h el viernes y 2 h el lunes
        expect(R::hours($week, [], '2026-11-20 16:00:00', '2026-11-23 10:00:00'))->toBe(3.0)
            // con el lunes de permiso, solo el viernes
            ->and(R::hours($week, ['2026-11-23'], '2026-11-20 16:00:00', '2026-11-23 10:00:00'))->toBe(1.0)
            ->and(R::hours(null, [], '2026-11-20 16:00:00', '2026-11-23 10:00:00'))->toBe(0.0);
    });

    it('los días con la meta cumplida, solo en sus días de trabajo', function () use ($week) {
        $days = R::workDays($week, ['2026-11-18'], '2026-11-16', '2026-11-22'); // lun a dom, el miércoles de permiso
        expect($days)->toBe(['2026-11-16', '2026-11-17', '2026-11-19', '2026-11-20'])
            // el sábado (21) no es de trabajo: lo que entregó ese día no cuenta para la meta
            ->and(R::goalDays(['2026-11-16' => 2, '2026-11-17' => 1, '2026-11-19' => 3, '2026-11-21' => 2], $days, 2))->toBe(['days' => 4, 'met' => 2, 'done' => 6]);
    });

    it('lo que tardó cada batch, en horas de su horario', function () use ($week) {
        $spans = [['start' => '2026-11-23 08:00:00', 'end' => '2026-11-23 12:00:00'], ['start' => '2026-11-20 16:00:00', 'end' => '2026-11-23 09:30:00'],
            ['start' => '2026-11-23 12:00:00', 'end' => '2026-11-23 12:00:00']];
        expect(R::batchHours($spans, $week, []))->toBe([4.0, 2.5])
            ->and(R::batchHours($spans, null, []))->toBe([]);
    });

    it('las horas sin encargo: su horario menos los ratos con algo asignado (juntando los que se pisan)', function () use ($week) {
        $busy = [['2026-11-23 09:00:00', '2026-11-23 11:00:00'], ['2026-11-23 10:00:00', '2026-11-23 12:00:00'], ['2026-11-23 15:00:00', '2026-11-23 20:00:00']];
        // de 8 a 17 son 9 h; con algo asignado de 9 a 12 y de 15 a 17 (5 h): 4 h sin encargo
        expect(R::idleHours($busy, $week, [], '2026-11-23 00:00:00', '2026-11-23 23:59:59'))->toBe(4.0)
            ->and(R::idleHours([], $week, [], '2026-11-23 00:00:00', '2026-11-23 23:59:59'))->toBe(9.0)
            ->and(R::idleHours($busy, null, [], '2026-11-23 00:00:00', '2026-11-23 23:59:59'))->toBe(0.0);
    });

    it('lo que hace el equipo por día si cumple la meta (sin horario, de lunes a viernes)', function () use ($week) {
        // 2 por día: uno de lunes a sábado (6/7) y uno sin horario (5/7) → 2 × 11/7
        expect(R::capacity([['days' => [1, 2, 3, 4, 5, 6], 'start' => '08:00', 'end' => '17:00'], null], 2))->toBe(round(2 * 11 / 7, 2));
    });

    it('cómo va el equipo contra la meta', function () use ($week) {
        $pace = R::teamPace([['schedule' => $week, 'off' => [], 'done' => 7], ['schedule' => null, 'off' => [], 'done' => 5]], 2, '2026-11-16', '2026-11-22');
        // 5 días de trabajo cada uno × 2 = 20; entregaron 12
        expect($pace)->toBe(['done' => 12, 'goal' => 20, 'pct' => 60.0]);
    });
});

describe('Para adelantar', function () {
    it('no mira el stock: se testea sin mercancía (lo que pide el sistema cada mañana sí lo mira)', function () {
        $campaigns = [
            ['city_id' => 1, 'city' => 'Caracas', 'active' => true, 'stock' => 0, 'purchases7' => 9, 'action' => ['key' => 'hold']],
            ['city_id' => 2, 'city' => 'Valencia', 'active' => false, 'stock' => 5, 'purchases7' => 3, 'action' => ['key' => 'hold']],
        ];
        expect(\App\Services\Meta\Queue\ProposalRules::city($campaigns))->toBeNull()
            ->and(\App\Services\Meta\Queue\ProposalRules::city($campaigns, true))->toBe(['city_id' => 1, 'city' => 'Caracas'])
            // sin ninguna campaña prendida, tampoco se adelanta
            ->and(\App\Services\Meta\Queue\ProposalRules::city([$campaigns[1]], true))->toBeNull();
    });

    it('lo que le falta a un producto para 2 semanas, menos lo que ya tiene pedido o listo', function () {
        // 2 vivos que duran 7 días: 2/7 por día → 4 en 14 días
        expect(AdvanceRules::left(2 / 7, 1))->toBe(3)
            ->and(AdvanceRules::left(2 / 7, 5))->toBe(0)
            ->and(AdvanceRules::nextDate(2 / 7, 1, '2026-11-23'))->toBe('2026-11-27')
            ->and(AdvanceRules::nextDate(2 / 7, 0, '2026-11-23'))->toBe('2026-11-23');
    });

    it('primero lo de los productos que venden (el que lo necesita antes), después los nuevos de la cola en su orden', function () {
        $items = AdvanceRules::items(
            [['product_id' => 1, 'product' => 'Comprimax', 'rate' => 2 / 7, 'open' => 2], ['product_id' => 2, 'product' => 'Releev', 'rate' => 2 / 7, 'open' => 0],
                ['product_id' => 3, 'product' => 'Lleno', 'rate' => 2 / 7, 'open' => 6]],
            [['product_id' => 9, 'product' => 'Nuevo', 'position' => 1, 'batches' => 3]],
            '2026-11-23',
        );
        expect(array_column($items, 'key'))->toBe(['next-2', 'next-1', 'new-9'])
            ->and($items[0]['left'])->toBe(4)
            ->and($items[0]['why'])->toBe('Va a necesitar ~4 batches más en las próximas 2 semanas, para reponer los que se cansan.')
            ->and($items[2]['why'])->toBe('Producto nuevo (lugar 1 de la cola del dueño): sus 3 batches del testeo, para probar sus ángulos.');
    });
});
