<?php

use App\Services\Meta\Review\AiReview;

// .143 (dueño, 2026-11-14: "quiero saber si ese criterio realmente es bueno comparado a cómo eran nuestros resultados antes"):
// las cuentas de la radiografía antes / después de la IA de Meta Ads. Una sola vara (el BE y el C de hoy) para los dos períodos.

/** Un anuncio $ad gastando $spend con $buys compras cada día desde $from, $n días. */
function diasDeAnuncio(string $ad, string $from, int $n, float $spend, float $buys, string $cell = '1|1'): array
{
    return array_map(fn ($i) => ['ad' => $ad, 'cell' => $cell, 'date' => date('Y-m-d', strtotime("{$from} +{$i} days")), 'spend' => $spend, 'purchases' => $buys], range(0, $n - 1));
}

$vara = ['1|1' => ['be' => 10.0, 'c' => 6.0]];

describe('.143: comparar por día', function () {
    it('cada período por día, y cuánto cambió', function () {
        expect(AiReview::perDay(510, 51))->toBe(10.0)->and(AiReview::perDay(5, 0))->toBeNull()
            ->and(AiReview::change(10, 12))->toBe('+20 %')->and(AiReview::change(10, 7))->toBe('−30 %')
            ->and(AiReview::change(0, 5))->toBe('—')->and(AiReview::change(null, 5))->toBe('—');
    });

    it('la semana empieza el lunes', function () {
        expect(AiReview::week('2026-11-04'))->toBe('2026-11-02')->and(AiReview::week('2026-11-01'))->toBe('2026-10-26');
    });
});

describe('.143: el dinero quemado', function () use ($vara) {
    it('pierde plata: gastó más de un BE sin comprar, o compró más caro que el BE', function () {
        expect(AiReview::losing(12, 0, 10))->toBeTrue()
            ->and(AiReview::losing(24, 2, 10))->toBeTrue()   // CPA $12 > BE $10
            ->and(AiReview::losing(18, 2, 10))->toBeFalse()  // CPA $9
            ->and(AiReview::losing(8, 0, 10))->toBeFalse()   // todavía no gastó un BE
            ->and(AiReview::losing(12, 0, 0))->toBeFalse();  // sin vara no se juzga
    });

    it('de lo gastado en anuncios, cuánto fue en semanas que perdían plata (sin vara, aparte)', function () use ($vara) {
        $days = [...diasDeAnuncio('perdedor', '2026-11-02', 7, 3, 0), ...diasDeAnuncio('bueno', '2026-11-02', 7, 7, 1),
            ...diasDeAnuncio('otro', '2026-11-02', 2, 5, 0, '2|1')];

        expect(AiReview::burned($days, $vara))->toBe(['spend' => 70.0, 'losing' => 21.0, 'pct' => 30.0, 'no_yard' => 10.0]);
    });

    it('cuánto alcanzó a gastar un perdedor antes de apagarse (los que siguen gastando no cuentan)', function () use ($vara) {
        $days = [...diasDeAnuncio('a', '2026-10-01', 4, 5, 0), ...diasDeAnuncio('b', '2026-10-01', 2, 15, 1), ...diasDeAnuncio('vivo', '2026-10-01', 30, 5, 0),
            ...diasDeAnuncio('ganador', '2026-10-01', 5, 6, 1)];

        expect(AiReview::untilOff($days, $vara, '2026-10-25'))->toBe(['losers' => 2, 'avg_spend' => 25.0, 'median_spend' => 25.0, 'avg_days' => 3.0, 'total' => 50.0]);
    });
});

describe('.143: los ganadores, por sus primeros 7 días', function () use ($vara) {
    it('ganador: 3 compras o más a un CPA en el C o debajo; solo los que ya cumplieron 7 días y empezaron en el período', function () use ($vara) {
        $days = [
            ...diasDeAnuncio('ganador', '2026-11-02', 10, 5, 1),   // 7 días: $35, 7 compras, CPA $5
            ...diasDeAnuncio('caro', '2026-11-02', 7, 8, 1),       // CPA $8 > C $6
            ...diasDeAnuncio('poco', '2026-11-02', 7, 1, 0.25),    // 1,75 compras: no llega a 3
            ...diasDeAnuncio('nuevo', '2026-11-10', 3, 5, 1),      // todavía no cumple 7 días
            ...diasDeAnuncio('viejo', '2026-10-20', 20, 5, 1),     // empezó antes del período
        ];
        $w = AiReview::winners($days, $vara, '2026-11-02', '2026-11-06');

        expect($w)->toMatchArray(['new' => 4, 'judged' => 3, 'winners' => 1, 'pct' => 33.3, 'spend' => 98.0])
            // 7×10−35 + 7×10−56 + 1,75×10−7
            ->and($w['gain'])->toBe(59.5);
    });
});

