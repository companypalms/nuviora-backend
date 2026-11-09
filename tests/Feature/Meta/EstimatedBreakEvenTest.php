<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .120 (dueño, 2026-11-09: "nunca podemos permitirnos perder dinero sin una razón buena justificada"): Releev y ResistBarPro
// gastaban con la C del nombre de la campaña ($4) y sin BE: nada las podía frenar. Los números son los de producción de ese día.

/** Releev: por ciudad, [campaña, id de la ciudad, gasto y compras de 3 días]. */
function releevCities(): array
{
    return ['Aragua' => ['120000000000000071', 3, 48.93, 6], 'Carabobo' => ['120000000000000072', 7, 50.10, 9], 'Distrito Capital' => ['120000000000000073', 1, 52.57, 9]];
}

function releevMeta(?float $be = null, string $source = 'name'): array
{
    $w = function (float $spend, float $purchases) {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 2000]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $all = fn (float $spend, float $purchases) => ['max' => $w($spend, $purchases), '7d' => $w($spend, $purchases), '3d' => $w($spend, $purchases), 'prev' => $w(0, 0), 'yesterday' => $w(0, 0)];
    $out = ['product' => ['id' => 1151, 'name' => 'Releev® Alivio muscular profundo', 'mode' => 'normal'], 'dates' => AdFacts::dates(), 'campaigns' => [], 'adsets' => [], 'ads' => [], 'creatives' => []];
    foreach (releevCities() as $city => [$id, $cityId, $spend, $purchases]) {
        $out['campaigns'][$id] = ['meta_id' => $id, 'name' => 'RELEEV | CBO | CPA OBJ $4 | ' . mb_strtoupper($city), 'city_id' => $cityId, 'city' => $city, 'active' => true, 'daily_budget' => 17.0,
            'target' => 4.0, 'break_even' => $be, 'target_source' => $source, 'target_no_cost' => false, 'windows' => $all($spend, $purchases)];
        $out['adsets']["{$id}9"] = ['meta_id' => "{$id}9", 'name' => 'B0140 ALIV', 'campaign_meta_id' => $id, 'active' => true, 'missing' => false,
            'batch_code' => 'B0140', 'angle_code' => 'ALIV', 'concept' => null, 'stage' => null, 'windows' => []];
    }
    // En Aragua: uno caro (2 compras a $15,00) y uno entre C y el BE estimado
    $aragua = releevCities()['Aragua'][0];
    foreach ([1 => ['C0201 ALIV', 30.0, 2], 2 => ['C0202 ALIV', 18.93, 4]] as $cid => [$label, $spend, $purchases]) {
        $adId = "{$aragua}{$cid}";
        $out['creatives'][$cid] = ['id' => $cid, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => 'ALIV',
            'batch_code' => 'B0140', 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
        $out['ads'][$adId] = ['id' => $cid, 'meta_id' => $adId, 'name' => $label, 'campaign_meta_id' => $aragua, 'adset_meta_id' => "{$aragua}9", 'creative_id' => $cid,
            'creative_type' => 'video', 'video_length' => null, 'angle_code' => 'ALIV', 'batch_code' => 'B0140', 'active' => true,
            'created_time' => '2026-11-03 10:00:00', 'windows' => $all($spend, $purchases)];
    }

    return $out;
}

function releevDecision(?float $be = null, string $source = 'name'): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1151 => releevMeta($be, $source)]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => array_fill_keys(array_column(releevCities(), 0), $empty)]);
    $ops->allows('products')->andReturn([1151 => ['price' => 25.0, 'price_change' => null, 'cities' => [1 => $city, 3 => $city, 7 => $city], 'version' => null,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1151])[1151];
}

/** Los hechos del análisis, con los nombres de las campañas. */
function releevFacts(?float $be = null, string $source = 'name'): array
{
    $names = array_map(fn ($c) => $c['name'], releevMeta()['campaigns']);

    return AnalysisFacts::build([1151 => releevDecision($be, $source)], ['campaigns' => $names]);
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 08:10:00'));
});

