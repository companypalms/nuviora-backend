<?php

use App\Models\AgencyClosureSetting;
use App\Services\Delivery\Closures\ClosureGap;

// .136 (dueño, 2026-11-11): "que a mí me diga de una manera notable cuánto es lo que el sistema determina que le debo
// pagar a la agencia y cuánto dicen ellos, para yo saber qué tanta diferencia hay".

it('las partes suman justo la diferencia entre lo que cobra la agencia y el cálculo interno', function () {
    $rows = [
        ['kind' => null, 'reported' => 5.0, 'expected' => 5.0],
        ['kind' => null, 'reported' => 5.2, 'expected' => 5.0],       // dentro de la tolerancia
        ['kind' => 'amount', 'reported' => 9.0, 'expected' => 6.0],
        ['kind' => 'amount', 'reported' => null, 'expected' => 4.0],  // sin monto en el archivo
        ['kind' => 'unmatched', 'reported' => 7.0, 'expected' => null],
        ['kind' => 'ambiguous', 'reported' => 3.0, 'expected' => null],
        ['kind' => 'duplicate', 'reported' => 5.0, 'expected' => 5.0],
        ['kind' => 'no_expected', 'reported' => 2.5, 'expected' => null],
    ];
    // Como ClosureReview::refreshTotals: todo lo que cobra, menos lo calculado de las emparejadas (sin las repetidas)
    $reported = 5 + 5.2 + 9 + 0 + 7 + 3 + 5 + 2.5;
    $expected = 5 + 5 + 6 + 4;

    $parts = ClosureGap::parts($rows, 3);

    expect(array_column($parts, 'key'))->toBe(['amount', 'unmatched', 'duplicate', 'no_expected', 'small', 'not_charged'])
        ->and(array_column($parts, 'text'))->toBe(['2 carreras con otro monto', '2 filas que el sistema no encontró', '1 carrera cobrada dos veces',
            '1 carrera sin cálculo del sistema', 'Centavos dentro de la tolerancia', '3 carreras del sistema que la agencia no cobró'])
        ->and(array_column($parts, 'amount'))->toBe([-1.0, 10.0, 5.0, 2.5, 0.2, null])
        ->and(round(array_sum(array_column($parts, 'amount')), 2))->toBe(round($reported - $expected, 2));
});

it('sin diferencias no hay partes; lo que cuadra justo no se muestra', function () {
    expect(ClosureGap::parts([['kind' => null, 'reported' => 5.0, 'expected' => 5.0]]))->toBe([])
        ->and(ClosureGap::parts([]))->toBe([]);
});

it('cobra de más, de menos o cuadra, con la tolerancia de la agencia', function () {
    $setting = new AgencyClosureSetting(['tolerance_amount' => 1, 'tolerance_percent' => null]);

    expect(ClosureGap::verdict(100, 112.5, $setting))->toBe('over')
        ->and(ClosureGap::verdict(100, 97, $setting))->toBe('under')
        ->and(ClosureGap::verdict(100, 100.8, $setting))->toBe('ok')
        ->and(ClosureGap::verdict(null, 50, $setting))->toBeNull()
        ->and(ClosureGap::verdict(100, null, $setting))->toBeNull();
});

it('el marcador: la semana más reciente, lo mismo con lo mismo (solo las que subieron su archivo)', function () {
    $c = fn (string $start, ?float $expected, ?float $reported, ?float $provisional, ?string $verdict) => [
        'period_start' => $start, 'week' => $start === '2026-11-02' ? '2–8 noviembre' : '26 octubre – 1 noviembre',
        'expected_total' => $expected, 'reported_total' => $reported, 'provisional' => $provisional, 'gap_verdict' => $verdict,
    ];
    $week = ClosureGap::week([
        $c('2026-11-02', 100, 112.5, null, 'over'),
        $c('2026-11-02', 80, 80, null, 'ok'),
        $c('2026-11-02', null, null, 45, null),
        $c('2026-10-26', 300, 400, null, 'over'),
    ]);

    expect($week)->toBe(['period_start' => '2026-11-02', 'week' => '2–8 noviembre', 'agencies' => 3, 'with_file' => 2,
        'system' => 180.0, 'provisional' => false, 'reported' => 192.5, 'difference' => 12.5, 'over' => 1])
        ->and(ClosureGap::week([]))->toBeNull()
        // ninguna subió su archivo: lo que ganaron por sus carreras
        ->and(ClosureGap::week([$c('2026-11-02', null, null, 45, null)]))->toMatchArray(['system' => 45.0, 'provisional' => true, 'reported' => null, 'difference' => null]);
});
