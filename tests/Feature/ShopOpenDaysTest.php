<?php

use App\Models\Shop;
use App\Services\Business\BusinessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

// Días en que la tienda abre sola (dueño, 2026-11-01: "que podamos apagar los domingos"), en una base en memoria.
// Noviembre 2026: viernes 6, sábado 7, domingo 8, lunes 9.

beforeEach(function () {
    Schema::create('shops', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('shopify_domain')->nullable();
        $t->string('shopify_access_token')->nullable();
        $t->string('shopify_webhook_secret')->nullable();
        $t->unsignedBigInteger('status_id')->nullable();
        $t->time('auto_open_at')->nullable();
        $t->time('auto_close_at')->nullable();
        $t->boolean('auto_schedule_enabled')->default(false);
        $t->json('auto_open_days')->nullable();
        $t->timestamps();
    });
    Schema::create('business_days', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedBigInteger('shop_id');
        $t->timestamp('open_at')->nullable();
        $t->timestamp('close_at')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Carbon::setTestNow());

function tienda(array $attrs = []): Shop
{
    return Shop::create($attrs + ['name' => 'Nuviora', 'auto_open_at' => '08:00', 'auto_close_at' => '19:00', 'auto_schedule_enabled' => true]);
}

it('sin días elegidos abre todos los días, como antes', function () {
    $shop = tienda();
    foreach (range(1, 7) as $day) {
        expect($shop->opensOn($day))->toBeTrue();
    }
});

it('con lunes a sábado, el domingo no abre sola', function () {
    $shop = tienda(['auto_open_days' => [1, 2, 3, 4, 5, 6]]);
    expect($shop->opensOn(7))->toBeFalse()->and($shop->opensOn(6))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-11-08 10:00'));
    expect($shop->fresh()->is_open)->toBeFalse();
    Carbon::setTestNow(Carbon::parse('2026-11-09 10:00'));
    expect($shop->fresh()->is_open)->toBeTrue();

    // Sin horario automático, como siempre: "abierta" (se maneja a mano)
    $shop->update(['auto_schedule_enabled' => false]);
    Carbon::setTestNow(Carbon::parse('2026-11-08 10:00'));
    expect($shop->fresh()->is_open)->toBeTrue();
});

it('un horario que pasa la medianoche cuenta la madrugada para el día que abrió', function () {
    $shop = tienda(['auto_open_at' => '22:00', 'auto_close_at' => '06:00', 'auto_open_days' => [5]]);

    Carbon::setTestNow(Carbon::parse('2026-11-06 23:00'));
    expect($shop->fresh()->is_open)->toBeTrue();
    Carbon::setTestNow(Carbon::parse('2026-11-07 03:00'));
    expect($shop->fresh()->is_open)->toBeTrue();
    Carbon::setTestNow(Carbon::parse('2026-11-07 23:00'));
    expect($shop->fresh()->is_open)->toBeFalse();
});

it('la apertura automática no abre un domingo desmarcado y sí el lunes', function () {
    tienda(['auto_open_days' => [1, 2, 3, 4, 5, 6]]);

    Carbon::setTestNow(Carbon::parse('2026-11-08 08:05'));
    $this->mock(BusinessService::class, function ($mock) {
        $mock->shouldNotReceive('activateDefaultRoster');
        $mock->shouldNotReceive('openShop');
    });
    $this->artisan('shops:check-schedule')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-11-09 08:05'));
    $this->mock(BusinessService::class, function ($mock) {
        $mock->shouldReceive('activateDefaultRoster')->once();
        $mock->shouldReceive('openShop')->once();
    });
    $this->artisan('shops:check-schedule')->assertSuccessful();
});
