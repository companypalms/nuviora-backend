<?php

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\MetaMetrics;

// .111 (dueño, 2026-11-06): "si se hizo un cambio importante en una campaña, como añadirle un batch nuevo o aumentarle el
// presupuesto, se deben esperar 24 horas para ver qué tal resultó ese cambio antes de tomar otra decisión… para darle
// tiempo a los batchs nuevos de recopilar datos". Antes "hoy" era el día del calendario: un batch de las 21:00 dejaba subir
// el presupuesto en el análisis de las 00:30. Aquí el análisis corre el 6/11 a las 06:00. C = 4 y BE = 5,70 (su manual).

const ESPERA_CBOBO = '120000000000000041';

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-06 06:00:00'));
});

function esperaCampana(array $in): array
{
    return (new CampaignRules(DecisionSettings::defaults()))->action(array_merge(['c' => 4.0, 'be' => 5.70, 'mode' => 'normal', 'budget' => 40.0,
        'spend3' => 30.0, 'purchases3' => 12, 'cpa7' => 3.0, 'freq3' => 1.3], $in));
}

/** El último cambio de la ficha: entró el batch B0110 POSP anoche a las 21:00. */
function esperaBatch(): array
{
    return ['what' => 'entró el batch «B0110 POSP»', 'at' => '2026-11-05 21:00:00', 'until' => '2026-11-06 21:00:00', 'hours' => 24.0];
}

describe('.111: el último cambio grande de una campaña (PHP puro)', function () {
    $f = ['ads' => [
        'a1' => ['campaign_meta_id' => ESPERA_CBOBO, 'adset_meta_id' => 's1', 'created_time' => '2026-10-20 10:00:00'],
        'a2' => ['campaign_meta_id' => ESPERA_CBOBO, 'adset_meta_id' => 's2', 'created_time' => '2026-11-05 21:00:00'],
        'a3' => ['campaign_meta_id' => 'otra', 'adset_meta_id' => 's3', 'created_time' => '2026-11-06 05:00:00'],
    ], 'adsets' => ['s1' => ['name' => 'B0101 FAS'], 's2' => ['name' => 'B0110 POSP'], 's3' => ['name' => 'B0200 FAS']]];
    $h = fn (array $budget = [], array $status = []) => ['budget_events' => $budget, 'status_events' => $status];

    it('un batch nuevo de anoche: espera hasta mañana a la misma hora', function () use ($f, $h) {
        expect(MetaDecisions::recentChange($h(), $f, ESPERA_CBOBO, '2026-11-06 06:00:00', 24))->toBe(esperaBatch());
    });

    it('el más nuevo manda: una subida de presupuesto de esta madrugada, después del batch', function () use ($f, $h) {
        $c = MetaDecisions::recentChange($h([['at' => '2026-11-06 02:30:00', 'date' => '2026-11-06', 'from' => 40.0, 'to' => 48.0]]), $f, ESPERA_CBOBO, '2026-11-06 06:00:00', 24);
        expect($c['what'])->toBe('subiste el presupuesto de $40,00 a $48,00')->and($c['until'])->toBe('2026-11-07 02:30:00');
    });

    it('volver a prenderla cuenta; un cambio de hace más de 24 horas o de otra campaña, no', function () use ($f, $h) {
        $old = ['ads' => [$f['ads']['a1'], $f['ads']['a3']]] + $f;
        expect(MetaDecisions::recentChange($h([], [['at' => '2026-11-05 10:00:00', 'date' => '2026-11-05', 'from' => 'PAUSED', 'to' => 'ACTIVE', 'budget' => 40.0]]),
            $old, ESPERA_CBOBO, '2026-11-06 06:00:00', 24)['what'])->toBe('volviste a prender la campaña')
            // Meta "procesando" no es un cambio del dueño
            ->and(MetaDecisions::recentChange($h([], [['at' => '2026-11-05 10:00:00', 'date' => '2026-11-05', 'from' => 'IN_PROCESS', 'to' => 'ACTIVE', 'budget' => 40.0]]),
                $old, ESPERA_CBOBO, '2026-11-06 06:00:00', 24))->toBeNull()
            ->and(MetaDecisions::recentChange($h([['at' => '2026-11-05 05:00:00', 'date' => '2026-11-05', 'from' => 40.0, 'to' => 48.0]]), $old, ESPERA_CBOBO, '2026-11-06 06:00:00', 24))->toBeNull()
            ->and(MetaDecisions::recentChange($h(), $f, ESPERA_CBOBO, '2026-11-06 06:00:00', 0))->toBeNull(); // 0 horas en Reglas: sin espera
    });
});

