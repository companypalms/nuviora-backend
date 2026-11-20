<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\Revival;
use App\Services\Meta\MetaMetrics;

// .166 (dueño, 2026-11-20): "que te parece si los creativos que fueron buenos ganadores, cuando se emperoran los apagamos, y en
// dos semanas les hacemos una copia y los volvemos a activar a ver si vuelven a ser buenos?". Eligió: 20+ compras y CPA de toda
// su vida debajo del punto medio entre la C y el BE; 14 días de descanso; uno a la vez por ciudad; 24 h entre cambios grandes;
// se juzga como un creativo nuevo; si vuelve a perder no se revive más; en su mismo conjunto si ahí no queda nada prendido, si
// no en uno nuevo ("Mixto"); y se decide si conviene con 10 revividos juzgados. En producción el 20/11 había 30 ganadores
// apagados en todas sus ciudades. Aquí: Cervimax en Carabobo, C $4 y BE $5,70 (punto medio $4,85).

const REVIVIR_CBO = '120000000000000201';

/** Las ventanas de un anuncio: su vida y lo reciente (un anuncio apagado no gasta en 3 ni en 7 días). */
function revivirVentanas(float $spend, float $purchases, bool $recent = true): array
{
    $make = function (float $s, float $p) {
        $t = MetaMetrics::sum([['spend' => $s, 'purchases' => $p, 'impressions' => $s > 0 ? 14000 : 0, 'reach' => $s > 0 ? 10000 : 0]]);

        return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    };
    $life = $make($spend, $purchases);
    $now = $recent ? $life : $make(0.0, 0.0);

    return ['max' => $life, '7d' => $now, '3d' => $now, 'prev' => $now, 'yesterday' => $now];
}

/** Gasto por día de un anuncio: $per cada día desde $from, $days días, con $purchases repartidas. */
function revivirDias(string $from, int $days, float $per, float $purchases): array
{
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $out[date('Y-m-d', strtotime("{$from} +{$i} days"))] = ['spend' => $per, 'purchases' => $purchases / $days];
    }

    return $out;
}

/**
 * Los anuncios de la campaña: [ad set, nombre, creativo, prendido, creado, vida (gasto, compras), reciente, días con gasto].
 * El C0145 VAGO fue ganador (25 compras a $4) y se apagó el 19/10; los demás están prendidos.
 */
function revivirAnuncios(): array
{
    return [
        'a1' => ['s1', 'C0145 VAGO', 1, false, '2026-10-08 10:00:00', [100.0, 25], false, revivirDias('2026-10-10', 10, 10.0, 25)],
        'a2' => ['s1', 'C0146 VAGO', 2, true, '2026-10-08 10:00:00', [60.0, 15], true, revivirDias('2026-11-05', 5, 12.0, 15)],
        'a3' => ['s2', 'C0170 NERV', 3, true, '2026-10-20 10:00:00', [40.0, 10], true, revivirDias('2026-11-05', 5, 8.0, 10)],
        'a4' => ['s3', 'C0171 NERV', 4, true, '2026-10-22 10:00:00', [30.0, 8], true, revivirDias('2026-11-05', 5, 6.0, 8)],
    ];
}

