<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .113 (dueño, 2026-11-07): ZenPulse C0153 salió "Apaga C0153 en todas las campañas" porque perdió en Aragua ($19,41 con
// 2 compras, CPA $9,71, C $4,12 y BE $7,05), pero en Carabobo seguía prendido con $1,15 gastados: "todavía no ha gastado
// suficiente como para poder determinarlo perdedor o ganador". Los números son los de producción de ese día.

/** Lo que leería AdFacts de ZenPulse: un anuncio de C0153 por ciudad. Cada ciudad: [vida, 7 días, 3 días] (gasto, compras) y si está prendido. */
function c0153Meta(array $cities = []): array
{
    $cities += [
        'Distrito Capital' => null,
        'Carabobo' => [[1.15, 0, 1.15, 0, 1.15, 0], true],
        'Aragua' => [[19.41, 2, 17.75, 2, 17.75, 2], true],
    ];
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $windows = fn (array $n) => ['max' => $w($n[0], $n[1]), '7d' => $w($n[2], $n[3]), '3d' => $w($n[4], $n[5]), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $meta = ['Distrito Capital' => ['120000000000000041', 1, 5.65, 9.27], 'Carabobo' => ['120000000000000042', 7, 4.12, 7.05], 'Aragua' => ['120000000000000043', 3, 4.12, 7.05]];
    $out = ['product' => ['id' => 1149, 'name' => 'ZenPulse', 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'campaigns' => [], 'adsets' => [], 'ads' => [],
        'creatives' => [153 => ['id' => 153, 'label' => 'C0153', 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => []]]];
    foreach ($meta as $city => [$id, $cityId, $c, $be]) {
        $out['campaigns'][$id] = ['meta_id' => $id, 'name' => "ZENPULSE | {$city}", 'city_id' => $cityId, 'city' => $city, 'active' => true, 'daily_budget' => 10.0,
            'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows([20, 4, 10, 2, 6, 1])];
        $out['adsets']["{$id}9"] = ['meta_id' => "{$id}9", 'name' => 'BATCH 004| SIN ANGULO', 'campaign_meta_id' => $id, 'active' => true, 'missing' => false,
            'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
        if ($cities[$city] === null) {
            continue;
        }
        [$n, $active] = $cities[$city];
        $out['ads']["{$id}1"] = ['id' => (int) substr($id, -4), 'meta_id' => "{$id}1", 'name' => 'C0153', 'campaign_meta_id' => $id, 'adset_meta_id' => "{$id}9",
            'creative_id' => 153, 'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => $active,
            'created_time' => '2026-11-04 19:44:47', 'windows' => $windows($n)];
        $out['creatives'][153]['ads'][] = "{$id}1";
    }

    return $out;
}

function c0153Decision(array $cities = []): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1149 => c0153Meta($cities)]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => ['120000000000000041' => $empty, '120000000000000042' => $empty, '120000000000000043' => $empty]]);
    $ops->allows('products')->andReturn([1149 => ['price' => 25.0, 'price_change' => null, 'cities' => [1 => $city, 3 => $city, 7 => $city], 'version' => null,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1149])[1149];
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-07 07:37:00'));
});

describe('.113: se apaga solo donde perdió; "en todas las campañas" solo si perdió en todas donde sigue prendido', function () {
    it('ZenPulse C0153: pierde en Aragua y en Carabobo todavía se prueba, así que se apaga solo en Aragua', function () {
        $d = c0153Decision();
        $cr = $d['creatives'][153];
        $cities = collect($cr['cities'])->keyBy('city');

        expect($cities['Aragua']['key'])->toBe('loser')
            ->and($cities['Aragua']['reading'])->toBe('Perdedor: llegó a 4,7×C con el CPA en el break-even o encima.')
            ->and($cities['Carabobo']['key'])->toBe('no_data')
            ->and($cr['verdict']['key'])->toBe('evaluation')
            ->and($cr['verdict']['reading'])->toBe('Pierde en Aragua; en Carabobo todavía no gastó 1×C.')
            ->and($cr['verdict']['proposal'])->toBe('Apagarlo solo en Aragua. En Carabobo no lo toques: todavía se prueba (se juzga ahí desde $4,12).')
            ->and($cr['cross'])->toBeNull()
            ->and(CreativeCalls::stopIn($cr))->toBe(['Aragua'])
            ->and(MetaDecisions::autoStatus($cr, '2026-11-07', DecisionSettings::defaults(), '2026-11-07')[0])->toBe('testing');

        $facts = AnalysisFacts::build([1149 => $d]);
        $call = collect($facts[0]['creatives'])->firstWhere('label', 'C0153')['call'];
        expect($facts[0]['stop_all'])->toBe([])
            ->and($facts[0]['stop_by_city'])->toBe(['Aragua' => ['C0153']])
            ->and($call['label'])->toBe('MANTENER · PARAR EN ARAGUA')
            ->and($call['todo'])->toBe('Apágalo en Aragua (ahí pierde). En Carabobo no lo toques: todavía se prueba (se juzga ahí desde $4,12).');

        $stops = collect(UrgentList::build($facts))->where('kind', 'stop_creatives')->values();
        expect($stops)->toHaveCount(1)
            ->and($stops[0]['city'])->toBe('Aragua')
            ->and($stops[0]['title'])->toBe('Apaga C0153: aquí pierde');
    });

    it('si en Carabobo ya está apagado, sí es perdedor y se apaga en todas (como antes)', function () {
        $d = c0153Decision(['Carabobo' => [[1.15, 0, 1.15, 0, 1.15, 0], false]]);
        $cr = $d['creatives'][153];
        $facts = AnalysisFacts::build([1149 => $d]);

        expect($cr['verdict']['key'])->toBe('loser')
            ->and($cr['verdict']['proposal'])->toBe('Apagarlo en todas las ciudades.')
            ->and($facts[0]['stop_all'])->toBe(['C0153'])
            ->and(collect(UrgentList::build($facts))->firstWhere('kind', 'stop_creatives')['title'])->toBe('Apaga C0153 en todas las campañas');
    });

    it('perdió en 2 ciudades y en la tercera todavía se prueba: ahí se deja probando (lo eligió el dueño)', function () {
        $d = c0153Decision(['Distrito Capital' => [[2.10, 0, 2.10, 0, 2.10, 0], true], 'Carabobo' => [[9.0, 1, 9.0, 1, 9.0, 1], true]]);
        $cr = $d['creatives'][153];

        expect(collect($cr['cities'])->pluck('key', 'city')->all())->toBe(['Distrito Capital' => 'no_data', 'Carabobo' => 'loser', 'Aragua' => 'loser'])
            ->and($cr['verdict']['key'])->toBe('evaluation')
            ->and($cr['cross'])->toBeNull()
            ->and($cr['verdict']['reading'])->toBe('Pierde en Carabobo y Aragua; en Distrito Capital todavía no gastó 1×C.')
            ->and($cr['verdict']['proposal'])->toBe('Apagarlo solo en Carabobo y Aragua. En Distrito Capital no lo toques: todavía se prueba (se juzga ahí desde $5,65).')
            ->and(CreativeCalls::stopIn($cr))->toBe(['Carabobo', 'Aragua']);
    });

    it('si donde perdió ya está apagado, solo dice dónde esperar', function () {
        $cr = c0153Decision(['Aragua' => [[19.41, 2, 17.75, 2, 17.75, 2], false]])['creatives'][153];

        expect($cr['verdict']['key'])->toBe('evaluation')
            ->and($cr['verdict']['proposal'])->toBe('En Carabobo no lo toques: todavía se prueba (se juzga ahí desde $4,12).')
            ->and(CreativeCalls::stopIn($cr))->toBe([]);
    });

    it('la regla pura: varias ciudades probándose', function () {
        $loser = ['key' => 'loser', 'data' => 'Gastó $19,41'];
        $none = ['key' => 'no_data', 'data' => 'Gastó $1,15'];
        $v = MetaDecisions::stillTesting(['a' => $loser, 'b' => $none, 'c' => $none], ['b' => 4.12, 'c' => 5.65], ['a' => true, 'b' => true, 'c' => true],
            ['a' => 'Aragua', 'b' => 'Carabobo', 'c' => 'Distrito Capital'], 1.0);

        expect($v['reading'])->toBe('Pierde en Aragua; en Carabobo y Distrito Capital todavía no gastó 1×C.')
            ->and($v['proposal'])->toBe('Apagarlo solo en Aragua. En Carabobo y Distrito Capital no lo toques: todavía se prueba (se juzga en cada una cuando gaste 1×C).')
            ->and($v['data'])->toBe('Aragua: Gastó $19,41 | Carabobo: Gastó $1,15 | Distrito Capital: Gastó $1,15');
    });
});
