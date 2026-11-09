<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .126 (dueño, 2026-11-09): "pesa más ganar en dólares, la ganancia siempre va a pesar más". La ganancia estimada de un gasto es
// compras de Meta × BE − gasto. Se muestra en cada campaña, creativo y producto, y ordena los productos, los creativos y las
// misiones de subir. Ninguna regla de apagar, pausar o subir cambia. Aquí: ZenPulse en Caracas (C $4,44, BE $7,61) y en Aragua
// (C $2,35, BE $4,25), y Cervimax en Carabobo (C $6,74, BE $10,93), con sus C y BE de producción del 9/11.

const GANA_ZEN_CCS = '120000000000000121';
const GANA_ZEN_MCAY = '120000000000000122';
const GANA_CERVI = '120000000000000123';

function ganaVentanas(float $spend, float $purchases): array
{
    $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 14000, 'reach' => 10000]]);
    $x = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];

    return ['max' => $x, '7d' => $x, '3d' => $x, 'prev' => $x, 'yesterday' => $x];
}

function ganaMeta(): array
{
    $campaign = fn (string $id, string $name, int $cityId, string $city, float $budget, float $c, float $be, array $w) => ['meta_id' => $id, 'name' => $name, 'city_id' => $cityId, 'city' => $city,
        'active' => true, 'daily_budget' => $budget, 'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => ganaVentanas(...$w)];
    $product = function (int $id, string $name, array $campaigns, array $ads) {
        $out = ['product' => ['id' => $id, 'name' => $name, 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'campaigns' => $campaigns, 'adsets' => [], 'ads' => [], 'creatives' => []];
        // [campaña, ad set, anuncio, creativo, gasto, compras]: dos batches vivos por campaña, para que no pida lanzar otro
        foreach ($ads as $i => [$campaignId, $set, $label, $cid, $spend, $purchases]) {
            $adId = '12000000000000' . ($id * 10 + $i);
            $out['adsets'][$set] ??= ['meta_id' => $set, 'name' => strtoupper($set), 'campaign_meta_id' => $campaignId, 'active' => true, 'missing' => false, 'batch_code' => null,
                'angle_code' => null, 'concept' => null, 'stage' => null, 'min_spend' => 0.0, 'created_time' => '2026-10-10 10:00:00', 'windows' => []];
            $out['ads'][$adId] = ['id' => $id * 10 + $i, 'meta_id' => $adId, 'name' => $label, 'campaign_meta_id' => $campaignId, 'adset_meta_id' => $set, 'creative_id' => $cid,
                'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => true, 'created_time' => '2026-10-10 10:00:00',
                'windows' => ganaVentanas($spend, $purchases)];
            $out['creatives'][$cid] ??= ['id' => $cid, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
                'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => []];
            $out['creatives'][$cid]['ads'][] = $adId;
        }

        return $out;
    };

    return [
        1149 => $product(1149, 'ZenPulse® Masajeador', [
            GANA_ZEN_CCS => $campaign(GANA_ZEN_CCS, 'ZENPULSE | CCS | CBO | 27/10/2026', 1, 'Distrito Capital', 39.6, 4.44, 7.61, [90.0, 30]),
            GANA_ZEN_MCAY => $campaign(GANA_ZEN_MCAY, 'ZENPULSE | MCAY | CBO | 27/10/2026', 3, 'Aragua', 30.0, 2.35, 4.25, [66.0, 30]),
        ], [
            // C0401 vende más compras, pero en Aragua (cada compra deja $4,25); C0402 vende menos, en Caracas ($7,61 cada una)
            [GANA_ZEN_MCAY, 'zm1', 'C0401 ZEN', 1, 44.0, 20], [GANA_ZEN_CCS, 'zc1', 'C0402 ZEN', 2, 50.0, 15],
            // C0403 pierde en Caracas: $40 con 3 compras ($13,33 cada una)
            [GANA_ZEN_CCS, 'zc2', 'C0403 ZEN', 3, 40.0, 3], [GANA_ZEN_MCAY, 'zm2', 'C0404 ZEN', 4, 22.0, 10],
        ]),
        1147 => $product(1147, 'Cervimax® Almohada', [
            GANA_CERVI => $campaign(GANA_CERVI, 'CERVIMAX | CBOBO | CBO | 14/10/2026', 7, 'Carabobo', 28.75, 6.74, 10.93, [60.0, 12]),
        ], [[GANA_CERVI, 'cv1', 'C0301 CERVI', 5, 30.0, 6], [GANA_CERVI, 'cv2', 'C0302 CERVI', 6, 30.0, 6]]),
    ];
}

function ganaDecide(): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn(ganaMeta());
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null];
    $p = ['price' => 25.0, 'price_change' => null, 'version' => null, 'cities' => [1 => $city, 3 => $city, 7 => $city], 'bank' => ['codes' => [], 'ready' => [], 'launched' => []]];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [GANA_ZEN_CCS => $empty, GANA_ZEN_MCAY => $empty, GANA_CERVI => $empty]]);
    $ops->allows('products')->andReturn([1149 => $p, 1147 => $p]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1149, 1147]);
}

