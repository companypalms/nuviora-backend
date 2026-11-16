<?php

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAssignedNotification;
use App\Services\Agencies\AgencyRouter;
use App\Services\Orders\OrderRelocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// .149 (dueño, 2026-11-16: "lo correcto sería que solo se reasignara a una ciudad, si quiero reasignarlo a una ciudad, y si
// quiero reasignarlo de agencia dentro de esa ciudad, también lo pueda reasignar. Y es importante que la líder de vendedoras
// pueda reasignar de ciudad al igual que yo"). El pedido #10731 estaba en Aragua con Dalgo y salían todas las agencias.

const ESTADOS = [1 => 'Llamado 1', 2 => 'Sin Stock', 3 => 'Asignar a agencia', 4 => 'Asignar repartidor', 5 => 'Asignado a repartidor',
    6 => 'En ruta', 7 => 'Entregado', 8 => 'Cancelado', 9 => 'Asignado a vendedor'];

beforeEach(function () {
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->unsignedBigInteger('role_id')->nullable();
        $t->unsignedInteger('max_active_orders')->nullable();
        $t->boolean('agency_is_working')->nullable();
        $t->time('agency_works_from')->nullable();
        $t->time('agency_works_until')->nullable();
        $t->json('agency_schedule')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('sales_groups', fn ($t) => [$t->id(), $t->string('name'), $t->boolean('is_active')->default(true), $t->timestamps()]);
    Schema::create('sales_group_members', function ($t) {
        $t->id();
        $t->unsignedBigInteger('sales_group_id');
        $t->unsignedBigInteger('user_id');
        $t->string('role');
        $t->timestamp('ended_at')->nullable();
        $t->timestamps();
    });
    Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('clients', fn ($t) => [$t->id(), $t->string('province')->nullable(), $t->string('city')->nullable(), $t->timestamps()]);
    Schema::create('cities', function ($t) {
        $t->id();
        $t->string('name');
        $t->decimal('delivery_cost_usd', 8, 2)->default(0);
        $t->unsignedBigInteger('agency_id')->nullable();
        $t->timestamps();
    });
    Schema::create('city_agency', function ($t) {
        $t->id();
        $t->unsignedBigInteger('city_id');
        $t->unsignedBigInteger('agency_id');
        $t->decimal('weight', 5, 2)->nullable();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('warehouses', fn ($t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()]);
    Schema::create('logistics_centers', function ($t) {
        $t->id();
        $t->unsignedBigInteger('city_id');
        $t->unsignedBigInteger('warehouse_id')->nullable();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('logistics_orders', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('status'), $t->timestamps()]);
    Schema::create('orders', function ($t) {
        $t->id();
        $t->string('name')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->unsignedBigInteger('agency_id')->nullable();
        $t->unsignedBigInteger('deliverer_id')->nullable();
        $t->unsignedBigInteger('warehouse_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
        $t->unsignedBigInteger('status_id');
        $t->unsignedBigInteger('previous_status_id')->nullable();
        $t->boolean('agency_locked')->default(false);
        $t->decimal('delivery_cost', 8, 2)->nullable();
        $t->timestamp('received_at')->nullable();
        $t->timestamps();
    });
    Schema::create('order_products', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->timestamps()]); // OrderUpdated los carga
    Schema::create('order_activity_logs', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action');
        $t->text('description')->nullable();
        $t->json('properties')->nullable();
        $t->timestamps();
    });

    DB::table('roles')->insert([['id' => 1, 'description' => 'Admin'], ['id' => 2, 'description' => 'Vendedor'], ['id' => 3, 'description' => 'Agencia'],
        ['id' => 4, 'description' => 'Gerente']]);
    DB::table('users')->insert([
        ['id' => 1, 'names' => 'Jose', 'role_id' => 1, 'max_active_orders' => null],
        ['id' => 10, 'names' => 'Lara', 'role_id' => 2, 'max_active_orders' => null],   // la líder del grupo Norte
        ['id' => 11, 'names' => 'Ana', 'role_id' => 2, 'max_active_orders' => null],    // de su grupo
        ['id' => 12, 'names' => 'Beto', 'role_id' => 2, 'max_active_orders' => null],   // de otro grupo
        ['id' => 20, 'names' => 'Dalgo', 'role_id' => 3, 'max_active_orders' => null],  // Aragua
        ['id' => 21, 'names' => 'Rapi2', 'role_id' => 3, 'max_active_orders' => 8],     // Carabobo, llena
        ['id' => 22, 'names' => 'Valen', 'role_id' => 3, 'max_active_orders' => null],  // Carabobo
    ]);
    $norte = DB::table('sales_groups')->insertGetId(['name' => 'Norte']);
    DB::table('sales_group_members')->insert([['sales_group_id' => $norte, 'user_id' => 10, 'role' => 'leader'], ['sales_group_id' => $norte, 'user_id' => 11, 'role' => 'member']]);
    foreach (ESTADOS as $id => $d) {
        DB::table('statuses')->insert(['id' => $id, 'description' => $d]);
    }
    DB::table('clients')->insert(['id' => 1, 'province' => 'Aragua', 'city' => 'Aragua']);
    DB::table('cities')->insert([['id' => 1, 'name' => 'Aragua', 'delivery_cost_usd' => 3], ['id' => 2, 'name' => 'Carabobo', 'delivery_cost_usd' => 4],
        ['id' => 3, 'name' => 'Zulia', 'delivery_cost_usd' => 5]]);
    DB::table('city_agency')->insert([['city_id' => 1, 'agency_id' => 20, 'weight' => null], ['city_id' => 2, 'agency_id' => 21, 'weight' => 60],
        ['city_id' => 2, 'agency_id' => 22, 'weight' => 40]]);
    DB::table('warehouses')->insert([['id' => 5, 'user_id' => 20], ['id' => 6, 'user_id' => 21], ['id' => 7, 'user_id' => 22]]);
    DB::table('orders')->insert(['id' => 100, 'name' => '10731', 'client_id' => 1, 'agent_id' => 11, 'agency_id' => 20, 'warehouse_id' => 5, 'city_id' => 1,
        'status_id' => 1, 'delivery_cost' => 3]);
    // Rapi2 tiene sus 8 pedidos activos
    foreach (range(1, 8) as $i) {
        DB::table('orders')->insert(['name' => "9{$i}", 'agency_id' => 21, 'city_id' => 2, 'status_id' => 3]);
    }

    // El stock de cada almacén (StockCheck) y el reparto por % (pick) no son de esta versión
    $router = Mockery::mock(AgencyRouter::class)->makePartial();
    $router->shouldReceive('hasStockAt')->andReturnUsing(fn ($order, $agencyId) => in_array($agencyId, [20, 22], true));
    $router->shouldReceive('pick')->andReturnUsing(fn () => [User::find(22), 'ok', ['city' => 'Carabobo', 'city_id' => 2, 'todas_llenas' => false]]);
    app()->instance(AgencyRouter::class, $router);
    Notification::fake();
    Event::fake([OrderUpdated::class]);
});

$mover = fn (int $actor, int $city, ?int $agency, bool $force = false) => Order::withoutEvents(
    fn () => app(OrderRelocation::class)->move(Order::with('status', 'client')->find(100), User::find($actor), $city, $agency, $force));
$pedido = fn (array $cambios) => DB::table('orders')->where('id', 100)->update($cambios);

describe('.149: quién puede y cuándo', function () {
    it('la líder igual que el dueño en los pedidos de su grupo; las demás vendedoras solo en Sin Stock', function () {
        $order = Order::find(100);   // de Ana, del grupo de Lara
        expect(OrderRelocation::who(User::find(1), $order))->toBe('owner')
            ->and(OrderRelocation::who(User::find(10), $order))->toBe('leader')
            ->and(OrderRelocation::who(User::find(11), $order))->toBe('seller')
            ->and(OrderRelocation::who(User::find(12), $order))->toBe('none')
            ->and(OrderRelocation::who(User::find(20), $order))->toBe('none');

        // Un pedido de Beto (otro grupo): Lara no es su líder
        expect(OrderRelocation::who(User::find(10), Order::make(['agent_id' => 12])))->toBe('none');
    });

    it('hasta que la agencia se lo da a un motorizado; entregado, solo el dueño', function () {
        expect(OrderRelocation::stop('leader', 'Llamado 1'))->toBeNull()
            ->and(OrderRelocation::stop('leader', 'Asignar repartidor'))->toBeNull()
            ->and(OrderRelocation::stop('seller', 'Sin Stock'))->toBeNull()
            ->and(OrderRelocation::stop('seller', 'Llamado 1'))->toBe('Solo se puede en Sin Stock. Para los demás pedidos, pídeselo a tu líder.')
            // sin agencia todavía (lo manda a la agencia y el sistema no supo la ciudad): ponérsela no es reasignarlo
            ->and(OrderRelocation::stop('seller', 'Llamado 1', true))->toBeNull()
            ->and(OrderRelocation::stop('owner', 'En ruta'))->toBe('Ya lo tiene el motorizado de la agencia. Primero tiene que volver (Novedad o Reprogramado).')
            ->and(OrderRelocation::stop('leader', 'Asignado a repartidor'))->toStartWith('Ya lo tiene el motorizado')
            ->and(OrderRelocation::stop('owner', 'Entregado'))->toBeNull()
            ->and(OrderRelocation::stop('leader', 'Entregado'))->toBe('Ya está entregado: solo el dueño puede cambiarle la ciudad o la agencia.')
            ->and(OrderRelocation::stop('owner', 'Cancelado'))->toBe('En "Cancelado" ya no se cambia la ciudad ni la agencia.')
            ->and(OrderRelocation::stop('none', 'Llamado 1'))->toBe('No tienes permiso para cambiar la ciudad o la agencia de este pedido.');
    });

    it('las dos rutas pasan por order.access (cada quien, sus pedidos)', function () {
        foreach (['GET', 'PUT'] as $method) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array($method, $r->methods(), true) && $r->uri() === 'api/orders/{order}/logistics');
            expect($route)->not->toBeNull()->and($route->gatherMiddleware())->toContain('order.access');
        }
    });
});

