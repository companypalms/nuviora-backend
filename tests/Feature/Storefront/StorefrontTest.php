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
    Schema::create('logistics_centers', fn ($t) => [$t->id(), $t->unsignedBigInteger('city_id'), $t->unsignedBigInteger('warehouse_id')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()]);
    // .172: el stock de cada ciudad (los almacenes de sus agencias y de su logística propia)
    Schema::create('warehouses', fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()]);
    Schema::create('inventories', fn ($t) => [$t->id(), $t->unsignedBigInteger('warehouse_id'), $t->unsignedBigInteger('product_id'), $t->integer('quantity')->default(0),
        $t->integer('reserved_stock')->default(0), $t->integer('defective_stock')->default(0), $t->integer('blocked_stock')->default(0), $t->json('sizes_stock')->nullable(), $t->timestamps()]);
    Schema::create('inventory_variants', fn ($t) => [$t->id(), $t->unsignedBigInteger('warehouse_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variant_id'),
        $t->integer('quantity')->default(0), $t->integer('defective_stock')->default(0), $t->timestamps()]);
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
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('name')->nullable(), $t->string('effective_status')->nullable(),
        $t->unsignedBigInteger('product_id')->nullable(), $t->timestamps()]);
    Schema::create('meta_insights_daily', fn ($t) => [$t->id(), $t->date('date'), $t->string('level'), $t->string('meta_id'), $t->decimal('landing_page_views', 12, 2)->nullable(),
        $t->decimal('purchases', 12, 2)->nullable(), $t->decimal('spend', 12, 2)->nullable()]);
    Schema::create('order_sources', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('ad_meta_id')->nullable(), $t->timestamps()]);
    Schema::create('order_cancellations', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('category')->nullable(), $t->timestamps()]);
    // .168: el pago anticipado, las encomiendas y las campañas "al entregar"
    Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id')->nullable(), $t->timestamps()]);
    Schema::create('meta_adsets', fn ($t) => [$t->id(), $t->string('meta_id'), $t->string('campaign_meta_id')->nullable(), $t->timestamps()]);
    Schema::create('business_days', fn ($t) => [$t->id(), $t->unsignedBigInteger('shop_id')->nullable(), $t->date('date'), $t->timestamp('open_at')->nullable(),
        $t->timestamp('close_at')->nullable(), $t->timestamps()]);
    Schema::table('orders', function ($t) {
        foreach (['courier_recipient_name', 'courier_recipient_id_number', 'courier_recipient_phone', 'location', 'payment_receipt'] as $c) {
            $t->string($c)->nullable();
        }
        $t->boolean('courier_shipment')->default(false);
        $t->boolean('is_return')->default(false);
        $t->boolean('is_exchange')->default(false);
        $t->decimal('cash_received', 10, 2)->nullable();
        $t->decimal('change_amount', 10, 2)->nullable();
        $t->unsignedBigInteger('payment_account_id')->nullable();
        $t->timestamp('payment_account_at')->nullable();
    });
    (require database_path('migrations/2026_11_21_100000_storefront_prepaid_and_courier.php'))->up();
    (require database_path('migrations/2026_11_22_100000_purchase_mode_by_product.php'))->up();

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
    DB::table('logistics_centers')->insert(['city_id' => 3, 'warehouse_id' => 130]);
    // de sobra en las tres ciudades (las pruebas de la .172 lo bajan)
    DB::table('warehouses')->insert([['id' => 120, 'user_id' => 20], ['id' => 121, 'user_id' => 21], ['id' => 130, 'user_id' => null]]);
    DB::table('inventories')->insert([['warehouse_id' => 120, 'product_id' => 7, 'quantity' => 50], ['warehouse_id' => 121, 'product_id' => 7, 'quantity' => 50],
        ['warehouse_id' => 130, 'product_id' => 7, 'quantity' => 50]]);
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
        DB::table('inventory_variants')->insert(['warehouse_id' => 120, 'product_id' => 7, 'variant_id' => 70, 'quantity' => 10]); // .172: la talla en stock
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

// ───────────────────────────── .168 ─────────────────────────────
// Dueño (21/11): "que la persona pueda pagar anticipado desde el sitio web… si la persona monta el pagomovil en el formulario
// se envía el evento purchase, si por el contrario es pago contraentrega o quiere primero hablar con una vendedora, entonces
// el evento purchase se enviaría a facebook cuando el pedido esté entregado en el sistema". Más las encomiendas (MRW y Zoom,
// envío incluido, salen de la ciudad que elige el dueño) y el mensaje de fuera de horario.