function revivirMeta(array $ads): array
{
    $out = ['product' => ['id' => 1147, 'name' => 'Cervimax® Alivia desde el primer uso', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
        'campaigns' => [REVIVIR_CBO => ['meta_id' => REVIVIR_CBO, 'name' => 'CERVIMAX | CBOBO | TEST | CBO | HV | CPA OBJ $4 | BE $5.7 | 17/09/2026 - Copia',
            'city_id' => 2, 'city' => 'Carabobo', 'active' => true, 'daily_budget' => 30.0, 'target' => 4.0, 'break_even' => 5.7, 'target_source' => 'financial',
            'target_no_cost' => false, 'windows' => ['max' => revivirVentanas(500.0, 120)['max'], '7d' => revivirVentanas(40.0, 10)['7d'],
                '3d' => revivirVentanas(9.0, 3)['3d'], 'prev' => revivirVentanas(9.0, 3)['prev'], 'yesterday' => revivirVentanas(3.0, 1)['yesterday']]]],
        'adsets' => [], 'ads' => [], 'creatives' => []];
    foreach (['s1' => 'B0120 VAGO', 's2' => 'B0130 NERV', 's3' => 'B0131 NERV'] as $id => $name) {
        $out['adsets'][$id] = ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => REVIVIR_CBO, 'active' => true, 'missing' => false, 'batch_code' => null,
            'angle_code' => null, 'concept' => null, 'stage' => null, 'min_spend' => 0.0, 'created_time' => '2026-10-08 10:00:00', 'windows' => []];
    }
    $n = 0;
    foreach ($ads as $adId => [$set, $name, $cid, $on, $created, [$spend, $purchases], $recent]) {
        $n++;
        $out['ads'][$adId] = ['id' => $n, 'meta_id' => $adId, 'name' => $name, 'campaign_meta_id' => REVIVIR_CBO, 'adset_meta_id' => $set, 'creative_id' => $cid,
            'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => $on, 'missing' => false, 'created_time' => $created,
            'windows' => revivirVentanas($spend, $purchases, $recent)];
        $out['creatives'][$cid] ??= ['id' => $cid, 'label' => preg_replace('/\s*-\s*Copia$/', '', $name), 'type' => 'video', 'status' => null, 'status_source' => null,
            'angle_code' => null, 'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => []];
        $out['creatives'][$cid]['ads'][] = $adId;
    }

    return $out;
}

function revivirDecide(?array $ads = null, array $dismissed = []): array
{
    $ads ??= revivirAnuncios();
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1147 => revivirMeta($ads)]);
    $facts->allows('dailyHistory')->andReturn(array_map(fn ($a) => $a[7], $ads));
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('worth')->andReturn([]);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-09-17', 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [REVIVIR_CBO => ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0]]]);
    $ops->allows('products')->andReturn([1147 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'bank' => ['codes' => [], 'ready' => [], 'launched' => []],
        'dismissed' => $dismissed,
        'cities' => [2 => ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null]]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1147])[1147];
}

function revivirMisiones(array $d): array
{
    return UrgentList::build(AnalysisFacts::build([1147 => $d], ['campaigns' => [REVIVIR_CBO => 'CERVIMAX | CBOBO | TEST | CBO | HV | CPA OBJ $4 | BE $5.7 | 17/09/2026 - Copia']]));
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 09:00:00'));
});