function ganaFacts(): array
{
    return AnalysisFacts::build(ganaDecide(), ['campaigns' => [GANA_ZEN_CCS => 'ZENPULSE | CCS | CBO | 27/10/2026', GANA_ZEN_MCAY => 'ZENPULSE | MCAY | CBO | 27/10/2026',
        GANA_CERVI => 'CERVIMAX | CBOBO | CBO | 14/10/2026']]);
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 00:30:00'));
});

describe('.126: la ganancia estimada (PHP puro)', function () {
    it('compras de Meta × BE − gasto; sin BE no se puede calcular', function () {
        // Solo Brillo Caracas el 9/11: $450 y 109 compras en 3 días, con BE $13,76
        expect(MetaDecisions::gain(450.0, 109, 13.76))->toBe(1049.84)
            ->and(MetaDecisions::gain(95.3, 6, 7.49))->toBe(-50.36)
            ->and(MetaDecisions::gain(450.0, 109, null))->toBeNull();
    });

    it('un creativo suma cada ciudad con su BE; un producto, sus campañas', function () {
        $w = fn (float $s, float $p) => ['7d' => ['totals' => ['spend' => $s, 'purchases' => $p]], 'max' => ['totals' => ['spend' => $s * 3, 'purchases' => $p * 3]]];
        $campaigns = ['a' => ['break_even' => 7.61], 'b' => ['break_even' => 4.25], 'c' => ['break_even' => null]];
        expect(MetaDecisions::creativeGain(['a' => $w(50, 15), 'b' => $w(44, 20), 'c' => $w(30, 9)], $campaigns))->toBe(['week' => 105.15, 'life' => 315.45])
            ->and(MetaDecisions::creativeGain(['c' => $w(30, 9)], $campaigns))->toBe(['week' => null, 'life' => null]);

        $c = fn (float $be, float $s, float $p) => ['break_even' => $be, 'windows' => ['3d' => ['totals' => ['spend' => $s, 'purchases' => $p]], '7d' => ['totals' => ['spend' => $s * 2, 'purchases' => $p * 2]]]];
        expect(MetaDecisions::productGain([$c(7.61, 90, 30), $c(4.25, 66, 30), ['break_even' => null, 'windows' => []]]))
            ->toBe(['d3' => 199.8, 'd7' => 399.6, 'spend3' => 156.0, 'spend7' => 312.0])
            ->and(MetaDecisions::productGain([['break_even' => null]]))->toBeNull();
    });
});

