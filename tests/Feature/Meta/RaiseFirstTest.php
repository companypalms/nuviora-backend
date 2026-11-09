<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Queue\DemandRules;

// .123 (dueño, 2026-11-09): "la subida de presupuesto siempre va primero antes de meterle batches"; la frecuencia, "solo
// aviso"; y entre C y BE: "prioriza siempre el batch nuevo, si con el batch nuevo no vuelve a la normalidad entonces sí se le
// baja el presupuesto. Pero solo un cambio grande a la vez. Meter un batch y tocar el presupuesto son dos cambios grandes".
// Los números son los de producción del 9/11: ZenPulse en Caracas (C $4,44, BE $7,61) subió el 3/11 y el 6/11 ($33,00 →
// $39,60), desde el 6/11 vende encima de C y el 9/11 a las 07:30 entró B0118. Solo Brillo en Caracas (C $8,31) vendía a $4,13
// con frecuencia 6,3 y decía "Mantener: saturada". Del 9 al 16/11, los días de ZenPulse son supuestos (sigue encima de C).

const SUBIDA_ZEN = '120000000000000081';
const SUBIDA_BRILLO = '120000000000000082';

/** ZenPulse Caracas: gasto y compras por día (del 27/10 al 8/11, los de producción; después, supuestos). */
function subidaDias(): array
{
    $days = ['2026-10-27' => [37.9, 13], '2026-10-28' => [36.4, 7], '2026-10-29' => [29.0, 11], '2026-10-30' => [24.0, 9], '2026-10-31' => [22.6, 4],
        '2026-11-01' => [31.6, 8], '2026-11-02' => [28.9, 9], '2026-11-03' => [31.5, 7], '2026-11-04' => [29.0, 8], '2026-11-05' => [30.2, 7],
        '2026-11-06' => [34.1, 6], '2026-11-07' => [38.2, 4], '2026-11-08' => [45.6, 8],
        '2026-11-09' => [40.1, 6], '2026-11-10' => [39.8, 7], '2026-11-11' => [39.2, 6], '2026-11-12' => [39.6, 6],
        '2026-11-13' => [36.0, 6], '2026-11-14' => [35.2, 6], '2026-11-15' => [34.9, 5], '2026-11-16' => [34.9, 6]];

    return array_map(fn ($d) => ['spend' => $d[0], 'purchases' => (float) $d[1]], $days);
}

/** Su historial hasta ayer: las dos subidas, y los anuncios del 26/10 y de B0118 (9/11). */
function subidaHistorial(string $today, array $budget = [], array $newAds = ['2026-10-26', '2026-10-26', '2026-11-09', '2026-11-09', '2026-11-09']): array
{
    return ['daily' => array_filter(subidaDias(), fn ($d) => $d < $today, ARRAY_FILTER_USE_KEY),
        'budget_events' => [['at' => '2026-11-03 08:00:00', 'date' => '2026-11-03', 'from' => 30.0, 'to' => 33.0],
            ['at' => '2026-11-06 08:00:00', 'date' => '2026-11-06', 'from' => 33.0, 'to' => 39.6], ...$budget],
        'status_events' => [], 'ad_pauses' => [], 'new_ads' => array_values(array_filter($newAds, fn ($d) => $d <= $today)), 'mins' => 5.0];
}

function subidaVentanas(float $spend, float $purchases, float $frequency = 1.4): array
{
    $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 10000 * $frequency, 'reach' => 10000]]);
    $x = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];

    return ['max' => $x, '7d' => $x, '3d' => $x, 'prev' => $x, 'yesterday' => $x];
}