describe('.166: qué ganador se revive en una ciudad', function () {
    it('el ganador que descansa 14 días o más, con 20+ compras y CPA debajo del punto medio entre C y BE', function () {
        $d = revivirDecide();
        $r = $d['campaigns'][REVIVIR_CBO]['numbers']['revive'];

        expect($d['campaigns'][REVIVIR_CBO]['action']['key'])->toBe('hold')
            ->and($r)->toBe(['creative_id' => 1, 'label' => 'C0145 VAGO', 'ad' => 'C0145 VAGO', 'adset' => 'B0120 VAGO', 'adset_active' => true,
                'where' => 'new', 'purchases' => 25.0, 'spend' => 100.0, 'cpa' => 4.0, 'limit' => 4.85, 'c' => 4.0, 'be' => 5.7, 'gain' => 42.5,
                'last_spend' => '2026-10-19', 'rest_days' => 22]);
    });

    it('la misión: en un conjunto nuevo si en el suyo hay otros prendidos, con la misma publicación', function () {
        $m = collect(revivirMisiones(revivirDecide()))->firstWhere('kind', 'revive');

        expect($m['title'])->toBe('Revive C0145 VAGO: lleva 22 días descansando')
            ->and($m['key'])->toBe('1147:' . REVIVIR_CBO . ':c1:revive')
            ->and($m['group'])->toBe('crecer')
            ->and($m['where'])->toBe('Cervimax · Carabobo')
            ->and($m['why'])->toBe('Fue ganador aquí: 25 compras a $4,00, debajo de $4,85 (la mitad entre tu C de $4,00 y tu BE de $5,70)')
            ->and($m['steps'][1])->toBe('En «Anuncios», busca «C0145 VAGO» del conjunto «B0120 VAGO»: está apagado.')
            ->and($m['steps'][2])->toContain('«Nuevo conjunto de anuncios»')
            ->and($m['steps'][3])->toContain('«Usar publicación existente»')
            ->and($m['steps'][4])->toBe('Prende el conjunto nuevo y la copia, y publica.');
    });

    it('en su mismo conjunto si ahí ya no queda nada prendido', function () {
        $ads = revivirAnuncios();
        $ads['a2'][3] = false;
        $ads['a2'][7] = revivirDias('2026-11-05', 4, 12.0, 15);
        $m = collect(revivirMisiones(revivirDecide($ads)))->firstWhere('kind', 'revive');

        expect($m['steps'][2])->toBe('Duplícalo en su mismo conjunto («Duplicar» › «Conjunto de anuncios original»): ahí ya no queda nada prendido.')
            ->and($m['steps'][4])->toBe('Prende la copia, y publica.');
    });

    it('no: con el CPA en el punto medio o encima, con menos de 20 compras o con menos de 14 días de descanso', function () {
        foreach ([
            revivirDias('2026-10-10', 10, 12.125, 25),  // CPA $4,85
            revivirDias('2026-10-10', 10, 6.0, 15),     // 15 compras
            revivirDias('2026-10-22', 10, 10.0, 25),    // último gasto el 31/10: 10 días
        ] as $days) {
            $ads = revivirAnuncios();
            $ads['a1'][7] = $days;
            expect(revivirDecide($ads)['campaigns'][REVIVIR_CBO]['numbers']['revive'])->toBeNull();
        }
    });

    it('no, con otro cambio grande en las últimas 24 horas (y sin misión)', function () {
        $ads = revivirAnuncios();
        $ads['a4'][4] = '2026-11-10 06:00:00';
        $d = revivirDecide($ads);

        expect($d['campaigns'][REVIVIR_CBO]['numbers']['revive'])->toBeNull()
            ->and(collect(revivirMisiones($d))->firstWhere('kind', 'revive'))->toBeNull();
    });

    it('con «No estoy de acuerdo», ese creativo no se vuelve a proponer en esa ciudad', function () {
        $d = revivirDecide(null, ['1147:' . REVIVIR_CBO . ':c1:revive' => '2026-11-09']);

        expect($d['campaigns'][REVIVIR_CBO]['numbers']['revive'])->toBeNull();
    });
});

describe('.166: el revivido se juzga como un creativo nuevo', function () {
    it('un anuncio nuevo del creativo después de 14 días sin gastar ahí es un revivido; mientras se prueba, ninguno más', function () {
        $ads = revivirAnuncios();
        $ads['a5'] = ['s2', 'C0145 VAGO - Copia', 1, true, '2026-11-08 10:00:00', [2.0, 0], true, revivirDias('2026-11-08', 2, 1.0, 0)];
        $d = revivirDecide($ads);
        $here = collect($d['creatives'][1]['cities'])->firstWhere('campaign_meta_id', REVIVIR_CBO);

        expect($d['revivals'])->toBe([['creative_id' => 1, 'campaign_meta_id' => REVIVIR_CBO, 'ad' => 'a5', 'since' => '2026-11-08', 'rested' => 20, 'result' => 'testing']])
            ->and($d['campaigns'][REVIVIR_CBO]['numbers']['revive'])->toBeNull()
            // su vida de antes no cuenta: lo dice su anuncio nuevo
            ->and($here['revived'])->toBe('2026-11-08')
            ->and($here['key'])->toBe($d['ads']['a5']['key'])
            ->and($here['reading'])->toStartWith('Revivido el 08/11: se juzga como un creativo nuevo.')
            ->and($here['stop'] ?? null)->toBeNull();
    });

    it('si vuelve a ganar, el aviso lo cuenta contra los creativos nuevos', function () {
        $ads = revivirAnuncios();
        $ads['a5'] = ['s2', 'C0145 VAGO - Copia', 1, true, '2026-11-03 10:00:00', [24.0, 8], true, revivirDias('2026-11-03', 6, 4.0, 8)];
        $d = revivirDecide($ads);
        $aviso = collect(revivirMisiones($d))->firstWhere('kind', 'revive_score');

        expect($d['revivals'][0]['result'])->toBe('won')
            ->and($d['revival_score']['won'])->toBe(1)->and($d['revival_score']['measure'])->toBe(10)
            ->and($aviso['title'])->toBe('Revividos: 1 de 1 volvieron a ganar (100 %)')
            ->and($aviso['group'])->toBe('aviso')
            ->and($aviso['why'])->toStartWith('Creativos nuevos de los últimos 60 días:')
            ->and($aviso['detail'])->toBe(['Cervimax · C0145 VAGO en Carabobo, revivido el 03/11: volvió a ganar.', 'Se decide con 10 revividos juzgados (van 1).']);
    });

    it('sin revividos juzgados no hay aviso', function () {
        expect(collect(revivirMisiones(revivirDecide()))->firstWhere('kind', 'revive_score'))->toBeNull();
    });
});

