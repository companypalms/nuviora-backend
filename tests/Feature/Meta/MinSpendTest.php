<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;

// .124 (dueño, 2026-11-09): "todos los batches nuevos los colocamos con un gasto mínimo de 4 o de 5 dólares… cuando ese batch
// ya se probó, es decir, ya tiene tiempo activo y ha tenido algunos datos, se procede a eliminar ese gasto mínimo. De modo que
// si es un batch que no es tan ganador, pues le damos la libertad a Meta para distribuir ese presupuesto en otros batches que
// sean más ganadores". Eligió "ya se probó" = con veredicto en esa ciudad o 7 días prendido. En producción el 9/11, FlexiStep
// en Caracas tenía $20 al día en mínimos y su último batch había entrado el 26/10. Aquí: C $4,87, BE $7,21, $106,80 por día.

const MINIMO_CCS = '120000000000000101';

/** Los ad sets de la campaña: [nombre, gasto mínimo, creado, gasto y compras de su anuncio, anuncio prendido]. */
function minimoSets(): array
{
    return [
        'f1' => ['B0090 FLEX', 5.0, '2026-10-07 10:00:00', 300.0, 80, true],  // PROVEN
        'f2' => ['B0095 FLEX', 5.0, '2026-10-12 10:00:00', 100.0, 16, true],  // intermedio
        'f3' => ['B0098 FLEX', 5.0, '2026-10-23 10:00:00', 60.0, 14, true],   // PROVEN
        'f4' => ['B0100 FLEX', 5.0, '2026-10-26 10:00:00', 3.0, 0, true],     // sin datos, pero lleva 14 días
        'f5' => ['B0121 FLEX', 5.0, '2026-11-08 10:00:00', 6.0, 1, true],     // 2 días: todavía se prueba
        'f6' => ['B0099 FLEX', 0.0, '2026-10-23 10:00:00', 40.0, 9, true],    // sin mínimo
        'f7' => ['B0091 FLEX', 5.0, '2026-10-07 10:00:00', 50.0, 12, false],  // con mínimo, pero sin anuncios prendidos
    ];
}

function minimoVentanas(float $spend, float $purchases): array
{
    $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => 14000, 'reach' => 10000]]);
    $x = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];

    return ['max' => $x, '7d' => $x, '3d' => $x, 'prev' => $x, 'yesterday' => $x];
}

function minimoMeta(array $sets, array $campaign3d = [256.0, 80]): array
{
    $out = ['product' => ['id' => 1146, 'name' => 'FlexiStep® Tobillera', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
        'campaigns' => [MINIMO_CCS => ['meta_id' => MINIMO_CCS, 'name' => 'FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026', 'city_id' => 1, 'city' => 'Distrito Capital',
            'active' => true, 'daily_budget' => 106.8, 'target' => 4.87, 'break_even' => 7.21, 'target_source' => 'financial', 'target_no_cost' => false,
            'windows' => minimoVentanas(...$campaign3d)]],
        'adsets' => [], 'ads' => [], 'creatives' => []];
    $n = 0;
    foreach ($sets as $id => [$name, $min, $created, $spend, $purchases, $on]) {
        $n++;
        $adId = '1200000000000010' . $n;
        $out['adsets'][$id] = ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => MINIMO_CCS, 'active' => true, 'missing' => false, 'batch_code' => null,
            'angle_code' => null, 'concept' => null, 'stage' => null, 'min_spend' => $min, 'created_time' => $created, 'windows' => []];
        $out['ads'][$adId] = ['id' => $n, 'meta_id' => $adId, 'name' => 'C04' . $n . '0 FLEX', 'campaign_meta_id' => MINIMO_CCS, 'adset_meta_id' => $id, 'creative_id' => $n,
            'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => $on, 'created_time' => $created,
            'windows' => minimoVentanas($spend, $purchases)];
        $out['creatives'][$n] = ['id' => $n, 'label' => 'C04' . $n . '0 FLEX', 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
    }

    return $out;
}

function minimoDecide(?array $sets = null, array $campaign3d = [256.0, 80]): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1146 => minimoMeta($sets ?? minimoSets(), $campaign3d)]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [MINIMO_CCS => ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => ['2026-11-08'], 'mins' => 30.0]]]);
    $ops->allows('products')->andReturn([1146 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'bank' => ['codes' => [], 'ready' => [], 'launched' => []],
        'cities' => [1 => ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null]]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1146])[1146];
}

