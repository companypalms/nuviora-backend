<?php

use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Tools\PurchasesTool;
use App\Services\Assistant\Tools\ToolProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .142 (dueño, 2026-11-13: "no me deja ver a qué precio compré el releev la última vez ni tampoco a qué proveedor"): el
// asistente solo veía las compras por recibir (en_camino); ahora lee también las ya hechas.

beforeEach(function () {
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('suppliers', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('warehouses', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('inventory_purchases', function ($t) {
        $t->id();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->unsignedBigInteger('product_id');
        $t->unsignedBigInteger('warehouse_id')->nullable();
        $t->integer('quantity');
        $t->decimal('total_amount', 14, 2)->nullable();
        $t->string('currency', 3)->nullable();
        $t->decimal('rate', 14, 4)->nullable();
        $t->decimal('total_usd', 14, 2)->nullable();
        $t->decimal('unit_cost_usd', 14, 4)->nullable();
        $t->string('payment_type')->nullable();
        $t->timestamp('purchased_at')->nullable();
        $t->string('status')->default('received');
        $t->date('expected_at')->nullable();
        $t->timestamp('received_at')->nullable();
        $t->decimal('expected_total_amount', 14, 2)->nullable();
        $t->timestamps();
    });
    Schema::create('inventory_lots', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->string('cost_source')->nullable(),
        $t->decimal('unit_cost_usd', 14, 4)->nullable(), $t->timestamp('received_at')->nullable()]);

    DB::table('products')->insert([['id' => 10, 'title' => 'Releev® Alivio muscular profundo'], ['id' => 11, 'title' => 'FlexiStep']]);
    DB::table('suppliers')->insert([['id' => 1, 'name' => 'Importadora Lara'], ['id' => 2, 'name' => 'China Directo']]);
    DB::table('warehouses')->insert(['id' => 1, 'name' => 'Bodega Agencia: Dalgo']);
    $buy = fn (array $row) => DB::table('inventory_purchases')->insert($row + ['warehouse_id' => 1, 'payment_type' => 'cash', 'status' => 'received', 'currency' => 'USD']);
    $buy(['product_id' => 10, 'supplier_id' => 2, 'quantity' => 100, 'total_amount' => 650, 'total_usd' => 650, 'unit_cost_usd' => 6.5,
        'purchased_at' => '2026-10-01 10:00:00', 'received_at' => '2026-10-20 09:00:00']);
    // la última recibida: en bolívares, a crédito
    $buy(['product_id' => 10, 'supplier_id' => 1, 'quantity' => 50, 'total_amount' => 15000, 'currency' => 'VES', 'rate' => 40, 'total_usd' => 375,
        'unit_cost_usd' => 7.5, 'payment_type' => 'credit', 'purchased_at' => '2026-11-05 10:00:00', 'received_at' => '2026-11-06 12:00:00']);
    // una cancelada no sale; una por recibir sí, marcada
    $buy(['product_id' => 10, 'supplier_id' => 1, 'quantity' => 999, 'total_amount' => 1, 'total_usd' => 1, 'unit_cost_usd' => 0.01, 'status' => 'cancelled',
        'purchased_at' => '2026-11-10 10:00:00']);
    $buy(['product_id' => 10, 'supplier_id' => 2, 'quantity' => 80, 'expected_total_amount' => 560, 'total_usd' => 560, 'unit_cost_usd' => 7, 'status' => 'pending',
        'purchased_at' => '2026-11-12 10:00:00', 'expected_at' => '2026-11-30']);
    $buy(['product_id' => 11, 'supplier_id' => 2, 'quantity' => 200, 'total_amount' => 800, 'total_usd' => 800, 'unit_cost_usd' => 4,
        'purchased_at' => '2026-11-01 10:00:00', 'received_at' => '2026-11-02 10:00:00']);
    DB::table('inventory_lots')->insert([
        ['product_id' => 10, 'cost_source' => 'purchase', 'unit_cost_usd' => 6.5, 'received_at' => '2026-10-20 09:00:00'],
        ['product_id' => 10, 'cost_source' => 'purchase', 'unit_cost_usd' => 7.5, 'received_at' => '2026-11-06 12:00:00'],
    ]);
});

it('la última compra de Releev: a qué proveedor, cuántas unidades y a qué costo por unidad', function () {
    $r = app(PurchasesTool::class)->run(['producto' => 'releev', 'proveedor' => null]);

    expect($r->data['ultima_compra_recibida'])->toBe(['fecha' => '06/11/2026', 'producto' => 'Releev® Alivio muscular profundo', 'proveedor' => 'Importadora Lara',
        'unidades' => 50, 'costo_por_unidad' => '$7,50', 'total' => 'Bs 15.000,00', 'tasa' => '40,00 Bs por dólar', 'total_en_dolares' => '$375,00',
        'pago' => 'A crédito', 'almacen' => 'Bodega Agencia: Dalgo', 'estado' => 'Recibida'])
        // de la más nueva a la más vieja; la cancelada no sale; la por recibir sí, marcada
        ->and(array_column($r->data['compras'], 'estado'))->toBe(['Por recibir, llega el 30/11/2026', 'Recibida', 'Recibida'])
        ->and($r->data['compras'][0]['costo_por_unidad'])->toBe('$7,00 (estimado: todavía no llega)')
        ->and($r->data['total_recibido'])->toBe(['compras' => 2, 'unidades' => 150, 'dolares' => '$1.025,00'])
        ->and($r->data['costo_que_usa_el_sistema'])->toBe('$7,50')
        // la tabla: fecha y proveedor, y en bolívares lo que se pagó
        ->and($r->display[0]['title'])->toBe('Compras de Releev® Alivio muscular profundo')
        ->and($r->display[0]['rows'][1])->toMatchArray(['name' => '06/11/2026 · Importadora Lara', 'group' => 'Bs 15.000,00',
            'values' => ['units' => 50, 'unit_cost' => 7.5, 'total_usd' => 375.0]]);
});

it('si el costo se completó a mano en Finanzas › Inventario, dice cuál usa el sistema', function () {
    DB::table('inventory_lots')->insert(['product_id' => 10, 'cost_source' => 'manual', 'unit_cost_usd' => 8, 'received_at' => '2026-11-07 09:00:00']);

    expect(app(PurchasesTool::class)->run(['producto' => 'releev', 'proveedor' => null])->data['costo_que_usa_el_sistema'])
        ->toBe('$8,00 (distinto de la última compra: se completó a mano en Finanzas › Inventario)');
});

it('por proveedor: lo que se le ha comprado, de todos los productos', function () {
    $r = app(PurchasesTool::class)->run(['producto' => null, 'proveedor' => 'china']);

    expect(array_column($r->data['compras'], 'producto'))->toBe(['Releev® Alivio muscular profundo', 'FlexiStep', 'Releev® Alivio muscular profundo'])
        ->and($r->data['total_recibido'])->toBe(['compras' => 2, 'unidades' => 300, 'dolares' => '$1.450,00'])
        ->and($r->data)->not->toHaveKey('costo_que_usa_el_sistema')
        ->and($r->display[0]['title'])->toBe('Compras a China Directo');
});

it('sin compras, o un proveedor que no existe', function () {
    DB::table('products')->insert(['id' => 12, 'title' => 'Comprimax']);

    expect(app(PurchasesTool::class)->run(['producto' => 'comprimax', 'proveedor' => null])->data)->toBe(['problema' => 'No hay compras registradas de ese producto.'])
        ->and(fn () => app(PurchasesTool::class)->run(['producto' => null, 'proveedor' => 'Amazon']))->toThrow(ToolProblem::class);
});

it('el modelo de ejemplo entiende "¿a qué precio compré…?" y "¿a qué proveedor…?"', function () {
    expect(ExampleModel::understand('a que precio compre el releev la ultima vez')['name'])->toBe('compras')
        ->and(ExampleModel::understand('a que proveedor le compre el releev la ultima vez')['name'])->toBe('compras')
        ->and(ExampleModel::understand('¿qué compras vienen en camino?')['name'])->toBe('en_camino');
});