/** Los anuncios y los ad sets de las dos campañas, como los entrega AdFacts. */
function subidaMeta(array $zen3d, float $zenBudget = 39.6): array
{
    $ad = fn (string $id, string $campaign, string $set, string $name, int $creative, string $created, float $spend, float $purchases) => ['id' => (int) substr($id, -3),
        'meta_id' => $id, 'name' => $name, 'campaign_meta_id' => $campaign, 'adset_meta_id' => $set, 'creative_id' => $creative, 'creative_type' => 'video',
        'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => true, 'created_time' => $created, 'windows' => subidaVentanas($spend, $purchases)];
    $set = fn (string $id, string $campaign, string $name) => ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => $campaign, 'active' => true, 'missing' => false,
        'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
    $creative = fn (int $id, string $label, string $adId) => ['id' => $id, 'label' => $label, 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
        'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
    $campaign = fn (string $id, string $name, float $budget, float $c, float $be, array $windows) => ['meta_id' => $id, 'name' => $name, 'city_id' => 1, 'city' => 'Distrito Capital',
        'active' => true, 'daily_budget' => $budget, 'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows];

    return [
        1149 => ['product' => ['id' => 1149, 'name' => 'ZenPulse® Masajeador', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
            'campaigns' => [SUBIDA_ZEN => $campaign(SUBIDA_ZEN, 'ZENPULSE | CCS | CBO | 27/10/2026', $zenBudget, 4.44, 7.61, subidaVentanas(...$zen3d))],
            'adsets' => ['z1' => $set('z1', SUBIDA_ZEN, 'B0101 ZEN'), 'z2' => $set('z2', SUBIDA_ZEN, 'B0118')],
            'ads' => ['120000000000000811' => $ad('120000000000000811', SUBIDA_ZEN, 'z1', 'C0401 ZEN', 1, '2026-10-26 10:00:00', 80.0, 13),
                '120000000000000812' => $ad('120000000000000812', SUBIDA_ZEN, 'z2', 'C0457 ZEN', 2, '2026-11-09 07:30:00', 30.0, 5)],
            'creatives' => [1 => $creative(1, 'C0401 ZEN', '120000000000000811'), 2 => $creative(2, 'C0457 ZEN', '120000000000000812')]],
        1150 => ['product' => ['id' => 1150, 'name' => 'Solo Brillo', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
            'campaigns' => [SUBIDA_BRILLO => $campaign(SUBIDA_BRILLO, 'SOLOBRILLO | CCS | CBO | HV | CPA 3.7 | BE 7.8', 106.0, 8.31, 13.76, subidaVentanas(450.0, 109, 6.26))],
            'adsets' => ['b1' => $set('b1', SUBIDA_BRILLO, 'B0050 BRILLO'), 'b2' => $set('b2', SUBIDA_BRILLO, 'B0051 BRILLO')],
            'ads' => ['120000000000000821' => $ad('120000000000000821', SUBIDA_BRILLO, 'b1', 'C0201 BRILLO', 3, '2026-10-09 10:00:00', 250.0, 60),
                '120000000000000822' => $ad('120000000000000822', SUBIDA_BRILLO, 'b2', 'C0204 BRILLO', 4, '2026-10-21 10:00:00', 200.0, 49)],
            'creatives' => [3 => $creative(3, 'C0201 BRILLO', '120000000000000821'), 4 => $creative(4, 'C0204 BRILLO', '120000000000000822')]],
    ];
}

/** Las decisiones de los dos productos en el análisis de hoy (00:30), con B0120 listo en la banca de ZenPulse. */
function subidaDecide(array $zen3d, array $budget = [], float $zenBudget = 39.6, ?array $newAds = null): array
{
    $today = now()->toDateString();
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn(subidaMeta($zen3d, $zenBudget));
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $city = ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => $today, 'yesterday' => now()->subDay()->toDateString(), 'campaigns' => [
        SUBIDA_ZEN => $newAds === null ? subidaHistorial($today, $budget) : subidaHistorial($today, $budget, $newAds),
        SUBIDA_BRILLO => ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => ['2026-10-21'], 'mins' => 6.0]]]);
    $ops->allows('products')->andReturn([
        1149 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'cities' => [1 => $city],
            'bank' => ['codes' => ['B0120'], 'ready' => ['B0120'], 'launched' => [], 'names' => ['B0120' => ['adset' => 'B0120 ZEN', 'ads' => ['C0460 ZEN']]]]],
        1150 => ['price' => 30.0, 'price_change' => null, 'version' => null, 'cities' => [1 => $city], 'bank' => ['codes' => [], 'ready' => [], 'launched' => []]],
    ]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1149, 1150]);
}

