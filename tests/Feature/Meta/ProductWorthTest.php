<?php

use App\Models\CreativeBatch;
use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Products\ProductWorthBoard;
use App\Services\Meta\Queue\DemandPlanner;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .139 (dueño, 2026-11-12): "los recursos son finitos… si un producto tiene una efectividad muy baja, pues probablemente valga
// la pena ponerlo en liquidación si tiene stock para terminar de venderlo, pero ya no vale la pena seguir ofreciéndolo y
// seguir lanzándole creativos". Y: "debemos saber rápido si un producto está funcionando… en esa efectividad no se puede tomar
// en cuenta los tiempos que han estado sin stock".

const VALE_CAMPANA = '120000000000000913';

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-12 10:00:00', config('app.timezone')));
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->longText('value')->nullable();
        $t->timestamps();
    });
    Schema::create('statuses', function ($t) {
        $t->id();
        $t->string('description');
    });
    Schema::create('cities', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('order_financials', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('kind', 10)->default('normal');
        $t->date('created_date')->nullable();
        $t->string('outcome', 15)->default('open');
        $t->unsignedBigInteger('status_id')->nullable();
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
    });
    Schema::create('order_tracking_comprehensive_logs', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('to_status_id')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    DB::table('statuses')->insert([['id' => 3, 'description' => 'Sin Stock'], ['id' => 7, 'description' => 'Asignado a vendedor'],
        ['id' => 16, 'description' => 'Entregado'], ['id' => 17, 'description' => 'Cancelado']]);
    DB::table('cities')->insert([['id' => 1, 'name' => 'Caracas'], ['id' => 2, 'name' => 'Valencia']]);
});

/** Pedidos de un producto un día: $delivered entregados y el resto cancelados. */
function pedidosDelDia(int $productId, string $date, int $n, int $delivered, int $cityId = 1): void
{
    static $id = 0;
    for ($i = 0; $i < $n; $i++) {
        $id++;
        DB::table('order_financials')->insert(['order_id' => $id, 'product_id' => $productId, 'city_id' => $cityId, 'created_date' => $date,
            'outcome' => $i < $delivered ? 'delivered' : 'not_delivered', 'status_id' => $i < $delivered ? 16 : 17]);
    }
}

describe('.139: los pedidos con los que se juzga cada producto', function () {
    it('un producto grande: todos los de los últimos 7 días maduros (hasta hace 5 días), aunque pasen de 50', function () {
        foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07'] as $d) {
            pedidosDelDia(1, $d, 5, 3, 1);
            pedidosDelDia(1, $d, 3, 2, 2);
        }
        pedidosDelDia(1, '2026-10-20', 20, 20); // de antes de la semana: no cuentan
        pedidosDelDia(1, '2026-11-09', 10, 0);  // todavía no tuvieron tiempo de entregarse

        expect((new OpsFacts())->worth([1], DecisionSettings::defaults())[1])->toBe(['orders' => 56, 'delivered' => 35, 'since' => '2026-11-01', 'to' => '2026-11-07',
            'left_out' => 0, 'cities' => [['city' => 'Caracas', 'orders' => 35, 'delivered' => 21], ['city' => 'Valencia', 'orders' => 21, 'delivered' => 14]]]);
    });

    it('un producto chico: si en 7 días no junta 50, los más nuevos hasta juntarlos; nunca de más de 60 días', function () {
        foreach (range(1, 7) as $day) {
            pedidosDelDia(2, sprintf('2026-11-%02d', $day), 2, 1);
        }
        foreach (range(1, 20) as $day) {
            pedidosDelDia(2, sprintf('2026-10-%02d', $day), 2, 2);
        }
        pedidosDelDia(3, '2026-09-01', 40, 0); // de hace más de 60 días: ese producto no tiene pedidos que cuenten

        $w = (new OpsFacts())->worth([2, 3], DecisionSettings::defaults());
        expect($w[2])->toMatchArray(['orders' => 50, 'delivered' => 43, 'since' => '2026-10-03'])
            ->and($w)->not->toHaveKey(3);
    });

    it('"no se pueden tomar en cuenta los tiempos que han estado sin stock": ni los que están en Sin Stock ni los que estuvieron más de 12 horas', function () {
        DB::table('order_financials')->insert([
            ['order_id' => 901, 'product_id' => 4, 'city_id' => 1, 'created_date' => '2026-11-05', 'outcome' => 'open', 'status_id' => 3],          // esperando stock hoy
            ['order_id' => 902, 'product_id' => 4, 'city_id' => 1, 'created_date' => '2026-11-05', 'outcome' => 'delivered', 'status_id' => 16],   // 20 horas en Sin Stock
            ['order_id' => 903, 'product_id' => 4, 'city_id' => 1, 'created_date' => '2026-11-05', 'outcome' => 'delivered', 'status_id' => 16],   // 2 horas: sí cuenta
            ['order_id' => 904, 'product_id' => 4, 'city_id' => 1, 'created_date' => '2026-11-05', 'outcome' => 'not_delivered', 'status_id' => 17],
        ]);
        DB::table('order_tracking_comprehensive_logs')->insert([
            ['order_id' => 901, 'to_status_id' => 3, 'created_at' => '2026-11-05 09:00:00'],
            ['order_id' => 902, 'to_status_id' => 3, 'created_at' => '2026-11-05 10:00:00'], ['order_id' => 902, 'to_status_id' => 16, 'created_at' => '2026-11-06 06:00:00'],
            ['order_id' => 903, 'to_status_id' => 3, 'created_at' => '2026-11-05 10:00:00'], ['order_id' => 903, 'to_status_id' => 7, 'created_at' => '2026-11-05 12:00:00'],
        ]);

        expect((new OpsFacts())->worth([4], DecisionSettings::defaults())[4])->toMatchArray(['orders' => 2, 'delivered' => 1, 'left_out' => 2]);
    });
});

