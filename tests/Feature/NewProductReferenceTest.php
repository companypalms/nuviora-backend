<?php

use App\Models\TargetCpaVersion;
use App\Services\Finance\FinancialCpa;
use App\Services\Meta\MetaAdsReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .79 (dueño, 2026-11-02): un producto nuevo (Termafit, campañas desde el 29/10) no tenía C ni BE: el sistema usa los
// pedidos de hace 5 días o más. Mientras no tenga 30 compras de Meta maduras, vale su referencia: la de Productos y
// objetivos o, si no la puso, la del nombre de la campaña. En una base en memoria: producto 7 (nuevo) y 8 (con historia).

beforeEach(function () {
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    Schema::create('product_ad_targets', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->decimal('target_cpa', 10, 2)->nullable();
        $t->decimal('break_even_cpa', 10, 2)->nullable();
        $t->date('valid_from');
        $t->unsignedBigInteger('created_by')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_campaigns', function ($t) {
        $t->id();
        $t->string('meta_id');
        $t->string('name')->nullable();
        $t->string('effective_status')->nullable();
        $t->timestamp('missing_since')->nullable();
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
    });
    DB::table('meta_campaigns')->insert([
        ['meta_id' => 't1', 'name' => 'TERMAFIT | MCAY |  CBO | HV | CPA $4| BE $5.6| 29/10/2026', 'effective_status' => 'ACTIVE', 'product_id' => 7, 'city_id' => 3],
        ['meta_id' => 't2', 'name' => 'TERMAFIT | CCS |  CBO | HV | CPA $4.2| BE $5.9| 29/10/2026', 'effective_status' => 'ACTIVE', 'product_id' => 7, 'city_id' => 1],
        ['meta_id' => 'f1', 'name' => 'FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026', 'effective_status' => 'ACTIVE', 'product_id' => 8, 'city_id' => 1],
    ]);
});

/** Lo que calculó el sistema (FinancialCpa::latest), por "p|c". */
function versiones(array $rows): void
{
    $out = [];
    foreach ($rows as $key => $r) {
        [$p, $c] = explode('|', $key);
        $out[$key] = (new TargetCpaVersion())->forceFill($r + ['product_id' => (int) $p, 'city_id' => $c === '' ? null : (int) $c, 'version' => 1,
            'period_from' => '2026-10-22', 'period_to' => '2026-10-28', 'computed_at' => '2026-11-02 06:30:00', 'detail' => ['no_cost' => false]]);
    }
    app()->instance(FinancialCpa::class, Mockery::mock(FinancialCpa::class, fn ($m) => $m->allows('latest')->andReturn($out)));
}

it('sin cálculo todavía, usa el CPA y el BE del nombre de su campaña en esa ciudad', function () {
    versiones([]);
    $r = new MetaAdsReport([], 'last_3d');
    expect($r->targetFor(7, 3))->toMatchArray(['target_cpa' => 4.0, 'break_even_cpa' => 5.6, 'source' => 'name'])
        ->and($r->targetFor(7, 1))->toMatchArray(['target_cpa' => 4.2, 'break_even_cpa' => 5.9, 'source' => 'name'])
        ->and($r->targetFor(7, null)['source'])->toBe('name');
});

it('con menos de 30 compras de Meta maduras, sigue con la referencia (un día de datos no alcanza)', function () {
    versiones(['7|' => ['purchases' => 13, 'target' => 6.10, 'break_even' => 8.40]]);
    expect((new MetaAdsReport([], 'last_3d'))->targetFor(7, 3))->toMatchArray(['target_cpa' => 4.0, 'source' => 'name']);
});

it('la de Productos y objetivos manda sobre la del nombre', function () {
    versiones([]);
    DB::table('product_ad_targets')->insert(['product_id' => 7, 'target_cpa' => 4.5, 'break_even_cpa' => 6.0, 'valid_from' => '2026-10-29']);
    expect((new MetaAdsReport([], 'last_3d'))->targetFor(7, 3))->toMatchArray(['target_cpa' => 4.5, 'break_even_cpa' => 6.0, 'source' => 'manual']);
});

it('con 30 compras o más manda el número del sistema y el nombre se ignora (FlexiStep)', function () {
    versiones(['8|' => ['purchases' => 739, 'target' => 5.29, 'break_even' => 7.94], '8|1' => ['purchases' => 427, 'target' => 5.39, 'break_even' => 8.02]]);
    expect((new MetaAdsReport([], 'last_3d'))->targetFor(8, 1))->toMatchArray(['target_cpa' => 5.39, 'break_even_cpa' => 8.02, 'source' => 'financial']);
});

it('sin la compra de inventario registrada no hay CPA, ni el del nombre (regla del 31/10)', function () {
    versiones(['7|' => ['purchases' => 13, 'target' => null, 'break_even' => null, 'detail' => ['no_cost' => true]]]);
    expect((new MetaAdsReport([], 'last_3d'))->targetFor(7, 3))->toMatchArray(['target_cpa' => null, 'source' => 'financial'])
        ->and((new MetaAdsReport([], 'last_3d'))->targetFor(7, 3)['financial']['no_cost'])->toBeTrue();
});