/** Una foto de comprobante de verdad (un PNG de 1×1), como la manda el formulario. */
const RECIBO168 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function tablas168(): void
{
    Schema::create('company_accounts', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('icon')->nullable();
        $t->string('method')->nullable();
        $t->string('currency')->nullable();
        $t->unsignedBigInteger('statement_source_id')->nullable();
        $t->unsignedBigInteger('financial_account_id')->nullable();
        $t->json('details')->nullable();
        $t->boolean('is_active')->default(true);
        $t->boolean('takes_orders')->default(true);
        $t->timestamps();
    });
    Schema::create('order_payments', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('method'), $t->decimal('amount', 10, 2),
        $t->decimal('rate', 14, 4)->nullable(), $t->string('reference')->nullable(), $t->decimal('usd_rate', 14, 4)->nullable(), $t->decimal('eur_rate', 14, 4)->nullable(),
        $t->decimal('binance_usd_rate', 14, 4)->nullable(), $t->timestamps()]);
    Schema::create('payment_receipts', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('path'), $t->string('original_name')->nullable(),
        $t->string('file_sha256')->nullable(), $t->string('image_dhash')->nullable(), $t->timestamps()]);
    Schema::create('receipt_checks', fn ($t) => [$t->id(), $t->unsignedBigInteger('payment_receipt_id')->nullable(), $t->unsignedBigInteger('order_id'), $t->string('status'),
        $t->text('error')->nullable(), $t->timestamp('reviewed_at')->nullable(), $t->timestamps()]);
    Schema::create('currencies', fn ($t) => [$t->id(), $t->string('description'), $t->decimal('value', 14, 4), $t->unsignedBigInteger('status_id')->nullable(), $t->timestamps()]);
    Schema::create('whatsapp_messages', function ($t) {
        $t->id();
        foreach (['order_id', 'client_id'] as $c) {
            $t->unsignedBigInteger($c)->nullable();
        }
        foreach (['message_id', 'message_type', 'template_name', 'status', 'error_reason'] as $c) {
            $t->string($c)->nullable();
        }
        $t->text('body')->nullable();
        $t->boolean('is_from_client')->default(false);
        $t->integer('error_code')->nullable();
        $t->json('media')->nullable();
        $t->timestamp('sent_at')->nullable();
        $t->timestamps();
    });
    Schema::table('clients', fn ($t) => $t->timestamp('last_whatsapp_received_at')->nullable());
    DB::table('statuses')->insert([['id' => 4, 'description' => 'Activo'], ['id' => 5, 'description' => 'Rechazado']]);
    DB::table('currencies')->insert(['description' => 'binance_usd', 'value' => 250, 'status_id' => 4]);
    DB::table('company_accounts')->insert(['id' => 6, 'name' => 'Pago móvil Banesco', 'method' => 'pago_movil', 'currency' => 'VES',
        'details' => json_encode([['label' => 'Banco', 'value' => 'Banesco (0134)'], ['label' => 'Teléfono', 'value' => '0414-0000000'], ['label' => 'Cédula', 'value' => 'V-11111111']])]);
    DB::table('courier_offices')->insert([
        ['id' => 70, 'company' => 'MRW', 'state' => 'Barinas', 'city' => 'Barinas', 'name' => 'Alto Barinas', 'address' => 'URB ALTO BARINAS NORTE, LOCAL 135', 'code' => 'mrw-1', 'is_active' => true, 'source' => 'lista'],
        ['id' => 71, 'company' => 'Zoom', 'state' => 'Lara', 'city' => 'Barquisimeto', 'name' => 'Centro', 'address' => 'Av. 20', 'code' => 'zoom-1', 'is_active' => true, 'source' => 'lista'],
        ['id' => 72, 'company' => 'MRW', 'state' => 'Lara', 'city' => 'Carora', 'name' => 'Carora', 'address' => null, 'code' => 'mrw-2', 'is_active' => false, 'source' => 'lista'],
    ]);
}

describe('.168: pagar por adelantado con pago móvil', function () {
    beforeEach(function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['prepaid_enabled' => true]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
    });

    it('el formulario pide la cuenta del día: sus datos para el cliente y su número firmado', function () {
        $r = $this->get('/tienda/pago?p=7558816', ['Origin' => 'https://solobrillo.com'])->assertOk();

        expect($r->json('account.name'))->toBe('Pago móvil Banesco')
            ->and(array_column($r->json('account.details'), 'value'))->toBe(['Banesco (0134)', '0414-0000000', 'V-11111111'])
            ->and(StorefrontCatalog::accountId($r->json('account.id')))->toBe(6)
            // un número sin firma no vale
            ->and(StorefrontCatalog::accountId('6.otra'))->toBeNull();
    });

    it('entra pagado: su pago móvil por el total con la tasa de la landing, la cuenta que vio y la foto; sale el Purchase ya', function () {
        $account = $this->get('/tienda/pago?p=7558816')->json('account.id');
        $r = enviar164($this, ['payment' => 'pago_movil', 'account' => $account, 'receipt' => RECIBO168])->assertOk();
        $order = Order::first();
        $pay = DB::table('order_payments')->first();
        $receipt = DB::table('payment_receipts')->first();

        expect($r->json('payment'))->toBe('pago_movil')
            ->and(array_column($r->json('events'), 'name'))->toBe(['AddPaymentInfo', 'Purchase'])
            ->and($r->json('events.0.id'))->toBe('nuv-pedido-1')
            ->and($r->json('event.id'))->toBe('nuv-web-1')
            ->and($order->prepaid_at)->not->toBeNull()
            ->and((float) $order->cash_received)->toBe(51.5)
            ->and($pay->method)->toBe('PAGOMOVIL')->and((float) $pay->amount)->toBe(51.5)->and((float) $pay->rate)->toBe(254.37)
            ->and((int) $order->payment_account_id)->toBe(6)
            ->and($receipt->original_name)->toBe('comprobante-WEB-1.png')
            ->and(Illuminate\Support\Facades\Storage::disk('public')->exists($receipt->path))->toBeTrue()
            ->and(StorefrontOrder::first()->payment)->toBe('pago_movil')
            ->and(urldecode($r->json('whatsapp')))->toContain('Ya lo pagué por pago móvil')
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_prepaid')->exists())->toBeTrue();
    });

    it('sin la foto del comprobante no entra; con el pago anticipado apagado tampoco', function () {
        expect(enviar164($this, ['payment' => 'pago_movil'])->assertStatus(422)->json('fields'))->toBe(['receipt'])
            ->and(enviar164($this, ['payment' => 'pago_movil', 'receipt' => 'data:image/png;base64,' . base64_encode('<?php echo 1;')])->assertStatus(422)->json('fields'))->toBe(['receipt']);
        StorefrontCatalog::saveSettings(['prepaid_enabled' => false]);
        expect(enviar164($this, ['payment' => 'pago_movil', 'receipt' => RECIBO168])->assertStatus(422)->json('fields'))->toContain('pay');
    });

    it('con la tienda cerrada le dice el horario y cuándo una asesora valida el pago', function () {
        $r = enviar164($this, ['payment' => 'pago_movil', 'receipt' => RECIBO168])->assertOk();

        // sin un día abierto la tienda está cerrada; sin horario automático, el del equipo
        expect($r->json('after_hours'))->toBeTrue()
            ->and($r->json('hours'))->toBe('de lunes a sábado, de 8:00 a. m. a 7:00 p. m.')
            ->and($r->json('when'))->toMatch('/^(Hoy|Mañana|El lunes) a partir de las 8:00 a\. m\.$/');
    });

    it('n8n no le manda "Confirma tu pedido… Pago al recibir": para él ya está confirmado', function () {
        enviar164($this, ['payment' => 'pago_movil', 'receipt' => RECIBO168])->assertOk();

        expect(Order::first()->prepaid_at)->not->toBeNull();
        // check-window toma prepaid_at como la confirmación (ver ExternalWhatsAppController; .173: también la encomienda por pagar)
        expect(file_get_contents(app_path('Http/Controllers/External/ExternalWhatsAppController.php')))->toContain('$noConfirmationSince = $prepaidAt ??');
    });
});

