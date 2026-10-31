<?php

use App\Services\Meta\Videos\TranscriptRules;
use Carbon\CarbonImmutable;

// Los videos de los creativos ganadores (`.55`): el hook y cuándo se vuelve a intentar.

describe('el hook', function () {
    it('es lo que se dice en los primeros 3 segundos (los tramos que empiezan antes)', function () {
        $segments = [
            ['start' => 0.0, 'end' => 1.8, 'text' => '¿Te duelen las rodillas?'],
            ['start' => 1.8, 'end' => 3.4, 'text' => 'Mira esto.'],
            ['start' => 3.4, 'end' => 9.0, 'text' => 'FlexiStep te ayuda a caminar sin dolor.'],
        ];
        expect(TranscriptRules::hook($segments, 'todo'))->toBe('¿Te duelen las rodillas? Mira esto.');
    });

    it('sin tiempos, la primera frase; si es muy larga, sus primeras 15 palabras', function () {
        expect(TranscriptRules::hook([], '¡Deja de sufrir! Este producto cambia todo.'))->toBe('¡Deja de sufrir!');
        $long = 'Esta es una frase muy larga que sigue y sigue sin parar porque el que habla no respira nunca entre palabras ni hace pausas';
        expect(TranscriptRules::hook([], $long))->toBe('Esta es una frase muy larga que sigue y sigue sin parar porque el que…');
    });

    it('un video sin voz no tiene hook', function () {
        expect(TranscriptRules::hook([], '   '))->toBeNull();
    });
});

describe('cuándo se intenta', function () {
    $now = CarbonImmutable::parse('2026-11-05 07:30:00');

    it('un video nuevo, sí', function () use ($now) {
        expect(TranscriptRules::due(null, 0, null, $now))->toBeTrue();
    });

    it('uno que falló: al día siguiente, hasta 3 veces', function () use ($now) {
        expect(TranscriptRules::due('failed', 1, $now->subHours(2), $now))->toBeFalse();
        expect(TranscriptRules::due('failed', 1, $now->subDay(), $now))->toBeTrue();
        expect(TranscriptRules::due('failed', 3, $now->subDays(3), $now))->toBeFalse();
    });

    it('uno que Meta no deja bajar: a la semana', function () use ($now) {
        expect(TranscriptRules::due('no_access', 1, $now->subDays(3), $now))->toBeFalse();
        expect(TranscriptRules::due('no_access', 1, $now->subDays(8), $now))->toBeTrue();
    });

    it('uno ya transcrito o muy grande, nunca', function () use ($now) {
        expect(TranscriptRules::due('done', 1, $now->subDays(30), $now))->toBeFalse();
        expect(TranscriptRules::due('too_big', 1, $now->subDays(30), $now))->toBeFalse();
    });
});