function minimoMisiones(array $d): array
{
    return UrgentList::build(AnalysisFacts::build([1146 => $d], ['campaigns' => [MINIMO_CCS => 'FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026']]));
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 09:00:00'));
});

describe('.124: qué batches ya se probaron y siguen con gasto mínimo', function () {
    it('lee el gasto mínimo del ad set (Meta lo da en centavos)', function () {
        expect(AdFacts::minSpend('{"daily_min_spend_target":"500","daily_spend_cap":null}'))->toBe(5.0)
            ->and(AdFacts::minSpend('{"daily_min_spend_target":450}'))->toBe(4.5)
            ->and(AdFacts::minSpend('{"bid_strategy":"LOWEST_COST"}'))->toBe(0.0)
            ->and(AdFacts::minSpend(null))->toBe(0.0);
    });

    it('con veredicto en esa ciudad o 7 días prendido: se quita; el que todavía se prueba, se queda', function () {
        $d = minimoDecide();
        $mins = $d['campaigns'][MINIMO_CCS]['numbers']['mins'];

        expect(array_column($mins['off'], 'adset'))->toBe(['B0090 FLEX', 'B0095 FLEX', 'B0098 FLEX', 'B0100 FLEX'])
            ->and($mins['off'][0])->toBe(['adset' => 'B0090 FLEX', 'min' => 5.0, 'why' => $d['adsets']['f1']['label']])
            ->and($d['adsets']['f1']['key'])->toBe('proven')->and($d['adsets']['f2']['key'])->toBe('intermediate')
            // sin veredicto (gastó menos de 1×C), pero ya lleva 14 días
            ->and($d['adsets']['f4']['key'])->toBe('no_data')
            ->and($mins['off'][3]['why'])->toBe('lleva 14 días prendido')
            // el de hace 2 días todavía se prueba; el que no tiene mínimo y el que no tiene anuncios prendidos no salen
            ->and($mins['keep'])->toBe([['adset' => 'B0121 FLEX', 'min' => 5.0]])
            ->and($d['campaigns'][MINIMO_CCS]['budget_notes'])->toBe([]);
    });

    it('un batch que todavía se prueba sin gasto mínimo: lo avisa en las notas del presupuesto', function () {
        $sets = minimoSets();
        $sets['f5'][1] = 0.0;
        $d = minimoDecide($sets);

        expect($d['campaigns'][MINIMO_CCS]['numbers']['mins']['keep'])->toBe([])
            ->and($d['campaigns'][MINIMO_CCS]['budget_notes'])->toBe(['B0121 FLEX todavía se prueba y no tiene gasto mínimo: puede que Meta no lo muestre. '
                . 'Tu regla: el CPA del nombre de la campaña (o tu C, $4,87) por día, mientras se prueba.']);
    });

    it('en Reglas se cambian los 7 días', function () {
        $d = minimoDecide();
        $f = minimoMeta(minimoSets());
        expect(DecisionSettings::defaults()['min_spend_days'])->toBe(7.0)
            ->and(array_column(MetaDecisions::testedMins($f, $d, MINIMO_CCS, false, '2026-11-10 09:00:00', 20)['off'], 'adset'))->toBe(['B0090 FLEX', 'B0095 FLEX', 'B0098 FLEX'])
            ->and(array_column(MetaDecisions::testedMins($f, $d, MINIMO_CCS, false, '2026-11-10 09:00:00', 1)['off'], 'adset'))->toContain('B0121 FLEX');
    });
});