describe('.168: la encomienda a otra ciudad del país (MRW y Zoom)', function () {
    beforeEach(function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['courier_enabled' => true, 'courier_origin_city_id' => 2, 'courier_dropoff' => ['MRW' => 'https://maps.app.goo.gl/mrw-valencia', 'Zoom' => '']]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
    });

    it('la configuración la ofrece y la lista trae solo las oficinas activas de esa empresa', function () {
        expect($this->get('/tienda/productos/7558816')->json('courier'))->toBe(['companies' => ['MRW', 'Zoom'], 'stock' => ['all' => 3, 'sizes' => []]]);
        $r = $this->get('/tienda/oficinas?empresa=MRW')->assertOk();

        expect(array_column($r->json('offices'), 'name'))->toBe(['Alto Barinas'])
            ->and($this->get('/tienda/oficinas?empresa=Tealca')->status())->toBe(404);
    });

    it('sale de la ciudad del dueño, pagada, con la empresa, la oficina y quien retira; sin express', function () {
        $r = enviar164($this, ['city' => null, 'address' => null, 'express' => true, 'receipt' => RECIBO168,
            'courier' => ['company' => 'MRW', 'office_id' => 70, 'id_number' => 'v 12.345.678']])->assertOk();
        $order = Order::first();

        expect($r->json('payment'))->toBe('pago_movil')
            ->and((int) $order->city_id)->toBe(2)->and((int) $order->agency_id)->toBe(21)
            ->and((bool) $order->courier_shipment)->toBeTrue()
            ->and($order->courier_recipient_name)->toBe('María José Pérez')
            ->and($order->courier_recipient_id_number)->toBe('V-12345678')
            ->and($order->courier_recipient_phone)->toBe('+584141234567')
            ->and($order->courier_company)->toBe('MRW')
            ->and($order->courier_office)->toBe('MRW Alto Barinas — URB ALTO BARINAS NORTE, LOCAL 135, Barinas')
            ->and($order->location)->toBe('https://maps.app.goo.gl/mrw-valencia')
            ->and($order->prepaid_at)->not->toBeNull()
            ->and($order->express_fee)->toBeNull()
            ->and((float) $order->current_total_price)->toBe(49.5)
            ->and(urldecode($r->json('whatsapp')))->toContain('para retirar en MRW');
    });

    it('si no encuentra su oficina la escribe; una apagada, Tealca o una cédula rara no valen', function () {
        enviar164($this, ['city' => null, 'receipt' => RECIBO168, 'courier' => ['company' => 'Zoom', 'office_text' => 'Zoom de Cabudare, al lado del banco', 'state' => 'Lara', 'id_number' => '12345678']])->assertOk();
        expect(Order::first()->courier_office)->toBe('Zoom (la escribió el cliente): Zoom de Cabudare, al lado del banco, Lara')
            ->and(Order::first()->location)->toBeNull();

        expect(enviar164($this, ['phone' => '04241234567', 'city' => null, 'receipt' => RECIBO168, 'courier' => ['company' => 'MRW', 'office_id' => 72, 'id_number' => 'V-1234567']])
            ->assertStatus(422)->json('fields'))->toContain('office')
            ->and(enviar164($this, ['phone' => '04241234567', 'city' => null, 'receipt' => RECIBO168, 'courier' => ['company' => 'Tealca', 'office_text' => 'Tealca Centro', 'state' => 'Lara', 'id_number' => '12345678']])
                ->assertStatus(422)->json('fields'))->toContain('company')
            ->and(enviar164($this, ['phone' => '04241234567', 'city' => null, 'receipt' => RECIBO168, 'courier' => ['company' => 'MRW', 'office_id' => 70, 'id_number' => 'J-1']])
                ->assertStatus(422)->json('fields'))->toBe(['id_number']);
    });
});