describe('.149: la ventana', function () use ($pedido) {
    it('cada ciudad trae solo sus agencias, con stock, cupo y cuál es la de hoy; las ciudades sin agencias no salen', function () {
        $o = app(OrderRelocation::class)->options(Order::with('status', 'client')->find(100), User::find(10));

        // el cliente escribió Aragua y el pedido está en Aragua: no hace falta decirlo
        expect($o)->toMatchArray(['stop' => null, 'status' => 'Llamado 1', 'city_id' => 1, 'agency_id' => 20, 'client_city' => null, 'can_force' => true])
            ->and(array_column($o['cities'], 'name'))->toBe(['Aragua', 'Carabobo'])
            ->and($o['cities'][0]['agencies'])->toBe([['id' => 20, 'name' => 'Dalgo', 'stock' => true, 'active' => 0, 'max' => null, 'full' => false, 'off' => null]])
            ->and($o['cities'][1]['stock'])->toBeTrue()
            ->and($o['cities'][1]['agencies'][0])->toMatchArray(['name' => 'Rapi2', 'stock' => false, 'active' => 8, 'max' => 8, 'full' => true])
            ->and($o['cities'][1]['agencies'][1])->toMatchArray(['name' => 'Valen', 'stock' => true, 'full' => false]);
    });

    it('si no se puede, dice por qué y no trae ciudades', function () use ($pedido) {
        $pedido(['status_id' => 6]);
        $o = app(OrderRelocation::class)->options(Order::with('status', 'client')->find(100), User::find(10));

        expect($o['stop'])->toStartWith('Ya lo tiene el motorizado')->and($o['cities'])->toBe([]);
    });
});