/** Las misiones del análisis de un producto. */
function subidaMisiones(array $decisions, int $productId): array
{
    $names = [SUBIDA_ZEN => 'ZENPULSE | CCS | CBO | 27/10/2026', SUBIDA_BRILLO => 'SOLOBRILLO | CCS | CBO | HV | CPA 3.7 | BE 7.8'];

    return array_values(array_filter(UrgentList::build(AnalysisFacts::build($decisions, ['campaigns' => $names])), fn ($m) => $m['product_id'] === $productId));
}

describe('.123: lo último que se hizo desde que la campaña se pasó de C (PHP puro)', function () {
    $f = ['ads' => [['campaign_meta_id' => SUBIDA_ZEN, 'adset_meta_id' => 'z1', 'created_time' => '2026-10-26 10:00:00'],
        ['campaign_meta_id' => SUBIDA_ZEN, 'adset_meta_id' => 'z2', 'created_time' => '2026-11-09 07:30:00'],
        ['campaign_meta_id' => 'otra', 'adset_meta_id' => 'z3', 'created_time' => '2026-11-09 09:00:00']],
        'adsets' => ['z1' => ['name' => 'B0101 ZEN'], 'z2' => ['name' => 'B0118'], 'z3' => ['name' => 'B0200']]];
    $band = fn (string $today, array $h, float $budget = 39.6, int $undoDays = 14) => MetaDecisions::band($h, $f, SUBIDA_ZEN, 4.44, $budget, $today,
        date('Y-m-d', strtotime("{$today} -1 day")), $undoDays);

    it('ZenPulse Caracas el 10/11: se pasó de C el 6/11, B0118 entró el 9/11 y la subida del 6/11 se puede devolver', function () use ($band) {
        expect($band('2026-11-10', subidaHistorial('2026-11-10')))->toBe(['since' => '2026-11-04',
            'batch' => ['date' => '2026-11-09', 'name' => 'B0118', 'closed' => 0], 'lowered' => null,
            'undo' => ['date' => '2026-11-06', 'from' => 33.0, 'to' => 39.6]]);
    });

    it('el 13/11 ya pasaron 3 días cerrados desde el batch', function () use ($band) {
        expect($band('2026-11-13', subidaHistorial('2026-11-13'))['batch']['closed'])->toBe(3);
    });

    it('un batch de antes de pasarse de C no cuenta: primero va el batch nuevo', function () use ($band) {
        expect($band('2026-11-09', subidaHistorial('2026-11-09', [], ['2026-10-26']))['batch'])->toBeNull();
    });

    it('devuelta la subida del 6/11, la que sigue es la del 3/11; una de hace más de 14 días ya no se devuelve', function () use ($band) {
        $h = subidaHistorial('2026-11-14', [['at' => '2026-11-13 09:00:00', 'date' => '2026-11-13', 'from' => 39.6, 'to' => 33.0]]);
        $b = $band('2026-11-14', $h, 33.0);
        expect($b['lowered'])->toBe(['date' => '2026-11-13', 'from' => 39.6, 'to' => 33.0, 'closed' => 0])
            ->and($b['undo'])->toBe(['date' => '2026-11-03', 'from' => 30.0, 'to' => 33.0])
            ->and($band('2026-11-14', $h, 33.0, 5)['undo'])->toBeNull()
            // con el presupuesto ya en $30, no queda subida que devolver
            ->and($band('2026-11-14', $h, 30.0)['undo'])->toBeNull();
    });
});

