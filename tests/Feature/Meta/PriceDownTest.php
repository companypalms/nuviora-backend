<?php

use App\Models\MetaChange;
use App\Models\TargetCpaVersion;
use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Changes\ChangeLessons;
use App\Services\Meta\Changes\MetaChanges;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .140 (dueño, 2026-11-12): bajar el precio de un producto Bueno con mucho espacio debajo de su C, "para tratar de ganar más
// volumen sin sacrificar ni CPA ni rentabilidad"; y después, "si al final por esa decisión estamos ganando más dinero o no".

const PRECIO_CAMPANA = '120000000000000940';

/** FlexiStep con una campaña (C $5,57) y lo que gastó y vendió cada día de las últimas 2 semanas: $33 y 10 compras ($3,30). */
function precioDecide(array $ops = [], array $worth = ['orders' => 100, 'delivered' => 62]): array
{
    $t = MetaMetrics::sum([['spend' => 33.0, 'purchases' => 10, 'impressions' => 14000, 'reach' => 10000]]);
    $w = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    $meta = ['product' => ['id' => 5, 'name' => 'FlexiStep® Tobillera de compresión', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
        'campaigns' => [PRECIO_CAMPANA => ['meta_id' => PRECIO_CAMPANA, 'name' => 'FLEXISTEP | CCS | CBO', 'city_id' => 1, 'city' => 'Distrito Capital', 'active' => true,
            'daily_budget' => 33.0, 'target' => 5.57, 'break_even' => 8.40, 'target_source' => 'financial', 'target_no_cost' => false,
            'windows' => ['max' => $w, '7d' => $w, '3d' => $w, 'prev' => $w, 'yesterday' => $w]]], 'adsets' => [], 'ads' => [], 'creatives' => []];
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([5 => $meta]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $daily = [];
    foreach (range(1, 20) as $i) {
        $daily[now()->subDays($i)->toDateString()] = ['spend' => 33.0, 'purchases' => 10.0];
    }
    $o = Mockery::mock(OpsFacts::class);
    $o->allows('worth')->andReturn([5 => $worth + ['since' => '2026-11-01', 'to' => '2026-11-07', 'left_out' => 0, 'cities' => []]]);
    $o->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [PRECIO_CAMPANA => ['daily' => $daily, 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0]]]);
    $version = (new TargetCpaVersion())->forceFill(['product_id' => 5, 'city_id' => null, 'target' => 5.57, 'break_even' => 8.40, 'orders_delivered' => 92, 'purchases' => 195,
        'revenue' => 2520.8, 'variable_costs' => 678.04, 'cogs' => 250.24, 'fixed_pct' => 10, 'target_type' => 'sales', 'target_pct' => 20]);
    $o->allows('products')->andReturn([5 => $ops + ['price' => 23.14, 'price_change' => null, 'version' => $version, 'phase' => 'iterate', 'dismissed' => [],
        'cities' => [1 => ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null]],
        'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $o))->compute([5])[5];
}

describe('.140: "Baja el precio" en Tu día en Meta Ads', function () {
    beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00')));

    it('un producto Bueno con su CPA real 41 % debajo del C: misión en Crecer, con los pasos y el porqué', function () {
        $d = precioDecide();
        $m = collect(UrgentList::build(AnalysisFacts::build([5 => $d], ['campaigns' => [PRECIO_CAMPANA => 'FLEXISTEP | CCS | CBO']])))->firstWhere('kind', 'price_down');

        expect($d['plan']['price_down'])->toMatchArray(['from' => 23.14, 'to' => 21.0, 'c_new' => 4.86, 'effectiveness' => 62.0])
            ->and($m)->toMatchArray(['key' => '5:price_down', 'group' => 'crecer', 'city' => null,
                'title' => 'Baja el precio de FlexiStep de $23,14 a $21,00: se entrega bien y te sobra espacio en Meta',
                'why' => 'Tu CPA real de 14 días es $3,30 y tu C es $5,57: sobra 41 %. Con $21,00 el C queda en ~$4,86, todavía 47 % encima de tu CPA.'])
            ->and($m['steps'][0])->toBe('En Shopify, cambia el precio de «FlexiStep® Tobillera de compresión» a $21,00.')
            ->and($m['detail'][0])->toBe('Efectividad 62 % en sus últimos 100 pedidos: es un producto Bueno.')
            // la IA del análisis también lo lee
            ->and(AnalysisFacts::input(AnalysisFacts::build([5 => $d], ['campaigns' => []])))->toContain('Baja el precio de $23,14 a $21,00');
    });

    it('no, si el producto es Regular, si el precio cambió hace poco o se está midiendo, o con "No estoy de acuerdo"', function () {
        expect(precioDecide(worth: ['orders' => 100, 'delivered' => 50])['plan']['price_down'])->toBeNull()
            ->and(precioDecide(['price_change' => ['from' => 25.0, 'to' => 23.14]])['plan']['price_down'])->toBeNull()
            ->and(precioDecide(['price_measuring' => true])['plan']['price_down'])->toBeNull()
            ->and(precioDecide(['dismissed' => ['5:price_down' => '2026-11-05']])['plan']['price_down'])->toBeNull()
            ->and(precioDecide(['dismissed' => ['5:price_down' => '2026-10-20']])['plan']['price_down'])->not->toBeNull();
    });
});

describe('.140: el cambio de precio en el Historial de cambios', function () {
    beforeEach(function () {
        Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->longText('value')->nullable(), $t->timestamps()]);
        Schema::create('users', fn ($t) => [$t->id(), $t->string('names')->nullable(), $t->timestamps()]);
        Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description')]);
        Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
        Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('name'), $t->unsignedBigInteger('product_id')->nullable(),
            $t->unsignedBigInteger('city_id')->nullable(), $t->timestamps()]);
        Schema::create('meta_insights_daily', fn ($t) => [$t->id(), $t->date('date'), $t->string('level'), $t->string('meta_id'), $t->string('campaign_meta_id')->nullable(),
            $t->decimal('spend', 12, 2)->nullable(), $t->decimal('purchases', 12, 2)->nullable()]);
        Schema::create('orders', fn ($t) => [$t->id(), $t->timestamp('created_at')->nullable()]);
        Schema::create('order_products', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id'), $t->decimal('price', 10, 2),
            $t->boolean('is_upsell')->nullable()]);
        Schema::create('order_financials', function ($t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('kind', 10)->default('normal');
            $t->date('created_date')->nullable();
            $t->string('outcome', 15)->default('open');
            $t->unsignedBigInteger('status_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            foreach (['revenue_main', 'revenue_upsell', 'revenue_refunded', 'cogs_main', 'cogs_upsell', 'logistics_cost', 'delivery_cost', 'commission_deliverer',
                'commission_seller', 'commission_upsell', 'commission_leader', 'commission_other'] as $c) {
                $t->decimal($c, 12, 2)->default(0);
            }
        });
        Schema::create('order_tracking_comprehensive_logs', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('to_status_id')->nullable(),
            $t->timestamp('created_at')->nullable()]);
        Schema::create('target_cpa_versions', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('city_id')->nullable(),
            $t->decimal('target', 10, 2)->nullable(), $t->decimal('break_even', 10, 2)->nullable()]);
        (require database_path('migrations/2026_10_30_500000_create_meta_analyses_table.php'))->up();
        (require database_path('migrations/2026_11_10_200000_create_meta_changes_table.php'))->up();
        (require database_path('migrations/2026_11_12_200000_make_meta_changes_campaign_nullable.php'))->up();

        DB::table('statuses')->insert([['id' => 3, 'description' => 'Sin Stock'], ['id' => 16, 'description' => 'Entregado'], ['id' => 17, 'description' => 'Cancelado']]);
        DB::table('products')->insert(['id' => 5, 'title' => 'FLEXISTEP TOBILLERA', 'showable_name' => 'FlexiStep']);
        DB::table('meta_campaigns')->insert(['meta_id' => '111', 'name' => 'FLEXISTEP | CCS | CBO', 'product_id' => 5, 'city_id' => 1]);
        DB::table('target_cpa_versions')->insert(['product_id' => 5, 'city_id' => null, 'target' => 5.57, 'break_even' => 8.40]);
        // Del 1 al 7/11 a $39: 5 pedidos por día, 3 entregados; del 8 al 14/11 a $36: 6 por día, 4 entregados. $10 de costo por pedido
        $id = 0;
        foreach (range(1, 14) as $day) {
            $date = sprintf('2026-11-%02d', $day);
            [$price, $n, $delivered, $buys] = $day <= 7 ? [39.0, 5, 3, 5.0] : [36.0, 6, 4, 6.0];
            for ($i = 0; $i < $n; $i++) {
                $id++;
                DB::table('orders')->insert(['id' => $id, 'created_at' => "{$date} 10:00:00"]);
                DB::table('order_products')->insert(['order_id' => $id, 'product_id' => 5, 'price' => $price, 'is_upsell' => false]);
                DB::table('order_financials')->insert(['order_id' => $id, 'product_id' => 5, 'created_date' => $date, 'outcome' => $i < $delivered ? 'delivered' : 'not_delivered',
                    'status_id' => $i < $delivered ? 16 : 17, 'revenue_main' => $i < $delivered ? $price : 0, 'cogs_main' => 7, 'delivery_cost' => 3]);
            }
            DB::table('meta_insights_daily')->insert(['date' => $date, 'level' => 'campaign', 'meta_id' => '111', 'campaign_meta_id' => '111', 'spend' => 20, 'purchases' => $buys]);
        }
        // Un pedido suelto a $29 (una promoción) no es un cambio de precio
        DB::table('orders')->insert(['id' => 500, 'created_at' => '2026-11-03 11:00:00']);
        DB::table('order_products')->insert(['order_id' => 500, 'product_id' => 5, 'price' => 29, 'is_upsell' => false]);
        DB::table('meta_analyses')->insert(['date' => '2026-11-07', 'version' => 1, 'status' => 'ok', 'facts' => '[]',
            'urgent' => json_encode([['key' => '5:price_down', 'product_id' => 5, 'kind' => 'price_down', 'city' => null, 'title' => 'Baja el precio de FlexiStep de $39,00 a $36,00']]),
            'marks' => json_encode(['5:price_down' => ['status' => 'done']])]);
    });

    it('lo encuentra en los pedidos, lo une a la misión y, ya entregados, dice si se gana más dinero', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
        expect(app(MetaChanges::class)->refreshPrices())->toBe(['found' => 1, 'saved' => 1]);

        // antes: 3 × $39 − 5 × $10 − $20 = $47 por día; después: 4 × $36 − 6 × $10 − $20 = $64
        expect(MetaChange::first())->toMatchArray(['kind' => 'price', 'product_id' => 5, 'campaign_meta_id' => null, 'city' => null, 'target_c' => 5.57, 'target_be' => 8.4,
            'detail' => ['from' => 39, 'to' => 36], 'result' => 'met',
            'mission' => ['date' => '2026-11-07', 'kind' => 'price_down', 'title' => 'Baja el precio de FlexiStep de $39,00 a $36,00', 'done' => 'done'],
            'hypothesis' => 'Si bajo el precio de $39,00 a $36,00, entran más pedidos y gano más dólares por día que antes (ganabas $47,00 por día, con 5 pedidos por día).',
            'result_text' => 'Se cumplió: ganas $17,00 más por día (con $36,00, ganas $64,00 por día; antes, con $39,00, ganabas $47,00). 6 pedidos por día (antes 5), '
                . 'efectividad 66,7 % (antes 60 %), CPA $3,33 (antes $4,00).'])
            ->and(MetaChange::first()->date->toDateString())->toBe('2026-11-08');
    });

    it('mientras esos pedidos no tuvieron tiempo de entregarse: midiendo', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00'));
        app(MetaChanges::class)->refreshPrices();

        expect(MetaChange::first())->toMatchArray(['result' => 'measuring',
            'result_text' => 'Midiendo: el resultado sale cuando los pedidos de esos 7 días ya se hayan entregado (hacia el 19/11). Por ahora: 6 pedidos por día (antes 5).']);
    });

    it('sale en la pantalla del historial (sin campaña) y "La última vez" en la próxima misión de bajar el precio', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
        $svc = app(MetaChanges::class);
        $svc->refreshPrices();
        $row = collect($svc->page([])['changes'])->firstWhere('kind', 'price');
        $lessons = $svc->lessons('2026-11-20');
        $urgent = ChangeLessons::missions([['key' => '5:price_down', 'kind' => 'price_down', 'product_id' => 5, 'city' => null]], [], $lessons);

        expect($row)->toMatchArray(['kind_label' => 'Cambiar el precio', 'what' => 'El precio, de $39,00 a $36,00', 'campaign' => null, 'product' => 'FlexiStep', 'result' => 'met'])
            ->and($urgent[0]['last']['text'])->toStartWith('La última vez (08/11): el precio, de $39,00 a $36,00. Se cumplió');
    });
});
