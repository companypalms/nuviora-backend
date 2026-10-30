<?php

use App\Services\Finance\FinancialCpa;

// CPA objetivo y break-even reales (pedido del dueño, 2026-10-30): gastos fijos adentro, utilidad después de los fijos,
// la cohorte más prudente entre 7 y 28 días y, para una ciudad sin muestra, el número de su producto.

/** Una semana como la de FlexiStep del 17 al 23-10: $27,40 cobrados y $7,88 de costos por entrega (envíos completados). */
function flexistepWeek(array $over = []): array
{
    return $over + ['created' => 199, 'delivered' => 92, 'revenue' => 2520.80, 'variable' => 724.96, 'other_variable' => 0.0,
        'cogs' => 250.24, 'purchases' => 195, 'spend' => 763.36];
}

describe('El % de gastos fijos', function () {
    it('usa el mínimo mientras lo real sea menor', function () {
        $f = FinancialCpa::fixedPct(1000, 20000, 10);
        expect($f['pct'])->toBe(10.0)->and($f['source'])->toBe('minimum')->and($f['real_pct'])->toBe(5.0);
    });

    it('usa lo real cuando pasa del mínimo', function () {
        $f = FinancialCpa::fixedPct(3000, 20000, 10);
        expect($f['pct'])->toBe(15.0)->and($f['source'])->toBe('real');
    });

    it('sin ventas no hay % real: queda el mínimo; y nunca pasa del 100 %', function () {
        expect(FinancialCpa::fixedPct(500, 0, 10))->toMatchArray(['pct' => 10.0, 'source' => 'minimum', 'real_pct' => null]);
        expect(FinancialCpa::fixedPct(5000, 1000, 10)['pct'])->toBe(100.0);
    });
});

describe('El objetivo y el break-even', function () {
    it('apartan los gastos fijos y dejan la utilidad después de ellos (cálculo a mano)', function () {
        $e = FinancialCpa::economics(flexistepWeek(), 10, ['type' => 'sales', 'pct' => 20]);
        // Quedan 2520,80 − 724,96 − 252,08 (10 %) = 1543,76 → ÷ 195 compras de Meta
        expect($e['break_even'])->toBe(7.92)
            ->and($e['target'])->toBe(5.33)            // (1543,76 − 504,16 de utilidad) ÷ 195
            ->and($e['fully_loaded'])->toBe(7.92)      // ya incluye los gastos fijos
            ->and($e['meta_cpa'])->toBe(3.91)
            ->and($e['effectiveness'])->toBe(46.23)
            ->and($e['viable'])->toBeTrue();
    });

    it('suma los otros variables a los costos', function () {
        $e = FinancialCpa::economics(flexistepWeek(['other_variable' => 195.0]), 10, ['type' => 'sales', 'pct' => 20]);
        expect($e['variable_costs'])->toBe(919.96)->and($e['break_even'])->toBe(6.92);
    });

    it('con la utilidad sobre el costo usa el costo del producto', function () {
        $e = FinancialCpa::economics(flexistepWeek(), 10, ['type' => 'cost', 'pct' => 20]);
        expect($e['target'])->toBe(7.66); // (1543,76 − 50,05) ÷ 195
    });

    it('si no queda nada para publicidad: $0 y no viable (§68)', function () {
        $e = FinancialCpa::economics(['created' => 10, 'delivered' => 5, 'revenue' => 100, 'variable' => 90, 'cogs' => 40, 'purchases' => 8, 'spend' => 20],
            10, ['type' => 'sales', 'pct' => 20]);
        expect($e['break_even'])->toBe(0.0)->and($e['target'])->toBe(0.0)->and($e['viable'])->toBeFalse();
    });

    it('sin compras de Meta no hay CPA', function () {
        $e = FinancialCpa::economics(flexistepWeek(['purchases' => 0]), 10, ['type' => 'sales', 'pct' => 20]);
        expect($e['break_even'])->toBeNull()->and($e['target'])->toBeNull()->and($e['meta_cpa'])->toBeNull()->and($e['viable'])->toBeNull();
    });
});

describe('Qué cohorte vale', function () {
    $e = fn (float $purchases, ?float $target, ?float $be = null) => ['purchases' => $purchases, 'target' => $target, 'break_even' => $be ?? $target];

    it('con muestra en 7 días, la más prudente de las dos', function () use ($e) {
        expect(FinancialCpa::pick($e(40, 5.33, 7.92), $e(160, 6.10, 8.80), 30))->toBe('short');  // la semana empeoró: se nota ya
        expect(FinancialCpa::pick($e(40, 6.10, 8.80), $e(160, 4.90, 7.10), 30))->toBe('long');   // mejoró: no se confía todavía
        expect(FinancialCpa::pick($e(40, 5.33), null, 30))->toBe('short');
    });

    it('con pocas compras en 7 días usa los 28', function () use ($e) {
        expect(FinancialCpa::pick($e(12, 3.00), $e(50, 5.00), 30))->toBe('long');
    });

    it('una ciudad sin muestra en ninguna usa su producto', function () use ($e) {
        expect(FinancialCpa::pick($e(5, 9.00), $e(20, 8.00), 30, $e(300, 5.50)))->toBe('product');
    });

    it('sin producto, la que tenga datos', function () use ($e) {
        expect(FinancialCpa::pick($e(5, 9.00), $e(20, 8.00), 30))->toBe('long');
        expect(FinancialCpa::pick($e(3, 9.00), null, 30))->toBe('short');
        expect(FinancialCpa::pick(null, $e(0, null), 30))->toBe('long');
    });

    it('con mínimo 0 basta una compra', function () use ($e) {
        expect(FinancialCpa::pick($e(1, 2.00), $e(4, 3.00), 0))->toBe('short');
    });
});
