<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .121 (dueño, 2026-11-09: "analiza todas las reglas que tenemos actualmente para pulirlas y evitar errores como estos"). Con
// los números de producción de ese día: FlexiStep CRE001 seguía "PROVEN" en Carabobo con la semana en $14,03 por compra.

const FLEXI_CARABOBO = '120000000000000081';
const FLEXI_CARACAS = '120000000000000082';

/** Por creativo: nombre y, por campaña, [vida, 7 días, 3 días] (gasto, compras). */
function flexiRecentCreatives(): array
{
    return [
        1 => ['CRE001-FLEX-FAS-BATCH004', [FLEXI_CARABOBO => [51.47, 10, 28.06, 2, 7.83, 0], FLEXI_CARACAS => [102.86, 27, 28.39, 3, 10.47, 2]]],
        // Perdió $60,00 con 3 compras; en los últimos 3 días vendió 2 a $3,00
        2 => ['C0300 FAS', [FLEXI_CARABOBO => [60.0, 3, 30.0, 2, 6.0, 2]]],
        // Igual, pero con 1 sola compra en los 3 días
        3 => ['C0301 FAS', [FLEXI_CARABOBO => [60.0, 3, 30.0, 2, 3.0, 1]]],
        // Prometedor (4 compras a $1,50) que dejó de vender: $14,00 en 3 días sin compras
        4 => ['C0302 FAS', [FLEXI_CARABOBO => [20.0, 4, 14.0, 0, 14.0, 0]]],
    ];
}

function flexiRecentMeta(?float $tracking = null): array
{
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $windows = fn (array $n) => ['max' => $w($n[0], $n[1]), '7d' => $w($n[2], $n[3]), '3d' => $w($n[4], $n[5]), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $out = ['product' => ['id' => 1148, 'name' => 'FlexiStep', 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'campaigns' => [], 'adsets' => [], 'ads' => [], 'creatives' => []]
        + ($tracking !== null ? ['tracking' => ['meta_purchases' => 30.0, 'orders' => 50, 'ratio' => $tracking]] : []);
    foreach ([FLEXI_CARABOBO => ['Carabobo', 7, 6.67, 10.49], FLEXI_CARACAS => ['Distrito Capital', 1, 4.87, 7.21]] as $id => [$city, $cityId, $c, $be]) {
        $id = (string) $id; // como llave de un arreglo, PHP lo vuelve int; en AdFacts llega como texto
        $out['campaigns'][$id] = ['meta_id' => $id, 'name' => "FLEXISTEP | {$city}", 'city_id' => $cityId, 'city' => $city, 'active' => true, 'daily_budget' => 30.0,
            'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows([900, 180, 200, 36, 88, 16])];
        $out['adsets']["{$id}9"] = ['meta_id' => "{$id}9", 'name' => 'BATCH', 'campaign_meta_id' => $id, 'active' => true, 'missing' => false,
            'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
    }
    foreach (flexiRecentCreatives() as $cid => [$label, $cities]) {
        $out['creatives'][$cid] = ['id' => $cid, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => []];
        foreach ($cities as $campaign => $n) {
            $campaign = (string) $campaign;
            $adId = "{$campaign}{$cid}";
            $out['ads'][$adId] = ['id' => (int) substr($adId, -4), 'meta_id' => $adId, 'name' => $label, 'campaign_meta_id' => $campaign, 'adset_meta_id' => "{$campaign}9",
                'creative_id' => $cid, 'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => true,
                'created_time' => '2026-10-14 15:11:25', 'windows' => $windows($n)];
            $out['creatives'][$cid]['ads'][] = $adId;
        }
    }

    return $out;
}

function flexiRecentDecision(?float $tracking = null): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1148 => flexiRecentMeta($tracking)]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [FLEXI_CARABOBO => $empty, FLEXI_CARACAS => $empty]]);
    $ops->allows('products')->andReturn([1148 => ['price' => 25.0, 'price_change' => null, 'cities' => [1 => $city, 7 => $city], 'version' => null,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1148])[1148];
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 08:10:00'));
});

