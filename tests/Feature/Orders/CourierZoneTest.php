<?php

use App\Models\City;
use App\Models\MetaCampaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Meta\CampaignSuggester;
use App\Services\Orders\CourierZone;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .174 (dueño, 2026-11-21): la zona "Resto de Venezuela", solo para los números y Meta Ads: la 4.ª campaña se clasifica ahí,
// todas las encomiendas cuentan ahí y lo que cobra MRW o Zoom lo pone el dueño. No se opera ahí: no sale en las listas para
// operar. En una base en memoria (no toca la base local).

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
        $t->timestamp('deactivated_at')->nullable();
        $t->rememberToken();
        $t->timestamps();
    });
    Schema::create('cities', fn ($t) => [$t->id(), $t->string('name')->unique(), $t->decimal('delivery_cost_usd', 8, 2)->default(0),
        $t->unsignedBigInteger('agency_id')->nullable(), $t->boolean('is_working')->default(true), $t->timestamps()]);
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()]);
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('name'), $t->unsignedBigInteger('product_id')->nullable(),
        $t->unsignedBigInteger('city_id')->nullable(), $t->timestamps()]);
    DB::table('cities')->insert([['name' => 'Distrito Capital'], ['name' => 'Carabobo'], ['name' => 'Aragua']]);
    DB::table('products')->insert(['title' => 'Cerofrizz']);
    (require database_path('migrations/2026_11_22_120000_courier_zone.php'))->up();
});

describe('.174: la zona «Resto de Venezuela»', function () {
    it('la migración la crea una sola vez', function () {
        (require database_path('migrations/2026_11_22_120000_courier_zone.php'))->up();

        expect(DB::table('cities')->where('name', 'Resto de Venezuela')->count())->toBe(1)
            ->and(CourierZone::id())->toBe((int) DB::table('cities')->where('name', 'Resto de Venezuela')->value('id'));
    });

    it('no sale en las listas para operar (ciudades, Cambiar ciudad o agencia…); sí para Meta Ads', function () {
        $role = Role::forceCreate(['description' => 'Admin']);
        Sanctum::actingAs(User::forceCreate(['names' => 'Admin', 'email' => 'admin@test', 'password' => 'x', 'role_id' => $role->id]));

        expect(array_column($this->getJson('/api/cities')->assertOk()->json(), 'name'))->toBe(['Distrito Capital', 'Carabobo', 'Aragua'])
            ->and(City::operational()->pluck('name')->all())->not->toContain('Resto de Venezuela')
            ->and(City::pluck('name')->all())->toContain('Resto de Venezuela');
    });

    it('la 4.ª campaña se clasifica sola si su nombre dice RDV (o Resto)', function () {
        $suggester = new CampaignSuggester();
        $zone = CourierZone::id();

        foreach (['CEROFRIZZ | RDV | CBO', 'Cerofrizz - Resto - ABO', 'CEROFRIZZ | RESTO DE VENEZUELA'] as $name) {
            $s = $suggester->suggest(new MetaCampaign(['name' => $name]));
            expect([$s['product_id'], $s['city_id'], $s['city_from']])->toBe([1, $zone, 'name']);
        }
        expect($suggester->suggest(new MetaCampaign(['name' => 'CEROFRIZZ | CCS | CBO']))['city_id'])->toBe(1);
    });

    it('lo que cobra MRW o Zoom lo pone el dueño: vacío = falta (la zona lo avisa)', function () {
        expect(CourierZone::feeUsd())->toBeNull();

        StorefrontCatalog::saveSettings(['courier_fee_usd' => '4.5']);
        expect(CourierZone::feeUsd())->toBe(4.5);

        StorefrontCatalog::saveSettings(['courier_fee_usd' => -2]);
        expect(CourierZone::feeUsd())->toBeNull();
    });
});