describe('.166: la regla sola', function () {
    $f = fn (array $ads, array $sets = []) => [
        'product' => ['id' => 9], 'campaigns' => ['c' => ['active' => true, 'target' => 4.0, 'break_even' => 6.0]],
        'adsets' => $sets + ['s' => ['name' => 'B1', 'active' => true, 'missing' => false]],
        'ads' => $ads, 'creatives' => [7 => ['label' => 'C0007', 'ads' => array_keys($ads)]],
    ];
    $ad = fn (bool $on = false, bool $missing = false, string $set = 's') => ['name' => 'C0007', 'campaign_meta_id' => 'c', 'adset_meta_id' => $set,
        'creative_id' => 7, 'active' => $on, 'missing' => $missing, 'created_time' => '2026-10-01 10:00:00'];
    $daily = ['x' => revivirDias('2026-10-01', 10, 10.0, 25)];
    $s = DecisionSettings::defaults();

    it('un revivido que perdió bloquea ese creativo ahí; uno en test, toda la ciudad', function () use ($f, $ad, $daily, $s) {
        $lost = [['creative_id' => 7, 'campaign_meta_id' => 'c', 'ad' => 'y', 'since' => '2026-10-20', 'rested' => 14, 'result' => 'lost']];
        $testing = [['creative_id' => 8, 'campaign_meta_id' => 'c', 'ad' => 'z', 'since' => '2026-11-01', 'rested' => 14, 'result' => 'testing']];

        expect(Revival::candidate($f(['x' => $ad()]), $daily, 'c', [], $s, '2026-11-10')['label'])->toBe('C0007')
            ->and(Revival::candidate($f(['x' => $ad()]), $daily, 'c', $lost, $s, '2026-11-10'))->toBeNull()
            ->and(Revival::candidate($f(['x' => $ad()]), $daily, 'c', $testing, $s, '2026-11-10'))->toBeNull()
            // prendido, o ya no está en Meta: no
            ->and(Revival::candidate($f(['x' => $ad(true)]), $daily, 'c', [], $s, '2026-11-10'))->toBeNull()
            ->and(Revival::candidate($f(['x' => $ad(false, true)]), $daily, 'c', [], $s, '2026-11-10'))->toBeNull();
    });

    it('cómo terminó cada revivido: gana PROVEN (o el que ya ganó y ahora falla); pierde el perdedor o el que se apagó sin ganar', function () {
        $ads = ['old' => ['campaign_meta_id' => 'c', 'creative_id' => 7, 'active' => false, 'created_time' => '2026-10-01 10:00:00'],
            'new' => ['campaign_meta_id' => 'c', 'creative_id' => 7, 'active' => true, 'created_time' => '2026-11-01 10:00:00']];
        $daily = ['old' => revivirDias('2026-10-01', 5, 5.0, 5), 'new' => revivirDias('2026-11-01', 3, 2.0, 1)];
        $result = fn (string $key, bool $on = true) => Revival::history(['new' => ['active' => $on] + $ads['new']] + $ads, ['new' => ['key' => $key]], $daily, 14)[0]['result'];

        expect($result('proven'))->toBe('won')->and($result('failing'))->toBe('won')
            ->and($result('loser'))->toBe('lost')->and($result('no_data', false))->toBe('lost')
            ->and($result('no_data'))->toBe('testing')
            // 13 días de descanso no es revivir
            ->and(Revival::history($ads, [], ['old' => revivirDias('2026-10-15', 5, 5.0, 5)] + $daily, 14))->toBe([])
            // .166: en Reglas
            ->and(DecisionSettings::defaults()['revive_rest_days'])->toBe(14.0)
            ->and(DecisionSettings::defaults()['revive_min_purchases'])->toBe(20.0)
            ->and(DecisionSettings::defaults()['revive_measure'])->toBe(10.0);
    });
});
