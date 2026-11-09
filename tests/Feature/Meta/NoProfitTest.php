<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .125 (dueño, 2026-11-09): "sí debemos establecer un límite para soltar producto, dependiendo de su CPA y si hicimos cambios y
// no mejoró"; del paso C de antes: "puede ser que la campaña se esté comenzando a recuperar e igual me la mande a apagar"; y
// "nunca podemos permitirnos perder dinero sin una razón buena justificada". FlexiStep en Aragua (C $5,01, BE $7,49), con sus
// 35 días de producción (del 5/10 al 8/11): la cuenta para pausar se reiniciaba con un día bueno y del 24/10 al 8/11 gastó $343
// y dejó −$29.

const SINGANAR_ARAGUA = '120000000000000111';
const SINGANAR_CCS = '120000000000000112';

/** FlexiStep Aragua: gasto y compras por día, del 5/10 al 8/11 (los de producción). */
function singanarDias(): array
{
    $serie = '25.8/7 24.7/2 27.5/3 26/8 23.9/6 20.7/4 26.8/5 26.1/5 16.8/3 23.4/3 25.4/1 27.3/2 29.1/3 30.4/4 25.9/7 24.7/2 24.2/3 23.3/2 21.7/1 24.8/5 28.4/3 23.3/2 27.2/6 '
        . '29.5/4 21.3/5 13.3/1 32/4 26.7/2 18.4/2 3.1/2 0/0 0/0 29.4/1 42.7/3 23.2/2';
    $out = [];
    foreach (explode(' ', $serie) as $i => $day) {
        [$spend, $purchases] = explode('/', $day);
        $out[date('Y-m-d', strtotime("2026-10-05 +{$i} days"))] = ['spend' => (float) $spend, 'purchases' => (float) $purchases];
    }

    return $out;
}

/** Su historial hasta ayer: las veces que entraron anuncios nuevos (los de producción). */
function singanarHistorial(string $today, array $budget = []): array
{
    $new = ['2026-10-07', '2026-10-12', '2026-10-14', '2026-10-21', '2026-10-23', '2026-10-26', '2026-11-03', '2026-11-04', '2026-11-06'];

    return ['daily' => array_filter(singanarDias(), fn ($d) => $d < $today, ARRAY_FILTER_USE_KEY), 'budget_events' => $budget, 'status_events' => [], 'ad_pauses' => [],
        'new_ads' => array_values(array_filter($new, fn ($d) => $d < $today)), 'mins' => 8.0];
}

function singanarVentanas(float $spend, float $purchases): array
{
    $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 14000, 'reach' => 10000]]);
    $x = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];

    return ['max' => $x, '7d' => $x, '3d' => $x, 'prev' => $x, 'yesterday' => $x];
}

