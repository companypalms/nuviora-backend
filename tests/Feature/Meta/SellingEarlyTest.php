<?php

use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\MetaMetrics;

// .110 (dueño, 2026-11-06): ZenPulse CREATIVO 7 27/10/2026 salió "Perdedor en todas las ciudades" y la misión pedía apagarlo
// en todas las campañas, pero en Distrito Capital vendía (2 compras con CPA $2,47 y C $5,76). Como ahí todavía no gastó 1×C,
// quedaba "Sin datos" y no contaba. Los números son los de producción de ese día.

const ZEN_CCS = '120000000000000031';
const ZEN_CBOBO = '120000000000000032';
const ZEN_MCAY = '120000000000000033';

/** Lo que leería AdFacts de ZenPulse: un anuncio de CREATIVO 7 por ciudad (vida, 7 días y 3 días). */
function zenMeta(array $ccs = [4.94, 2, 1.72, 2, 0.98, 1]): array
{
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $windows = fn (array $n) => ['max' => $w($n[0], $n[1]), '7d' => $w($n[2], $n[3]), '3d' => $w($n[4], $n[5]), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $campaign = fn (string $id, int $cityId, string $city, float $c, float $be) => ['meta_id' => $id, 'name' => "ZENPULSE | {$city}", 'city_id' => $cityId,
        'city' => $city, 'active' => true, 'daily_budget' => 10.0, 'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false,
        'windows' => $windows([10, 3, 5, 1, 2, 0])];
    $ad = fn (string $id, string $campaign, bool $active, array $n) => ['id' => (int) substr($id, -4), 'meta_id' => $id, 'name' => 'CREATIVO 7 27/10/2026',
        'campaign_meta_id' => $campaign, 'adset_meta_id' => "{$campaign}9", 'creative_id' => 7, 'creative_type' => 'video', 'video_length' => null,
        'angle_code' => null, 'batch_code' => null, 'active' => $active, 'windows' => $windows($n)];
    $set = fn (string $campaign) => ['meta_id' => "{$campaign}9", 'name' => 'BATCH 003| SIN ANGULO', 'campaign_meta_id' => $campaign, 'active' => true,
        'missing' => false, 'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];

    return ['product' => ['id' => 1149, 'name' => 'ZenPulse', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
        'campaigns' => [ZEN_CCS => $campaign(ZEN_CCS, 1, 'Distrito Capital', 5.76, 9.38), ZEN_CBOBO => $campaign(ZEN_CBOBO, 7, 'Carabobo', 2.72, 5.27),
            ZEN_MCAY => $campaign(ZEN_MCAY, 3, 'Aragua', 2.36, 4.10)],
        'adsets' => [ZEN_CCS . '9' => $set(ZEN_CCS), ZEN_CBOBO . '9' => $set(ZEN_CBOBO), ZEN_MCAY . '9' => $set(ZEN_MCAY)],
        'ads' => [
            '120000000000000311' => $ad('120000000000000311', ZEN_CCS, true, $ccs),
            '120000000000000321' => $ad('120000000000000321', ZEN_CBOBO, true, [5.49, 1, 1.55, 0, 0.42, 0]),
            '120000000000000331' => $ad('120000000000000331', ZEN_MCAY, false, [7.98, 0, 0.77, 0, 0.07, 0]),
        ],
        'creatives' => [7 => ['id' => 7, 'label' => 'CREATIVO 7 27/10/2026', 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null,
            'ads' => ['120000000000000311', '120000000000000321', '120000000000000331']]]];
}

function zenCreative(?array $meta = null): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1149 => $meta ?? zenMeta()]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('worth')->andReturn([]);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [ZEN_CCS => $empty, ZEN_CBOBO => $empty, ZEN_MCAY => $empty]]);
    $ops->allows('products')->andReturn([1149 => ['price' => 25.0, 'price_change' => null, 'cities' => [1 => $city, 3 => $city, 7 => $city], 'version' => null,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1149])[1149]['creatives'][7];
}

describe('.110: un creativo que vende en una ciudad no es "perdedor en todas"', function () {
    it('ZenPulse CREATIVO 7: va bien en Distrito Capital, así que solo se apaga en Carabobo (Aragua ya está apagado)', function () {
        $cr = zenCreative();
        $cities = collect($cr['cities'])->keyBy('city');

        expect($cr['verdict']['key'])->not->toBe('loser')
            ->and($cr['cross']['key'] ?? null)->not->toBe('loser_all')
            ->and($cities['Distrito Capital']['key'])->toBe('evaluation')
            ->and($cities['Distrito Capital']['reading'])->toBe('Va bien por ahora: 2 compras con CPA $2,47, debajo de C. Todavía gastó menos de 1×C.')
            ->and($cities['Distrito Capital']['data'])->toBe('Gastó $4,94 (0,9×C) · 2 compras · CPA $2,47 · C $5,76 · BE $9,38')
            ->and($cities['Carabobo']['key'])->toBe('loser')
            ->and($cities['Aragua']['key'])->toBe('loser')
            ->and(CreativeCalls::stopIn($cr))->toBe(['Carabobo']);
    });

    it('si en Distrito Capital no hubiera vendido, ahí todavía se probaría: se apaga solo en Carabobo (.113)', function () {
        $cr = zenCreative(zenMeta([4.94, 0, 1.72, 0, 0.98, 0]));

        expect($cr['verdict']['key'])->toBe('evaluation')
            ->and($cr['cross'])->toBeNull()
            ->and(collect($cr['cities'])->firstWhere('city', 'Distrito Capital')['key'])->toBe('no_data')
            ->and(CreativeCalls::stopIn($cr))->toBe(['Carabobo']);
    });

    it('la regla: solo cambia "Sin datos" con 1 compra o más; lo demás queda igual', function () {
        $r = new AdRules(DecisionSettings::defaults());
        $sinDatos = $r->state(3.0, 1, 4.0, 5.7, 'creativo');

        expect($sinDatos['key'])->toBe('no_data')
            ->and($r->sellingEarly($sinDatos, 3.0, 1)['reading'])->toBe('Va bien por ahora: 1 compra con CPA $3,00, debajo de C. Todavía gastó menos de 1×C.')
            ->and($r->sellingEarly($sinDatos, 3.0, 1)['proposal'])->toBe('Mantener; se vuelve a mirar cada día.')
            ->and($r->sellingEarly($r->state(3.0, 0, 4.0, 5.7), 3.0, 0))->toBeNull()
            ->and($r->sellingEarly($r->state(9.0, 1, 4.0, 5.7), 9.0, 1))->toBeNull()   // perdedor: sigue
            ->and($r->sellingEarly($r->state(3.0, 1, null, null), 3.0, 1))->toBeNull(); // sin C: sigue sin objetivo
    });
});
