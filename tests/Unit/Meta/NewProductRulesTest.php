<?php

use App\Services\Meta\Products\NewProductRules;

// .118: cuándo se abre la misión de producto nuevo y sus 5 etapas en 7 días.

function condicionesMision(array $over = []): array
{
    return $over + ['no_ready' => [], 'need_today' => [], 'last' => null, 'today' => '2026-11-09', 'forecast' => 'ok'];
}

describe('Lo que falta para abrirla', function () {
    it('con todo cumplido no falta nada', function () {
        expect(NewProductRules::missing(condicionesMision()))->toBe([]);
    });

    it('dice cada cosa que falta', function () {
        expect(NewProductRules::missing(condicionesMision(['no_ready' => ['FlexiStep', 'Comprimax'], 'need_today' => ['Termafit en Aragua'], 'last' => '2026-11-05', 'forecast' => 'fixed'])))->toBe([
            'Falta un batch listo en la banca de FlexiStep y Comprimax.',
            'Hoy necesitan un batch: Termafit en Aragua.',
            'El siguiente producto nuevo, a partir del 12/11.',
            'Según el pronóstico, el equipo de editores no alcanza.',
        ]);
    });

    it('a los 7 días exactos ya se puede', function () {
        expect(NewProductRules::missing(condicionesMision(['last' => '2026-11-02'])))->toBe([])
            ->and(NewProductRules::missing(condicionesMision(['last' => '2026-11-03'])))->toHaveCount(1);
    });

    it('sin pronóstico no se abre', function () {
        expect(NewProductRules::missing(condicionesMision(['forecast' => null])))->toBe(['Todavía no hay pronóstico de los editores.']);
    });
});

describe('Las etapas', function () {
    it('5 etapas y 7 días, en este orden', function () {
        $s = NewProductRules::stages('2026-11-09', [], '2026-11-09');
        expect(array_column($s, 'key'))->toBe(['search', 'strategy', 'creatives', 'landing', 'publish'])
            ->and(array_column($s, 'due'))->toBe(['2026-11-11', '2026-11-12', '2026-11-14', '2026-11-15', '2026-11-16'])
            ->and(array_filter(array_column($s, 'late')))->toBe([]);
    });

    it('vencida al día siguiente de su fecha, si no está hecha', function () {
        expect(NewProductRules::stages('2026-11-09', [], '2026-11-11')[0]['late'])->toBeFalse()
            ->and(NewProductRules::stages('2026-11-09', [], '2026-11-12')[0]['late'])->toBeTrue()
            ->and(NewProductRules::stages('2026-11-09', ['search' => true], '2026-11-12')[0]['late'])->toBeFalse();
    });

    it('la primera vencida que depende del dueño, una sola', function () {
        expect(NewProductRules::firstLate(NewProductRules::stages('2026-11-01', [], '2026-11-30'))['key'])->toBe('search')
            ->and(NewProductRules::firstLate(NewProductRules::stages('2026-11-01', ['search' => true, 'strategy' => true], '2026-11-07')))->toBeNull()
            ->and(NewProductRules::firstLate(NewProductRules::stages('2026-11-01', ['search' => true, 'strategy' => true], '2026-11-09'))['key'])->toBe('landing');
    });
});
