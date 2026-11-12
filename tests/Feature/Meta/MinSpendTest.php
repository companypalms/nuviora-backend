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
    $ops->allows('worth')->andReturn([]);
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

        // .130: el mejor (B0090, $3,75) y el que vende en la C (B0098, $4,29) se quedan con su mínimo
        expect(array_column($mins['off'], 'adset'))->toBe(['B0095 FLEX', 'B0100 FLEX'])
            ->and($mins['off'][0])->toBe(['adset' => 'B0095 FLEX', 'min' => 5.0, 'why' => $d['adsets']['f2']['label'], 'cpa' => 6.25])
            ->and($d['adsets']['f1']['key'])->toBe('proven')->and($d['adsets']['f2']['key'])->toBe('intermediate')
            // sin veredicto (gastó menos de 1×C), pero ya lleva 14 días
            ->and($d['adsets']['f4']['key'])->toBe('no_data')
            ->and($mins['off'][1])->toBe(['adset' => 'B0100 FLEX', 'min' => 5.0, 'why' => 'lleva 14 días prendido', 'cpa' => null])
            ->and(array_column($mins['stays'], 'adset'))->toBe(['B0090 FLEX', 'B0098 FLEX'])
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
            ->and(array_column(MetaDecisions::testedMins($f, $d, MINIMO_CCS, false, '2026-11-10 09:00:00', 20, 4.87)['off'], 'adset'))->toBe(['B0095 FLEX'])
            ->and(array_column(MetaDecisions::testedMins($f, $d, MINIMO_CCS, false, '2026-11-10 09:00:00', 1, 4.87)['off'], 'adset'))->toContain('B0121 FLEX');
    });
});

// .130 (dueño, 2026-11-10): "no se elimina el límite del presupuesto de el mejor batch, ni tampoco de los batch que tienen un CPA
// igual o menor al CPA objetivo", "para asegurarnos de no empeorar el rendimiento". El mejor: el de CPA más bajo en 7 días.
describe('.130: el mínimo no se quita del mejor ni de los que venden en la C', function () {
    it('el mejor y los que venden en la C se quedan, y dicen por qué', function () {
        $stays = minimoDecide()['campaigns'][MINIMO_CCS]['numbers']['mins']['stays'];

        expect($stays)->toBe([
            ['adset' => 'B0090 FLEX', 'min' => 5.0, 'cpa' => 3.75, 'best' => true, 'why' => 'es el mejor y vende en tu C: $3,75 por compra en 7 días (tu C es $4,87)'],
            ['adset' => 'B0098 FLEX', 'min' => 5.0, 'cpa' => 4.29, 'best' => false, 'why' => 'vende en tu C: $4,29 por compra en 7 días (tu C es $4,87)'],
        ]);
    });

    it('el mejor se queda aunque venda encima de la C; con un solo batch vivo, no hay misión', function () {
        $sets = minimoSets();
        $sets['f1'][4] = 50;  // B0090: $6,00 por compra, el mejor
        $sets['f3'][4] = 9;   // B0098: $6,67
        unset($sets['f6']);   // sin el B0099 ($4,44, sin mínimo)
        $mins = minimoDecide($sets)['campaigns'][MINIMO_CCS]['numbers']['mins'];
        expect(array_column($mins['stays'], 'why'))->toBe(['es el mejor de la ciudad: $6,00 por compra en 7 días'])
            ->and(array_column($mins['off'], 'adset'))->toBe(['B0095 FLEX', 'B0098 FLEX', 'B0100 FLEX']);

        $solo = minimoMisiones(minimoDecide(array_slice(minimoSets(), 1, 1, true)));
        expect(collect($solo)->where('kind', 'min_spend')->all())->toBe([]);
    });

    it('el mejor puede ser uno sin mínimo o uno que todavía se prueba: entonces solo cuida la C', function () {
        $sets = minimoSets();
        $sets['f6'][4] = 20;  // B0099, sin mínimo: $2,00 por compra
        $mins = minimoDecide($sets)['campaigns'][MINIMO_CCS]['numbers']['mins'];

        expect(array_column($mins['stays'], 'best'))->toBe([false, false])
            ->and(array_column($mins['stays'], 'adset'))->toBe(['B0090 FLEX', 'B0098 FLEX'])
            ->and(array_column($mins['off'], 'adset'))->toBe(['B0095 FLEX', 'B0100 FLEX']);
    });

    it('sin C solo cuida al mejor; sin compras en ninguno, no hay mejor', function () {
        $d = minimoDecide();
        $f = minimoMeta(minimoSets());
        $sinC = MetaDecisions::testedMins($f, $d, MINIMO_CCS, false, '2026-11-10 09:00:00', 7);
        expect(array_column($sinC['stays'], 'adset'))->toBe(['B0090 FLEX'])
            ->and(array_column($sinC['off'], 'adset'))->toBe(['B0095 FLEX', 'B0098 FLEX', 'B0100 FLEX']);

        // dos que ya se probaron sin vender (gastaron menos de 1×C, pero llevan más de 7 días)
        $sets = array_intersect_key(minimoSets(), ['f4' => 1]) + ['f8' => ['B0102 FLEX', 5.0, '2026-10-20 10:00:00', 4.0, 0, true]];
        $mins = minimoDecide($sets)['campaigns'][MINIMO_CCS]['numbers']['mins'];
        expect($mins['stays'])->toBe([])
            ->and(array_column($mins['off'], 'cpa'))->toBe([null, null]);
    });

    it('usa los números del ad set si Meta los trae', function () {
        $sets = minimoSets();
        $f = minimoMeta($sets);
        // B0095: su anuncio vende a $6,25, pero el ad set entero (con anuncios ya apagados) vende a $4,00 en 7 días
        $f['adsets']['f2']['windows'] = array_replace(minimoVentanas(100.0, 16), ['7d' => minimoVentanas(80.0, 20)['7d']]);
        $mins = MetaDecisions::testedMins($f, minimoDecide(), MINIMO_CCS, false, '2026-11-10 09:00:00', 7, 4.87);

        expect(array_column($mins['stays'], 'adset'))->toContain('B0095 FLEX')
            ->and(array_column($mins['off'], 'adset'))->toBe(['B0100 FLEX']);
    });
});