/** FlexiStep con Aragua (y, si se pide, Caracas): [gasto, compras] de 3 días de cada una. */
function singanarMeta(array $aragua3d, ?array $ccs3d = null, string $mode = 'normal', bool $extra = false): array
{
    $campaign = fn (string $id, string $name, int $cityId, string $city, float $budget, float $c, float $be, array $w) => ['meta_id' => $id, 'name' => $name, 'city_id' => $cityId, 'city' => $city,
        'active' => true, 'daily_budget' => $budget, 'target' => $c, 'break_even' => $be, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => singanarVentanas(...$w)];
    $out = ['product' => ['id' => 1146, 'name' => 'FlexiStep® Tobillera', 'mode' => $mode], 'dates' => AdFacts::dates(),
        'campaigns' => [SINGANAR_ARAGUA => $campaign(SINGANAR_ARAGUA, 'FLEXISTEP | MCAY | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026', 3, 'Aragua', 25.0, 5.01, 7.49, $aragua3d)],
        'adsets' => [], 'ads' => [], 'creatives' => []];
    if ($ccs3d) {
        $out['campaigns'][SINGANAR_CCS] = $campaign(SINGANAR_CCS, 'FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026', 1, 'Distrito Capital', 106.8, 4.87, 7.21, $ccs3d);
    }
    // Dos batches vivos en cada campaña, que ni ganan ni pierden (para que no haya nada que apagar ni que lanzar por "menos de 2")
    $n = 0;
    foreach (array_keys($out['campaigns']) as $campaignId) {
        foreach (['B0090 FLEX' => '2026-10-07 10:00:00', 'B0126 FLEX' => '2026-11-06 10:00:00'] + ($extra ? ['B0124 FLEX' => '2026-11-03 10:00:00'] : []) as $name => $created) {
            $n++;
            $setId = "s{$n}";
            $adId = '1200000000000011' . (20 + $n);
            $out['adsets'][$setId] = ['meta_id' => $setId, 'name' => $name, 'campaign_meta_id' => (string) $campaignId, 'active' => true, 'missing' => false, 'batch_code' => null,
                'angle_code' => null, 'concept' => null, 'stage' => null, 'min_spend' => 0.0, 'created_time' => $created, 'windows' => []];
            $out['ads'][$adId] = ['id' => $n, 'meta_id' => $adId, 'name' => "C04{$n}0 FLEX", 'campaign_meta_id' => (string) $campaignId, 'adset_meta_id' => $setId, 'creative_id' => $n,
                'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => true, 'created_time' => $created,
                'windows' => singanarVentanas(60.0, 10)];
            $out['creatives'][$n] = ['id' => $n, 'label' => "C04{$n}0 FLEX", 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
                'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
        }
    }

    return $out;
}

/** Las decisiones de FlexiStep hoy. $bank: lo que hay listo en la banca; $ops: lo demás de OpsFacts::products. */
function singanarDecide(array $aragua3d, array $o = []): array
{
    $today = now()->toDateString();
    $meta = singanarMeta($aragua3d, $o['ccs3d'] ?? null, $o['mode'] ?? 'normal', $o['extra'] ?? false);
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1146 => $meta]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $city = ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null];
    // Caracas gana: $40 por día con 10 compras
    $good = [];
    foreach (array_keys(singanarDias()) as $d) {
        $good[$d] = ['spend' => 40.0, 'purchases' => 10.0];
    }
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => $today, 'yesterday' => now()->subDay()->toDateString(), 'campaigns' => [
        SINGANAR_ARAGUA => $o['history'] ?? singanarHistorial($today, $o['budget'] ?? []),
        SINGANAR_CCS => ['daily' => array_filter($o['ccs_daily'] ?? $good, fn ($d) => $d < $today, ARRAY_FILTER_USE_KEY), 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0]]]);
    $bank = $o['bank'] ?? [];
    $ops->allows('products')->andReturn([1146 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'cities' => [1 => $city, 3 => $city],
        'dismissed' => $o['dismissed'] ?? [], 'bank' => ['codes' => $bank, 'ready' => $bank, 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1146])[1146];
}

function singanarMisiones(array $d): array
{
    return UrgentList::build(AnalysisFacts::build([1146 => $d], ['campaigns' => [SINGANAR_ARAGUA => 'FLEXISTEP | MCAY | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026',
        SINGANAR_CCS => 'FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026']]));
}

describe('.125: los últimos 14 días de una campaña (PHP puro)', function () {
    it('FlexiStep Aragua el 9/11: $290,10 y 34 compras ($8,53), con anuncios nuevos 4 veces', function () {
        expect(MetaDecisions::chronic(singanarHistorial('2026-11-09'), '2026-11-08', 14))
            ->toBe(['days' => 14, 'spend' => 290.1, 'purchases' => 34.0, 'batches' => 4, 'lowered' => 0]);
    });

    it('cuenta las bajadas de presupuesto de esos días; una campaña con menos de 14 días gastando todavía no se mira', function () {
        $h = singanarHistorial('2026-11-09', [['at' => '2026-11-01 09:00:00', 'date' => '2026-11-01', 'from' => 25.0, 'to' => 20.0], ['at' => '2026-10-10 09:00:00', 'date' => '2026-10-10', 'from' => 30.0, 'to' => 25.0]]);
        expect(MetaDecisions::chronic($h, '2026-11-08', 14)['lowered'])->toBe(1);
        $young = ['daily' => array_slice(singanarDias(), -10, null, true), 'new_ads' => [], 'budget_events' => []];
        expect(MetaDecisions::chronic($young, '2026-11-08', 14))->toBeNull()
            ->and(MetaDecisions::chronic(['daily' => []], '2026-11-08', 14))->toBeNull();
    });
});