describe('.120: una campaña con C y sin BE decide con uno estimado', function () {
    it('el BE estimado es C × 1,5 y queda marcado; el real no se toca', function () {
        $f = MetaDecisions::estimateBe(['campaigns' => ['a' => ['target' => 4.0, 'break_even' => null], 'b' => ['target' => 4.44, 'break_even' => 7.61],
            'c' => ['target' => null, 'break_even' => null], 'd' => ['target' => 3.59, 'break_even' => 0.0]]], 1.5);

        expect($f['campaigns']['a'])->toBe(['target' => 4.0, 'break_even' => 6.0, 'break_even_estimated' => 1.5])
            ->and($f['campaigns']['b'])->toBe(['target' => 4.44, 'break_even' => 7.61])
            ->and($f['campaigns']['c'])->toBe(['target' => null, 'break_even' => null])
            ->and($f['campaigns']['d']['break_even'])->toBe(5.39);
    });

    it('Releev en Aragua ($8,16 por compra) pasa a Intervenir; las otras dos siguen entre C y el BE', function () {
        $d = releevDecision();
        $action = fn (string $city) => $d['campaigns'][releevCities()[$city][0]]['action'];

        expect($action('Aragua')['key'])->toBe('intervene')
            ->and($action('Aragua')['data'])->toBe('3 días: gasto $48,93 · 6 compras · CPA $8,16 · C $4,00 (referencia del nombre de la campaña) · BE $6,00 (estimado) · presupuesto $17,00/día')
            ->and($action('Carabobo')['key'])->toBe('hold_prune')
            ->and($action('Distrito Capital')['key'])->toBe('hold_prune')
            ->and($d['campaigns'][releevCities()['Aragua'][0]]['numbers'])->toMatchArray(['c' => 4.0, 'be' => 6.0, 'be_estimated' => 1.5]);
    });

    it('sus creativos ya pueden perder por el BE, y dicen que es estimado', function () {
        $d = releevDecision();

        expect($d['creatives'][1]['verdict']['key'])->toBe('loser')
            ->and($d['creatives'][1]['verdict']['data'])->toBe('Gastó $30,00 (7,5×C) · 2 compras · CPA $15,00 · C $4,00 · BE $6,00 (estimado)')
            ->and($d['creatives'][2]['verdict']['key'])->toBe('intermediate');
    });

    it('con su BE real no se estima nada', function () {
        $d = releevDecision(9.0, 'manual');
        $aragua = $d['campaigns'][releevCities()['Aragua'][0]];

        expect($aragua['action']['key'])->toBe('hold_prune')
            ->and($aragua['action']['data'])->toContain('· BE $9,00 (tu referencia)')->not->toContain('estimado')
            ->and($aragua['numbers'])->not->toHaveKey('be_estimated')
            ->and(collect(UrgentList::build(releevFacts(9.0, 'manual')))->where('kind', 'no_be')->all())->toBe([]);
    });

    it('en Reglas se cambia el 1,5', function () {
        expect(DecisionSettings::defaults()['be_estimate_mult'])->toBe(1.5)
            ->and(DecisionSettings::normalize(['be_estimate_mult' => 1.4])['be_estimate_mult'])->toBe(1.4)
            ->and(DecisionSettings::normalize(['be_estimate_mult' => 0.5])['be_estimate_mult'])->toBe(1.5);
    });
});

describe('.120: la misión de poner el BE real', function () {
    it('una por producto, en Arreglar, con los nombres de sus campañas', function () {
        $missions = collect(UrgentList::build(releevFacts()))->where('kind', 'no_be')->values();

        expect($missions)->toHaveCount(1)
            ->and($missions[0])->toMatchArray(['key' => '1151:no_be', 'group' => 'arreglar', 'where' => 'Releev', 'title' => 'Pon el BE real: hoy se decide con uno estimado',
                'why' => 'Sin BE no hay límite para frenar; mientras tanto se usa $6,00 (1,5 × tu C de $4,00)'])
            ->and($missions[0]['steps'])->toBe([
                'En Ads Manager, agrega «| BE $X» con tu BE real al nombre de «RELEEV | CBO | CPA OBJ $4 | ARAGUA», «RELEEV | CBO | CPA OBJ $4 | CARABOBO» y «RELEEV | CBO | CPA OBJ $4 | DISTRITO CAPITAL».',
                'O ponlo una sola vez en Meta Ads › Productos y objetivos: el Target CPA y el Break-even CPA de Releev.',
            ])
            ->and($missions[0]['detail'])->toBe(['Aragua: C $4,00 · BE estimado $6,00.', 'Carabobo: C $4,00 · BE estimado $6,00.', 'Distrito Capital: C $4,00 · BE estimado $6,00.']);
    });

    it('si la C la puso a mano, el BE va en Productos y objetivos', function () {
        $mission = collect(UrgentList::build(releevFacts(null, 'manual')))->firstWhere('kind', 'no_be');

        expect($mission['steps'])->toBe(['Abre Meta Ads › Productos y objetivos.', 'Ponle a Releev su Break-even CPA (ya tiene el Target CPA) y guarda.']);
    });

    it('las misiones de la ciudad dicen que el límite es estimado', function () {
        $aragua = collect(UrgentList::build(releevFacts()))->first(fn ($m) => $m['kind'] === 'intervene' && $m['city'] === 'Aragua');

        expect($aragua['why'])->toBe('$8,16 por compra; tu límite estimado es $6,00')
            ->and($aragua['detail'])->toContain('Tu objetivo (C) aquí es $4,00 (referencia del nombre de la campaña). Todavía no tiene BE: se usa $6,00, estimado.');
    });
});
