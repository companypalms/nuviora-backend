<?php

use App\Models\CreativeBatch;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Queue\BatchCities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .87 (dueño, 2026-11-03): FlexiStep lanzó B0102 en Aragua y no en Carabobo; en Aragua solo B0102 tenía anuncios prendidos.
// Con números de Meta de verdad ("120…", solo dígitos): como llave de un arreglo, PHP los vuelve int, y antes de la .87 eso
// dejaba sin efecto la poda y la regla de la .65 en producción (las pruebas usaban "c1" y no se veía).

/** Una ventana con lo justo: gasto, compras e impresiones. */
function ventanaMeta(float $spend, float $purchases, float $impressions = 5000): array
{
    $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $purchases, 'impressions' => $impressions]]);
    $w = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];

    return ['3d' => $w, 'prev' => $w, '7d' => $w, 'yesterday' => $w, 'max' => $w];
}

const ARAGUA = '120000000000000001';
const CARABOBO = '120000000000000002';

/** Lo que leería AdFacts de FlexiStep (producto 5). */
function flexiMeta(): array
{
    $campaign = fn (string $id, string $name, int $cityId, string $city, float $spend, float $purchases) => ['meta_id' => $id, 'name' => $name,
        'city_id' => $cityId, 'city' => $city, 'active' => true, 'daily_budget' => 25.0, 'target' => 5.36, 'break_even' => 8.17, 'target_source' => 'financial',
        'target_no_cost' => false, 'windows' => ventanaMeta($spend, $purchases)];
    $set = fn (string $id, string $campaign, string $name, ?string $code, bool $active = true) => ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => $campaign,
        'active' => $active, 'missing' => false, 'batch_code' => $code, 'angle_code' => 'FAS', 'concept' => null, 'stage' => null, 'windows' => []];
    $ad = fn (string $id, string $campaign, string $set, string $name, bool $active, float $spend, float $purchases) => ['id' => (int) substr($id, -4), 'meta_id' => $id,
        'name' => $name, 'campaign_meta_id' => $campaign, 'adset_meta_id' => $set, 'creative_id' => null, 'creative_type' => 'video', 'video_length' => null,
        'angle_code' => 'FAS', 'batch_code' => null, 'active' => $active, 'windows' => ventanaMeta($spend, $purchases)];
    $f = ['product' => ['id' => 5, 'name' => 'FlexiStep', 'mode' => 'normal'], 'creatives' => [], 'dates' => AdFacts::dates(),
        'campaigns' => [ARAGUA => $campaign(ARAGUA, 'FLEXISTEP | ARAGUA', 3, 'Aragua', 4, 0), CARABOBO => $campaign(CARABOBO, 'FLEXISTEP | CBOBO', 7, 'Carabobo', 80, 10)],
        'adsets' => [
            '120000000000000011' => $set('120000000000000011', ARAGUA, 'B0102 FAS', 'B0102'),
            '120000000000000012' => $set('120000000000000012', ARAGUA, 'BATCH005 FAS', null), // prendido, pero sus anuncios apagados
            '120000000000000021' => $set('120000000000000021', CARABOBO, 'BATCH003 FAS', null),
        ],
        'ads' => []];
    foreach ([['120000000000000111', 'C0457'], ['120000000000000112', 'C0458'], ['120000000000000113', 'C0459']] as [$id, $name]) {
        $f['ads'][$id] = $ad($id, ARAGUA, '120000000000000011', $name, true, 1, 0);
    }
    $f['ads']['120000000000000121'] = $ad('120000000000000121', ARAGUA, '120000000000000012', 'CRE002-FLEX-FAS-BATCH005', false, 60, 9);
    $f['ads']['120000000000000211'] = $ad('120000000000000211', CARABOBO, '120000000000000021', 'CRE003-FLEX-FAS-BATCH003', true, 50, 10);
    $f['ads']['120000000000000212'] = $ad('120000000000000212', CARABOBO, '120000000000000021', 'CRE009-FLEX-FAS-BATCH003', true, 30, 0);

    return $f;
}

