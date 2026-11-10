<?php

use App\Models\MetaChange;
use App\Models\Role;
use App\Models\User;
use App\Services\Meta\Changes\MetaChanges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .131 (dueño, 2026-11-10): "necesito que cada cambio de Meta Ads tenga una hipótesis que se guarda en un historial". Con la
// base en memoria: FlexiStep en Caracas (C $6,00, BE $9,00), con las fotos de Meta del 1 al 8 de noviembre. Hoy es el 10/11.

beforeEach(function () {
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description'), $t->timestamps()]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('phone')->default('');
        $t->string('address')->default('');
        $t->string('email')->unique();
        $t->string('password');
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamps();
    });
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('cities', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('name'), $t->unsignedBigInteger('product_id')->nullable(),
        $t->unsignedBigInteger('city_id')->nullable(), $t->timestamps()]);
    Schema::create('meta_adsets', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id'), $t->string('name'), $t->string('batch_code')->nullable(),
        $t->timestamp('created_time')->nullable(), $t->timestamps()]);
    Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id'), $t->string('adset_meta_id')->nullable(), $t->string('name'),
        $t->unsignedBigInteger('meta_creative_ref_id')->nullable(), $t->timestamps()]);
    Schema::create('meta_creatives', fn ($t) => [$t->id(), $t->string('label')->nullable(), $t->string('tracking_id')->nullable(), $t->string('creative_code')->nullable(), $t->timestamps()]);
    Schema::create('meta_entity_snapshots', fn ($t) => [$t->id(), $t->string('level'), $t->string('meta_id'), $t->string('status')->nullable(),
        $t->string('effective_status')->nullable(), $t->decimal('daily_budget', 12, 2)->nullable(), $t->decimal('min_spend', 12, 2)->nullable(), $t->timestamp('recorded_at')]);
    Schema::create('meta_insights_daily', function ($t) {
        $t->id();
        $t->date('date');
        $t->string('level');
        $t->string('meta_id');
        $t->string('campaign_meta_id')->nullable();
        $t->string('adset_meta_id')->nullable();
        $t->string('ad_meta_id')->nullable();
        foreach (['spend', 'purchases', 'impressions', 'reach', 'unique_link_clicks', 'video_3s_plays', 'thruplays'] as $c) {
            $t->decimal($c, 12, 2)->nullable();
        }
    });
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->string('status');
        $t->string('kind')->nullable();
        $t->string('source')->nullable();
        $t->string('angle_code')->nullable();
        $t->text('hypothesis')->nullable();
        $t->unsignedBigInteger('reference_creative_id')->nullable();
        $t->unsignedInteger('testing_round')->nullable();
        $t->json('result')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_pieces', fn ($t) => [$t->id(), $t->unsignedBigInteger('creative_batch_id'), $t->unsignedInteger('position')->nullable(),
        $t->string('test')->nullable(), $t->string('style')->nullable(), $t->string('format')->nullable(), $t->timestamps()]);
    (require database_path('migrations/2026_10_30_500000_create_meta_analyses_table.php'))->up();
    (require database_path('migrations/2026_11_10_200000_create_meta_changes_table.php'))->up();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00'));
    Cache::flush();
    DB::table('products')->insert(['id' => 5, 'title' => 'FLEXISTEP RODILLERA', 'showable_name' => 'FlexiStep']);
    DB::table('cities')->insert(['id' => 1, 'name' => 'Distrito Capital']);
    DB::table('meta_campaigns')->insert([['meta_id' => '111', 'name' => 'FLEXISTEP | CCS | CBO', 'product_id' => 5, 'city_id' => 1],
        ['meta_id' => '222', 'name' => 'SIN CLASIFICAR', 'product_id' => null, 'city_id' => null]]);
    DB::table('meta_adsets')->insert([['meta_id' => 's1', 'campaign_meta_id' => '111', 'name' => 'B0095 FLEX', 'batch_code' => 'B0095', 'created_time' => '2026-10-20 10:00:00'],
        ['meta_id' => 's3', 'campaign_meta_id' => '111', 'name' => 'B0121 FLEX', 'batch_code' => 'B0121', 'created_time' => '2026-11-07 10:00:00']]);
    DB::table('meta_ads')->insert([['meta_id' => 'a1', 'campaign_meta_id' => '111', 'adset_meta_id' => 's1', 'name' => 'C0410 FLEX', 'meta_creative_ref_id' => null],
        ['meta_id' => 'a5', 'campaign_meta_id' => '111', 'adset_meta_id' => 's3', 'name' => 'C0450 FLEX', 'meta_creative_ref_id' => null],
        ['meta_id' => 'a9', 'campaign_meta_id' => '111', 'adset_meta_id' => 's1', 'name' => 'C0390 FLEX', 'meta_creative_ref_id' => 77]]);
    DB::table('meta_creatives')->insert(['id' => 77, 'label' => 'C0390 FLEX']);
    $snap = fn (string $level, string $id, string $at, ?string $status, ?float $budget = null, ?float $min = null) => ['level' => $level, 'meta_id' => $id,
        'status' => $status, 'daily_budget' => $budget, 'min_spend' => $min, 'recorded_at' => $at];
    DB::table('meta_entity_snapshots')->insert([
        $snap('campaign', '111', '2026-11-01 08:00:00', 'ACTIVE', 30.0), $snap('campaign', '111', '2026-11-04 10:00:00', 'ACTIVE', 36.0),
        $snap('campaign', '111', '2026-11-08 09:00:00', 'PAUSED', 36.0),
        $snap('campaign', '222', '2026-11-01 08:00:00', 'ACTIVE', 10.0), $snap('campaign', '222', '2026-11-03 08:00:00', 'ACTIVE', 20.0),
        $snap('adset', 's1', '2026-11-01 08:00:00', 'ACTIVE', null, 5.0), $snap('adset', 's1', '2026-11-06 11:00:00', 'ACTIVE', null, null),
        $snap('ad', 'a1', '2026-11-01 08:00:00', 'ACTIVE'), $snap('ad', 'a1', '2026-11-05 09:30:00', 'PAUSED'),
    ]);
    foreach (['01' => [30, 5], '02' => [30, 5], '03' => [30, 5], '04' => [33, 5], '05' => [36, 7], '06' => [36, 6], '07' => [36, 4], '08' => [10, 1]] as $d => [$spend, $purchases]) {
        DB::table('meta_insights_daily')->insert(['date' => "2026-11-{$d}", 'level' => 'campaign', 'meta_id' => '111', 'spend' => $spend, 'purchases' => $purchases]);
    }
    // El hook rate del batch (30 %) y el del original (20 %)
    DB::table('meta_insights_daily')->insert([['date' => '2026-11-08', 'level' => 'ad', 'meta_id' => 'a5', 'adset_meta_id' => 's3', 'ad_meta_id' => 'a5', 'impressions' => 1000, 'video_3s_plays' => 300],
        ['date' => '2026-11-08', 'level' => 'ad', 'meta_id' => 'a9', 'adset_meta_id' => 's1', 'ad_meta_id' => 'a9', 'impressions' => 1000, 'video_3s_plays' => 200]]);
    DB::table('meta_analyses')->insert(['date' => '2026-11-04', 'version' => 1, 'status' => 'ok', 'created_at' => '2026-11-04 00:30:00',
        'facts' => json_encode([['product_id' => 5, 'cities' => [['campaign_meta_id' => '111', 'city' => 'Distrito Capital', 'numbers' => ['c' => 6.0, 'be' => 9.0]]]]]),
        'urgent' => json_encode([['key' => '5:111:scale', 'product_id' => 5, 'kind' => 'scale', 'city' => 'Distrito Capital', 'title' => 'Sube Distrito Capital 20 %: $30,00 → $36,00']]),
        'marks' => json_encode(['5:111:scale' => ['status' => 'done']])]);
    $batch = DB::table('creative_batches')->insertGetId(['product_id' => 5, 'number' => 121, 'status' => 'tested', 'kind' => 'iteration', 'source' => 'rules',
        'reference_creative_id' => 77, 'result' => json_encode(['verdicts' => [['city' => 'Distrito Capital', 'key' => 'promising', 'label' => 'Prometedor']]])]);
    foreach ([1, 2, 3] as $i) {
        DB::table('creative_batch_pieces')->insert(['creative_batch_id' => $batch, 'position' => $i, 'test' => 'hook']);
    }

    $role = Role::forceCreate(['description' => 'Admin']);
    $this->admin = User::forceCreate(['names' => 'Dueño', 'email' => 'd@test', 'password' => 'x', 'role_id' => $role->id]);
});