describe('.169: la encomienda pagada con una asesora', function () {
    beforeEach(function () {
        tablas168();
        StorefrontCatalog::saveSettings(['courier_enabled' => true, 'courier_origin_city_id' => 2, 'advisor_enabled' => true]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
    });

    it('llena sus datos y la agencia, sin foto: no queda pagada y la vendedora sabe que tiene que cobrarle', function () {
        $r = enviar164($this, ['city' => null, 'address' => null, 'payment' => 'asesora',
            'courier' => ['company' => 'MRW', 'office_id' => 70, 'state' => 'Barinas', 'id_number' => '12345678']])->assertOk();
        $order = Order::first();

        expect($r->json('payment'))->toBe('asesora')
            ->and((bool) $order->courier_shipment)->toBeTrue()->and($order->courier_company)->toBe('MRW')
            ->and((int) $order->city_id)->toBe(2)
            ->and($order->prepaid_at)->toBeNull()
            ->and(DB::table('order_payments')->count())->toBe(0)
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_advisor')->value('description'))->toContain('pago móvil con una asesora')
            ->and(urldecode($r->json('whatsapp')))->toContain('quiero hablar con una asesora');
    });

    it('sin la asesora prendida, la encomienda solo se paga ahora; y "al recibir" no vale en una encomienda', function () {
        expect(enviar164($this, ['city' => null, 'payment' => 'cod', 'courier' => ['company' => 'MRW', 'office_id' => 70, 'id_number' => '12345678']])
            ->assertStatus(422)->json('fields'))->toContain('pay');
        StorefrontCatalog::saveSettings(['advisor_enabled' => false]);
        expect(enviar164($this, ['city' => null, 'payment' => 'asesora', 'courier' => ['company' => 'MRW', 'office_id' => 70, 'id_number' => '12345678']])
            ->assertStatus(422)->json('fields'))->toContain('pay');
    });
});

describe('.168: hablar con una asesora antes', function () {
    beforeEach(function () {
        tablas168();
        StorefrontCatalog::saveSettings(['advisor_enabled' => true]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
    });

    it('entra sin dirección, sin pago, con su nota para la vendedora y su mensaje', function () {
        $r = enviar164($this, ['payment' => 'asesora', 'address' => '', 'reference' => ''])->assertOk();

        expect(Order::first()->prepaid_at)->toBeNull()
            ->and(DB::table('order_payments')->count())->toBe(0)
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_advisor')->exists())->toBeTrue()
            ->and(urldecode($r->json('whatsapp')))->toContain('quiero hablar con una asesora sobre mi pedido WEB-1');
    });
});

describe('.168: el Purchase al entregar (solo en las campañas marcadas)', function () {
    beforeEach(function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['prepaid_enabled' => true, 'advisor_enabled' => true]);
        DB::table('meta_purchase_modes')->insert(['product_id' => 7, 'campaign_meta_id' => '120210000000555', 'starts_on' => today()->toDateString()]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
    });

    $landing = fn (string $campaign) => "https://solobrillo.com/products/solo-brillo?utm_campaign={$campaign}&utm_content=120210000000001";

    it('al recibir, en una campaña marcada: al pedir solo el "pedido hecho"; el Purchase queda para la entrega', function () use ($landing) {
        $r = enviar164($this, ['landing' => $landing('120210000000555')])->assertOk();

        expect(StorefrontOrder::first()->purchase_mode)->toBe('delivery')
            ->and($r->json('event'))->toBeNull()
            ->and(array_column($r->json('events'), 'name'))->toBe(['AddPaymentInfo']);
    });

    it('si paga por adelantado, el Purchase sale ya aunque la campaña esté marcada', function () use ($landing) {
        $r = enviar164($this, ['landing' => $landing('120210000000555'), 'payment' => 'pago_movil', 'receipt' => RECIBO168])->assertOk();

        expect(array_column($r->json('events'), 'name'))->toBe(['AddPaymentInfo', 'Purchase']);
    });

    it('en otra campaña (o sin campaña, o desmarcada) todo sigue como siempre: el Purchase al pedir', function () use ($landing) {
        enviar164($this, ['landing' => $landing('120210000000777')])->assertOk();
        DB::table('meta_purchase_modes')->update(['ends_on' => today()->toDateString()]);
        enviar164($this, ['phone' => '04241234567', 'landing' => $landing('120210000000555')])->assertOk();

        expect(StorefrontOrder::pluck('purchase_mode')->all())->toBe(['order', 'order']);
    });

    it('al pasar a Entregado sale el Purchase una sola vez, con la hora del pedido (o la de ahora si pasaron más de 6 días)', function () use ($landing) {
        Illuminate\Support\Facades\Bus::fake();
        StorefrontCatalog::saveSettings(['pixel_id' => '1802353243804', 'capi_token' => 'EAAprueba']);
        enviar164($this, ['landing' => $landing('120210000000555')])->assertOk();
        $order = Order::first();
        $front = StorefrontOrder::first();

        App\Services\Storefront\StorefrontDelivered::handle($order);
        Illuminate\Support\Facades\Bus::assertDispatched(App\Jobs\SendStorefrontEvent::class,
            fn ($j) => $j->event === 'Purchase' && $j->time === $front->created_at->timestamp);

        // ya salió: no se repite; un retorno tampoco
        $front->forceFill(['capi_status' => 'sent'])->save();
        App\Services\Storefront\StorefrontDelivered::handle($order);
        Illuminate\Support\Facades\Bus::assertDispatchedTimes(App\Jobs\SendStorefrontEvent::class, 2); // el "pedido hecho" al pedir y el Purchase al entregar, nada más
        expect(StorefrontRules::deliveredTime(1000, 1000 + 7 * 86400))->toBe(1000 + 7 * 86400)
            ->and(StorefrontRules::deliveredTime(1000, 1000 + 6 * 86400))->toBe(1000);
    });

    it('la API manda el "pedido hecho" con su número, y el Purchase al entregar con su hora y el total entregado', function () use ($landing) {
        StorefrontCatalog::saveSettings(['pixel_id' => '1802353243804', 'capi_token' => 'EAAprueba']);
        Illuminate\Support\Facades\Bus::fake();
        enviar164($this, ['landing' => $landing('120210000000555')])->assertOk();
        $front = StorefrontOrder::first();
        Order::first()->forceFill(['current_total_price' => 47.5])->saveQuietly();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        expect((new MetaCapi())->send($front, MetaCapi::PLACED))->toBe('sent')
            ->and((new MetaCapi())->send($front->fresh(), MetaCapi::PURCHASE, 1_800_000_000))->toBe('sent');
        $sent = Http::recorded()->map(fn ($x) => $x[0]['data'][0])->all();
        expect([$sent[0]['event_name'], $sent[0]['event_id'], $sent[0]['custom_data']['value']])->toBe(['AddPaymentInfo', 'nuv-pedido-1', 51.5])
            ->and([$sent[1]['event_name'], $sent[1]['event_id'], $sent[1]['event_time'], $sent[1]['custom_data']['value']])->toBe(['Purchase', 'nuv-web-1', 1_800_000_000, 47.5])
            ->and($front->fresh()->placed_capi_status)->toBe('sent')
            ->and($front->fresh()->purchase_sent_at)->not->toBeNull();
    });
});

// ───────────────────────────── .170 ─────────────────────────────
// Dueño (21/11): "quiero probar que podamos enviar bien los eventos de meta de purchase… me gustaría probarlo antes de prender
// campañas": el Purchase al entregar de un pedido de prueba, sin entregarlo, y el link de prueba de cada campaña.

describe('.170: probar el Purchase al entregar sin entregar el pedido', function () {
    beforeEach(function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['prepaid_enabled' => true, 'pixel_id' => '1802353243804', 'capi_token' => 'EAAprueba']);
        DB::table('meta_purchase_modes')->insert(['product_id' => 7, 'campaign_meta_id' => '120210000000555', 'starts_on' => today()->toDateString()]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        Illuminate\Support\Facades\Bus::fake();
        Sanctum::actingAs(User::find(1));
    });

    it('solo con el código de prueba: sale por el mismo camino (la hora del pedido, su total y el código) y el pedido no cambia', function () {
        enviar164($this, ['landing' => 'https://solobrillo.com/products/solo-brillo?utm_campaign=120210000000555'])->assertOk();
        $front = StorefrontOrder::first();
        $status = Order::first()->status_id;
        Order::first()->forceFill(['current_total_price' => 47.5])->saveQuietly();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        $this->postJson("/api/store-form/orders/{$front->id}/test-purchase")->assertStatus(422)
            ->assertJsonPath('message', 'Primero pon el «Código de prueba de eventos» de Meta y guarda.');
        Http::assertNothingSent();

        StorefrontCatalog::saveSettings(['capi_test_code' => 'TEST123']);
        $r = $this->postJson("/api/store-form/orders/{$front->id}/test-purchase")->assertOk();
        Http::assertSent(fn ($x) => $x['data'][0]['event_name'] === 'Purchase' && $x['data'][0]['event_id'] === 'nuv-web-1'
            && $x['data'][0]['event_time'] === $front->created_at->timestamp && $x['data'][0]['custom_data']['value'] === 47.5
            && $x['test_event_code'] === 'TEST123');
        expect($r->json('orders.0.capi'))->toBe('sent')
            ->and($r->json('orders.0.purchase_sent_at'))->not->toBeNull()
            ->and(Order::first()->status_id)->toBe($status)
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_test_purchase')->count())->toBe(1);

        // ya salió: no se repite (tampoco al entregarlo de verdad)
        $this->postJson("/api/store-form/orders/{$front->id}/test-purchase")->assertStatus(422)
            ->assertJsonPath('message', 'Meta ya recibió el Purchase de este pedido.');
        App\Services\Storefront\StorefrontDelivered::handle(Order::first());
        Http::assertSentCount(1);
        Illuminate\Support\Facades\Bus::assertNotDispatched(App\Jobs\SendStorefrontEvent::class, fn ($j) => $j->event === 'Purchase');
    });

    it('no vale en un pedido que no espera la entrega ni para otro que no sea el Admin; si Meta lo rechaza, dice por qué', function () {
        StorefrontCatalog::saveSettings(['capi_test_code' => 'TEST123']);
        enviar164($this, ['landing' => 'https://solobrillo.com/products/solo-brillo?utm_campaign=120210000000777'])->assertOk();
        enviar164($this, ['phone' => '04241234567', 'landing' => 'https://solobrillo.com/products/solo-brillo?utm_campaign=120210000000555'])->assertOk();
        [$normal, $marked] = StorefrontOrder::orderBy('id')->get()->all();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

        $this->postJson("/api/store-form/orders/{$normal->id}/test-purchase")->assertStatus(422)
            ->assertJsonPath('message', 'Este pedido no espera la entrega: su Purchase salió al pedir.');
        Sanctum::actingAs(User::find(2));
        $this->postJson("/api/store-form/orders/{$marked->id}/test-purchase")->assertForbidden();
        Http::assertNothingSent();

        Sanctum::actingAs(User::find(1));
        $this->postJson("/api/store-form/orders/{$marked->id}/test-purchase")->assertStatus(422)
            ->assertJsonPath('message', 'Meta no recibió el Purchase: Invalid OAuth access token.');
    });

});

