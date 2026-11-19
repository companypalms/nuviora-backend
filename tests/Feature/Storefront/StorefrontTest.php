<?php

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\StorefrontOrder;
use App\Models\User;
use App\Services\Orders\OrderIntake;
use App\Services\Orders\OrderSources;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\MetaCapi;
use App\Services\Storefront\StorefrontKpis;
use App\Services\Storefront\StorefrontRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .164 (dueño, 2026-11-19): nuestro formulario de pedido en la landing de un producto de prueba (pago al recibir), con
// Releasit de respaldo. Con la base en memoria: Solo Brillo ($33, Shopify 7558816) en la tienda 4, agencias en Distrito
// Capital y Carabobo, un centro logístico en Aragua, y Lara sin nada (ahí no se paga al recibir).

beforeEach(function () {
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('email')->nullable();
        $t->string('password')->nullable();
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->longText('value')->nullable(), $t->timestamps()]);
    Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('products', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('name')->nullable();
        $t->string('showable_name')->nullable();
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('variant_id')->nullable();
        $t->decimal('price', 10, 2)->default(0);
        $t->string('image')->nullable();
        $t->timestamps();
    });
    Schema::create('product_variants', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('title');
        $t->unsignedBigInteger('shopify_variant_id')->nullable();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('cities', fn ($t) => [$t->id(), $t->string('name'), $t->unsignedBigInteger('agency_id')->nullable(), $t->timestamps()]);
    Schema::create('provinces', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('city_agency', fn ($t) => [$t->id(), $t->unsignedBigInteger('city_id'), $t->unsignedBigInteger('agency_id'), $t->boolean('is_active')->default(true), $t->timestamps()]);
    Schema::create('logistics_centers', fn ($t) => [$t->id(), $t->unsignedBigInteger('city_id'), $t->boolean('is_active')->default(true), $t->timestamps()]);
    Schema::create('product_city_availability', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('city_id'), $t->boolean('is_available')->default(true), $t->timestamps()]);
    Schema::create('shops', fn ($t) => [$t->id(), $t->string('name'), $t->string('shopify_domain')->nullable(), $t->timestamps()]);
    Schema::create('clients', function ($t) {
        $t->id();
        $t->unsignedBigInteger('customer_id')->nullable()->unique();
        $t->string('customer_number')->nullable();
        foreach (['first_name', 'last_name', 'phone', 'province', 'city', 'address1', 'address2', 'country_name'] as $c) {
            $t->string($c)->nullable();
        }
        $t->timestamps();
    });
    Schema::create('orders', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id')->unique();
        $t->string('name')->nullable();
        $t->string('order_number')->nullable();
        $t->decimal('current_total_price', 10, 2)->default(0);
        $t->string('currency')->nullable();
        $t->timestamp('processed_at')->nullable();
        foreach (['client_id', 'shop_id', 'city_id', 'province_id', 'agency_id', 'agent_id'] as $c) {
            $t->unsignedBigInteger($c)->nullable();
        }
        $t->unsignedBigInteger('status_id');
        $t->timestamps();
    });
    Schema::create('order_products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('variant_id')->nullable();
        $t->unsignedBigInteger('product_number')->nullable();
        foreach (['title', 'name', 'showable_name', 'image', 'size'] as $c) {
            $t->string($c)->nullable();
        }
        $t->decimal('price', 10, 2)->default(0);
        $t->integer('quantity')->default(1);
        $t->timestamps();
    });
    Schema::create('order_activity_logs', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('actor_role')->nullable();
        $t->string('action');
        $t->text('description')->nullable();
        $t->json('properties')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_19_100000_create_storefront_tables.php'))->up();
    // los KPIs: las visitas de Meta, de dónde vino cada pedido y sus cancelaciones
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->unsignedBigInteger('product_id')->nullable(), $t->timestamps()]);
    Schema::create('meta_insights_daily', fn ($t) => [$t->id(), $t->date('date'), $t->string('level'), $t->string('meta_id'), $t->decimal('landing_page_views', 12, 2)->nullable(),
        $t->decimal('purchases', 12, 2)->nullable()]);
    Schema::create('order_sources', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('ad_meta_id')->nullable(), $t->timestamps()]);
    Schema::create('order_cancellations', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('category')->nullable(), $t->timestamps()]);

    Event::fake(); // también los observers del pedido (agencia, almacén, logística): aquí solo importa lo que guarda el formulario
    DB::table('roles')->insert([['id' => 1, 'description' => 'Admin'], ['id' => 2, 'description' => 'Vendedor'], ['id' => 3, 'description' => 'Agencia']]);
    DB::table('users')->insert([['id' => 1, 'names' => 'Jose', 'role_id' => 1], ['id' => 2, 'names' => 'Ana', 'role_id' => 2],
        ['id' => 20, 'names' => 'Rapi', 'role_id' => 3], ['id' => 21, 'names' => 'Valen', 'role_id' => 3]]);
    DB::table('statuses')->insert([['id' => 1, 'description' => 'Nuevo'], ['id' => 2, 'description' => 'Entregado'], ['id' => 3, 'description' => 'Cancelado']]);
    DB::table('shops')->insert(['id' => 4, 'name' => 'Solo brillo']);
    DB::table('products')->insert(['id' => 7, 'title' => 'SOLO BRILLO LIMPIADOR', 'showable_name' => 'Solo Brillo', 'product_id' => 7558816, 'variant_id' => 4521, 'price' => 33, 'image' => 'https://cdn.shopify.com/x.jpg']);
    DB::table('cities')->insert([['id' => 1, 'name' => 'Distrito Capital', 'agency_id' => 20], ['id' => 2, 'name' => 'Carabobo', 'agency_id' => 21],
        ['id' => 3, 'name' => 'Aragua', 'agency_id' => null], ['id' => 4, 'name' => 'Lara', 'agency_id' => null]]);
    DB::table('provinces')->insert(['id' => 9, 'name' => 'Distrito Capital']);
    DB::table('city_agency')->insert([['city_id' => 1, 'agency_id' => 20], ['city_id' => 2, 'agency_id' => 21]]);
    DB::table('logistics_centers')->insert(['city_id' => 3]);
    DB::table('product_offers')->insert([
        ['id' => 31, 'product_id' => 7, 'units' => 3, 'discount_pct' => 33, 'title' => 'LLEVA 3 GALONES CON 33% DE DESCUENTO', 'badge' => '🎁 EL DESCUENTO MÁXIMO', 'is_default' => false, 'position' => 0, 'is_active' => true],
        ['id' => 32, 'product_id' => 7, 'units' => 2, 'discount_pct' => 25, 'title' => 'LLEVA 2 GALONES CON 25% DE DESCUENTO', 'badge' => '🏆 LA OPCIÓN FAVORITA', 'is_default' => false, 'position' => 1, 'is_active' => true],
        ['id' => 33, 'product_id' => 7, 'units' => 1, 'discount_pct' => 0, 'title' => 'LLEVA 1 GALÓN', 'badge' => null, 'is_default' => true, 'position' => 2, 'is_active' => true],
    ]);
    DB::table('settings')->insert(['key' => 'rate_binance_usd', 'value' => '240']);
    StorefrontCatalog::saveSettings(['enabled' => true, 'shop_id' => 4, 'product_ids' => [7], 'allowed_origins' => ['https://solobrillo.com']]);

    $this->intake = $this->mock(OrderIntake::class);
    $this->sources = $this->mock(OrderSources::class);
});