describe('.123: ZenPulse Caracas, entre C y BE', function () {
    it('el 10/11 (B0118 entró ayer): espera hasta el 13/11, no lanza B0120 y no le pide un batch al líder para hoy', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 00:30:00'));
        $d = subidaDecide([123.9, 18]);
        $a = $d[1149]['campaigns'][SUBIDA_ZEN]['action'];

        expect($a['key'])->toBe('hold_prune')
            ->and($a['reading'])->toBe('Rentable pero no escalable: CPA entre C y el break-even. El 09/11 entró el batch «B0118».')
            ->and($a['proposal'])->toBe('Esperar: se le dan 3 días cerrados para ver si vuelve a C. Si el 13/11 sigue encima, toca devolver la subida del 06/11: $39,60 → $33,00.')
            ->and($a)->not->toHaveKey('cooldown')
            ->and($d[1149]['campaigns'][SUBIDA_ZEN]['numbers']['offers'][0]['code'])->toBe('B0120');

        $m = subidaMisiones($d, 1149);
        expect(array_column($m, 'kind'))->toBe(['hold_prune'])
            ->and($m[0]['group'])->toBe('aviso')->and($m[0]['steps'])->toBe([])->and($m[0]['batches'])->toBe([])
            ->and($m[0]['title'])->toBe('Espera hasta el 13/11: el 09/11 entró el batch «B0118» y se le dan 3 días cerrados para ver si vuelve a tu objetivo. Si no, toca devolver la subida del 06/11: $39,60 → $33,00');
        expect(DemandRules::today($d[1149]))->toMatchArray(['today' => 0, 'cities' => [], 'consumed' => []]);
    });

    it('el 13/11 sigue encima de C: la misión es devolver la última subida, sin lanzar nada', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-13 00:30:00'));
        $d = subidaDecide([118.6, 19]);
        $a = $d[1149]['campaigns'][SUBIDA_ZEN]['action'];

        expect($a['key'])->toBe('step_down')->and($a['label'])->toBe('Devolver la subida $39,60 → $33,00')
            ->and($a['reading'])->toBe('Pasaron 3 días cerrados desde que entró el batch «B0118» (09/11) y sigue entre C y el break-even.');

        $m = subidaMisiones($d, 1149);
        expect(array_column($m, 'kind'))->toBe(['step_down'])
            ->and($m[0])->toMatchArray(['group' => 'arreglar', 'where' => 'ZenPulse · Distrito Capital', 'title' => 'Devuelve la última subida: baja el presupuesto a $33,00 por día',
                'why' => '−17 % · $6,24 por compra: entre tu objetivo ($4,44) y tu límite ($7,61)', 'money' => ['cut' => 6.6, 'add' => null], 'batches' => []])
            ->and($m[0]['steps'])->toBe(['Abre la campaña «ZENPULSE | CCS | CBO | 27/10/2026».', 'Cambia el presupuesto diario de $39,60 a $33,00 (−17 %).',
                'No lances batches hoy: un cambio grande a la vez.']);
        expect(DemandRules::today($d[1149])['today'])->toBe(0);
        expect(UrgentList::tally($m, []))->toBe(['tasks' => 1, 'resolved' => 0]);
    });

    it('si el 13/11 ya volvió a C, no se devuelve nada: se sube otra vez', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-13 00:30:00'));
        $a = subidaDecide([84.0, 20])[1149]['campaigns'][SUBIDA_ZEN]['action'];

        expect($a['key'])->toBe('scale');
    });

    it('devuelta la subida el 13/11: otros 3 días cerrados y, si el 17/11 sigue igual, otro batch (B0120)', function () {
        $down = [['at' => '2026-11-13 09:00:00', 'date' => '2026-11-13', 'from' => 39.6, 'to' => 33.0]];
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-15 00:30:00'));
        $d = subidaDecide([105.0, 17], $down, 33.0);
        expect($d[1149]['campaigns'][SUBIDA_ZEN]['action']['proposal'])->toBe('Esperar: se le dan 3 días cerrados para ver si vuelve a C. Si el 17/11 sigue encima, toca otro batch.')
            ->and(subidaMisiones($d, 1149)[0]['group'])->toBe('aviso');

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-17 00:30:00'));
        $d = subidaDecide([105.0, 17], $down, 33.0);
        $m = subidaMisiones($d, 1149);
        expect($d[1149]['campaigns'][SUBIDA_ZEN]['action']['reading'])->toBe('Rentable pero no escalable: CPA entre C y el break-even. El 13/11 bajaste el presupuesto y sigue encima de C.')
            ->and($m[0]['title'])->toBe('Lanza B0120')->and($m[0]['group'])->toBe('arreglar');
    });

    it('sin batch desde que se pasó de C: primero el batch, como siempre (no devuelve la subida)', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $d = subidaDecide([117.9, 18], [], 39.6, ['2026-10-26']);
        $m = subidaMisiones($d, 1149);

        expect($d[1149]['campaigns'][SUBIDA_ZEN]['action']['key'])->toBe('hold_prune')
            ->and($m[0]['title'])->toBe('Lanza B0120')
            ->and(DemandRules::today($d[1149])['consumed'])->toBe(['B0120']);
    });
});

