<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .152 (dueño, 2026-11-17): de 8 apagados grandes que en su semana dejaban plata, solo 2 cumplieron (−$71 por día contra las
// otras ciudades); ZenPulse en Aragua (05/11) era el 51 % del gasto, con 23 compras a $4,78 en la semana (BE $6,80), y al
// apagarlo la ciudad pasó de $7,19 a $14,76. Ahora, 3 días malos con la semana en el BE o debajo: se vigila, no se apaga.

/** ZenPulse en Aragua: por creativo, [vida, 7 días, 3 días] (gasto, compras). */
function zenWatchMeta(): array
{
    $creatives = [
        5 => ['CREATIVO 5', [300.0, 60, 109.93, 23, 40.0, 2]],   // 3 días a $20,00, pero la semana a $4,78: se vigila
        8 => ['CREATIVO 8', [200.0, 40, 60.0, 6, 30.0, 1]],      // 3 días a $30,00 y la semana a $10,00: se apaga
    ];
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $windows = fn (array $n) => ['max' => $w($n[0], $n[1]), '7d' => $w($n[2], $n[3]), '3d' => $w($n[4], $n[5]), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $id = '120000000000000061';
    $out = ['product' => ['id' => 1200, 'name' => 'ZenPulse', 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'adsets' => [], 'ads' => [], 'creatives' => [],
        'campaigns' => [$id => ['meta_id' => $id, 'name' => 'ZENPULSE | Aragua', 'city_id' => 3, 'city' => 'Aragua', 'active' => true, 'daily_budget' => 28.0,
            'target' => 3.96, 'break_even' => 6.80, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows([500, 90, 170, 29, 70, 3])]]];
    $out['adsets']["{$id}9"] = ['meta_id' => "{$id}9", 'name' => 'BATCH', 'campaign_meta_id' => $id, 'active' => true, 'missing' => false,
        'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
    foreach ($creatives as $cid => [$label, $n]) {
        $adId = "{$id}{$cid}";
        $out['creatives'][$cid] = ['id' => $cid, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
        $out['ads'][$adId] = ['id' => (int) substr($adId, -5), 'meta_id' => $adId, 'name' => $label, 'campaign_meta_id' => $id, 'adset_meta_id' => "{$id}9",
            'creative_id' => $cid, 'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => true,
            'created_time' => '2026-10-20 15:11:25', 'windows' => $windows($n)];
    }

    return $out;
}

function zenWatchDecision(): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1200 => zenWatchMeta()]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('worth')->andReturn([]);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => ['120000000000000061' => $empty]]);
    $ops->allows('products')->andReturn([1200 => ['price' => 25.0, 'price_change' => null, 'cities' => [3 => $city], 'version' => null,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1200])[1200];
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-17 08:10:00'));
});

describe('.152: 3 días malos no apagan al que deja plata en la semana', function () {
    it('.187: CREATIVO 5 (su semana $4,78, en el punto medio entre C y BE o debajo: $5,38) se vigila; CREATIVO 8, que también pierde en la semana, se apaga', function () {
        // dueño, 2026-11-28, después de la revisión: "el punto medio entre C y BE" (con C + 10 % de la .186 se apagaba)
        $d = zenWatchDecision();
        $vigila = collect($d['creatives'][5]['cities'])->firstWhere('city', 'Aragua');
        $pierde = collect($d['creatives'][8]['cities'])->firstWhere('city', 'Aragua');

        expect($vigila['key'])->not->toBe('loser')
            ->and($vigila['watch'])->toBe('Vigila: en los últimos 3 días gastó $40,00 (10,1×C) con 2 compras (CPA $20,00), pero en su semana va bien: '
                . '23 compras con CPA $4,78 (tu C es $3,96 y tu BE $6,80). No se apaga mientras su semana siga en el punto medio entre los dos ($5,38) o debajo.')
            ->and(CreativeCalls::stopIn($d['creatives'][5]))->toBe([])
            ->and($pierde['key'])->toBe('loser')
            ->and(CreativeCalls::stopIn($d['creatives'][8]))->toBe(['Aragua']);

        $facts = AnalysisFacts::build([1200 => $d]);
        expect($facts[0]['stop_by_city'])->toBe(['Aragua' => ['CREATIVO 8']])
            ->and($facts[0]['watch_by_city'])->toBe(['Aragua' => [['label' => 'CREATIVO 5', 'text' => $vigila['watch']]]]);

        $urgent = collect(UrgentList::build($facts));
        // Aragua es su única ciudad: perdió en todas
        expect($urgent->where('kind', 'stop_creatives')->pluck('title')->all())->toBe(['Apaga CREATIVO 8 en todas las campañas'])
            ->and($urgent->firstWhere('kind', 'watch'))->toMatchArray(['label' => 'Vigilar', 'group' => 'aviso', 'city' => 'Aragua',
                'title' => 'Vigila CREATIVO 5: 3 días malos, pero en su semana deja plata (no lo apagues)']);
    });
});
