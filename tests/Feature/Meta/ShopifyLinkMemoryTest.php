<?php

use App\Services\Meta\Products\ShopifyLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// .178 (2026-11-26): la tarjeta del asistente para crear Sonrisa V34 se anuló con "Algo cambió" 38 segundos después: al
// confirmar se le volvía a preguntar a Shopify y contestó distinto. Lo que respondió Shopify se recuerda lo que dura una
// tarjeta (2 horas).

beforeEach(function () {
    Schema::create('shops', function ($t) {
        $t->id();
        $t->string('shopify_domain')->nullable();
        $t->string('shopify_access_token')->nullable();
        $t->timestamps();
    });
    DB::table('shops')->insert(['shopify_domain' => 'tienda-prueba.myshopify.com', 'shopify_access_token' => 'token-de-prueba']);
    $this->product = fn (string $price, string $title = 'Sonrisa Profesional V34') => Http::response(['products' => [
        ['id' => 15564437094531, 'title' => $title, 'variants' => [['price' => $price]]],
    ]]);
});

it('al confirmar se usa lo que Shopify respondió al proponer, aunque ahora conteste otro precio u otro nombre', function () {
    Http::fakeSequence('tienda-prueba.myshopify.com/*')->pushResponse(($this->product)('39.00'))->pushResponse(($this->product)('45.00', 'Sonrisa V34 (editando)'));
    $link = 'https://mitienda.com/products/sonrisa-profesional-v34';
    $first = ShopifyLink::resolve($link);

    expect($first)->toBe(['id' => 15564437094531, 'title' => 'Sonrisa Profesional V34', 'price' => 39.0, 'problem' => null])
        ->and(ShopifyLink::resolve($link))->toBe($first);
    Http::assertSentCount(1);
});

it('lo que no se encontró no se recuerda: la próxima vez se vuelve a preguntar', function () {
    Http::fakeSequence('tienda-prueba.myshopify.com/*')->pushResponse(Http::response(['products' => []]))->pushResponse(($this->product)('39.00'));
    $link = 'https://mitienda.com/products/sonrisa-profesional-v34';

    expect(ShopifyLink::resolve($link)['problem'])->toStartWith('No encontré ese producto')
        ->and(ShopifyLink::resolve($link))->toMatchArray(['id' => 15564437094531, 'price' => 39.0, 'problem' => null]);
    Http::assertSentCount(2);
});

it('se recuerda 2 horas, lo que dura una tarjeta', function () {
    // con el link del panel de Shopify (el número): Shopify da el producto solo
    $one = fn (string $price) => Http::response(['product' => ['id' => 15564437094531, 'title' => 'Sonrisa Profesional V34', 'variants' => [['price' => $price]]]]);
    Http::fakeSequence('tienda-prueba.myshopify.com/*')->pushResponse($one('39.00'))->pushResponse($one('45.00'));
    $link = 'https://admin.shopify.com/store/mitienda/products/15564437094531';

    expect(ShopifyLink::resolve($link)['price'])->toBe(39.0);
    $this->travel(2)->hours();
    $this->travel(1)->minutes();
    expect(ShopifyLink::resolve($link)['price'])->toBe(45.0);
});