// ───────────────────────────── .171 ─────────────────────────────
// Dueño (21/11): "me parece muy complicado esas pruebas… hay que buscar una manera más sencilla sin duplicar campañas":
// "Purchase según cómo paga el cliente" se prende por producto, para todas sus campañas.

// ───────────────────────────── .172 ─────────────────────────────
// Dueño (21/11): si no hay stock "no debería ofrecer"; la ciudad sin stock puede pedir por encomienda; agotado en todas partes,
// "no sale agotado ni nada".

/** El stock útil de un almacén de prueba (120 = Distrito Capital, 121 = Carabobo, 130 = la logística propia de Aragua). */
function stock172(int $warehouse, int $quantity, int $reserved = 0): void
{
    DB::table('inventories')->where('warehouse_id', $warehouse)->update(['quantity' => $quantity, 'reserved_stock' => $reserved]);
}

describe('.172: el formulario solo ofrece donde hay stock', function () {
    it('una ciudad sale solo si alguna agencia (o la logística propia) tiene; la página no ve más que la oferta más grande', function () {
        stock172(120, 2, 1); // le queda 1
        stock172(121, 0);
        stock172(130, 40);
        $c = $this->get('/tienda/productos/7558816')->assertOk()->json();

        expect(array_column($c['cities'], 'name'))->toBe(['Aragua', 'Distrito Capital'])
            ->and(array_column(array_column($c['cities'], 'stock'), 'all'))->toBe([3, 1])
            ->and($c['available'])->toBeTrue();
    });

    it('las encomiendas salen solo si la ciudad de origen tiene; sin stock en ninguna parte no sale nada', function () {
        tablas168();
        StorefrontCatalog::saveSettings(['courier_enabled' => true, 'courier_origin_city_id' => 2]);
        stock172(121, 0);
        expect($this->get('/tienda/productos/7558816')->json('courier'))->toBeNull();

        stock172(121, 5);
        stock172(120, 0);
        stock172(130, 0);
        Illuminate\Support\Facades\Cache::flush();
        $c = $this->get('/tienda/productos/7558816')->json();
        // Carabobo con delivery y también de donde salen las encomiendas (los clientes de las otras ciudades piden por encomienda)
        expect(array_column($c['cities'], 'name'))->toBe(['Carabobo'])->and($c['courier']['companies'])->toBe(['MRW', 'Zoom'])->and($c['available'])->toBeTrue();

        stock172(121, 0);
        Illuminate\Support\Facades\Cache::flush();
        $c = $this->get('/tienda/productos/7558816')->json();
        expect([$c['cities'], $c['courier'], $c['available']])->toBe([[], null, false]);
    });

    it('con tallas cuenta la talla en el mismo almacén (el menor entre el producto y la talla)', function () {
        DB::table('product_variants')->insert([['id' => 70, 'product_id' => 7, 'title' => 'XL', 'is_active' => true], ['id' => 71, 'product_id' => 7, 'title' => 'L', 'is_active' => true]]);
        stock172(121, 1);
        stock172(130, 0);
        DB::table('inventory_variants')->insert([['warehouse_id' => 120, 'product_id' => 7, 'variant_id' => 70, 'quantity' => 2],
            ['warehouse_id' => 121, 'product_id' => 7, 'variant_id' => 71, 'quantity' => 4]]);
        $c = $this->get('/tienda/productos/7558816')->json();

        expect(array_column($c['cities'], 'stock', 'name'))->toBe(['Carabobo' => ['all' => 1, 'sizes' => ['70' => 0, '71' => 1]],
            'Distrito Capital' => ['all' => 3, 'sizes' => ['70' => 2, '71' => 0]]]);
    });

    it('al confirmar se revisa otra vez: sin pagar no entra (dice la ciudad); ya pagado entra con el aviso; el mismo pedido reenviado pasa', function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['prepaid_enabled' => true]);
        Illuminate\Support\Facades\Bus::fake();
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        stock172(120, 1);

        enviar164($this)->assertStatus(422)->assertJson(['fields' => ['where'], 'stock' => true, 'city' => 'Distrito Capital']); // la oferta de 2
        enviar164($this, ['offer' => 33])->assertOk();
        enviar164($this, ['phone' => '04241234567', 'payment' => 'pago_movil', 'receipt' => RECIBO168])->assertOk();
        stock172(120, 0);
        expect(enviar164($this, ['offer' => 33])->assertOk()->json('duplicate'))->toBeTrue()
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_sold_out')->pluck('description')->all())
            ->toBe(['Se agotó en Distrito Capital mientras el cliente pagaba: ofrécele esperar o devuélvele el pago.']);
    });

    it('en tu panel: qué sale hoy en cada ciudad y en las encomiendas', function () {
        Sanctum::actingAs(User::find(1));
        StorefrontCatalog::saveSettings(['courier_enabled' => true, 'courier_origin_city_id' => 2]);
        stock172(120, 12);
        stock172(121, 0);
        $p = $this->putJson('/api/store-form/products/7/purchase-mode', ['on' => false])->assertOk()->json('product.stock');

        expect($p['cities'])->toBe([['name' => 'Aragua', 'ok' => true, 'units' => 50], ['name' => 'Carabobo', 'ok' => false, 'units' => 0],
            ['name' => 'Distrito Capital', 'ok' => true, 'units' => 12]])
            ->and($p['courier'])->toBe(['name' => 'Carabobo', 'ok' => false, 'units' => 0]);
    });
});