describe('.125: 14 días sin dejar dinero, y los últimos 3 tampoco', function () {
    it('FlexiStep Aragua el 9/11: pausar la ciudad, diciendo lo que se intentó', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $d = singanarDecide([95.3, 6], ['bank' => ['B0130']]);
        $a = $d['campaigns'][SINGANAR_ARAGUA]['action'];

        expect($a['key'])->toBe('pause_city')
            ->and($a['reading'])->toBe('14 días sin dejar dinero: gastó $290,10 y vendió a $8,53 por compra, en tu BE ($7,49) o encima; y en los últimos 3 días tampoco. '
                . 'En ese tiempo entraron anuncios nuevos 4 veces, y no mejoró.')
            ->and($a['proposal'])->toBe('Pausar la ciudad. Se vuelve a prender cuando haya 3 batches que nunca se publicaron ahí.');

        $m = collect(singanarMisiones($d))->firstWhere('kind', 'pause_city');
        expect($m)->toMatchArray(['group' => 'frenar', 'where' => 'FlexiStep · Aragua', 'title' => 'Pausa la campaña: 14 días sin dejar dinero',
            'why' => 'En 14 días gastó $290,10 a $8,53 por compra; tu límite es $7,49', 'money' => ['cut' => 25.0, 'add' => null]])
            ->and($m['detail'])->toContain($a['reading']);
    });

    it('el 28/10 en los últimos 3 días vendía debajo del BE ($7,17): no se pausa, y dice que se está recuperando', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28 00:30:00'));
        $d = singanarDecide([78.9, 11]);
        $c = $d['campaigns'][SINGANAR_ARAGUA];

        expect($c['action']['key'])->toBe('hold_prune')
            ->and(collect($c['notes'])->firstWhere('key', 'recovering')['text'])->toBe('En 14 días gastó $359,10 y vendió a $8,16 por compra (en tu BE o encima), pero en los últimos 3 días '
                . 'vende a $7,17, debajo del BE: no se pausa. Se vuelve a mirar cada día.');
    });

    it('una campaña que gana nunca cae en la regla: Caracas, con los mismos días', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $d = singanarDecide([95.3, 6], ['ccs3d' => [120.0, 30]]);

        expect($d['campaigns'][SINGANAR_CCS]['action']['key'])->toBe('scale')
            ->and(collect($d['campaigns'][SINGANAR_CCS]['notes'])->firstWhere('key', 'recovering'))->toBeNull();
    });
});

