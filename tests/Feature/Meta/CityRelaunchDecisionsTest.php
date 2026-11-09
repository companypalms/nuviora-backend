<?php

use App\Models\MetaAnalysis;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .117: una campaña apagada espera 3 batches nunca publicados en su ciudad, con los datos de Meta y lo que descarta el dueño.

/** FlexiStep con Aragua prendida y Carabobo apagada, salvo que se pida otra cosa. */
function metaReactivar(bool $araguaActive = true): array
{
    $t = MetaMetrics::sum([['spend' => 0, 'purchases' => 0, 'impressions' => 0]]);
    $w = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
    $win = ['3d' => $w, 'prev' => $w, '7d' => $w, 'yesterday' => $w, 'max' => $w];
    $c = fn (string $id, string $name, int $city, string $cityName, bool $active) => ['meta_id' => $id, 'name' => $name, 'city_id' => $city, 'city' => $cityName, 'active' => $active, 'missing' => false,
        'daily_budget' => 25.0, 'target' => 5.0, 'break_even' => 8.0, 'target_source' => 'financial', 'target_no_cost' => false, 'windows' => $win];

    return ['product' => ['id' => 5, 'name' => 'FlexiStep', 'mode' => 'normal'], 'creatives' => [], 'dates' => AdFacts::dates(), 'adsets' => [], 'ads' => [],
        'campaigns' => ['100' => $c('100', 'FLEXI | ARAGUA', 3, 'Aragua', $araguaActive), '200' => $c('200', 'FLEXI | CBOBO', 7, 'Carabobo', false)]];
}

function decisionesReactivar(array $meta, array $ready, array $extra = []): array
{
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([5 => $meta]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
    $city = ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null];
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(), 'campaigns' => ['100' => $empty, '200' => $empty]]);
    $ops->allows('products')->andReturn([5 => $extra + ['price' => 30.0, 'price_change' => null, 'cities' => [3 => $city, 7 => $city], 'version' => null,
        'bank' => ['codes' => $ready, 'ready' => $ready, 'launched' => []]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([5])[5];
}

describe('Con los datos de Meta', function () {
    it('3 batches listos: la ciudad apagada sale para prenderse, sin volverse urgente', function () {
        $d = decisionesReactivar(metaReactivar(), ['B0101', 'B0102', 'B0103', 'B0104']);
        $c = $d['campaigns']['200'];
        expect($c['action']['key'])->toBe('relaunch')->and(array_column($c['numbers']['offers'], 'code'))->toBe(['B0101', 'B0102', 'B0103'])
            ->and($d['plan']['urgency']['reasons'])->not->toContain('Ciudades en el break-even o encima')
            ->and($d['plan']['no_city'])->toBeNull();
    });

    it('con 2 batches todavía no sale nada', function () {
        expect(decisionesReactivar(metaReactivar(), ['B0101', 'B0102'])['campaigns']['200']['action'])->toBeNull();
    });

    it('si la descartaste no insiste', function () {
        expect(decisionesReactivar(metaReactivar(), ['B0101', 'B0102', 'B0103'], ['dismissed' => ['5:200:relaunch' => '2026-11-09']])['campaigns']['200']['action'])->toBeNull();
    });

    it('sin ninguna ciudad prendida y sin batches suficientes: lo decide el dueño', function () {
        $d = decisionesReactivar(metaReactivar(false), ['B0101']);
        expect($d['plan']['no_city'])->toBe(['have' => 1, 'need' => 3]);
        expect(decisionesReactivar(metaReactivar(false), ['B0101'], ['dismissed' => ['5:no_city' => '2026-11-09']])['plan']['no_city'])->toBeNull();
    });

    it('sin ninguna ciudad prendida pero con 3 batches: se prende, no hace falta decidir', function () {
        $d = decisionesReactivar(metaReactivar(false), ['B0101', 'B0102', 'B0103']);
        expect($d['plan']['no_city'])->toBeNull()->and($d['campaigns']['200']['action']['key'])->toBe('relaunch');
    });
});

describe('"No estoy de acuerdo"', function () {
    beforeEach(function () {
        Schema::create('roles', fn ($t) => [$t->id(), $t->string('description'), $t->timestamps()]);
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('names');
            $t->string('surnames')->default('');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->json('value')->nullable(), $t->timestamps()]);
        Schema::create('meta_analyses', function ($t) {
            $t->id();
            $t->date('date');
            $t->json('urgent')->nullable();
            $t->json('marks')->nullable();
            $t->timestamps();
        });
        Sanctum::actingAs(User::forceCreate(['names' => 'Dueño', 'email' => 'd@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Admin'])->id]));
        $this->analysis = MetaAnalysis::forceCreate(['date' => '2026-11-09', 'urgent' => [['key' => '5:200:relaunch', 'kind' => 'relaunch'], ['key' => '5:200:scale', 'kind' => 'scale']]]);
    });

    it('prender esa ciudad no se vuelve a sugerir; si lo deshace, sí', function () {
        $url = "/api/meta/analysis/{$this->analysis->id}/marks";
        $this->putJson($url, ['key' => '5:200:relaunch', 'status' => 'disagree', 'note' => 'Ahí ya no vendo'])->assertOk();
        expect(array_keys((array) Setting::get(OpsFacts::DISMISSED, [])))->toBe(['5:200:relaunch']);
        $this->putJson($url, ['key' => '5:200:relaunch', 'status' => null])->assertOk();
        expect((array) Setting::get(OpsFacts::DISMISSED, []))->toBe([]);
    });

    it('en otras misiones no guarda nada, y "Hecho" tampoco', function () {
        $url = "/api/meta/analysis/{$this->analysis->id}/marks";
        $this->putJson($url, ['key' => '5:200:scale', 'status' => 'disagree'])->assertOk();
        $this->putJson($url, ['key' => '5:200:relaunch', 'status' => 'done'])->assertOk();
        expect((array) Setting::get(OpsFacts::DISMISSED, []))->toBe([]);
    });
});