describe('.171: Purchase según cómo paga, por producto', function () {
    beforeEach(function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['prepaid_enabled' => true]);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        Illuminate\Support\Facades\Bus::fake();
        Sanctum::actingAs(User::find(1));
    });

    it('prendido: al recibir espera la entrega en cualquier campaña o sin campaña; pagado, sale ya; apagado, como siempre', function () {
        $r = $this->putJson('/api/store-form/products/7/purchase-mode', ['on' => true])->assertOk();
        expect($r->json('product.purchase_by_payment'))->toBe(today()->toDateString())
            ->and($r->json('message'))->toContain('según cómo paga el cliente');

        $cod = enviar164($this)->assertOk(); // sin campaña
        $paid = enviar164($this, ['phone' => '04241234567', 'payment' => 'pago_movil', 'receipt' => RECIBO168,
            'landing' => 'https://solobrillo.com/products/solo-brillo?utm_campaign=120210000000777'])->assertOk();
        expect(StorefrontOrder::orderBy('id')->pluck('purchase_mode')->all())->toBe(['delivery', 'delivery'])
            ->and(array_column($cod->json('events'), 'name'))->toBe(['AddPaymentInfo'])
            ->and(array_column($paid->json('events'), 'name'))->toBe(['AddPaymentInfo', 'Purchase']);

        $this->putJson('/api/store-form/products/7/purchase-mode', ['on' => false])->assertOk()->assertJsonPath('product.purchase_by_payment', null);
        enviar164($this, ['phone' => '04161234567'])->assertOk();
        expect(StorefrontOrder::latest('id')->value('purchase_mode'))->toBe('order');
    });

    it('prenderlo cierra las marcas por campaña de la .168; solo el Admin', function () {
        DB::table('meta_purchase_modes')->insert(['product_id' => 7, 'campaign_meta_id' => '120210000000555', 'starts_on' => today()->subDays(3)->toDateString()]);
        $this->putJson('/api/store-form/products/7/purchase-mode', ['on' => true])->assertOk();
        $this->putJson('/api/store-form/products/7/purchase-mode', ['on' => true])->assertOk(); // dos veces: una sola fila

        expect(DB::table('meta_purchase_modes')->whereNull('ends_on')->get(['campaign_meta_id'])->pluck('campaign_meta_id')->all())->toBe([null])
            ->and(DB::table('meta_purchase_modes')->where('campaign_meta_id', '120210000000555')->value('ends_on'))->toBe(today()->toDateString());
        Sanctum::actingAs(User::find(2));
        $this->putJson('/api/store-form/products/7/purchase-mode', ['on' => false])->assertForbidden();
    });

    it('el experimento: el producto ahora contra sus últimos 14 días con anuncios de antes, con los días ya cerrados', function () {
        Schema::create('order_financials', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), ...array_map(fn ($c) => $t->decimal($c, 10, 2)->default(0),
            ['revenue_main', 'revenue_upsell', 'revenue_refunded', 'cogs_main', 'cogs_upsell', 'delivery_cost', 'delivery_legacy_cost', 'commission_seller',
                'commission_upsell', 'commission_leader', 'commission_deliverer'])]);
        DB::table('statuses')->insert([['id' => 30, 'description' => 'Entregado'], ['id' => 31, 'description' => 'Cancelado']]);
        DB::table('meta_campaigns')->insert(['meta_id' => '555', 'product_id' => 7]);
        $start = today()->subDays(20);
        // prendido un día para probar los eventos y apagado: no cuenta; cuenta desde que lo volvió a prender
        DB::table('meta_purchase_modes')->insert([['product_id' => 7, 'campaign_meta_id' => null, 'starts_on' => today()->subDays(25)->toDateString(),
            'ends_on' => today()->subDays(25)->toDateString()], ['product_id' => 7, 'campaign_meta_id' => null, 'starts_on' => $start->toDateString(), 'ends_on' => null]]);
        // antes (mayo): 12 entregados de 15 cerrados, $360 vendidos, $90 de publicidad
        $order = function (Carbon\Carbon $at, int $status, float $total) {
            static $n = 0;
            $n++;
            $id = DB::table('orders')->insertGetId(['order_id' => 9000 + $n, 'name' => "#{$n}", 'status_id' => $status, 'current_total_price' => $total, 'created_at' => $at]);
            DB::table('order_products')->insert(['order_id' => $id, 'product_id' => 7, 'price' => $total, 'quantity' => 1]);
        };
        $may = Carbon\Carbon::parse('2026-05-20');
        foreach (range(1, 12) as $i) {
            $order($may->copy()->addDays($i % 10), 30, 30.0);
        }
        foreach (range(1, 3) as $i) {
            $order($may->copy()->addDays($i), 31, 30.0);
        }
        DB::table('meta_insights_daily')->insert([['date' => '2026-05-25', 'level' => 'campaign', 'meta_id' => '555', 'spend' => 60, 'purchases' => 10],
            ['date' => '2026-05-30', 'level' => 'campaign', 'meta_id' => '555', 'spend' => 30, 'purchases' => 5]]);
        // ahora: 5 entregados de 5, $150 vendidos, $30 de publicidad (y un día todavía sin cerrar que no cuenta)
        foreach (range(1, 5) as $i) {
            $order($start->copy()->addDays($i), 30, 30.0);
        }
        DB::table('meta_insights_daily')->insert([['date' => $start->copy()->addDay()->toDateString(), 'level' => 'campaign', 'meta_id' => '555', 'spend' => 30, 'purchases' => 5],
            ['date' => today()->subDay()->toDateString(), 'level' => 'campaign', 'meta_id' => '555', 'spend' => 99, 'purchases' => 1]]);

        $e = (new StorefrontKpis())->experiment([7]);

        expect([$e['active'], $e['basis'], $e['days'], $e['from'], $e['to']])->toBe([true, 'own', 17, $start->toDateString(), today()->subDays(4)->toDateString()])
            ->and([$e['before']['from'], $e['before']['to']])->toBe(['2026-05-17', '2026-05-30'])
            ->and(array_intersect_key($e['before'], array_flip(['spend', 'delivered', 'closed', 'ad_share', 'effectiveness'])))
            ->toBe(['spend' => 90.0, 'delivered' => 12, 'closed' => 15, 'effectiveness' => 80.0, 'ad_share' => 25.0])
            ->and(array_intersect_key($e['after'], array_flip(['spend', 'delivered', 'ad_share'])))->toBe(['spend' => 30.0, 'delivered' => 5, 'ad_share' => 20.0])
            ->and($e['verdict']['key'])->toBe('measuring');
    });
});