describe('.111: qué hace cada campaña en las 24 horas', function () {
    it('iba a subir: espera hasta las 21:00 y dice cuánto subir después', function () {
        $a = esperaCampana(['recent_change' => esperaBatch()]);
        expect($a['key'])->toBe('wait')
            ->and($a['reading'])->toBe('CPA 38 % debajo de C con 12 compras en 3 días. Pero el 05/11 a las 21:00 entró el batch «B0110 POSP», y tu regla es esperar 24 horas para ver cómo resultó.')
            ->and($a['proposal'])->toBe('Esperar hasta el 06/11 a las 21:00 (hora de Venezuela). Después: subir 20 %: $40,00 → $48,00. Vigilar CPA y frecuencia.')
            ->and($a['cooldown']['held'])->toBe('subir el presupuesto')
            // sin el cambio, sube como siempre
            ->and(esperaCampana([])['key'])->toBe('scale');
    });

    it('antes, un batch de anoche no frenaba la subida en el análisis de la mañana (era "hoy" del calendario)', function () {
        expect(esperaCampana(['new_ads_today' => false])['key'])->toBe('scale')
            ->and(esperaCampana(['new_ads_today' => false, 'recent_change' => esperaBatch()])['key'])->toBe('wait');
    });

    it('iba a bajar o a pausar: también espera', function () {
        $bajar = esperaCampana(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-11-02', 'closed_since_intervention' => 3, 'streak_days' => 4,
            'recent_change' => ['what' => 'apagaste anuncios', 'at' => '2026-11-05 21:00:00', 'until' => '2026-11-06 21:00:00', 'hours' => 24.0]]);
        $pausar = esperaCampana(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-30', 'closed_since_intervention' => 6, 'streak_days' => 7,
            'recent_change' => esperaBatch()]);
        expect($bajar['key'])->toBe('wait')->and($bajar['cooldown']['held'])->toBe('bajar el presupuesto')
            ->and($pausar['key'])->toBe('wait')->and($pausar['cooldown']['held'])->toBe('pausar la campaña');
    });

    it('mantener y podar: solo apaga los que pierden; los creativos nuevos, después de las 21:00', function () {
        $a = esperaCampana(['spend3' => 30, 'purchases3' => 6, 'prune' => ['C0457 FAS'], 'recent_change' => esperaBatch()]);
        expect($a['key'])->toBe('hold_prune')
            ->and($a['proposal'])->toBe('Por ahora, solo apagar los que pierden. Los creativos nuevos, desde el 06/11 a las 21:00 (hora de Venezuela): el 05/11 a las 21:00 entró el batch «B0110 POSP», y tu regla es esperar 24 horas para ver cómo resultó. Apagar: C0457 FAS.')
            ->and($a['cooldown']['held'])->toBe('lanzar un batch');
    });

    it('intervenir después de subir el presupuesto: antes esperaba todo el día; ahora apaga los que pierden y lanza después', function () {
        $cool = ['what' => 'subiste el presupuesto de $40,00 a $48,00', 'at' => '2026-11-06 02:30:00', 'until' => '2026-11-07 02:30:00', 'hours' => 24.0];
        $a = esperaCampana(['spend3' => 36, 'purchases3' => 6, 'budget_changed_today' => true, 'prune' => ['C0457 FAS'], 'recent_change' => $cool]);
        expect($a['key'])->toBe('intervene')->and($a['proposal'])->toStartWith('Por ahora, solo apagar los que pierden. Los creativos nuevos, desde el 07/11 a las 02:30');
    });
});