function decisionesFlexi(?array $meta = null, array $carabobo = [], array $daily = []): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([5 => $meta ?? flexiMeta()]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [ARAGUA => $empty, CARABOBO => ['daily' => $daily] + $empty]]);
    $ops->allows('products')->andReturn([5 => ['price' => 30.0, 'price_change' => null, 'cities' => [3 => $city, 7 => $carabobo + $city], 'version' => null,
        'bank' => ['codes' => ['B0103'], 'ready' => ['B0103'], 'launched' => ['B0102']]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([5])[5];
}

describe('.87: los batches por ciudad, con números de Meta de verdad', function () {
    it('poda en la ciudad y dice qué anuncio tira abajo el batch (antes no encontraba nada)', function () {
        $d = decisionesFlexi();
        $n = $d['campaigns'][CARABOBO]['numbers'];

        expect($d['campaigns'][CARABOBO]['action']['key'])->toBe('hold_prune')
            ->and($n['prune'])->toBe(['CRE009-FLEX-FAS-BATCH003'])
            ->and($d['adsets']['120000000000000021']['reading'])->toContain('Adentro: CRE009-FLEX-FAS-BATCH003: perdedor');
    });

    it('un batch prendido con todos sus anuncios apagados no está vivo; lo que se poda hoy tampoco', function () {
        $d = decisionesFlexi();

        expect($d['campaigns'][ARAGUA]['numbers']['live'])->toBe(1)      // solo B0102: BATCH005 tiene sus anuncios apagados
            ->and($d['campaigns'][CARABOBO]['numbers']['live'])->toBe(1); // BATCH003, con CRE009 podado, sigue con CRE003
    });

    it('B0102 salió en Aragua: en Carabobo sigue en banca (se duplica su ad set); en Aragua, solo lo de la banca', function () {
        $d = decisionesFlexi();

        expect($d['campaigns'][ARAGUA]['numbers']['offers'])->toBe([['code' => 'B0103', 'how' => 'bank']])
            ->and($d['campaigns'][CARABOBO]['numbers']['offers'])->toBe([
                ['code' => 'B0103', 'how' => 'bank'],
                ['code' => 'B0102', 'how' => 'copy', 'adset' => 'B0102 FAS', 'campaign' => 'FLEXISTEP | ARAGUA', 'city' => 'Aragua', 'verdict' => 'Sin datos'],
            ]);
    });

    it('un batch que ya gana en otra ciudad va antes que la banca', function () {
        $f = flexiMeta();
        $out = ['adsets' => ['120000000000000011' => ['key' => 'promising', 'label' => 'Prometedor']]];

        expect(array_column(MetaDecisions::offers($f, $out, CARABOBO, ['B0102'], ['B0103']), 'code'))->toBe(['B0102', 'B0103'])
            // si ya no está en Meta, no se puede duplicar
            ->and(MetaDecisions::offers(['adsets' => ['120000000000000011' => ['missing' => true] + $f['adsets']['120000000000000011']]] + $f, $out, CARABOBO, ['B0102'], []))->toBe([]);
    });
});

describe('.87: Cola y banca dice dónde salió y para qué ciudades sigue en banca', function () {
    beforeEach(function () {
        Schema::create('cities', function ($t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('meta_campaigns', function ($t) {
            $t->id();
            $t->string('meta_id');
            $t->string('effective_status')->nullable();
            $t->timestamp('missing_since')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
        });
        Schema::create('meta_adsets', function ($t) {
            $t->id();
            $t->string('meta_id');
            $t->string('campaign_meta_id');
            $t->string('batch_code')->nullable();
            $t->timestamp('missing_since')->nullable();
        });
        DB::table('cities')->insert([['id' => 1, 'name' => 'Caracas'], ['id' => 3, 'name' => 'Aragua'], ['id' => 7, 'name' => 'Carabobo']]);
        DB::table('meta_campaigns')->insert([
            ['meta_id' => ARAGUA, 'effective_status' => 'ACTIVE', 'product_id' => 5, 'city_id' => 3],
            ['meta_id' => CARABOBO, 'effective_status' => 'ACTIVE', 'product_id' => 5, 'city_id' => 7],
            ['meta_id' => '120000000000000003', 'effective_status' => 'ACTIVE', 'product_id' => 5, 'city_id' => 1],
            ['meta_id' => '120000000000000004', 'effective_status' => 'PAUSED', 'product_id' => 5, 'city_id' => 9],
        ]);
        DB::table('meta_adsets')->insert(['meta_id' => '120000000000000011', 'campaign_meta_id' => ARAGUA, 'batch_code' => 'B0102']);
    });

    it('B0102: lanzado en Aragua, en banca para Carabobo y Caracas (una campaña pausada no cuenta)', function () {
        $b = (new CreativeBatch())->forceFill(['id' => 40, 'product_id' => 5, 'number' => 102, 'status' => 'testing']);
        $bank = (new CreativeBatch())->forceFill(['id' => 41, 'product_id' => 5, 'number' => 103, 'status' => 'bank']);

        expect(BatchCities::of(collect([$b, $bank])))->toBe([40 => ['launched' => ['Aragua'], 'bank_for' => ['Carabobo', 'Caracas']]]);
    });

    it('si su ad set ya no está en Meta, no sigue en banca para nadie', function () {
        DB::table('meta_adsets')->update(['missing_since' => now()]);
        $b = (new CreativeBatch())->forceFill(['id' => 40, 'product_id' => 5, 'number' => 102, 'status' => 'tested']);

        expect(BatchCities::of(collect([$b])))->toBe([40 => ['launched' => ['Aragua'], 'bank_for' => []]]);
    });
});

describe('.101: pedidos esperando stock (dueño, 2026-11-04)', function () {
    // Carabobo va para subir: 12 compras en 3 días con CPA $2,50 (C $5,36)
    $meta = function (): array {
        $f = flexiMeta();
        $f['campaigns'][CARABOBO]['windows'] = ventanaMeta(30, 12);

        return $f;
    };
    $daily = ['2026-10-28' => ['spend' => 20.0, 'purchases' => 3], '2026-10-29' => ['spend' => 29.49, 'purchases' => 8], '2026-10-30' => ['spend' => 25.68, 'purchases' => 2]];

    it('sin pedidos esperando, sube', function () use ($meta, $daily) {
        expect(decisionesFlexi($meta(), [], $daily)['campaigns'][CARABOBO]['action']['key'])->toBe('scale');
    });

    it('con 55 esperando desde el 29/10: aviso con lo gastado desde entonces, y no sube', function () use ($meta, $daily) {
        $d = decisionesFlexi($meta(), ['waiting' => ['orders' => 55, 'since' => '2026-10-29']], $daily);
        expect($d['campaigns'][CARABOBO]['action']['key'])->toBe('hold')
            ->and($d['campaigns'][CARABOBO]['action']['reading'])->toBe('Va bien, pero hay 55 pedidos esperando stock en esta ciudad: no se sube hasta que llegue.')
            ->and($d['plan']['alarms'])->toContain('55 pedidos esperando stock en Carabobo desde el 29/10: la campaña gastó $55,17 desde entonces. Manda stock; mientras tanto no se sube el presupuesto ahí.');
    });

    it('con menos de 5 esperando, nada (puede ser un pedido que espera unas horas)', function () use ($meta, $daily) {
        $d = decisionesFlexi($meta(), ['waiting' => ['orders' => 4, 'since' => '2026-10-29']], $daily);
        expect($d['campaigns'][CARABOBO]['action']['key'])->toBe('scale')
            ->and(implode(' ', $d['plan']['alarms']))->not->toContain('esperando stock');
    });
});