describe('.168: lo que le escribe el sistema al que pagó por adelantado', function () {
    beforeEach(function () {
        tablas168();
        Illuminate\Support\Facades\Storage::fake('public');
        StorefrontCatalog::saveSettings(['prepaid_enabled' => true]);
        config(['services.receipt_checks.mode' => 'observe', 'services.openai.key' => 'sk-prueba']);
        $this->sources->shouldReceive('record');
        $this->intake->shouldReceive('finish');
        Illuminate\Support\Facades\Bus::fake();
        enviar164($this, ['payment' => 'pago_movil', 'receipt' => RECIBO168])->assertOk();
        $this->front = StorefrontOrder::first();
        $this->sent = [];
        $this->mock(App\Services\WhatsAppService::class, function ($m) {
            $m->shouldReceive('sendMessage')->andReturnUsing(function ($to, $text) {
                $this->sent[] = $text;

                return ['messages' => [['id' => 'wamid.' . count($this->sent)]]];
            });
        });
        $this->abrir = fn () => DB::table('business_days')->insert(['shop_id' => 4, 'date' => today()->toDateString(), 'open_at' => now()]);
        $this->ventana = fn () => DB::table('clients')->update(['last_whatsapp_received_at' => now()]);
        $this->correr = fn () => app(App\Services\Storefront\PrepaidMessages::class)->run();
    });

    it('fuera de horario: apenas escribe, el aviso de que una asesora validará su pago en el horario (una vez)', function () {
        ($this->ventana)();
        $this->travel(2)->minutes();
        ($this->correr)();
        ($this->correr)();

        expect($this->sent)->toHaveCount(1)
            ->and($this->sent[0])->toContain('Recibimos tu pedido WEB-1 y tu comprobante de pago')
            ->and($this->sent[0])->toContain('Nuestro horario de atención es de lunes a sábado, de 8:00 a. m. a 7:00 p. m.')
            ->and($this->sent[0])->toContain('una asesora validará tu pago y procederá con tu envío')
            ->and($this->sent[0])->not->toContain('IA');
    });

    it('comprobante bien y tienda abierta: "ya confirmamos tu pago" entre 5 y 15 minutos después', function () {
        ($this->abrir)();
        ($this->ventana)();
        DB::table('receipt_checks')->update(['status' => 'ok']);
        ($this->correr)();
        $due = $this->front->fresh()->confirm_due_at;

        expect($due->between(now()->addMinutes(5)->subSecond(), now()->addMinutes(15)->addSecond()))->toBeTrue()
            ->and($this->sent)->toBe([]);
        $this->travel(16)->minutes();
        ($this->correr)();

        expect($this->sent)->toHaveCount(1)->and($this->sent[0])->toContain('Ya confirmamos tu pago del pedido WEB-1')
            ->and($this->front->fresh()->confirm_status)->toBe('sent');
    });

    it('sin la ventana de WhatsApp abierta, la vendedora lo confirma; con un problema en el comprobante, nada', function () {
        ($this->abrir)();
        DB::table('receipt_checks')->update(['status' => 'fail']);
        ($this->correr)();
        expect($this->front->fresh()->confirm_status)->toBeNull()->and($this->front->fresh()->confirm_due_at)->toBeNull();

        // administración lo aprueba a mano: ahora sí, pero no tiene la ventana abierta
        DB::table('receipt_checks')->update(['reviewed_at' => now()]);
        ($this->correr)();
        $this->travel(16)->minutes();
        ($this->correr)();

        expect($this->sent)->toBe([])
            ->and($this->front->fresh()->confirm_status)->toBe('seller')
            ->and(DB::table('order_activity_logs')->where('action', 'storefront_confirm_seller')->value('description'))->toContain('escríbele que ya confirmamos su pago')
            ->and(App\Services\Storefront\PrepaidState::of(Order::first())['state'])->toBe('seller');
    });

    it('validado con la tienda cerrada: espera a que abra (y ahí 5 a 15 minutos)', function () {
        ($this->ventana)();
        DB::table('receipt_checks')->update(['status' => 'ok']);
        ($this->correr)();
        expect($this->front->fresh()->confirm_due_at)->toBeNull();

        ($this->abrir)();
        ($this->correr)();
        expect($this->front->fresh()->confirm_due_at)->not->toBeNull();
    });
});
