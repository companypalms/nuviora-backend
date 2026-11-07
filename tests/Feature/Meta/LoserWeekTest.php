<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .114 (dueño, 2026-11-07): "por qué en la campaña de FlexiStep de Carabobo no me mandó a apagar" CRE003-FLEX-FAS-BATCH003
// (no vendía desde el 28/10, ~$4 al día: en 3 días nunca llegaba a 2×C = $12,76) y CRE007-FEX-FASP-REF007 ($12,68 en 3 días
// sin compras, pero 5 compras en la semana). Los números son los de producción de ese día.

/** FlexiStep: por creativo, por ciudad: [vida, 7 días, 3 días] (gasto, compras) y si está prendido. */
function flexiWeekMeta(): array
{
    $creatives = [
        7 => ['CRE007-FEX-FASP-REF007', ['Distrito Capital' => [[113.54, 53, 43.25, 17, 24.95, 8], true], 'Carabobo' => [[97.78, 12, 30.54, 5, 12.68, 0], true],
            'Aragua' => [[25.27, 6, 0, 0, 0, 0], false]]],
        3 => ['CRE003-FLEX-FAS-BATCH003', ['Distrito Capital' => [[14.25, 1, 0, 0, 0, 0], false], 'Carabobo' => [[53.72, 7, 26.43, 0, 11.93, 0], true],
            'Aragua' => [[14.72, 1, 0, 0, 0, 0], false]]],
    ];
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $windows = fn (array $n) => ['max' => $w($n[0], $n[1]), '7d' => $w($n[2], $n[3]), '3d' => $w($n[4], $n[5]), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $meta = ['Distrito Capital' => ['120000000000000051', 1, 4.79, 7.21], 'Carabobo' => ['120000000000000052', 7, 6.38, 9.78], 'Aragua' => ['120000000000000053', 3, 5.15, 7.85]];
    $out = ['product' => ['id' => 1148, 'name' => 'FlexiStep', 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'campaigns' => [], 'adsets' => [], 'ads' => [], 'creatives' => []];
    foreach ($meta as $city => [$id, $cityId, $c, $be]) {
        $out['campaigns'][$id] = ['meta_id' => $id, 'name' => "FLEXISTEP | {$city}", 'city_id' => $cityId, 'city' => $city, 'active' => true, 'daily_budget' => 20.0,
            'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows([400, 80, 60, 10, 30, 5])];
        $out['adsets']["{$id}9"] = ['meta_id' => "{$id}9", 'name' => 'BATCH', 'campaign_meta_id' => $id, 'active' => true, 'missing' => false,
            'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
    }
    foreach ($creatives as $cid => [$label, $cities]) {
        $out['creatives'][$cid] = ['id' => $cid, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => []];
        foreach ($cities as $city => [$n, $active]) {
            $campaign = $meta[$city][0];
            $adId = "{$campaign}{$cid}";
            $out['ads'][$adId] = ['id' => (int) substr($adId, -5), 'meta_id' => $adId, 'name' => $label, 'campaign_meta_id' => $campaign, 'adset_meta_id' => "{$campaign}9",
                'creative_id' => $cid, 'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => $active,
                'created_time' => '2026-10-14 15:11:25', 'windows' => $windows($n)];
            $out['creatives'][$cid]['ads'][] = $adId;
        }
    }

    return $out;
}

function flexiWeekDecision(): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1148 => flexiWeekMeta()]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => ['120000000000000051' => $empty, '120000000000000052' => $empty, '120000000000000053' => $empty]]);
    $ops->allows('products')->andReturn([1148 => ['price' => 25.0, 'price_change' => null, 'cities' => [1 => $city, 3 => $city, 7 => $city], 'version' => null,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1148])[1148];
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-07 08:10:00'));
});

describe('.114: "perdedor en los últimos días" mira la semana si en 3 días no llegó a 2×C', function () {
    it('CRE003-FLEX-FAS-BATCH003 pierde en Carabobo por la semana; perdió en todas, así que se apaga', function () {
        $d = flexiWeekDecision();
        $cr = $d['creatives'][3];
        $carabobo = collect($cr['cities'])->firstWhere('city', 'Carabobo');

        expect($carabobo['key'])->toBe('loser')
            ->and($carabobo['reading'])->toBe('Perdedor en los últimos 7 días: gastó 4,1×C sin compras (en toda su vida: intermedio).')
            ->and($cr['verdict']['key'])->toBe('loser')
            ->and(CreativeCalls::stopIn($cr))->toBe(['Carabobo']);

        $facts = AnalysisFacts::build([1148 => $d]);
        expect($facts[0]['stop_all'])->toBe(['CRE003-FLEX-FAS-BATCH003'])
            ->and(collect($facts[0]['creatives'])->firstWhere('label', 'CRE003-FLEX-FAS-BATCH003')['call']['label'])->toBe('PARAR')
            ->and(collect(UrgentList::build($facts))->where('kind', 'stop_creatives')->pluck('title')->all())
            ->toBe(['Apaga CRE003-FLEX-FAS-BATCH003 en todas las campañas']);
    });

    it('CRE007-FEX-FASP-REF007 sigue en Carabobo: en la semana vendió 5 con CPA debajo de C', function () {
        $cr = flexiWeekDecision()['creatives'][7];

        expect(collect($cr['cities'])->firstWhere('city', 'Carabobo')['key'])->toBe('intermediate')
            ->and($cr['verdict']['key'])->toBe('proven')
            ->and(CreativeCalls::stopIn($cr))->toBe([]);
    });
});