/** Un producto con una campaña prendida (C $5,01, BE $7,49) y dos batches vivos, como los de FlexiStep. */
function valeMeta(): array
{
    $t = MetaMetrics::sum([['spend' => 60.0, 'purchases' => 6, 'impressions' => 14000, 'reach' => 10000]]);
    $w = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    $windows = ['max' => $w, '7d' => $w, '3d' => $w, 'prev' => $w, 'yesterday' => $w];
    $out = ['product' => ['id' => 9, 'name' => 'LegUp® Medias de compresión', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
        'campaigns' => [VALE_CAMPANA => ['meta_id' => VALE_CAMPANA, 'name' => 'LEGUP | MCAY | CBO', 'city_id' => 3, 'city' => 'Aragua', 'active' => true, 'daily_budget' => 20.0,
            'target' => 5.01, 'break_even' => 7.49, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $windows]],
        'adsets' => [], 'ads' => [], 'creatives' => []];
    foreach ([1 => '2026-10-07 10:00:00', 2 => '2026-11-06 10:00:00'] as $n => $created) {
        $ad = "12000000000009{$n}0";
        $out['adsets']["s{$n}"] = ['meta_id' => "s{$n}", 'name' => "B00{$n}0 LEG", 'campaign_meta_id' => VALE_CAMPANA, 'active' => true, 'missing' => false, 'batch_code' => null,
            'angle_code' => null, 'concept' => null, 'stage' => null, 'min_spend' => 0.0, 'created_time' => $created, 'windows' => []];
        $out['ads'][$ad] = ['id' => $n, 'meta_id' => $ad, 'name' => "C09{$n}0 LEG", 'campaign_meta_id' => VALE_CAMPANA, 'adset_meta_id' => "s{$n}", 'creative_id' => $n,
            'creative_type' => 'video', 'video_length' => null, 'angle_code' => null, 'batch_code' => null, 'active' => true, 'created_time' => $created, 'windows' => $windows];
        $out['creatives'][$n] = ['id' => $n, 'label' => "C09{$n}0 LEG", 'type' => 'video', 'status' => null, 'status_source' => null, 'angle_code' => null,
            'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$ad]];
    }

    return $out;
}

/** Las decisiones de hoy con su muestra ($sample) y lo que gastó y vendió cada día ($perDay = [gasto, compras], de los últimos $days días). */
function valeDecide(?array $sample, array $perDay = [20.0, 2.0], int $days = 30, array $dismissed = []): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([9 => valeMeta()]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $daily = [];
    foreach (range(1, $days) as $i) {
        $daily[now()->subDays($i)->toDateString()] = ['spend' => $perDay[0], 'purchases' => $perDay[1]];
    }
    $city = ['available' => true, 'stock' => 140, 'units_per_day' => 4.0, 'days_of_stock' => 35.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('worth')->andReturn($sample ? [9 => $sample] : []);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [VALE_CAMPANA => ['daily' => $daily, 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0]]]);
    $ops->allows('products')->andReturn([9 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'cities' => [3 => $city], 'dismissed' => $dismissed,
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([9])[9];
}

function valeMuestra(int $orders, int $delivered): array
{
    return ['orders' => $orders, 'delivered' => $delivered, 'since' => '2026-10-20', 'to' => '2026-11-07', 'left_out' => 6,
        'cities' => [['city' => 'Aragua', 'orders' => $orders, 'delivered' => $delivered]]];
}

describe('.139: un producto Malo', function () {
    it('sale en "Frenar pérdidas" con sus dos salidas, y sus encargos nuevos esperan a que decidas', function () {
        $d = valeDecide(valeMuestra(30, 10));
        $m = collect(UrgentList::build(AnalysisFacts::build([9 => $d], ['campaigns' => [VALE_CAMPANA => 'LEGUP | MCAY | CBO']])))->firstWhere('kind', 'worth_bad');

        expect($d['product']['worth'])->toMatchArray(['level' => 'bad', 'early' => true, 'effectiveness' => 33.3, 'stop' => true, 'left_out' => 6])
            ->and($d['plan']['drop'])->toBeNull()
            ->and($m)->toMatchArray(['key' => '9:worth_bad', 'group' => 'frenar', 'tone' => 'error',
                'title' => 'LegUp no vale la pena: efectividad 33,3 % en sus últimos 30 pedidos',
                'why' => 'Efectividad 33,3 % en sus últimos 30 pedidos (primera lectura: faltan 20 pedidos para la completa). Tu línea: 40 % (con pocos pedidos, solo cuando está claramente mal).',
                'steps' => ['Si quieres terminar de vender lo que queda (140 unidades en sus ciudades): dile a tu asistente «pon LegUp en liquidación».',
                    'Si no vale la pena ni eso: dile a tu asistente «abandona LegUp». Después te sale la misión de apagar sus campañas.',
                    'Si quieres darle otra oportunidad: toca «No estoy de acuerdo» y sus encargos siguen normal 14 días.']])
            ->and($m['detail'])->toBe(['Aragua: 10 de 30 entregados (33,3 %).',
                'Efectividad = pedidos entregados ÷ pedidos que entraron desde el 20/10, ya con tiempo de entregarse; no cuentan 6 que esperaron stock.',
                'Mientras decides, no se piden batches nuevos de este producto; lo que los editores ya están haciendo sigue.'])
            ->and(DemandPlanner::plan([9 => $d], [], DecisionSettings::defaults(), now()->toDateString()))->toBe([]);
    });

    it('con $500 o más en 4 semanas queda Regular: ni misión ni freno', function () {
        // 28 días × (6 compras × $7,49 − $20) = $698,32
        $d = valeDecide(valeMuestra(60, 24), [20.0, 6.0]);

        expect($d['product']['worth'])->toMatchArray(['level' => 'regular', 'rescued' => true, 'gain28' => 698.32, 'stop' => false])
            ->and($d['plan']['worth_bad'])->toBeNull();
    });

    it('con "No estoy de acuerdo" sigue normal 14 días', function () {
        $d = valeDecide(valeMuestra(60, 20), dismissed: ['9:worth_bad' => now()->subDays(3)->toDateString()]);

        expect($d['product']['worth'])->toMatchArray(['level' => 'bad', 'stop' => false])->and($d['plan']['worth_bad'])->toBeNull();
    });

    it('sin gasto en Meta en 14 días: solo la etiqueta (sin misión), y igual no se le piden encargos', function () {
        $d = valeDecide(valeMuestra(60, 20), [0.0, 0.0]);

        expect($d['product']['worth']['stop'])->toBeTrue()->and($d['plan']['worth_bad'])->toBeNull();
    });

    it('un Regular o un Bueno no frena nada, y "Decide si sueltas" (.125) sigue como antes', function () {
        $d = valeDecide(valeMuestra(60, 35));

        expect($d['product']['worth'])->toMatchArray(['level' => 'good', 'stop' => false])->and($d['plan']['worth_bad'])->toBeNull();
    });
});

describe('.139: la etiqueta guardada y el orden de los editores', function () {
    it('guarda la etiqueta de los productos con campañas y la de los viejos que tuvieron pedidos (sin ganancia ni freno)', function () {
        foreach (range(1, 7) as $day) {
            pedidosDelDia(5, sprintf('2026-11-%02d', $day), 6, 1); // un producto viejo, sin campañas: 16,7 %
        }
        ProductWorthBoard::save([9 => ['product' => ['worth' => ['level' => 'bad', 'label' => 'Malo', 'stop' => true, 'gain28' => -140.0] + valeMuestra(60, 20)]]],
            DecisionSettings::defaults());

        expect(ProductWorthBoard::all())->toHaveKeys([9, 5])
            ->and(ProductWorthBoard::all()[9])->not->toHaveKey('gain28')
            ->and(ProductWorthBoard::all()[5])->toMatchArray(['level' => 'bad', 'orders' => 42, 'stop' => false])
            ->and(ProductWorthBoard::stoppedIds())->toBe([9])
            ->and(ProductWorthBoard::ranks())->toBe([9 => 3, 5 => 3]);

        // "No estoy de acuerdo" en su misión: se ve ya en la página de productos y en el pronóstico (sin esperar al análisis)
        ProductWorthBoard::brake(9, false);
        expect(ProductWorthBoard::stoppedIds())->toBe([]);
        ProductWorthBoard::brake(9, true);
        expect(ProductWorthBoard::stoppedIds())->toBe([9]);
    });

    it('a igual estado, lo de los productos Buenos va antes que lo de los Regulares', function () {
        $batch = fn (int $id, int $product, int $position) => (new CreativeBatch())->forceFill(['id' => $id, 'product_id' => $product, 'status' => 'approved', 'position' => $position]);
        $batches = collect([$batch(1, 7, 1), $batch(2, 8, 2), $batch(3, 9, 3)]);

        expect(EditorWork::sorted($batches, [7 => 2, 8 => 0])->pluck('id')->all())->toBe([2, 3, 1]) // Bueno, sin etiqueta, Regular
            ->and(EditorWork::sorted($batches)->pluck('id')->all())->toBe([1, 2, 3]);
    });
});
