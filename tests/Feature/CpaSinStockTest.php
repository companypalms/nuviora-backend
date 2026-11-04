<?php

use App\Services\Finance\ExpenseLedger;
use App\Services\Finance\FinancialCpa;
use App\Services\Finance\Shrinkage;
use App\Services\Meta\MetaAdsData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .101 (dueño, 2026-11-04): Termafit salió con C $2,91 y BE $4,39 y el análisis apagó el CRE002 en Aragua. Sus pedidos del
// 29 y 30/10 (los reales de producción): 36, de los que 11 estaban en "Sin Stock" en Distrito Capital. En una base en
// memoria: producto 1177; ciudades 1 Distrito Capital, 3 Aragua, 4 Carabobo; estados 1 Entregado, 2 Cancelado,
// 3 Programado para otro dia, 4 Sin Stock.

beforeEach(function () {
    Schema::create('order_financials', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
        $t->date('created_date');
        $t->string('kind')->default('normal');
        $t->string('outcome')->nullable();
        $t->unsignedBigInteger('status_id')->nullable();
        foreach (['revenue_main', 'revenue_upsell', 'revenue_refunded', 'cogs_main', 'cogs_upsell', 'logistics_cost', 'delivery_cost',
            'commission_deliverer', 'commission_seller', 'commission_upsell', 'commission_leader', 'commission_other'] as $c) {
            $t->decimal($c, 12, 2)->default(0);
        }
        $t->unsignedInteger('delivery_missing_trips')->default(0);
    });
    Schema::create('statuses', function ($t) {
        $t->id();
        $t->string('description');
        $t->timestamps();
    });
    Schema::create('meta_campaigns', function ($t) {
        $t->id();
        $t->string('meta_id');
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
    });
    Schema::create('meta_insights_daily', function ($t) {
        $t->id();
        $t->string('campaign_meta_id');
        $t->string('level');
        $t->date('date');
        $t->decimal('spend', 12, 2)->default(0);
        $t->decimal('purchases', 10, 2)->nullable();
    });
    DB::table('statuses')->insert([['id' => 1, 'description' => 'Entregado'], ['id' => 2, 'description' => 'Cancelado'],
        ['id' => 3, 'description' => 'Programado para otro dia'], ['id' => 4, 'description' => 'Sin Stock']]);

    // Por ciudad: [entregados, lo cobrado y el costo de cada uno, cancelados, programados, sin stock], y Meta por día
    $ciudades = [
        3 => ['t3', [2, 29.00, 7.90, 3, 3, 0], ['2026-10-29' => [26.15, 5], '2026-10-30' => [26.16, 4]]],
        4 => ['t4', [5, 28.40, 8.70, 3, 3, 0], ['2026-10-29' => [29.49, 8], '2026-10-30' => [25.68, 2]]],
        1 => ['t1', [2, 29.00, 9.70, 2, 2, 11], ['2026-10-29' => [29.89, 10], '2026-10-30' => [25.86, 6]]],
    ];
    foreach ($ciudades as $city => [$meta, [$delivered, $cobrado, $costo, $cancelled, $open, $sinStock], $dias]) {
        DB::table('meta_campaigns')->insert(['meta_id' => $meta, 'product_id' => 1177, 'city_id' => $city]);
        $row = ['product_id' => 1177, 'city_id' => $city, 'created_date' => '2026-10-29'];
        for ($i = 0; $i < $delivered; $i++) {
            DB::table('order_financials')->insert($row + ['outcome' => 'delivered', 'status_id' => 1, 'revenue_main' => $cobrado, 'cogs_main' => $costo]);
        }
        foreach ([[$cancelled, 'not_delivered', 2], [$open, 'open', 3], [$sinStock, 'open', 4]] as [$n, $outcome, $status]) {
            for ($i = 0; $i < $n; $i++) {
                DB::table('order_financials')->insert($row + ['outcome' => $outcome, 'status_id' => $status]);
            }
        }
        foreach ($dias as $date => [$spend, $purchases]) {
            DB::table('meta_insights_daily')->insert(['campaign_meta_id' => $meta, 'level' => 'campaign', 'date' => $date, 'spend' => $spend, 'purchases' => $purchases]);
        }
    }
});

function cohorteTermafit(): array
{
    $expenses = Mockery::mock(ExpenseLedger::class);
    $expenses->allows('forRange')->andReturn(['items' => []]);
    $shrinkage = Mockery::mock(Shrinkage::class);
    $shrinkage->allows('forRange')->andReturn(['items' => []]);
    $cpa = new FinancialCpa(new MetaAdsData(), $expenses, $shrinkage);

    return (fn () => $this->levels(Carbon::parse('2026-10-24'), Carbon::parse('2026-10-30'), ['cities' => [], 'all' => 0.0], $this->metaStarts()))->call($cpa);
}

describe('.101: los pedidos sin stock no cuentan (Termafit, 29 y 30/10)', function () {
    it('el producto: 25 pedidos que cuentan, 9 entregados, 17 terminados y 11 sin stock', function () {
        $p = cohorteTermafit()['1177|'];
        expect((int) $p['created'])->toBe(25)->and((int) $p['delivered'])->toBe(9)->and((int) $p['closed'])->toBe(17)
            ->and((int) $p['sin_stock'])->toBe(11)->and(round($p['revenue'], 2))->toBe(258.00)->and(round($p['variable'], 2))->toBe(78.70);
    });

    it('Distrito Capital: sus 11 sin stock se llevan su parte de las compras de Meta (16 × 6/17) y del gasto', function () {
        $dc = cohorteTermafit()['1177|1'];
        expect(round($dc['purchases'], 2))->toBe(5.65)->and(round($dc['spend'], 2))->toBe(19.68)
            ->and(round(cohorteTermafit()['1177|3']['purchases'], 2))->toBe(9.0);
    });

    it('C $4,13 y BE $6,23, en vez de $2,91 y $4,39', function () {
        $e = FinancialCpa::economics(cohorteTermafit()['1177|'], 10, ['type' => 'sales', 'pct' => 20]);
        expect($e['target'])->toBe(4.13)->and($e['break_even'])->toBe(6.23)->and($e['closed'])->toBe(17)->and($e['sin_stock'])->toBe(11);
    });

    it('si llega el stock (los pedidos avanzan), vuelven a contar: C $2,91 y BE $4,39 como antes', function () {
        DB::table('order_financials')->where('status_id', 4)->update(['status_id' => 3]);
        $e = FinancialCpa::economics(cohorteTermafit()['1177|'], 10, ['type' => 'sales', 'pct' => 20]);
        expect($e['target'])->toBe(2.91)->and($e['break_even'])->toBe(4.39)->and($e['sin_stock'])->toBe(0);
    });

    it('un pedido sin estado cuenta normal', function () {
        DB::table('order_financials')->where('status_id', 3)->update(['status_id' => null]);
        expect((int) cohorteTermafit()['1177|']['created'])->toBe(25);
    });

    it('con 17 pedidos terminados no hay muestra: Termafit todavía no usa su propio número', function () {
        $e = FinancialCpa::economics(cohorteTermafit()['1177|'], 10, ['type' => 'sales', 'pct' => 20]);
        expect(FinancialCpa::pick($e, $e, 30))->toBe('long')->and($e['closed'])->toBeLessThan(30);
    });
});
