<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .89 (dueño, 2026-11-03: "cuando llegue un pedido de ese producto va a poder reconocer y enlazarlo?"): el producto de una
// línea de un pedido de Shopify, en una base en memoria. Primero por el título, como siempre; si no coincide, por el
// número de Shopify que guardó el asistente con el link de la tienda (así no se crea un producto repetido).

beforeEach(function () {
    Schema::create('products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('title');
        $t->string('showable_name')->nullable();
        $t->timestamps();
    });
    DB::table('products')->insert([
        ['id' => 1, 'product_id' => 7001, 'title' => 'FlexiStep® Tobillera de compresión', 'showable_name' => 'FlexiStep'],
        ['id' => 2, 'product_id' => 8812345678901, 'title' => 'Termafit', 'showable_name' => 'Termafit'], // la creó el asistente con el link
        ['id' => 3, 'product_id' => 0, 'title' => 'Lumina', 'showable_name' => 'Lumina'],                    // sin link todavía
    ]);
});

it('el título manda, como siempre (aunque el número sea otro)', function () {
    expect(Product::forShopifyLine('flexistep® tobillera de compresión', 999)->id)->toBe(1);
});

it('si el título de Shopify es otro, encuentra el producto del asistente por su número de Shopify', function () {
    expect(Product::forShopifyLine('Termafit® Faja Térmica Reductora', 8812345678901)->id)->toBe(2);
});

it('un producto sin link (número 0) no se confunde con nada: el pedido crea uno, como antes', function () {
    expect(Product::forShopifyLine('Lumina® Sérum', 5555))->toBeNull()
        ->and(Product::forShopifyLine('Otro', 0))->toBeNull();
});
