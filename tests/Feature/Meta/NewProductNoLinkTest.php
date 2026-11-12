<?php

use App\Models\Product;
use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .138 (dueño, 2026-11-12: la ficha de un producto nuevo sin link de Shopify fallaba "falló el sistema" una y otra vez): el
// número de Shopify es único, y los productos sin link se guardaban con 0: el segundo chocaba con el primero.

beforeEach(function () {
    // Como en producción después de la migración de la .138: único y vacío si no tiene link
    Schema::create('products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id')->nullable()->unique();
        $t->string('title');
        $t->string('name')->nullable();
        $t->string('showable_name')->nullable();
        $t->decimal('price', 10, 2)->default(0);
        $t->timestamps();
    });
});

it('dos productos nuevos sin link se crean los dos, sin número de Shopify', function () {
    [$a, $createdA] = ProductProfileTool::newProduct(['name' => 'Electroestimulador', 'title' => 'Electroestimulador', 'price' => 0, 'shopify_id' => 0]);
    [$b, $createdB] = ProductProfileTool::newProduct(['name' => 'LegUp', 'title' => 'LegUp', 'price' => 0, 'shopify_id' => 0]);

    expect($createdA)->toBeTrue()->and($createdB)->toBeTrue()->and($a)->not->toBe($b)
        ->and(Product::whereKey([$a, $b])->pluck('product_id')->all())->toBe([null, null]);
});

it('con link: si un pedido ya creó ese producto de Shopify, se usa ese (nunca uno repetido)', function () {
    DB::table('products')->insert(['id' => 7, 'product_id' => 8123456789, 'title' => 'Electroestimulador de glúteos']);

    expect(ProductProfileTool::newProduct(['name' => 'Electroestimulador', 'title' => 'Electroestimulador', 'price' => 39, 'shopify_id' => 8123456789]))->toBe([7, false])
        ->and(ProductProfileTool::newProduct(['name' => 'Otro', 'title' => 'Otro', 'price' => 20, 'shopify_id' => 8000000001]))->toBe([8, true])
        ->and(Product::find(8)->product_id)->toBe(8000000001);
});