describe('.131: el historial de cambios', function () {
    it('guarda cada cambio con su hipótesis, la misión que lo pidió y si se cumplió', function () {
        expect(app(MetaChanges::class)->refresh())->toBe(['found' => 5, 'saved' => 5]);
        $by = MetaChange::all()->keyBy('kind');

        // la campaña sin clasificar no entra
        expect(MetaChange::where('campaign_meta_id', '222')->exists())->toBeFalse()
            ->and($by['raise'])->toMatchArray(['product_id' => 5, 'city' => 'Distrito Capital', 'target_c' => 6.0, 'target_be' => 9.0, 'result' => 'met',
                'hypothesis' => 'Si subo el presupuesto de $30,00 a $36,00 (+20 %), la ciudad deja más dinero por día que antes (dejaba $15,00) sin pasar tu BE ($9,00).'])
            ->and($by['raise']->mission)->toBe(['date' => '2026-11-04', 'kind' => 'scale', 'title' => 'Sube Distrito Capital 20 %: $30,00 → $36,00', 'done' => 'done'])
            ->and($by['raise']->measured_at)->not->toBeNull()
            ->and($by['ads_off'])->toMatchArray(['result' => 'met', 'mission' => null])
            ->and($by['min_off']->result)->toBe('unknown')
            ->and($by['pause']->result)->toBe('not_measured')
            // el batch: la hipótesis fija de la variación, su veredicto en Caracas y su hook rate contra el original
            ->and($by['new_batch']->hypothesis)->toBe('Este creativo tuvo un hook rate bajo. Queremos probar si variando el hook aumentamos su hook rate y su efectividad.')
            ->and($by['new_batch']->result_text)->toBe('Se cumplió: Prometedor en Distrito Capital. Hook rate: 30 % (el original, 20 %), mejoró.');
    });

    it('la C y el BE: del análisis más cercano que los trae (los de antes de la .82 no los traen)', function () {
        DB::table('meta_analyses')->delete();
        $facts = fn (?array $numbers) => json_encode([['product_id' => 5, 'cities' => [['campaign_meta_id' => '111', 'city' => 'Distrito Capital'] + ($numbers ? ['numbers' => $numbers] : [])]]]);
        DB::table('meta_analyses')->insert([['date' => '2026-11-03', 'version' => 1, 'status' => 'ok', 'facts' => $facts(null), 'urgent' => '[]', 'marks' => null],
            ['date' => '2026-11-06', 'version' => 1, 'status' => 'ok', 'facts' => $facts(['c' => 7.0, 'be' => 10.0]), 'urgent' => '[]', 'marks' => null]]);
        app(MetaChanges::class)->refresh();

        expect(MetaChange::where('kind', 'raise')->first())->toMatchArray(['target_c' => 7.0, 'target_be' => 10.0, 'mission' => null]);
    });

    it('no se repite, y lo ya medido queda fijo a los 14 días; la nota del dueño se queda', function () {
        $svc = app(MetaChanges::class);
        $svc->refresh();
        $raise = MetaChange::where('kind', 'raise')->first();
        $raise->forceFill(['note' => 'Lo subí por la promo'])->save();
        $svc->refresh();
        expect(MetaChange::count())->toBe(5)->and($raise->fresh()->note)->toBe('Lo subí por la promo');

        // Meta corrige los números después de 14 días: lo que ya se midió no cambia
        DB::table('meta_insights_daily')->where('meta_id', '111')->where('date', '2026-11-05')->update(['purchases' => 0]);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00:00'));
        $svc->refresh();
        expect($raise->fresh()->result)->toBe('met');
    });

    it('la pantalla: el marcador, los filtros y la nota', function () {
        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/api/meta/changes')->assertOk();

        expect($r->json('score'))->toMatchArray(['met' => 3, 'not_met' => 0, 'measuring' => 0, 'unknown' => 1, 'not_measured' => 1, 'total' => 5])
            ->and(collect($r->json('score.by_kind'))->firstWhere('kind', 'raise'))->toBe(['kind' => 'raise', 'label' => 'Subir el presupuesto', 'met' => 1, 'not_met' => 0,
                'measuring' => 0, 'unknown' => 0, 'total' => 1])
            ->and(array_column($r->json('changes'), 'kind'))->toBe(['pause', 'new_batch', 'min_off', 'ads_off', 'raise'])
            ->and($r->json('products'))->toBe([['id' => 5, 'name' => 'FlexiStep']])
            ->and($r->json('cities'))->toBe(['Distrito Capital'])
            ->and($r->json('refresh_error'))->toBeNull();

        $raise = $this->getJson('/api/meta/changes?kind=raise')->assertOk()->json('changes.0');
        expect($raise)->toMatchArray(['product' => 'FlexiStep', 'campaign' => 'FLEXISTEP | CCS | CBO', 'what' => 'De $30,00 a $36,00 por día (+20 %)', 'big' => true,
            'result_label' => 'Se cumplió', 'criterion' => 'Se cumple si en los días después deja más dinero por día que antes, sin pasar tu BE.']);

        $this->putJson("/api/meta/changes/{$raise['id']}/note", ['note' => '  Por la promo  '])->assertOk()->assertJson(['note' => 'Por la promo']);
        $this->putJson("/api/meta/changes/{$raise['id']}/note", ['note' => str_repeat('x', 501)])->assertStatus(422);
        $this->getJson('/api/meta/changes?kind=otra')->assertStatus(422);
    });

    it('solo el Admin', function () {
        $seller = User::forceCreate(['names' => 'Vendedora', 'email' => 'v@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Vendedor'])->id]);
        Sanctum::actingAs($seller);
        $this->getJson('/api/meta/changes')->assertForbidden();
    });
});