describe('.123: mientras espera, la regla de 2 batches vivos sigue primero', function () {
    $ladder = ['hold' => true, 'what' => 'entró el batch «B0118»', 'since' => '2026-11-09', 'until' => '2026-11-13', 'days' => 3, 'next' => 'otro batch'];
    $ciudad = fn (int $live, array $prune = []) => ['campaign_meta_id' => SUBIDA_ZEN, 'campaign' => 'ZENPULSE | CCS', 'city' => 'Distrito Capital', 'notes' => [], 'budget_notes' => [],
        'price_option' => null, 'action' => ['key' => 'hold_prune', 'label' => 'Mantener y podar', 'tone' => 'info', 'data' => '', 'reading' => 'Rentable pero no escalable.',
            'proposal' => 'Esperar.', 'from' => null, 'to' => null, 'ladder' => $ladder],
        'numbers' => ['spend3' => 117.9, 'purchases3' => 18, 'c' => 4.44, 'be' => 7.61, 'source' => null, 'budget' => 39.6, 'streak' => 0, 'prune' => $prune, 'live' => $live,
            'offers' => [['code' => 'B0120', 'how' => 'bank']]]];
    $producto = fn (array $city) => ['product_id' => 1149, 'product' => 'ZenPulse', 'mode' => 'normal', 'urgency' => ['level' => 2, 'label' => 'U2', 'batches' => '1', 'reasons' => []],
        'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [$city], 'creatives' => [], 'batches' => []];

    it('con 1 batch vivo lanza igual; con algo que apagar, solo lo apaga', function () use ($ciudad, $producto) {
        expect(UrgentList::build([$producto($ciudad(1))])[0]['title'])->toBe('Lanza B0120')
            ->and(UrgentList::build([$producto($ciudad(2, ['C0457 ZEN']))])[0]['title'])->toBe('Apaga 1 anuncio caro')
            ->and(UrgentList::holds($ciudad(2)['action']))->toBeTrue()
            ->and(UrgentList::holds(['key' => 'hold_prune']))->toBeFalse();
    });
});

describe('.123: Solo Brillo Caracas, la frecuencia es solo un aviso', function () {
    it('con el CPA 50 % debajo de C y frecuencia 6,3: la misión es subir $106 → $127,20, con el aviso adentro', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 00:30:00'));
        $d = subidaDecide([123.9, 18]);
        $c = $d[1150]['campaigns'][SUBIDA_BRILLO];

        expect($c['action']['key'])->toBe('scale')->and($c['action']['to'])->toBe(127.2)
            ->and($c['action']['proposal'])->toBe('Subir 20 %: $106,00 → $127,20. La frecuencia está alta (6,3): es solo un aviso. Vigilar el CPA.')
            ->and($c['notes'][0])->toMatchArray(['key' => 'saturated', 'label' => 'Frecuencia alta']);

        $m = collect(subidaMisiones($d, 1150))->firstWhere('kind', 'scale');
        expect($m['group'])->toBe('crecer')->and($m['title'])->toBe('Sube el presupuesto a $127,20 por día')
            ->and($m['detail'])->toContain('Frecuencia alta: Frecuencia de 3 días 6,3: la misma gente está viendo mucho tus anuncios. Es solo un aviso, no frena la subida; conviene tener creativos de arriba del embudo listos.');
    });
});