describe('.125: en el BE o encima y sin batch para lanzar, la cuenta corre igual', function () {
    // Una campaña joven (10 días) que lleva 3 días en el BE o encima, sin nada en la banca ni en otra ciudad
    $joven = function (int $bad) {
        $daily = [];
        for ($i = 10; $i >= 1; $i--) {
            $daily[date('Y-m-d', strtotime("2026-11-09 -{$i} days"))] = $i <= $bad ? ['spend' => 30.0, 'purchases' => 3.0] : ['spend' => 30.0, 'purchases' => 8.0];
        }

        return ['daily' => $daily, 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    };

    it('los primeros días: intervenir, y avisa que si no llega un batch toca bajar', function () use ($joven) {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        // 3 días malos: la ventana de 3 días entra al BE recién ayer (1 día de racha)
        $a = singanarDecide([90.0, 9], ['history' => $joven(3)])['campaigns'][SINGANAR_ARAGUA]['action'];

        expect($a['key'])->toBe('intervene')
            ->and($a['proposal'])->toBe('Intervenir: podar y lanzar un batch nuevo. No tocar el presupuesto hoy. Si no llega un batch y sigue igual 2 días cerrados, toca bajar 20 %.');
    });

    it('a los 2 días cerrados sin batch: bajar 20 %; con un batch en la banca, sigue en intervenir (lanzarlo)', function () use ($joven) {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $sin = singanarDecide([90.0, 9], ['history' => $joven(5)])['campaigns'][SINGANAR_ARAGUA]['action'];
        $con = singanarDecide([90.0, 9], ['history' => $joven(5), 'bank' => ['B0130']])['campaigns'][SINGANAR_ARAGUA]['action'];

        expect($sin['key'])->toBe('decrease')->and($sin['to'])->toBe(20.0)
            ->and($sin['reading'])->toBe('Sigue en el break-even o encima 2 días cerrados y sin batch para lanzar.')
            ->and($con['key'])->toBe('intervene')->and($con['proposal'])->toStartWith('Intervenir: podar y lanzar B0130 de la banca. No tocar el presupuesto hoy.')
            ->not->toContain('Si no llega un batch');
    });

    it('a los 5 días cerrados sin batch: pausar la ciudad', function () use ($joven) {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $a = singanarDecide([90.0, 9], ['history' => $joven(8)])['campaigns'][SINGANAR_ARAGUA]['action'];

        expect($a['key'])->toBe('pause_city')->and($a['reading'])->toBe('5 días cerrados en el break-even o encima, sin batch para lanzar.');
    });
});

describe('.125: el producto entero sin dejar dinero es una misión para decidir', function () {
    // Caracas también pierde: $40 por día con 5 compras ($8,00; BE $7,21)
    $bad = fn () => array_map(fn ($r) => ['spend' => 40.0, 'purchases' => 5.0], singanarDias());
    $pierde = fn (array $o = []) => singanarDecide([95.3, 6], ['ccs3d' => [120.0, 15], 'ccs_daily' => $bad(), 'extra' => true] + $o);

    it('sus dos ciudades 14 días sin dejar dinero y 2 batches nuevos en ese tiempo: "Decide si sueltas FlexiStep"', function () use ($pierde) {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $d = $pierde();

        expect($d['plan']['drop'])->toMatchArray(['days' => 14, 'spend' => 850.1, 'purchases' => 104.0, 'profit' => -90.74, 'batches' => 2])
            ->and($d['plan']['drop']['cities'])->toBe([['city' => 'Aragua', 'spend' => 290.1, 'purchases' => 34.0, 'be' => 7.49],
                ['city' => 'Distrito Capital', 'spend' => 560.0, 'purchases' => 70.0, 'be' => 7.21]]);

        $m = collect(singanarMisiones($d))->firstWhere('kind', 'drop_product');
        expect($m)->toMatchArray(['key' => '1146:drop_product', 'group' => 'frenar', 'where' => 'FlexiStep',
            'title' => 'Decide si sueltas FlexiStep: 14 días sin dejar dinero', 'why' => 'En 14 días gastó $850,10 y dejó −$90,74, con 2 batches nuevos', 'money' => null])
            ->and($m['steps'])->toBe(['Si lo sueltas: dile a tu asistente «pausa FlexiStep». Después te sale la misión de apagar sus campañas.',
                'Si le das otra oportunidad: dile a tu asistente «pon FlexiStep en testeo»: se piden batches de ángulos nuevos.',
                'Si prefieres esperar: toca «No estoy de acuerdo» y no te lo vuelvo a preguntar en 14 días.'])
            ->and($m['detail'][0])->toBe('Aragua: gastó $290,10 · 34 compras · $8,53 por compra · BE $7,49.')
            ->and(UrgentList::tally([$m], []))->toBe(['tasks' => 1, 'resolved' => 0]);
    });

    it('con "No estoy de acuerdo" no se vuelve a preguntar en 14 días; después, sí', function () use ($pierde) {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));

        expect($pierde(['dismissed' => ['1146:drop_product' => '2026-11-05']])['plan']['drop'])->toBeNull()
            ->and($pierde(['dismissed' => ['1146:drop_product' => '2026-10-20']])['plan']['drop'])->not->toBeNull();
    });

    it('no sale si el producto deja dinero, si solo entró 1 batch, o si es nuevo', function () use ($bad) {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        // Caracas gana (los $40 por día con 10 compras de siempre): entre las dos ciudades el producto deja dinero
        expect(singanarDecide([95.3, 6], ['ccs3d' => [120.0, 30], 'extra' => true])['plan']['drop'])->toBeNull()
            // pierde, pero en 14 días solo entró 1 batch: todavía no se hicieron cambios
            ->and(singanarDecide([95.3, 6], ['ccs3d' => [120.0, 15], 'ccs_daily' => $bad()])['plan']['drop'])->toBeNull();
        // un producto con menos de 14 días gastando
        $f = singanarMeta([95.3, 6], [120.0, 15], 'normal', true);
        $young = [SINGANAR_ARAGUA => ['daily' => array_slice(singanarDias(), -8, null, true)], SINGANAR_CCS => ['daily' => array_slice($bad(), -8, null, true)]];
        expect(MetaDecisions::dropProduct($f, $young, '2026-11-08', 14, 10, 2))->toBeNull();
    });
});