describe('.111: en el análisis del día', function () {
    $ciudad = fn (array $action, int $live, array $prune = []) => ['campaign_meta_id' => ESPERA_CBOBO, 'campaign' => 'ZENPULSE | CBOBO', 'city' => 'Carabobo', 'notes' => [], 'budget_notes' => [],
        'price_option' => null, 'action' => $action, 'numbers' => ['spend3' => 30, 'purchases3' => 6, 'c' => 4, 'be' => 5.7, 'source' => null, 'budget' => 40, 'streak' => 0,
            'prune' => $prune, 'live' => $live, 'offers' => [['code' => 'B0111', 'how' => 'bank']]]];
    $producto = fn (array $cities) => ['product_id' => 1149, 'product' => 'ZenPulse', 'mode' => 'normal', 'urgency' => ['level' => 3, 'label' => 'U3', 'batches' => '1', 'reasons' => []],
        'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => $cities, 'creatives' => [], 'batches' => []];

    it('la subida que espera es un aviso con la hora y el porqué', function () use ($ciudad, $producto) {
        $list = UrgentList::build([$producto([$ciudad(esperaCampana(['recent_change' => esperaBatch()]), 2)])]);
        expect(array_column($list, 'kind'))->toBe(['cooldown'])
            ->and($list[0]['group'])->toBe('aviso')
            ->and($list[0]['title'])->toBe('Espera hasta el 06/11 a las 21:00 para subir el presupuesto')
            ->and($list[0]['why'])->toBe('El 05/11 a las 21:00 entró el batch «B0110 POSP»; tu regla: 24 horas para ver cómo resultó (hora de Venezuela)');
    });

    it('mantener y podar: apaga el que pierde pero no lanza B0111 (con 2 vivos)', function () use ($ciudad, $producto) {
        $m = UrgentList::build([$producto([$ciudad(esperaCampana(['spend3' => 30, 'purchases3' => 6, 'prune' => ['C0457 FAS'], 'recent_change' => esperaBatch()]), 2, ['C0457 FAS'])])])[0];
        expect($m['kind'])->toBe('hold_prune')->and($m['title'])->toBe('Apaga 1 anuncio caro')->and($m['batches'])->toBe([]);
        // sin el cambio, lanzaba B0111 como siempre
        $antes = UrgentList::build([$producto([$ciudad(esperaCampana(['spend3' => 30, 'purchases3' => 6, 'prune' => ['C0457 FAS']]), 2, ['C0457 FAS'])])])[0];
        expect($antes['title'])->toBe('Apaga 1 anuncio caro y lanza B0111');
    });

    it('nada que apagar: aviso de espera; con menos de 2 vivos, lanza igual (esa regla va primero)', function () use ($ciudad, $producto) {
        $nada = UrgentList::build([$producto([$ciudad(esperaCampana(['spend3' => 30, 'purchases3' => 6, 'recent_change' => esperaBatch()]), 2)])])[0];
        expect($nada['group'])->toBe('aviso')->and($nada['title'])->toStartWith('Espera hasta el 06/11 a las 21:00 para lanzar un batch: el 05/11 a las 21:00 entró');
        $uno = UrgentList::build([$producto([$ciudad(esperaCampana(['spend3' => 30, 'purchases3' => 6, 'recent_change' => esperaBatch()]), 1)])])[0];
        expect($uno['title'])->toBe('Lanza B0111');
        expect(UrgentList::need('hold_prune', ['live' => 2], 'normal', true))->toBe(0)
            ->and(UrgentList::need('hold_prune', ['live' => 1], 'normal', true))->toBe(1)
            ->and(UrgentList::need('hold_prune', ['live' => 3], 'normal'))->toBe(1);
    });
});

describe('.111: un anuncio nuevo no se apaga antes de 24 horas', function () {
    it('la regla (PHP puro)', function () {
        $r = new AdRules(DecisionSettings::defaults());
        $perdedor = $r->state(12.0, 0, 4.0, 5.7, 'anuncio');
        expect($perdedor['key'])->toBe('loser')
            ->and($r->young($perdedor, 9.5)['reading'])->toBe('En test: lleva 9 horas; se juzga desde las 24 (tu regla).')
            ->and($r->young($perdedor, 9.5)['key'])->toBe('evaluation')
            ->and($r->young($perdedor, 25))->toBeNull()
            ->and($r->young($perdedor, null))->toBeNull()
            ->and($r->young($r->state(30, 12, 4.0, 5.7), 2))->toBeNull();
    });

    it('con todo armado: el batch de anoche gastó 3×C sin vender y no sale para apagar; la campaña espera', function () {
        $w = function (float $spend, float $purchases) {
            $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 3000]]);
            $x = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];

            return ['max' => $x, '7d' => $x, '3d' => $x, 'prev' => $x, 'yesterday' => $x];
        };
        $ad = fn (string $id, string $set, string $name, int $creative, string $created, float $spend, float $purchases) => ['id' => (int) substr($id, -3), 'meta_id' => $id,
            'name' => $name, 'campaign_meta_id' => ESPERA_CBOBO, 'adset_meta_id' => $set, 'creative_id' => $creative, 'creative_type' => 'video', 'video_length' => null,
            'angle_code' => null, 'batch_code' => null, 'active' => true, 'created_time' => $created, 'windows' => $w($spend, $purchases)];
        $set = fn (string $id, string $name) => ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => ESPERA_CBOBO, 'active' => true, 'missing' => false,
            'batch_code' => null, 'angle_code' => null, 'concept' => null, 'stage' => null, 'windows' => []];
        $meta = ['product' => ['id' => 1149, 'name' => 'ZenPulse', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
            'campaigns' => [ESPERA_CBOBO => ['meta_id' => ESPERA_CBOBO, 'name' => 'ZENPULSE | CBOBO', 'city_id' => 7, 'city' => 'Carabobo', 'active' => true,
                'daily_budget' => 40.0, 'target' => 4.0, 'break_even' => 5.7, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $w(30, 12)]],
            'adsets' => ['s1' => $set('s1', 'B0101 FAS'), 's2' => $set('s2', 'B0110 POSP')],
            'ads' => [
                '120000000000000411' => $ad('120000000000000411', 's1', 'C0401 FAS', 1, '2026-10-20 10:00:00', 18, 12),
                '120000000000000421' => $ad('120000000000000421', 's2', 'C0457 POSP', 2, '2026-11-05 21:00:00', 12, 0),
            ],
            'creatives' => [
                1 => ['id' => 1, 'label' => 'C0401 FAS', 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null, 'batch_code' => null,
                    'creative_code' => null, 'iteration_of' => null, 'ads' => ['120000000000000411']],
                2 => ['id' => 2, 'label' => 'C0457 POSP', 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null, 'batch_code' => null,
                    'creative_code' => null, 'iteration_of' => null, 'ads' => ['120000000000000421']],
            ]];
        $facts = Mockery::mock(AdFacts::class);
        $facts->allows('load')->andReturn([1149 => $meta]);
        $facts->allows('dailyHistory')->andReturn([]);
        $settings = Mockery::mock(DecisionSettings::class);
        $settings->allows('get')->andReturn(DecisionSettings::defaults());
        $ops = Mockery::mock(OpsFacts::class);
        $ops->allows('worth')->andReturn([]);
        $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => '2026-11-06', 'yesterday' => '2026-11-05',
            'campaigns' => [ESPERA_CBOBO => ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => ['2026-11-05'], 'mins' => 0.0]]]);
        $ops->allows('products')->andReturn([1149 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'bank' => ['codes' => [], 'ready' => [], 'launched' => []],
            'cities' => [7 => ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null]]]]);

        $d = (new MetaDecisions($facts, $settings, $ops))->compute([1149])[1149];
        expect($d['ads']['120000000000000421']['key'])->toBe('evaluation')
            ->and($d['ads']['120000000000000421']['reading'])->toBe('En test: lleva 9 horas; se juzga desde las 24 (tu regla).')
            ->and($d['adsets']['s2']['key'])->toBe('evaluation')
            ->and($d['creatives'][2]['verdict']['key'])->toBe('evaluation')
            ->and($d['campaigns'][ESPERA_CBOBO]['numbers']['prune'])->toBe([])
            ->and($d['campaigns'][ESPERA_CBOBO]['action']['key'])->toBe('wait')
            ->and($d['campaigns'][ESPERA_CBOBO]['action']['cooldown']['what'])->toBe('entró el batch «B0110 POSP»');
    });
});
