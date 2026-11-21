<?php

use App\Models\Order;
use App\Services\Finance\OrderEconomics;
use App\Services\InventoryService;
use App\Services\Products\ProductMerge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .167 (dueño, 2026-11-21): "en el inventario aparecen dos Bunnycare. El primer Bunnycare es en donde puse stock. Sin embargo,
// desde ahí no se están jalando las órdenes, sino desde el otro… coloca solo un Bunnycare". En producción: el #1183 BuniCare
// (del asistente: ficha, stock 72 + 30 + 18 en tránsito, campañas) y el #1184 que creó Shopify el 19/11 con 51 pedidos, todos
// en "Sin Stock". Se queda el #1183 con el número de Shopify, el precio, la foto y los pedidos; el #1184 se borra.

beforeEach(function () {
    Schema::create('products', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable()->unique(), $t->unsignedBigInteger('variant_id')->nullable(),
        $t->string('sku')->nullable(), $t->string('title')->nullable(), $t->string('showable_name')->nullable(), $t->decimal('price', 10, 2)->default(0),
        $t->string('image')->nullable(), $t->timestamps()]);
    Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('orders', fn ($t) => [$t->id(), $t->unsignedBigInteger('status_id'), $t->timestamps()]);
    Schema::create('order_products', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('product_number')->nullable(),
        $t->unique(['order_id', 'product_id'])]);
    Schema::create('order_financials', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id')->nullable()]);
    Schema::create('product_profiles', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->unique()]);
    Schema::create('inventories', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('warehouse_id'), $t->integer('quantity')]);
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable()]);

    DB::table('statuses')->insert([['id' => 1, 'description' => 'Nuevo'], ['id' => 2, 'description' => 'Sin Stock'], ['id' => 3, 'description' => 'Entregado']]);
    DB::table('products')->insert([
        ['id' => 1183, 'product_id' => null, 'variant_id' => null, 'sku' => null, 'title' => 'BuniCare', 'showable_name' => 'BuniCare', 'price' => 0, 'image' => null],
        ['id' => 1184, 'product_id' => 15524373528707, 'variant_id' => 68372705345667, 'sku' => null, 'title' => 'Bunicare – Corrector de juanetes con bisagra, talla única',
            'showable_name' => null, 'price' => 27, 'image' => 'https://cdn.shopify.com/bunicare.webp'],
        ['id' => 227, 'product_id' => 8713806151811, 'variant_id' => null, 'sku' => null, 'title' => 'BUNON PRO® RECUPERA TUS PIES', 'showable_name' => null, 'price' => 20, 'image' => null],
    ]);
    DB::table('product_profiles')->insert(['product_id' => 1183]);
    DB::table('inventories')->insert([['product_id' => 1183, 'warehouse_id' => 2, 'quantity' => 72], ['product_id' => 1183, 'warehouse_id' => 3, 'quantity' => 30]]);
    DB::table('meta_campaigns')->insert([['product_id' => 1183], ['product_id' => 1183], ['product_id' => 227]]);
    foreach ([[1, 2], [2, 2], [3, 2], [4, 3]] as [$id, $status]) {
        DB::table('orders')->insert(['id' => $id, 'status_id' => $status]);
        DB::table('order_products')->insert(['order_id' => $id, 'product_id' => 1184, 'product_number' => 15524373528707]);
        DB::table('order_financials')->insert(['order_id' => $id, 'product_id' => 1184]);
    }
    DB::table('orders')->insert(['id' => 5, 'status_id' => 2]);
    DB::table('order_products')->insert(['order_id' => 5, 'product_id' => 227]);
});

/** La unión con lo de afuera (el stock de cada pedido, el recálculo y la salida de "Sin Stock") de mentira. */
function unir(bool $apply, ?InventoryService $inventory = null, ?OrderEconomics $economics = null): array
{
    return (new ProductMerge($inventory ?? Mockery::mock(InventoryService::class), $economics ?? Mockery::mock(OrderEconomics::class), fn (int $id) => $id !== 3))
        ->run(1183, 1184, $apply);
}