describe('.143: las subidas de presupuesto', function () use ($vara) {
    it('una semana que gastó por día 20 % o más que la anterior: si la ganancia por día aguantó', function () use ($vara) {
        $camp = fn (string $from, int $n, float $spend, float $buys) => array_map(fn ($d) => ['campaign' => 'c1'] + $d, diasDeAnuncio('x', $from, $n, $spend, $buys));
        $days = [...$camp('2026-10-26', 7, 20, 3), ...$camp('2026-11-02', 7, 30, 4), ...$camp('2026-11-09', 7, 33, 3)];
        $s = AiReview::scaleWeeks($days, $vara);

        // 20 → 30 por día (+50 %): ganaba 3×10−20 = $10 por día, ahora 4×10−30 = $10: aguantó; 30 → 33 es solo +10 %
        expect($s)->toHaveCount(1)->and($s[0])->toMatchArray(['week' => '2026-11-02', 'spend_before' => 20.0, 'spend_after' => 30.0, 'cpa_before' => 6.67,
            'cpa_after' => 7.5, 'gain_before' => 10.0, 'gain_after' => 10.0, 'held' => true]);
    });

    it('un arranque o un apagado (menos de 5 días con gasto) no es una subida', function () use ($vara) {
        $camp = fn (string $from, int $n, float $spend) => array_map(fn ($d) => ['campaign' => 'c1'] + $d, diasDeAnuncio('x', $from, $n, $spend, 1));

        expect(AiReview::scaleWeeks([...$camp('2026-10-30', 3, 10), ...$camp('2026-11-02', 7, 30)], $vara))->toBe([]);
    });
});

describe('.143: los mismos productos y las misiones', function () {
    it('los mismos productos: con gasto el 80 % de los días o más en los dos períodos', function () {
        expect(AiReview::sameProducts([1 => 51, 2 => 30, 3 => 45], [1 => 12, 2 => 12, 3 => 9, 4 => 12], 51, 12))->toBe([1]);
    });

    it('una misión repetida varios días seguidos es una sola; si vuelve después de un hueco, es otra', function () {
        expect(AiReview::streaks(['1:c1:scale' => ['2026-11-03', '2026-11-02', '2026-11-04', '2026-11-09'], '1:c2:decrease' => ['2026-11-05']]))->toBe([
            ['key' => '1:c1:scale', 'from' => '2026-11-02', 'to' => '2026-11-04', 'dates' => ['2026-11-02', '2026-11-03', '2026-11-04']],
            ['key' => '1:c2:decrease', 'from' => '2026-11-05', 'to' => '2026-11-05', 'dates' => ['2026-11-05']],
            ['key' => '1:c1:scale', 'from' => '2026-11-09', 'to' => '2026-11-09', 'dates' => ['2026-11-09']],
        ]);
    });

    it('sin hacerle caso: la IA tenía razón si lo que pedía frenar siguió perdiendo, o lo que pedía subir siguió en el C', function () {
        expect(AiReview::rightWhenIgnored('stop_creatives', 30, 2, 6, 10))->toBeTrue()    // CPA $15 > BE
            ->and(AiReview::rightWhenIgnored('decrease', 30, 4, 6, 10))->toBeFalse()      // CPA $7,50: no perdía
            ->and(AiReview::rightWhenIgnored('scale', 30, 6, 6, 10))->toBeTrue()          // CPA $5 ≤ C
            ->and(AiReview::rightWhenIgnored('scale', 30, 4, 6, 10))->toBeFalse()
            ->and(AiReview::rightWhenIgnored('stop_creatives', 0.5, 0, 6, 10))->toBeNull() // se apagó: no se sabe
            ->and(AiReview::rightWhenIgnored('few_batches', 30, 0, 6, 10))->toBeNull();   // lanzar batches no se juzga así
    });
});