describe('.126: cada campaña, cada creativo y cada producto dicen lo que dejan', function () {
    it('la campaña trae lo que dejó en 3 y en 7 días; el producto, la suma', function () {
        $d = ganaDecide();

        expect($d[1149]['campaigns'][GANA_ZEN_CCS]['numbers'])->toMatchArray(['gain3' => 138.3, 'gain7' => 138.3])
            ->and($d[1149]['campaigns'][GANA_ZEN_MCAY]['numbers']['gain3'])->toBe(61.5)
            ->and($d[1149]['plan']['gain'])->toBe(['d3' => 199.8, 'd7' => 199.8, 'spend3' => 156.0, 'spend7' => 156.0])
            ->and($d[1147]['plan']['gain']['d3'])->toBe(71.16);
    });

    it('el ganador del producto es el que más dólares deja, no el que más compras tiene', function () {
        $d = ganaDecide()[1149];

        // C0401 tiene 20 compras (deja $41,00); C0402, 15 (deja $64,15)
        expect($d['creatives'][1]['gain'])->toBe(['week' => 41.0, 'life' => 41.0])
            ->and($d['creatives'][2]['gain'])->toBe(['week' => 64.15, 'life' => 64.15])
            ->and($d['creatives'][1]['verdict']['key'])->toBe('proven')->and($d['creatives'][2]['verdict']['key'])->toBe('proven')
            ->and($d['plan']['work']['winner'])->toMatchArray(['id' => 2, 'label' => 'C0402 ZEN', 'gain' => 64.15])
            ->and($d['plan']['next_work'][0])->toStartWith('Iteraciones de C0402 ZEN');
    });

    it('en el análisis: los productos y los creativos van del que más deja al que menos, con su línea en dólares', function () {
        $f = ganaFacts();

        expect(array_column($f, 'product'))->toBe(['ZenPulse® Masajeador', 'Cervimax® Almohada'])
            ->and($f[0]['gain']['d7'])->toBe(199.8)
            // C0403 pierde: va al final (por gasto iría segundo)
            ->and(array_column($f[0]['creatives'], 'label'))->toBe(['C0402 ZEN', 'C0401 ZEN', 'C0404 ZEN', 'C0403 ZEN'])
            ->and($f[0]['creatives'][0]['numbers'])->toContain('Dejó unos $64,15 en 7 días · dejó unos $64,15 en toda su vida (compras × tu BE − gasto)')
            ->and($f[0]['creatives'][3]['numbers'])->toContain('Perdió unos $17,17 en 7 días · perdió unos $17,17 en toda su vida (compras × tu BE − gasto)')
            ->and($f[0]['creatives'][3]['gain'])->toBe(['week' => -17.17, 'life' => -17.17]);
        expect(CreativeCalls::left(64.15))->toBe('Dejó unos $64,15')->and(CreativeCalls::left(-17.17))->toBe('Perdió unos $17,17');
    });

    it('lo que le llega al editor sigue sin montos', function () {
        foreach (ganaFacts() as $p) {
            foreach ($p['creatives'] as $cr) {
                expect((string) ($cr['call']['editor'] ?? ''))->not->toContain('$');
            }
        }
    });
});

describe('.126: las misiones de subir, primero la que más deja', function () {
    it('ZenPulse Caracas ($46,10 por día), Cervimax Carabobo ($23,72) y ZenPulse Aragua ($20,50), aunque sean de productos distintos', function () {
        $scale = array_values(array_filter(UrgentList::build(ganaFacts()), fn ($m) => $m['kind'] === 'scale'));

        expect(array_column($scale, 'where'))->toBe(['ZenPulse · Distrito Capital', 'Cervimax · Carabobo', 'ZenPulse · Aragua'])
            ->and($scale[0]['why'])->toBe('+20 % · $3,00 por compra; tu objetivo es $4,44 · deja unos $46,10 por día')
            ->and($scale[0]['detail'])->toContain('En 3 días dejó unos $138,30 (compras × tu BE − gasto): unos $46,10 por día.')
            ->and($scale[0])->not->toHaveKey('_gain');
    });

    it('una campaña que pierde lo dice en su detalle', function () {
        $city = ['campaign_meta_id' => GANA_ZEN_CCS, 'campaign' => 'ZENPULSE | CCS', 'city' => 'Distrito Capital', 'notes' => [], 'budget_notes' => [], 'price_option' => null,
            'action' => ['key' => 'intervene', 'label' => 'Intervenir', 'tone' => 'warning', 'data' => '', 'reading' => 'En el break-even o encima.', 'proposal' => 'Intervenir.', 'from' => null, 'to' => null],
            'numbers' => ['spend3' => 95.3, 'purchases3' => 6, 'c' => 5.01, 'be' => 7.49, 'source' => null, 'budget' => 25.0, 'streak' => 3, 'prune' => ['C0403 ZEN'], 'live' => 2, 'offers' => [],
                'gain3' => -50.36, 'be_estimated' => 1.5]];
        $m = UrgentList::build([['product_id' => 1149, 'product' => 'ZenPulse', 'mode' => 'normal', 'urgency' => ['level' => 1, 'label' => 'U1', 'batches' => '1', 'reasons' => []],
            'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [$city], 'creatives' => [], 'batches' => []]]);

        expect(collect($m)->firstWhere('kind', 'intervene')['detail'])->toContain('En 3 días perdió unos $50,36 (compras × tu BE estimado − gasto): unos $16,79 por día.');
    });
});