describe('.149: cambiar', function () use ($mover, $pedido) {
    it('solo la ciudad: la agencia la elige el sistema en la ciudad nueva (antes se quedaba Dalgo, de Aragua)', function () use ($mover) {
        [$order, $message] = $mover(10, 2, null);

        expect($order->only(['city_id', 'agency_id', 'warehouse_id', 'status_id', 'agency_locked']))
            ->toBe(['city_id' => 2, 'agency_id' => 22, 'warehouse_id' => 7, 'status_id' => 1, 'agency_locked' => false])
            ->and((float) $order->delivery_cost)->toBe(4.0)
            ->and($message)->toBe('Listo: el pedido pasó a Carabobo con Valen (la eligió el sistema).')
            ->and(DB::table('order_activity_logs')->where('action', 'relocated')->value('description'))
            ->toBe('Ciudad o agencia cambiada por la líder: Carabobo con Valen (la eligió el sistema).');

        // Ahora sí: el pedido está en Carabobo y el cliente escribió Aragua
        expect(app(OrderRelocation::class)->options(Order::with('status', 'client')->find(100), User::find(10))['client_city'])->toBe('Aragua');
    });

    it('una agencia de otra ciudad no se acepta', function () use ($mover) {
        expect(fn () => $mover(1, 2, 20))->toThrow(ValidationException::class, 'Esa agencia no reparte en Carabobo. Elige una de la lista.');
        expect(Order::find(100)->agency_id)->toBe(20);
    });

    it('agencia llena: la líder la puede pasar igual si confirma; la vendedora no', function () use ($mover, $pedido) {
        try {
            $mover(10, 2, 21);
            $this->fail('debía preguntar');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['full' => ['Rapi2 está en su máximo (8 pedidos activos). ¿Se lo pasas igual?']]);
        }

        [$order, $message] = $mover(10, 2, 21, true);
        expect($order->agency_id)->toBe(21)->and($order->agency_locked)->toBeTrue()
            ->and($message)->toEndWith('Se pasó a Rapi2 aunque estaba en su máximo (8 activos).')
            ->and(json_decode(DB::table('order_activity_logs')->where('action', 'relocated')->value('properties'), true)['forced'])->toBeTrue();

        // Ana, en Sin Stock, no la puede forzar
        $pedido(['status_id' => 2, 'agency_id' => 22, 'warehouse_id' => 7, 'city_id' => 2]);
        expect(fn () => $mover(11, 2, 21, true))->toThrow(ValidationException::class, 'Rapi2 está en su máximo (8 pedidos activos). Elige otra o pídeselo a tu líder.');
    });

    it('si la agencia vieja ya lo tenía, la nueva lo recibe en "Asignar a agencia", sin el motorizado de la otra', function () use ($mover, $pedido) {
        $pedido(['status_id' => 4, 'deliverer_id' => 30]);
        [$order, $message] = $mover(10, 2, 22);

        expect($order->only(['agency_id', 'status_id', 'deliverer_id']))->toBe(['agency_id' => 22, 'status_id' => 3, 'deliverer_id' => null])
            ->and($message)->toBe('Listo: el pedido pasó a Carabobo con Valen. La agencia lo recibe en "Asignar a agencia".');
        Notification::assertSentTo(User::find(22), OrderAssignedNotification::class);
    });

    it('entregado (solo el dueño): cambia la agencia pero no toca el estado ni quién lo entregó', function () use ($mover, $pedido) {
        $pedido(['status_id' => 7, 'deliverer_id' => 30]);
        [$order] = $mover(1, 2, 22);

        expect($order->only(['agency_id', 'status_id', 'deliverer_id']))->toBe(['agency_id' => 22, 'status_id' => 7, 'deliverer_id' => 30]);
        expect(fn () => $mover(10, 1, 20))->toThrow(ValidationException::class, 'Ya está entregado');
    });

    it('si no cambia nada, lo dice', function () use ($mover) {
        expect(fn () => $mover(10, 1, 20))->toThrow(ValidationException::class, 'Ya está en Aragua con Dalgo: no cambió nada.');
    });
});
