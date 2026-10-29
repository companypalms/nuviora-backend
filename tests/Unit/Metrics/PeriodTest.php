<?php

use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;

// Panel del dueño (guias/DICCIONARIO-METRICAS.md, §1 y §8): el período elegido y el anterior con el que se compara.

$now = fn (string $at = '2026-10-29 10:15:00') => CarbonImmutable::parse($at, 'America/Caracas');
$range = fn (Period $p) => [$p->start->format('Y-m-d H:i'), $p->end->format('Y-m-d H:i')];

describe('Período del panel del dueño', function () use ($now, $range) {
    it('"hoy" llega hasta esta hora y se compara con ayer a esta misma hora', function () use ($now, $range) {
        $p = Period::fromPreset('today', null, null, $now());
        expect($range($p))->toBe(['2026-10-29 00:00', '2026-10-29 10:15']);
        expect($range($p->previous()))->toBe(['2026-10-28 00:00', '2026-10-28 10:15']);
        expect($p->days())->toBe(1);
    });

    it('"ayer" es el día completo y se compara con anteayer completo', function () use ($now, $range) {
        $p = Period::fromPreset('yesterday', null, null, $now());
        expect($range($p))->toBe(['2026-10-28 00:00', '2026-10-28 23:59']);
        expect($range($p->previous()))->toBe(['2026-10-27 00:00', '2026-10-27 23:59']);
    });

    it('"últimos 7 días" incluye hoy y se compara con los 7 días de antes, hasta la misma hora', function () use ($now, $range) {
        $p = Period::fromPreset('7d', null, null, $now());
        expect($range($p))->toBe(['2026-10-23 00:00', '2026-10-29 10:15']);
        expect($p->days())->toBe(7);
        expect($range($p->previous()))->toBe(['2026-10-16 00:00', '2026-10-22 10:15']);
    });

    it('"este mes" se compara con el mismo tramo del mes pasado', function () use ($now, $range) {
        $p = Period::fromPreset('month', null, null, $now());
        expect($range($p))->toBe(['2026-10-01 00:00', '2026-10-29 10:15']);
        expect($range($p->previous()))->toBe(['2026-09-01 00:00', '2026-09-29 10:15']);
    });

    it('un 31 de marzo, "este mes" se compara con febrero hasta su último día', function () use ($now, $range) {
        $p = Period::fromPreset('month', null, null, $now('2027-03-31 18:00:00'));
        expect($range($p->previous()))->toBe(['2027-02-01 00:00', '2027-02-28 18:00']);
    });

    it('"mes pasado" es el mes completo y se compara con el mes anterior completo', function () use ($now, $range) {
        $p = Period::fromPreset('last_month', null, null, $now());
        expect($range($p))->toBe(['2026-09-01 00:00', '2026-09-30 23:59']);
        expect($range($p->previous()))->toBe(['2026-08-01 00:00', '2026-08-31 23:59']);
    });

    it('fechas elegidas: días completos, nunca más allá de ahora, y se comparan con los mismos días justo antes', function () use ($now, $range) {
        $p = Period::fromPreset('custom', '2026-10-20', '2026-10-25', $now());
        expect($range($p))->toBe(['2026-10-20 00:00', '2026-10-25 23:59']);
        expect($p->days())->toBe(6);
        expect($range($p->previous()))->toBe(['2026-10-14 00:00', '2026-10-19 23:59']);

        $p = Period::fromPreset('custom', '2026-10-27', '2026-11-05', $now());
        expect($range($p))->toBe(['2026-10-27 00:00', '2026-10-29 10:15']);
    });

    it('rechaza fechas que no tienen sentido', function () use ($now) {
        expect(fn () => Period::fromPreset('custom', '2026-10-25', '2026-10-20', $now()))->toThrow(InvalidArgumentException::class);
        expect(fn () => Period::fromPreset('custom', null, '2026-10-20', $now()))->toThrow(InvalidArgumentException::class);
        expect(fn () => Period::fromPreset('custom', '2026-11-01', '2026-11-05', $now()))->toThrow(InvalidArgumentException::class);
        expect(fn () => Period::fromPreset('custom', '2025-01-01', '2026-10-29', $now()))->toThrow(InvalidArgumentException::class);
        expect(fn () => Period::fromPreset('semana', null, null, $now()))->toThrow(InvalidArgumentException::class);
    });

    it('sabe si sus pedidos todavía se están entregando (los últimos 4 días, como Finanzas › Cohortes)', function () use ($now) {
        expect(Period::fromPreset('today', null, null, $now())->isMaturing($now(), 4))->toBeTrue();
        expect(Period::fromPreset('custom', '2026-10-01', '2026-10-24', $now())->isMaturing($now(), 4))->toBeFalse();
        expect(Period::fromPreset('custom', '2026-10-01', '2026-10-25', $now())->isMaturing($now(), 4))->toBeTrue();
    });
});