describe('.125: producto en pausa con una campaña prendida', function () {
    it('la misión es apagarla, y no pide batches ni quitar mínimos', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $d = singanarDecide([95.3, 6], ['mode' => 'paused']);
        $a = $d['campaigns'][SINGANAR_ARAGUA]['action'];

        expect($a['label'])->toBe('Producto en pausa')
            ->and($a['reading'])->toBe('El producto está en pausa y esta campaña sigue prendida: gastó $95,30 en 3 días.');

        $list = singanarMisiones($d);
        expect(array_column($list, 'kind'))->toBe(['product_off'])
            ->and($list[0])->toMatchArray(['group' => 'frenar', 'where' => 'FlexiStep · Aragua', 'title' => 'Pausa la campaña: el producto está en pausa',
                'why' => 'Gastó $95,30 en 3 días con el producto en pausa', 'money' => ['cut' => 25.0, 'add' => null]])
            ->and($list[0]['steps'])->toBe(['Abre la campaña «FLEXISTEP | MCAY | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026».', 'Apágala con el interruptor de la izquierda.']);
    });

    it('si ya no gasta, no hay nada que hacer', function () {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 00:30:00'));
        $d = singanarDecide([0.0, 0], ['mode' => 'paused']);

        expect($d['campaigns'][SINGANAR_ARAGUA]['action']['label'])->toBe('Nada')->and(singanarMisiones($d))->toBe([]);
    });
});

describe('.125: los datos de Meta son viejos', function () {
    it('con más de 6 horas sin leer Meta: un aviso; con menos, nada', function () {
        $i = UrgentList::stale('2026-11-08 17:05:00', '2026-11-09 00:30:00', 6);
        expect($i)->toMatchArray(['kind' => 'stale', 'group' => 'aviso', 'where' => 'Meta', 'key' => 'stale:2026-11-09'])
            ->and($i['title'])->toBe('La última lectura de Meta fue hace 7 horas (el 08/11 a las 17:05, hora de Venezuela): estas misiones pueden estar hechas con números viejos. '
                . 'Revisa la conexión en Meta Ads › Configuración y vuelve a analizar')
            ->and(UrgentList::stale('2026-11-09 00:05:00', '2026-11-09 00:30:00', 6))->toBeNull()
            ->and(UrgentList::stale(null, '2026-11-09 00:30:00', 6)['text'])->toBe('Meta todavía no se ha leído nunca.')
            // un aviso no cuenta como misión
            ->and(UrgentList::tally([$i], []))->toBe(['tasks' => 0, 'resolved' => 0]);
    });
});
