<?php

use App\Models\Setting;
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
        $t->unsignedBigInteger('order_id')->nullable();
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
    // .110: el historial de estados y los ajustes (las horas en "Sin Stock")
    Schema::create('order_tracking_comprehensive_logs', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('from_status_id')->nullable();
        $t->unsignedBigInteger('to_status_id')->nullable();
        $t->timestamps();
    });
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
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

// .110 (dueño, 2026-11-06): ZenPulse arrancó el 27/10 sin el producto en las ciudades y la C salió baja; en Carabobo 29 de
// sus 54 pedidos pasaron por "Sin Stock". Los que estuvieron más de 12 horas tampoco cuentan, aunque después avanzaran.
// Aquí: los 11 de Distrito Capital ya salieron de "Sin Stock" (hoy están programados), después de $horas horas.

function estuvieronSinStock(array $horas): void
{
    DB::table('order_financials')->update(['order_id' => DB::raw('id')]);
    $ids = DB::table('order_financials')->where('status_id', 4)->pluck('id');
    DB::table('order_financials')->whereIn('id', $ids)->update(['status_id' => 3]);
    foreach ($ids as $id) {
        $at = Carbon::parse('2026-10-29 10:00');
        DB::table('order_tracking_comprehensive_logs')->insert(['order_id' => $id, 'from_status_id' => null, 'to_status_id' => 3, 'created_at' => $at->copy()->subHour()]);
        foreach ($horas as $h) {
            DB::table('order_tracking_comprehensive_logs')->insert(['order_id' => $id, 'from_status_id' => 3, 'to_status_id' => 4, 'created_at' => $at->copy()]);
            // una reasignación mientras espera no lo saca de "Sin Stock"
            DB::table('order_tracking_comprehensive_logs')->insert(['order_id' => $id, 'from_status_id' => 4, 'to_status_id' => 4, 'created_at' => $at->copy()->addMinutes(30)]);
            DB::table('order_tracking_comprehensive_logs')->insert(['order_id' => $id, 'from_status_id' => 4, 'to_status_id' => 3, 'created_at' => $at->addMinutes((int) round($h * 60))->copy()]);
            $at->addHour();
        }
    }
}

describe('.110: lo que estuvo más de 12 horas en "Sin Stock" tampoco cuenta', function () {
    $cpa = fn () => FinancialCpa::economics(cohorteTermafit()['1177|'], 10, ['type' => 'sales', 'pct' => 20]);

    it('14 horas esperando: aunque ya avanzaron, no cuentan (C $4,13 y BE $6,23, igual que si siguieran ahí)', function () use ($cpa) {
        estuvieronSinStock([14]);
        expect($cpa()['target'])->toBe(4.13)->and($cpa()['break_even'])->toBe(6.23)->and($cpa()['sin_stock'])->toBe(11)
            ->and(round(cohorteTermafit()['1177|1']['purchases'], 2))->toBe(5.65);
    });

    it('un rato corto (2 horas) sí cuenta: C $2,91 y BE $4,39, como antes', function () use ($cpa) {
        estuvieronSinStock([2]);
        expect($cpa()['target'])->toBe(2.91)->and($cpa()['break_even'])->toBe(4.39)->and($cpa()['sin_stock'])->toBe(0);
    });

    it('se suman todas las veces: 7 horas y después 6 son 13', function () use ($cpa) {
        estuvieronSinStock([7, 6]);
        expect($cpa()['sin_stock'])->toBe(11);
    });

    it('también sale el que al final se entregó (sacar solo los cancelados haría la C optimista)', function () {
        estuvieronSinStock([14]);
        $id = DB::table('order_financials')->where('city_id', 1)->where('outcome', 'delivered')->value('id');
        DB::table('order_tracking_comprehensive_logs')->insert([['order_id' => $id, 'from_status_id' => 3, 'to_status_id' => 4, 'created_at' => '2026-10-29 10:00'],
            ['order_id' => $id, 'from_status_id' => 4, 'to_status_id' => 1, 'created_at' => '2026-10-30 06:00']]);
        $p = cohorteTermafit()['1177|'];
        expect((int) $p['created'])->toBe(24)->and((int) $p['delivered'])->toBe(8)->and((int) $p['sin_stock'])->toBe(12);
    });

    it('las horas se cambian en Finanzas › Configuración (con 15, los de 14 horas vuelven a contar)', function () use ($cpa) {
        estuvieronSinStock([14]);
        Setting::set(FinancialCpa::SETTING_SIN_STOCK_HOURS, 15);
        expect(FinancialCpa::sinStockHours())->toBe(15.0)->and($cpa()['sin_stock'])->toBe(0);
    });

    it('las horas de un pedido (PHP puro): una reasignación no corta y si sigue ahí cuenta hasta ahora', function () {
        $c = [['to' => 3, 'at' => '2026-10-29 08:00'], ['to' => 4, 'at' => '2026-10-29 10:00'], ['to' => 4, 'at' => '2026-10-29 11:00'],
            ['to' => 3, 'at' => '2026-10-29 22:30'], ['to' => 4, 'at' => '2026-10-30 08:00']];
        expect(FinancialCpa::hoursIn(array_slice($c, 0, 4), 4, '2026-10-31 00:00'))->toBe(12.5)
            ->and(FinancialCpa::hoursIn($c, 4, '2026-10-30 10:00'))->toBe(14.5)
            ->and(FinancialCpa::hoursIn([['to' => 1, 'at' => '2026-10-29 08:00']], 4, '2026-10-31 00:00'))->toBe(0.0);
    });
});