it('el ensayo dice todo lo que haría y no cambia nada', function () {
    $r = unir(false);

    expect($r['applied'])->toBeFalse()
        ->and($r['product'])->toBe(['product_id' => 15524373528707, 'variant_id' => 68372705345667, 'price' => 27, 'image' => 'https://cdn.shopify.com/bunicare.webp'])
        ->and($r['moved'])->toBe(['order_financials.product_id' => 4, 'order_products.product_id' => 4])
        ->and($r['orders'])->toBe([1, 2, 3, 4])
        ->and($r['waiting'])->toBe(3)->and($r['could_leave'])->toBe(2)
        ->and($r['rebuilt'])->toBeNull()->and($r['recovered'])->toBeNull()
        // nada cambió
        ->and(DB::table('products')->whereIn('id', [1183, 1184])->pluck('product_id', 'id')->all())->toBe([1183 => null, 1184 => 15524373528707])
        ->and(DB::table('order_products')->where('product_id', 1184)->count())->toBe(4);
});

it('de verdad: el nuestro queda con Shopify y los pedidos, el repetido se borra y los pedidos salen de "Sin Stock"', function () {
    $inventory = Mockery::mock(InventoryService::class);
    $inventory->shouldReceive('recoverFromSinStock')->times(3)->andReturnUsing(fn (Order $o) => $o->id !== 3);
    $economics = Mockery::mock(OrderEconomics::class);
    $economics->shouldReceive('rebuild')->once()->with([1, 2, 3, 4])->andReturn(4);

    $r = unir(true, $inventory, $economics);
    $keep = DB::table('products')->find(1183);

    expect($r['applied'])->toBeTrue()->and($r['rebuilt'])->toBe(4)->and($r['recovered'])->toBe(2)
        ->and((int) $keep->product_id)->toBe(15524373528707)->and((int) $keep->variant_id)->toBe(68372705345667)
        ->and((float) $keep->price)->toBe(27.0)->and($keep->image)->toBe('https://cdn.shopify.com/bunicare.webp')
        // su nombre, su ficha, su stock y sus campañas se quedan
        ->and($keep->title)->toBe('BuniCare')
        ->and(DB::table('product_profiles')->where('product_id', 1183)->count())->toBe(1)
        ->and(DB::table('inventories')->where('product_id', 1183)->sum('quantity'))->toEqual(102)
        ->and(DB::table('meta_campaigns')->where('product_id', 1183)->count())->toBe(2)
        // los pedidos, al nuestro; el repetido ya no existe; el BUNON PRO no se toca
        ->and(DB::table('order_products')->where('product_id', 1183)->pluck('order_id')->all())->toBe([1, 2, 3, 4])
        ->and(DB::table('order_financials')->where('product_id', 1183)->count())->toBe(4)
        ->and(DB::table('products')->find(1184))->toBeNull()
        ->and(DB::table('order_products')->where('product_id', 227)->count())->toBe(1)
        ->and((int) DB::table('products')->find(227)->product_id)->toBe(8713806151811);
});

it('no une si el repetido no tiene número de Shopify, si el nuestro ya está unido a otro o si son el mismo', function () {
    expect(fn () => (new ProductMerge(null, null, fn () => true))->run(1184, 1183, false))->toThrow(RuntimeException::class, 'no tiene número de Shopify')
        ->and(fn () => (new ProductMerge(null, null, fn () => true))->run(227, 1184, false))->toThrow(RuntimeException::class, 'ya está unido a otro producto')
        ->and(fn () => (new ProductMerge(null, null, fn () => true))->run(1183, 1183, false))->toThrow(RuntimeException::class, 'son el mismo');
});

it('si algo choca a la mitad, no queda nada a medias', function () {
    // los dos con ficha: la ficha es única por producto
    DB::table('product_profiles')->insert(['product_id' => 1184]);

    expect(fn () => unir(true))->toThrow(\Illuminate\Database\QueryException::class)
        ->and(DB::table('products')->find(1184))->not->toBeNull()
        ->and(DB::table('products')->find(1183)->product_id)->toBeNull()
        ->and(DB::table('order_products')->where('product_id', 1184)->count())->toBe(4);
});

it('el comando: ensayo por defecto', function () {
    $this->app->instance(ProductMerge::class, new ProductMerge(Mockery::mock(InventoryService::class), Mockery::mock(OrderEconomics::class), fn () => true));

    $this->artisan('products:unir', ['queda' => 1183, 'repetido' => 1184])
        ->expectsOutput('ENSAYO: no se cambió nada.')
        ->expectsOutput('Se queda: #1183 BuniCare. Se borraría: #1184 Bunicare – Corrector de juanetes con bisagra, talla única.')
        ->expectsOutput('Pedidos que pasarían: 4. En "Sin Stock": 3; con el stock del que se queda, les alcanza a 3.')
        ->expectsOutput('FIN')
        ->assertSuccessful();
    expect(DB::table('products')->find(1184))->not->toBeNull();
});
