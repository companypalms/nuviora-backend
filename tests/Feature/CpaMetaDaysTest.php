<?php

use App\Services\Finance\ExpenseLedger;
use App\Services\Finance\FinancialCpa;
use App\Services\Finance\Shrinkage;
use App\Services\Meta\MetaAdsData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Dueño, 2026-11-02: en Comprimax, Aragua salía "va bien" con un BE de $26. Su cohorte de 28 días tenía 3 semanas de
// pedidos sin datos de Meta (venían de campañas que el sistema no tiene), y cada compra parecía traer 4 pedidos.
// En una base en memoria: producto 1, ciudad 2; Meta empieza el 17 de octubre.

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

    DB::table('meta_campaigns')->insert(['meta_id' => 'c1', 'product_id' => 1, 'city_id' => 2]);
    // Del 30/09 al 27/10, un pedido entregado por día ($36,60 cobrado, $13,60 de costo)
    for ($d = Carbon::parse('2026-09-30'); $d->lte(Carbon::parse('2026-10-27')); $d->addDay()) {
        DB::table('order_financials')->insert(['product_id' => 1, 'city_id' => 2, 'created_date' => $d->toDateString(),
            'outcome' => 'delivered', 'revenue_main' => 36.60, 'cogs_main' => 10, 'delivery_cost' => 3.60]);
    }
    // Meta: un día antes no hubo gasto (no cuenta); desde el 17/10, $8 y 1 compra por día
    DB::table('meta_insights_daily')->insert(['campaign_meta_id' => 'c1', 'level' => 'campaign', 'date' => '2026-10-16', 'spend' => 0, 'purchases' => 0]);
    for ($d = Carbon::parse('2026-10-17'); $d->lte(Carbon::parse('2026-10-27')); $d->addDay()) {
        DB::table('meta_insights_daily')->insert(['campaign_meta_id' => 'c1', 'level' => 'campaign', 'date' => $d->toDateString(), 'spend' => 8, 'purchases' => 1]);
    }
});

function cohorte(string $from, string $to): array
{
    $expenses = Mockery::mock(ExpenseLedger::class);
    $expenses->allows('forRange')->andReturn(['items' => []]);
    $shrinkage = Mockery::mock(Shrinkage::class);
    $shrinkage->allows('forRange')->andReturn(['items' => []]);
    $cpa = new FinancialCpa(new MetaAdsData(), $expenses, $shrinkage);

    return (function () use ($from, $to) {
        $starts = $this->metaStarts();

        return ['starts' => $starts, 'levels' => $this->levels(Carbon::parse($from), Carbon::parse($to), ['cities' => [], 'all' => 0.0], $starts)];
    })->call($cpa);
}

describe('Los pedidos de antes de los datos de Meta', function () {
    it('el primer día de Meta es el primero con gasto', function () {
        expect(cohorte('2026-09-30', '2026-10-27')['starts'])->toBe(['1|2' => '2026-10-17']);
    });

    it('no cuentan: 28 días con 11 compras de Meta suman los 11 pedidos desde el 17/10, no los 28', function () {
        $city = cohorte('2026-09-30', '2026-10-27')['levels']['1|2'];
        expect((int) $city['created'])->toBe(11)->and((int) $city['delivered'])->toBe(11)
            ->and(round($city['revenue'], 2))->toBe(402.60)
            ->and((float) $city['purchases'])->toBe(11.0);

        // Un pedido por compra: el break-even sale de lo que deja cada pedido, no 2,5 veces más
        $e = FinancialCpa::economics($city, 10, ['type' => 'sales', 'pct' => 20]);
        expect($e['break_even'])->toBe(19.34)->and($e['target'])->toBe(12.02);
    });

    it('el producto suma sus ciudades ya recortadas', function () {
        expect((int) cohorte('2026-09-30', '2026-10-27')['levels']['1|']['created'])->toBe(11);
    });

    it('una semana con datos completos no cambia', function () {
        expect((int) cohorte('2026-10-21', '2026-10-27')['levels']['1|2']['created'])->toBe(7);
    });

    it('sin ningún dato de Meta cuentan todos los pedidos', function () {
        DB::table('meta_insights_daily')->delete();
        expect((int) cohorte('2026-09-30', '2026-10-27')['levels']['1|2']['created'])->toBe(28);
    });
});
