<?php

use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\AnalysisWriter;
use App\Services\Meta\Analysis\MoneyCheck;
use App\Services\Meta\Analysis\UrgentList;

// Etapa 5, paso 3a: el análisis de la mañana. La lista urgente la arman las reglas; la IA solo redacta.

function producto(int $id, string $name, int $level, float $risk, array $cities, array $alarms = [], ?string $watch = null): array
{
    return ['product_id' => $id, 'product' => $name, 'mode' => 'normal', 'urgency' => ['level' => $level, 'label' => "U{$level}", 'batches' => '1', 'reasons' => []],
        'money_at_risk' => $risk, 'next_work' => [], 'price' => [], 'price_watch' => $watch, 'alarms' => $alarms, 'tracking' => null,
        'cities' => $cities, 'creatives' => [], 'batches' => []];
}

function ciudad(string $id, string $city, string $key, string $label, ?string $price = null): array
{
    return ['campaign_meta_id' => $id, 'campaign' => null, 'city' => $city, 'notes' => [], 'budget_notes' => [], 'price_option' => $price,
        'action' => ['key' => $key, 'label' => $label, 'tone' => 'info', 'data' => '', 'reading' => "lectura {$label}", 'proposal' => "propuesta {$label}", 'from' => null, 'to' => null]];
}

describe('La lista urgente', function () {
    it('primero lo que está en el break-even o encima, después escalar y después el resto', function () {
        $facts = [
            producto(1, 'FlexiStep', 3, 0, [ciudad('c1', 'Caracas', 'scale', 'Subir $39,60 → $43,56', 'O probar bajar el precio $2,00'), ciudad('c2', 'Maracay', 'hold', 'Mantener')]),
            producto(2, 'Loli', 1, 60, [ciudad('c3', 'Caracas', 'drop', 'Dejar el producto aquí'), ciudad('c4', 'Valencia', 'decrease', 'Bajar $30,00 → $24,00'),
                ciudad('c5', 'Maracay', 'hold_prune', 'Mantener y podar')]),
            producto(3, 'Cervimax', 2, 20, [ciudad('c6', 'Caracas', 'intervene', 'Intervenir')], ['Tracking: Meta ve el 70 % de los pedidos reales…']),
        ];
        $list = UrgentList::build($facts);
        expect(array_map(fn ($i) => "{$i['product']} {$i['kind']}", $list))->toBe([
            // dentro del mismo grupo, el producto más urgente (Loli, U1) primero; y en un producto, la alarma de tracking antes de tocar campañas
            'Loli drop', 'Loli decrease', 'Cervimax tracking', 'Cervimax intervene', 'Loli hold_prune', 'FlexiStep scale', 'FlexiStep price',
        ]);
        expect(collect($list)->pluck('kind'))->not->toContain('hold');
    });

    it('entre iguales, el producto más urgente y con más dinero en riesgo primero', function () {
        $list = UrgentList::build([
            producto(1, 'A', 2, 10, [ciudad('a', 'Caracas', 'intervene', 'Intervenir')]),
            producto(2, 'B', 1, 5, [ciudad('b', 'Caracas', 'intervene', 'Intervenir')]),
            producto(3, 'C', 1, 50, [ciudad('c', 'Caracas', 'intervene', 'Intervenir')]),
        ]);
        expect(array_column($list, 'product'))->toBe(['C', 'B', 'A']);
    });

    it('las alarmas de stock y "no se vende aquí" son de lo más urgente; las claves no cambian entre versiones', function () {
        $facts = [producto(1, 'Solo Brillo', 4, 0, [ciudad('m', 'Maracay', 'none', 'No se vende aquí'), ciudad('x', 'Caracas', 'none', 'Nada')],
            ['Sin stock en Caracas y la campaña sigue activa.', 'Sin stock en Caracas de: S, M.'])];
        $list = UrgentList::build($facts);
        expect(array_column($list, 'kind'))->toBe(['stock_out', 'not_sold', 'alarm'])
            ->and(UrgentList::build($facts)[1]['key'])->toBe($list[1]['key']);
    });
});

describe('Que la IA no invente montos', function () {
    it('lee los montos como se escriben en español', function () {
        expect(MoneyCheck::amounts('subir $39,60 → $43,56; gastó $1.412,06 y $30; o $2.5'))->toBe([39.6, 43.56, 1412.06, 30.0, 2.5]);
    });

    it('avisa de los que no estaban en los datos', function () {
        $datos = 'Caracas: Subir $39,60 → $43,56 · C $4,00';
        expect(MoneyCheck::unknown('Sube Caracas de $39,60 a $43,56 (C de $4).', $datos))->toBe([])
            ->and(MoneyCheck::unknown('Sube Caracas a $50,00.', $datos))->toBe(['$50,00']);
    });
});

describe('Lo que recibe la IA y la plantilla sin IA', function () {
    $facts = [producto(2, 'Loli', 1, 60, [ciudad('c4', 'Valencia', 'decrease', 'Bajar $30,00 → $24,00')], ['Tracking: Meta ve el 70 %…'])];
    $facts[0]['creatives'] = [['id' => 9, 'label' => 'C004 NERV', 'verdict' => 'Perdedor', 'verdict_key' => 'loser', 'tone' => 'error', 'reading' => 'Perdedor: gastó 2,5×C',
        'proposal' => 'Apagarlo en Valencia.', 'cross' => null, 'funnel' => 'Arriba del embudo', 'week' => ['spend' => 10.0, 'purchases' => 0.0, 'cpa' => null],
        'cities' => [], 'video' => ['window' => '3 días', 'diagnosis' => ['Hook flojo y hold bueno: iterar los primeros segundos.'], 'iterate' => 'hook'], 'status' => 'loser']];

    it('un bloque por producto con lo que dijeron las reglas', function () use ($facts) {
        $in = AnalysisFacts::input($facts);
        expect($in)->toContain('== PRODUCTO: Loli')->toContain('Ciudad Valencia: Bajar $30,00 → $24,00')->toContain('Creativo #1 C004 NERV: Perdedor')
            ->toContain('video: Hook flojo')->toContain('Alarma: Tracking');
    });

    it('sin IA arma el mismo formato con las reglas', function () use ($facts) {
        $t = AnalysisWriter::example($facts, UrgentList::build($facts));
        expect($t['resumen'])->toContain('Loli en Valencia: Bajar $30,00 → $24,00')
            ->and($t['productos'][0]['ciudades'][0]['ciudad'])->toBe('Valencia')
            ->and($t['productos'][0]['decisiones_creativas'][0])->toStartWith('P1 C004 NERV: Perdedor');
    });
});

describe('La tarea del calendario', function () {
    it('se tilda sola al abrir el análisis de ese día', function () {
        $o = ReminderRules::occurrence('meta_analysis', '2026-11-02', '2026-11-02', ['meta_analysis_days' => ['2026-11-02' => '7:12']]);
        expect($o['auto_done'])->toBeTrue()->and($o['auto_note'])->toBe('Lo abriste a las 7:12.')->and($o['link'])->toBe('/meta-ads/analisis');
        expect(ReminderRules::occurrence('meta_analysis', '2026-11-03', '2026-11-03', [])['auto_done'])->toBeFalse();
    });
});