/** Lo que manda el formulario de la landing (un cliente que tardó 10 segundos en llenarlo). */
function pedido164(array $x = []): array
{
    $ts = now()->subSeconds(10)->timestamp;

    return $x + ['p' => '7558816', 'offer' => 32, 'variant' => null, 'name' => 'María José Pérez', 'phone' => '0414-123.45.67', 'city' => 1,
        'address' => 'Urb. Las Acacias, calle 3, casa 12', 'reference' => 'Frente a la panadería', 'express' => true, 'rate' => '254.37',
        'visitor' => 'v123', 'landing' => 'https://solobrillo.com/products/solo-brillo?utm_content=120210000000001&fbclid=abc', 'referrer' => 'https://m.facebook.com/',
        'page' => 'https://solobrillo.com/products/solo-brillo', 'fbp' => 'fb.1.1.2', 'fbc' => 'fb.1.1.abc', 'hp' => '', 'ts' => $ts,
        'sig' => StorefrontRules::sign($ts, (string) config('app.key'))];
}

function enviar164($test, array $x = [])
{
    return $test->call('POST', '/tienda/pedidos', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://solobrillo.com'], json_encode(pedido164($x)));
}

describe('.164: la configuración que dibuja el formulario', function () {
    it('las ofertas en Bs con la tasa de la página, dónde se paga al recibir y el Content ID; solo la tienda la puede leer', function () {
        $r = $this->get('/tienda/productos/7558816?tasa=254.37&precio=33', ['Origin' => 'https://solobrillo.com'])->assertOk();

        expect($r->json('enabled'))->toBeTrue()
            ->and($r->json('rate'))->toBe(254.37)
            ->and(array_column($r->json('offers'), 'price_bs'))->toBe([16872, 12591, 8394])
            ->and($r->json('offers.2.is_default'))->toBeTrue()
            // Lara no tiene agencia ni centro logístico: ahí no se paga al recibir
            ->and(array_column($r->json('cities'), 'name'))->toBe(['Aragua', 'Carabobo', 'Distrito Capital'])
            ->and($r->json('express'))->toBe(['usd' => 2, 'bs' => 509])
            ->and($r->json('pixel.content_id'))->toBe('shopify_VE_7558816_4521')
            ->and($r->json('texts.title'))->toBe('PIDE EL TUYO Y PAGA AL RECIBIRLO 👇')
            ->and($r->headers->get('Access-Control-Allow-Origin'))->toBe('https://solobrillo.com');
        // otra página no puede leerla
        expect($this->get('/tienda/productos/7558816', ['Origin' => 'https://otra.com'])->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    });

    it('apagado o en otro producto, dice que no (y la landing deja Releasit)', function () {
        expect($this->get('/tienda/productos/999')->json())->toBe(['enabled' => false]);
        StorefrontCatalog::saveSettings(['enabled' => false]);
        expect($this->get('/tienda/productos/7558816')->json())->toBe(['enabled' => false]);
    });

    it('el cargador es el mismo para todos y sabe de qué servidor viene', function () {
        $r = $this->get('/tienda/cargador.js?p=7558816')->assertOk();

        expect($r->headers->get('Content-Type'))->toContain('javascript')
            ->and($r->getContent())->toStartWith('window.__NUVIORA_BASE__=')
            ->and($r->getContent())->toContain('nvf-hide-releasit');
    });
});

describe('.164: el pedido', function () {
    it('entra como uno de Shopify: WEB-1 en su tienda y su ciudad, con su oferta, su express, de dónde vino y el WhatsApp con el mensaje', function () {
        $this->sources->shouldReceive('record')->once()->withArgs(fn ($order, $payload) => $payload['landing_site'] === pedido164()['landing'] && $payload['source_name'] === 'nuviora_form');
        $this->intake->shouldReceive('finish')->once();

        $r = enviar164($this)->assertOk();
        $order = Order::first();
        $line = DB::table('order_products')->first();
        $client = DB::table('clients')->first();
        $front = StorefrontOrder::first();

        expect($r->json('ok'))->toBeTrue()->and($r->json('duplicate'))->toBeFalse()->and($r->json('order'))->toBe('WEB-1')
            ->and($r->json('event'))->toBe(['id' => 'nuv-web-1', 'value' => 51.5, 'currency' => 'USD', 'num_items' => 2])
            ->and($r->json('whatsapp'))->toStartWith('https://wa.me/584221792891?text=')
            ->and(urldecode($r->json('whatsapp')))->toContain('Soy María y he realizado el pedido WEB-1')
            ->and($order->only(['name', 'order_number', 'order_id', 'currency', 'shop_id', 'city_id', 'agency_id', 'province_id', 'status_id']))
            ->toBe(['name' => 'WEB-1', 'order_number' => 'WEB-1', 'order_id' => 9_000_000_000_000_001, 'currency' => 'USD', 'shop_id' => 4, 'city_id' => 1,
                'agency_id' => 20, 'province_id' => 9, 'status_id' => 1])
            // 2 × $33 − 25 % = $49,50, más el express de $2
            ->and((float) $order->current_total_price)->toBe(51.5)->and($order->express_fee)->toBe(2.0)
            ->and((float) $line->price)->toBe(24.75)->and($line->quantity)->toBe(2)->and((int) $line->product_number)->toBe(7558816)
            ->and([$client->phone, $client->first_name, $client->last_name, $client->province, $client->address2])
            ->toBe(['+584141234567', 'María', 'José Pérez', 'Distrito Capital', 'Frente a la panadería'])
            ->and($front->only(['units', 'phone_last10', 'total_bs', 'event_id', 'fbc', 'visitor_id']))
            ->toBe(['units' => 2, 'phone_last10' => '4141234567', 'total_bs' => 12591 + 509, 'event_id' => 'nuv-web-1', 'fbc' => 'fb.1.1.abc', 'visitor_id' => 'v123'])
            ->and($front->rate)->toBe(254.37);
        Event::assertDispatched(OrderUpdated::class);
    });

    it('el mismo teléfono y producto en 30 minutos es el mismo pedido; después, uno nuevo', function () {
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish')->twice();

        enviar164($this)->assertOk();
        $again = enviar164($this, ['phone' => '+58 414 1234567'])->assertOk();
        expect($again->json('duplicate'))->toBeTrue()->and($again->json('order'))->toBe('WEB-1')->and(Order::count())->toBe(1)
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_repeat')->count())->toBe(1);

        $this->travel(31)->minutes();
        expect(enviar164($this)->json('order'))->toBe('WEB-2')->and(DB::table('clients')->count())->toBe(1);
    });

    it('un cliente que ya existe se reconoce por los últimos 10 dígitos', function () {
        DB::table('clients')->insert(['id' => 50, 'phone' => '04141234567', 'first_name' => 'Maria']);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');

        enviar164($this)->assertOk();
        expect(Order::first()->client_id)->toBe(50)->and(DB::table('clients')->count())->toBe(1);
    });

    it('lo que no se ve bien vuelve con el campo (y no crea nada)', function () {
        expect(enviar164($this, ['phone' => '0212-5551234'])->assertStatus(422)->json('fields'))->toBe(['phone'])
            ->and(enviar164($this, ['city' => 4])->assertStatus(422)->json('fields'))->toBe(['city'])
            ->and(enviar164($this, ['name' => 'A', 'address' => ''])->assertStatus(422)->json('fields'))->toBe(['name', 'address'])
            ->and(enviar164($this, ['offer' => 999])->assertStatus(422)->json('fields'))->toBe(['offer'])
            ->and(Order::count())->toBe(0);
    });

    it('un robot no pasa: el campo escondido lleno o mandado al instante', function () {
        $now = now()->timestamp;
        enviar164($this, ['hp' => 'http://spam'])->assertStatus(422);
        enviar164($this, ['ts' => $now, 'sig' => StorefrontRules::sign($now, (string) config('app.key'))])->assertStatus(422);
        enviar164($this, ['sig' => 'falsa'])->assertStatus(422);
        expect(Order::count())->toBe(0);
    });

    it('con tallas, la talla es obligatoria', function () {
        DB::table('product_variants')->insert(['id' => 70, 'product_id' => 7, 'title' => 'XL', 'shopify_variant_id' => 999, 'is_active' => true]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');

        expect(enviar164($this)->assertStatus(422)->json('fields'))->toBe(['size']);
        enviar164($this, ['variant' => 70])->assertOk();
        expect(DB::table('order_products')->value('size'))->toBe('XL')->and(DB::table('order_products')->value('showable_name'))->toBe('Solo Brillo - XL');
    });

    it('un mismo teléfono no hace más de 3 pedidos en un día; ni pasan del máximo del día', function () {
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        foreach (range(1, 3) as $i) {
            enviar164($this)->assertOk();
            $this->travel(31)->minutes();
        }
        $r = enviar164($this)->assertStatus(429);
        expect($r->json('limit'))->toBeTrue()->and(Order::count())->toBe(3);
    });

    it('el embudo se cuenta por día, sin datos personales; el paso a WhatsApp se anota en el pedido', function () {
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        $id = enviar164($this)->json('id');
        $send = fn (array $b) => $this->call('POST', '/tienda/eventos', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($b));

        $send(['p' => '7558816', 'kinds' => ['view', 'start', 'field:phone', 'inventado']]);
        $send(['p' => '7558816', 'kinds' => ['view', 'whatsapp'], 'order' => $id]);
        expect(DB::table('storefront_events')->pluck('count', 'kind')->all())->toBe(['view' => 2, 'start' => 1, 'field:phone' => 1, 'whatsapp' => 1])
            ->and(StorefrontOrder::find($id)->whatsapp_opened_at)->not->toBeNull();
    });
});

describe('.164: la página del dueño', function () {
    it('solo el Admin; la clave de Meta se escribe pero nunca vuelve', function () {
        Sanctum::actingAs(User::find(2));
        $this->getJson('/api/store-form')->assertForbidden();

        Sanctum::actingAs(User::find(1));
        $base = $this->getJson('/api/store-form')->assertOk()->json('settings');
        $this->putJson('/api/store-form/settings', $base + ['capi_token' => 'EAAclave-de-prueba', 'pixel_id' => '1234567890'])->assertOk();
        $r = $this->getJson('/api/store-form')->assertOk();

        expect($r->json('settings.capi_set'))->toBeTrue()->and($r->json('settings'))->not->toHaveKey('capi_token')
            ->and(json_encode($r->json()))->not->toContain('EAAclave')
            ->and(StorefrontCatalog::capiToken())->toBe('EAAclave-de-prueba')
            ->and(DB::table('settings')->where('key', 'storefront_form')->value('value'))->not->toContain('EAAclave');
        // guardar sin escribir otra clave la deja como estaba
        $this->putJson('/api/store-form/settings', array_diff_key($r->json('settings'), ['capi_set' => 1]))->assertOk();
        expect(StorefrontCatalog::capiToken())->toBe('EAAclave-de-prueba');
    });

    it('las ofertas y el diseño se guardan y el formulario los toma enseguida', function () {
        Sanctum::actingAs(User::find(1));
        $this->putJson('/api/store-form/products/7/offers', ['offers' => [
            ['units' => 1, 'discount_pct' => 0, 'title' => 'LLEVA 1', 'badge' => null, 'is_default' => true, 'is_active' => true],
            ['units' => 2, 'discount_pct' => 30, 'title' => 'LLEVA 2', 'badge' => 'TOP', 'is_default' => false, 'is_active' => true],
        ]])->assertOk();
        $this->putJson('/api/store-form/products/7/design', ['texts' => ['title' => 'PIDE TU SOLO BRILLO'], 'colors' => ['button' => '#ff0000'], 'font' => 'Poppins',
            'radius' => 14, 'custom_css' => '.nvf-title{letter-spacing:1px}'])->assertOk();
        $c = $this->get('/tienda/productos/7558816?tasa=100')->json();

        expect(array_column($c['offers'], 'price_bs'))->toBe([3300, 4620])->and($c['texts']['title'])->toBe('PIDE TU SOLO BRILLO')
            ->and($c['colors']['button'])->toBe('#FF0000')->and($c['font'])->toBe('Poppins')->and($c['radius'])->toBe(14);
        $this->putJson('/api/store-form/products/7/offers', ['offers' => [
            ['units' => 1, 'discount_pct' => 0, 'is_default' => true, 'is_active' => true], ['units' => 2, 'discount_pct' => 0, 'is_default' => true, 'is_active' => true],
        ]])->assertStatus(422);
    });
});

describe('.164: los KPIs del formulario', function () {
    it('cuenta los pedidos, las visitas de Meta y el embudo desde el primer pedido', function () {
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        enviar164($this)->assertOk();
        enviar164($this, ['phone' => '04241234567', 'express' => false])->assertOk();
        DB::table('meta_campaigns')->insert(['meta_id' => '555', 'product_id' => 7]);
        DB::table('meta_insights_daily')->insert(['date' => today()->toDateString(), 'level' => 'campaign', 'meta_id' => '555', 'landing_page_views' => 40, 'purchases' => 2]);
        DB::table('order_sources')->insert(['order_id' => Order::first()->id, 'ad_meta_id' => '120210000000001']);
        Order::first()->update(['status_id' => 2]);

        $w = (new StorefrontKpis())->web([7]);
        expect([$w['orders'], $w['visits'], $w['meta_purchases'], $w['attributed'], $w['express'], $w['delivered'], $w['closed'], $w['days']])
            ->toBe([2, 40.0, 2.0, 1, 1, 1, 1, 1]);
    });
});

describe('.164: la compra por la API de conversiones de Meta', function () {
    beforeEach(function () {
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        enviar164($this)->assertOk();
        $this->front = StorefrontOrder::first();
    });

    it('sin la clave no se manda nada', function () {
        Http::fake();

        expect((new MetaCapi())->send($this->front))->toBeNull();
        Http::assertNothingSent();
    });

    it('con la clave: el mismo número del píxel, los datos cifrados y cómo salió', function () {
        StorefrontCatalog::saveSettings(['pixel_id' => '1802353243804', 'capi_token' => 'EAAprueba', 'capi_test_code' => 'TEST123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        expect((new MetaCapi())->send($this->front))->toBe('sent');
        Http::assertSent(function ($r) {
            $e = $r['data'][0];

            return str_contains($r->url(), '/1802353243804/events') && $e['event_name'] === 'Purchase' && $e['event_id'] === 'nuv-web-1'
                && $e['user_data']['ph'] === [hash('sha256', '584141234567')] && $e['user_data']['fn'] === [hash('sha256', 'maría')]
                && $e['user_data']['ct'] === [hash('sha256', 'distritocapital')] && $e['user_data']['fbc'] === 'fb.1.1.abc'
                && $e['custom_data'] === ['value' => 51.5, 'currency' => 'USD', 'content_ids' => ['shopify_VE_7558816_4521'], 'content_type' => 'product', 'num_items' => 2, 'order_id' => 'WEB-1']
                && $r['test_event_code'] === 'TEST123' && !str_contains(json_encode($r->data()), 'María');
        });
        // una vez mandada no se repite
        expect((new MetaCapi())->send($this->front->fresh()))->toBeNull();
    });

    it('si Meta la rechaza, queda anotado el porqué (y cuenta en el KPI técnico)', function () {
        StorefrontCatalog::saveSettings(['pixel_id' => '1802353243804', 'capi_token' => 'EAAprueba']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

        expect((new MetaCapi())->send($this->front))->toBe('failed')
            ->and($this->front->fresh()->capi_error)->toBe('Invalid OAuth access token.');
    });
});