describe('.121: un ganador que cae gastando poco (FlexiStep CRE001, 9/11)', function () {
    it('en Carabobo se juzga por la semana y se apaga ahí; en Caracas vende y sigue', function () {
        $cr = flexiRecentDecision()['creatives'][1];
        $in = fn (string $city) => collect($cr['cities'])->firstWhere('city', $city);

        expect($in('Carabobo')['key'])->toBe('failing')->and($in('Carabobo')['stop'])->toBeTrue()
            ->and($in('Carabobo')['data'])->toBe('7 días: gastó $28,06 (4,2×C) · 2 compras · CPA $14,03 · C $6,67 · BE $10,49')
            ->and($in('Carabobo')['reading'])->toStartWith('Pierde dinero: en los últimos 7 días gastó 4,2×C con el CPA 34 % encima del break-even.')
            ->and($in('Distrito Capital')['key'])->toBe('proven')->and($in('Distrito Capital'))->not->toHaveKey('stop')
            ->and(CreativeCalls::stopIn($cr))->toBe(['Carabobo']);
    });

    it('el análisis dice apagarlo en Carabobo e iterarlo', function () {
        $facts = AnalysisFacts::build([1148 => flexiRecentDecision()]);
        $call = collect($facts[0]['creatives'])->firstWhere('id', 1)['call'];

        expect($call['label'])->toBe('ITERAR · PARAR EN CARABOBO')
            ->and($call['todo'])->toBe('Apágalo en Carabobo (ahí pierde). Fue ganador: pide hooks nuevos.')
            ->and($facts[0]['stop_all'])->not->toContain('CRE001-FLEX-FAS-BATCH004');
    });
});

describe('.121: el que se recuperó y el prometedor que deja de vender', function () {
    it('un perdedor que en 3 días vendió 2 en C o debajo no se apaga ni se poda; con 1 sola compra, sí', function () {
        $d = flexiRecentDecision();
        $facts = AnalysisFacts::build([1148 => $d]);

        expect($d['creatives'][2]['verdict']['key'])->toBe('evaluation')
            ->and($d['creatives'][2]['verdict']['reading'])->toBe('Se recuperó: en los últimos 3 días vendió 2 compras con CPA $3,00, en C o debajo (en toda su vida: perdedor).')
            ->and($d['creatives'][3]['verdict']['key'])->toBe('loser')
            ->and($facts[0]['stop_all'])->toContain('C0301 FAS')->not->toContain('C0300 FAS')
            ->and($d['campaigns'][FLEXI_CARABOBO]['numbers']['prune'])->toContain('C0301 FAS')->not->toContain('C0300 FAS');
    });

    it('un prometedor con 3 días a 2×C sin compras pierde ahí', function () {
        $d = flexiRecentDecision();
        $cr = $d['creatives'][4];

        expect($cr['cities'][0]['key'])->toBe('loser')
            ->and($cr['cities'][0]['reading'])->toBe('Perdedor en los últimos 3 días: gastó 2,1×C sin compras (en toda su vida: prometedor).')
            ->and(CreativeCalls::stopIn($cr))->toBe(['Carabobo']);
    });

    it('las misiones: una sola de apagar en Carabobo, con los que pierden ahí', function () {
        $stop = collect(UrgentList::build(AnalysisFacts::build([1148 => flexiRecentDecision()])))->where('kind', 'stop_creatives')->values();

        expect($stop->pluck('title')->all())->toBe(['Apaga 2 creativos perdedores en todas las campañas', 'Apaga CRE001-FLEX-FAS-BATCH004: aquí pierde']);
    });
});

describe('.121: el tracking ya no deja prendido a un ganador que pierde', function () {
    it('si Meta ve el 60 % de los pedidos, se decide con las compras ajustadas: CRE001 en Carabobo sigue', function () {
        // 2 compras son ≈ 3,3 reales → CPA ≈ $8,42, debajo del BE de $10,49
        $carabobo = collect(flexiRecentDecision(60.0)['creatives'][1]['cities'])->firstWhere('city', 'Carabobo');

        expect($carabobo['key'])->toBe('failing')->and($carabobo)->not->toHaveKey('stop');
    });

    it('si Meta ve de más (130 %), nada lo salva', function () {
        $carabobo = collect(flexiRecentDecision(130.0)['creatives'][1]['cities'])->firstWhere('city', 'Carabobo');

        expect($carabobo['stop'])->toBeTrue()->and(array_column($carabobo['causes'], 'key'))->toContain('tracking');
    });
});