describe('.124: la misión de quitar el gasto mínimo', function () {
    it('una por campaña, en Arreglar, con los nombres, el monto y lo que no se toca', function () {
        $list = minimoMisiones(minimoDecide());
        $m = collect($list)->firstWhere('kind', 'min_spend');

        expect($m)->toMatchArray(['key' => '1146:' . MINIMO_CCS . ':min_spend', 'group' => 'arreglar', 'where' => 'FlexiStep · Distrito Capital',
            'title' => 'Quita el gasto mínimo de 2 batches que ya se probaron y no venden en tu C',
            'why' => '$10,00 de $106,80 por día están amarrados a batches que ya se probaron y no venden en tu C', 'money' => null])
            ->and($m['steps'])->toBe([
                'Abre la campaña «FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026».',
                'Edita cada uno de estos conjuntos de anuncios: «B0095 FLEX» y «B0100 FLEX».',
                'En «Presupuesto y calendario» › «Límites de gasto del conjunto de anuncios», borra el mínimo diario ($5,00) y publica.',
                'Deja el mínimo de «B0090 FLEX» y «B0098 FLEX»: el mejor y los que venden en tu C no se tocan.',
                'No toques el de «B0121 FLEX»: todavía se prueba.',
            ])
            ->and($m['detail'])->toContain('B0100 FLEX: mínimo $5,00 por día · lleva 14 días prendido · sin compras en 7 días.')
            ->toContain('B0095 FLEX: mínimo $5,00 por día · ' . minimoDecide()['adsets']['f2']['label'] . ' · $6,25 por compra en 7 días, encima de tu C ($4,87).')
            ->toContain('Se queda: B0090 FLEX · es el mejor y vende en tu C: $3,75 por compra en 7 días (tu C es $4,87).')
            ->toContain('Tu regla: el gasto mínimo es solo mientras el batch se prueba, y no se quita del mejor (el de CPA más bajo en 7 días) ni de los que venden en tu C o debajo, para no empeorar el rendimiento. Sin él, Meta pasa esa plata a los que más venden.')
            ->toContain('No es un cambio grande: no hace falta esperar 24 horas.');
        // va junto con la subida de la misma campaña, y cuenta como misión
        expect(array_column($list, 'kind'))->toContain('scale')
            ->and(UrgentList::tally([$m], []))->toBe(['tasks' => 1, 'resolved' => 0]);
    });

    it('un solo batch, o mínimos distintos: lo dice con su nombre y cada monto', function () {
        $uno = collect(minimoMisiones(minimoDecide(array_slice(minimoSets(), 0, 2, true))))->firstWhere('kind', 'min_spend');
        expect($uno['title'])->toBe('Quita el gasto mínimo de B0095 FLEX: ya se probó y no vende en tu C')
            ->and($uno['why'])->toBe('$5,00 de $106,80 por día está amarrado a un batch que ya se probó y no vende en tu C')
            ->and($uno['steps'][1])->toBe('Edita el conjunto de anuncios «B0095 FLEX».')
            ->and($uno['steps'][3])->toBe('Deja el mínimo de «B0090 FLEX»: es el mejor de la ciudad.')
            ->and($uno['steps'])->toHaveCount(4);

        $sets = array_intersect_key(minimoSets(), ['f1' => 1, 'f2' => 1, 'f4' => 1]);
        $sets['f2'][1] = 4.0;
        $dos = collect(minimoMisiones(minimoDecide($sets)))->firstWhere('kind', 'min_spend');
        expect($dos['steps'][1])->toBe('Edita cada uno de estos conjuntos de anuncios: «B0095 FLEX» ($4,00) y «B0100 FLEX» ($5,00).')
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
                'mins' => ['off' => [['adset' => 'B0095 FLEX', 'min' => 5.0, 'why' => 'Intermedio', 'cpa' => 6.25]], 'keep' => []]]];
        $pausa = UrgentList::build([['product_id' => 1146, 'product' => 'FlexiStep', 'mode' => 'normal', 'urgency' => ['level' => 1, 'label' => 'U1', 'batches' => '1', 'reasons' => []],
            'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [$ciudad], 'creatives' => [], 'batches' => []]]);
        expect(array_column($pausa, 'kind'))->toBe(['pause_city']);
    });
});
