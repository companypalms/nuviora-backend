<?php

use App\Models\Order;
use App\Services\Agencies\AgencyRouter;
use App\Services\Assignment\AssignOrderService;
use App\Services\Orders\OrderIntake;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

// .164: el cierre del alta de un pedido que no viene de Shopify (el manual y el del formulario de la tienda), movido de
// OrderController sin cambios: con stock, el webhook de "Nuevo" y la vendedora; sin stock, "Sin Stock" (sin webhook).

beforeEach(function () {
    Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('orders', fn ($t) => [$t->id(), $t->string('name')->nullable(), $t->unsignedBigInteger('agent_id')->nullable(), $t->unsignedBigInteger('status_id'), $t->timestamps()]);
    Schema::create('order_products', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->timestamps()]);
    DB::table('statuses')->insert([['id' => 1, 'description' => 'Nuevo'], ['id' => 2, 'description' => 'Sin Stock']]);
    Event::fake();
    $this->webhooks = $this->mock(WebhookService::class);
    $this->assigner = $this->mock(AssignOrderService::class);
    $this->mock(AgencyRouter::class)->shouldReceive('provisional');
});

/** Un pedido nuevo que tiene (o no) stock en su agencia. */
function pedidoConStock(bool $stock, ?int $agent = null): Order
{
    $id = DB::table('orders')->insertGetId(['name' => 'WEB-1', 'agent_id' => $agent, 'status_id' => 1]);
    $order = Mockery::mock(Order::find($id))->makePartial();
    $order->shouldReceive('getStockDetails')->andReturn(['has_warning' => !$stock]);

    return $order;
}

it('con stock y sin vendedora: el webhook de "Nuevo" y después la vendedora', function () {
    $this->webhooks->shouldReceive('triggerOrderStatus')->once()->ordered();
    $this->assigner->shouldReceive('assignOne')->once()->ordered();

    (new OrderIntake())->finish(pedidoConStock(true));
});

it('sin stock: "Sin Stock", sin webhook, y se busca vendedora igual', function () {
    $this->webhooks->shouldNotReceive('triggerOrderStatus');
    $this->assigner->shouldReceive('assignOne')->once();

    (new OrderIntake())->finish(pedidoConStock(false));
    expect(DB::table('orders')->value('status_id'))->toBe(2);
});

it('con vendedora: con stock solo el webhook; sin stock solo "Sin Stock"', function () {
    $this->webhooks->shouldReceive('triggerOrderStatus')->once();
    $this->assigner->shouldNotReceive('assignOne');
    (new OrderIntake())->finish(pedidoConStock(true, 5));
    (new OrderIntake())->finish(pedidoConStock(false, 5));

    expect(DB::table('orders')->pluck('status_id')->all())->toBe([1, 2]);
});

it('si algo falla, el pedido queda guardado y no rompe el alta', function () {
    $this->webhooks->shouldReceive('triggerOrderStatus')->andThrow(new RuntimeException('n8n caído'));

    (new OrderIntake())->finish(pedidoConStock(true));
    expect(DB::table('orders')->count())->toBe(1);
});