describe('.124: la misión de quitar el gasto mínimo', function () {
    it('una por campaña, en Arreglar, con los nombres, el monto y lo que no se toca', function () {
        $list = minimoMisiones(minimoDecide());
        $m = collect($list)->firstWhere('kind', 'min_spend');

        expect($m)->toMatchArray(['key' => '1146:' . MINIMO_CCS . ':min_spend', 'group' => 'arreglar', 'where' => 'FlexiStep · Distrito Capital',
            'title' => 'Quita el gasto mínimo de 4 batches que ya se probaron', 'why' => '$20,00 de $106,80 por día están amarrados a batches que ya se probaron', 'money' => null])
            ->and($m['steps'])->toBe([
                'Abre la campaña «FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026».',
                'Edita cada uno de estos conjuntos de anuncios: «B0090 FLEX», «B0095 FLEX», «B0098 FLEX» y «B0100 FLEX».',
                'En «Presupuesto y calendario» › «Límites de gasto del conjunto de anuncios», borra el mínimo diario ($5,00) y publica.',
                'No toques el de «B0121 FLEX»: todavía se prueba.',
            ])
            ->and($m['detail'])->toContain('B0100 FLEX: mínimo $5,00 por día · lleva 14 días prendido.')
            ->toContain('Tu regla: el gasto mínimo es solo mientras el batch se prueba. Sin él, Meta pasa esa plata a los que más venden.')
            ->toContain('No es un cambio grande: no hace falta esperar 24 horas.');
        // va junto con la subida de la misma campaña, y cuenta como misión
        expect(array_column($list, 'kind'))->toContain('scale')
            ->and(UrgentList::tally([$m], []))->toBe(['tasks' => 1, 'resolved' => 0]);
    });

    it('un solo batch, o mínimos distintos: lo dice con su nombre y cada monto', function () {
        $uno = collect(minimoMisiones(minimoDecide(array_slice(minimoSets(), 0, 1, true))))->firstWhere('kind', 'min_spend');
        expect($uno['title'])->toBe('Quita el gasto mínimo de B0090 FLEX: ya se probó')
            ->and($uno['why'])->toBe('$5,00 de $106,80 por día está amarrado a un batch que ya se probó')
            ->and($uno['steps'][1])->toBe('Edita el conjunto de anuncios «B0090 FLEX».')
            ->and($uno['steps'])->toHaveCount(3);

        $sets = minimoSets();
        $sets['f2'][1] = 4.0;
        $dos = collect(minimoMisiones(minimoDecide(array_slice($sets, 0, 2, true))))->firstWhere('kind', 'min_spend');
        expect($dos['steps'][1])->toBe('Edita cada uno de estos conjuntos de anuncios: «B0090 FLEX» ($5,00) y «B0095 FLEX» ($4,00).')
            ->and($dos['steps'][2])->toBe('En «Presupuesto y calendario» › «Límites de gasto del conjunto de anuncios», borra el mínimo diario y publica.')
            ->and($dos['why'])->toStartWith('$9,00 de $106,80 por día');
    });

    it('si todos los que tienen mínimo todavía se prueban, no hay misión', function () {
        $list = minimoMisiones(minimoDecide(array_slice(minimoSets(), 4, 2, true)));

        expect(collect($list)->where('kind', 'min_spend')->all())->toBe([]);
    });

    it('sale también donde la campaña está entre C y BE; no donde se pausa', function () {
        $banda = minimoMisiones(minimoDecide(null, [256.0, 44]));
        expect(array_column($banda, 'kind'))->toContain('min_spend');

        $ciudad = ['campaign_meta_id' => MINIMO_CCS, 'campaign' => 'FLEXISTEP | CCS', 'city' => 'Distrito Capital', 'notes' => [], 'budget_notes' => [], 'price_option' => null,
            'action' => ['key' => 'pause_city', 'label' => 'Pausar la ciudad', 'tone' => 'error', 'data' => '', 'reading' => 'Sigue encima.', 'proposal' => 'Pausar.', 'from' => 30.0, 'to' => null],
            'numbers' => ['spend3' => 90, 'purchases3' => 6, 'c' => 4.87, 'be' => 7.21, 'source' => null, 'budget' => 30.0, 'streak' => 6, 'prune' => [], 'live' => 2, 'offers' => [],
                'mins' => ['off' => [['adset' => 'B0090 FLEX', 'min' => 5.0, 'why' => 'PROVEN']], 'keep' => []]]];
        $pausa = UrgentList::build([['product_id' => 1146, 'product' => 'FlexiStep', 'mode' => 'normal', 'urgency' => ['level' => 1, 'label' => 'U1', 'batches' => '1', 'reasons' => []],
            'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [$ciudad], 'creatives' => [], 'batches' => []]]);
        expect(array_column($pausa, 'kind'))->toBe(['pause_city']);
    });
});
