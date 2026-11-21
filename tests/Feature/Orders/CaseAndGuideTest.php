<?php

use App\Events\WhatsappMessageReceived;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Orders\DeliveryCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .173 (dueño, 2026-11-21): "tenemos que cambiar todo el flujo de n8n dependiendo de cómo paga el cliente y cómo va a
// recibir"; el número de guía lo escribe la vendedora y n8n le avisa al cliente; la encomienda cobrada completa queda "Ya
// pagó" sola. En una base en memoria (no toca la base local); n8n y Meta son de mentira (Http::fake).

beforeEach(function () {
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description'), $t->timestamps()]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('phone')->default('');
        $t->string('address')->default('');
        $t->string('email')->unique();
        $t->string('password');
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->rememberToken();
        $t->timestamps();
    });
    Schema::create('statuses', fn ($t) => [$t->id(), $t->string('description'), $t->timestamps()]);
    Schema::create('clients', function ($t) {
        $t->id();
        foreach (['first_name', 'last_name', 'phone', 'email', 'province', 'city', 'address1', 'address2'] as $c) {
            $t->string($c)->nullable();
        }
        $t->unsignedBigInteger('customer_id')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->timestamp('last_whatsapp_received_at')->nullable();
        $t->timestamp('last_interaction_at')->nullable();
        $t->timestamps();
    });
    Schema::create('orders', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('order_number')->nullable();
        $t->string('name')->nullable();
        foreach (['client_id', 'shop_id', 'city_id', 'agency_id', 'agent_id', 'deliverer_id'] as $c) {
            $t->unsignedBigInteger($c)->nullable();
        }
        $t->unsignedBigInteger('status_id');
        $t->decimal('current_total_price', 10, 2)->default(0);
        $t->string('currency')->nullable();
        $t->text('location')->nullable();
        foreach (['cash_received', 'change_amount', 'change_amount_company', 'change_amount_agency', 'change_rate'] as $c) {
            $t->decimal($c, 10, 2)->nullable();
        }
        foreach (['change_covered_by', 'change_method_company', 'change_method_agency', 'payment_receipt', 'change_receipt'] as $c) {
            $t->string($c)->nullable();
        }
        $t->json('receipts_summary')->nullable();
        $t->boolean('courier_shipment')->default(false);
        foreach (['courier_recipient_name', 'courier_recipient_id_number', 'courier_recipient_phone', 'courier_company', 'courier_office'] as $c) {
            $t->string($c)->nullable();
        }
        $t->timestamp('prepaid_at')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_22_110000_courier_guide.php'))->up();
    Schema::create('order_products', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->timestamps()]);
    Schema::create('order_payments', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('method'), $t->decimal('amount', 10, 2),
        $t->decimal('rate', 14, 4)->nullable(), $t->string('reference')->nullable(), $t->decimal('usd_rate', 14, 4)->nullable(), $t->decimal('eur_rate', 14, 4)->nullable(),
        $t->decimal('binance_usd_rate', 14, 4)->nullable(), $t->timestamps()]);
    Schema::create('payment_receipts', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->string('path'), $t->string('file_sha256')->nullable(),
        $t->string('image_dhash')->nullable(), $t->timestamps()]);
    Schema::create('receipt_checks', fn ($t) => [$t->id(), $t->unsignedBigInteger('payment_receipt_id')->nullable(), $t->unsignedBigInteger('order_id'),
        $t->string('kind')->nullable(), $t->string('status'), $t->timestamp('reviewed_at')->nullable(), $t->timestamps()]);
    Schema::create('currencies', fn ($t) => [$t->id(), $t->string('description'), $t->decimal('value', 14, 4), $t->unsignedBigInteger('status_id')->nullable(), $t->timestamps()]);
    Schema::create('shops', fn ($t) => [$t->id(), $t->string('name')->default(''), $t->boolean('auto_schedule_enabled')->default(false), $t->timestamps()]);
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key'), $t->text('value')->nullable(), $t->timestamps()]);
    Schema::create('order_activity_logs', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action')->nullable();
        $t->text('description')->nullable();
        $t->json('properties')->nullable();
        $t->string('actor_role')->nullable();
        $t->timestamps();
    });
    Schema::create('whatsapp_messages', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('message_id')->nullable();
        $t->text('body');
        $t->boolean('is_from_client');
        $t->string('message_type')->default('outgoing_agent_message');
        $t->string('template_name', 120)->nullable();
        $t->string('status')->default('sent');
        $t->integer('error_code')->nullable();
        $t->string('error_reason', 500)->nullable();
        $t->json('media')->nullable();
        $t->timestamp('sent_at')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_19_100000_create_storefront_tables.php'))->up();
    Schema::table('storefront_orders', fn ($t) => [$t->string('payment')->nullable(), $t->string('confirm_status')->nullable(), $t->timestamp('confirm_sent_at')->nullable()]);
    // lo que se lee al mandar la orden completa (n8n y la pantalla)
    Schema::create('order_change_extras', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->json('change_payment_details')->nullable(),
        $t->string('change_receipt')->nullable(), $t->timestamps()]);
    Schema::create('sales_groups', fn ($t) => [$t->id(), $t->string('name')->default(''), $t->boolean('is_active')->default(true), $t->timestamps()]);
    Schema::create('sales_group_members', fn ($t) => [$t->id(), $t->unsignedBigInteger('sales_group_id'), $t->unsignedBigInteger('user_id'), $t->string('role'),
        $t->timestamp('ended_at')->nullable(), $t->timestamps()]);
    Schema::create('internal_conversations', fn ($t) => [$t->id(), $t->unsignedBigInteger('order_id')->nullable(), $t->timestamps()]);
    Schema::create('internal_messages', fn ($t) => [$t->id(), $t->unsignedBigInteger('conversation_id'), $t->timestamps()]);
    Schema::create('webhooks', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('url');
        $t->string('event_type')->default('order.status_changed');
        $t->unsignedBigInteger('status_id')->nullable();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });

    config(['app.external_api_key' => 'clave-de-prueba']);
    Event::fake([WhatsappMessageReceived::class]);
    Http::preventStrayRequests();
    Http::fake(['n8n.prueba/*' => Http::response(['ok' => true], 200)]);
    DB::table('webhooks')->insert([
        ['name' => 'Estados', 'url' => 'https://n8n.prueba/estados', 'event_type' => 'order.status_changed', 'status_id' => null, 'is_active' => true],
        ['name' => 'Guía', 'url' => 'https://n8n.prueba/guia', 'event_type' => 'order.courier_guide', 'status_id' => null, 'is_active' => true],
    ]);
});

function rol173(string $name): User
{
    $role = Role::forceCreate(['description' => $name]);

    return User::forceCreate(['names' => $name, 'email' => strtolower($name) . '@test', 'password' => 'x', 'role_id' => $role->id]);
}

/** Un pedido de 50 US$; devuelve su id interno y el del cliente. */
function pedido173(array $extra = [], string $status = 'Asignado a vendedor'): array
{
    $statusId = DB::table('statuses')->where('description', $status)->value('id') ?? DB::table('statuses')->insertGetId(['description' => $status]);
    $client = DB::table('clients')->insertGetId(['first_name' => 'María', 'last_name' => 'Prueba', 'phone' => '+58412' . random_int(1000000, 9999999), 'created_at' => now(), 'updated_at' => now()]);
    $order = DB::table('orders')->insertGetId($extra + ['order_id' => random_int(1, 999999999), 'order_number' => '1001', 'client_id' => $client, 'status_id' => $statusId,
        'current_total_price' => 50, 'created_at' => now()->subMinutes(5), 'updated_at' => now()]);

    return [$order, $client];
}

function ventana173($test, int $order, int $client): array
{
    return $test->withHeader('X-API-KEY', 'clave-de-prueba')->getJson("/api/external/whatsapp/check-window?internal_id={$order}&client_id={$client}")->assertOk()->json();
}

const ENCOMIENDA173 = ['courier_shipment' => true, 'courier_recipient_name' => 'María Prueba', 'courier_recipient_id_number' => 'V-12345678',
    'courier_recipient_phone' => '04121234567', 'courier_company' => 'MRW', 'courier_office' => 'MRW Barquisimeto Centro — Av. 20, Lara'];

describe('.173: el caso de cada pedido para n8n', function () {
    it('a domicilio y al recibir: como siempre (se cobra al entregar, sin encomienda, la confirmación normal)', function () {
        [$order, $client] = pedido173();
        $r = ventana173($this, $order, $client);

        expect([$r['order']['case'], $r['order']['delivery'], $r['order']['paid'], $r['order']['collect_on_delivery'], $r['order']['courier']])
            ->toBe(['domicilio_al_recibir', 'domicilio', false, true, null])
            ->and($r['template_tracking']['confirmation_sent_at'])->toBeNull();
    });

    it('a domicilio y ya pagó: no se le cobra y no recibe la confirmación de "pago al recibir"', function () {
        [$order, $client] = pedido173(['prepaid_at' => now()->subMinute()]);
        $r = ventana173($this, $order, $client);

        expect([$r['order']['case'], $r['order']['paid'], $r['order']['collect_on_delivery']])->toBe(['domicilio_pagado', true, false])
            ->and($r['template_tracking']['confirmation_sent_at'])->not->toBeNull()
            ->and($r['template_tracking']['should_send_next_template'])->toBeFalse();
    });

    it('encomienda por pagar (con la asesora): la atiende la vendedora; n8n no le manda "Confirma tu pedido… Pago al recibir"', function () {
        [$order, $client] = pedido173(ENCOMIENDA173);
        $r = ventana173($this, $order, $client);

        expect([$r['order']['case'], $r['order']['delivery'], $r['order']['paid'], $r['order']['collect_on_delivery']])->toBe(['encomienda_por_pagar', 'encomienda', false, false])
            ->and($r['order']['courier'])->toBe(['company' => 'MRW', 'office' => 'MRW Barquisimeto Centro — Av. 20, Lara', 'recipient_name' => 'María Prueba',
                'recipient_id_number' => 'V-12345678', 'guide' => null, 'guide_at' => null])
            ->and($r['template_tracking']['confirmation_sent_at'])->toBe(Order::find($order)->created_at->toDateTimeString())
            ->and($r['template_tracking']['should_send_next_template'])->toBeFalse();
    });

    it('encomienda pagada, con su guía', function () {
        [$order, $client] = pedido173(ENCOMIENDA173 + ['prepaid_at' => now(), 'courier_guide' => '123456789', 'courier_guide_at' => '2026-11-22 10:00:00']);
        $r = ventana173($this, $order, $client);

        expect($r['order']['case'])->toBe('encomienda_pagada')
            ->and([$r['order']['courier']['guide'], $r['order']['courier']['guide_at']])->toBe(['123456789', '2026-11-22 10:00:00']);
    });

    it('una encomienda que ya recibió la confirmación (la vendedora la cambió después) sigue con su ancla', function () {
        [$order, $client] = pedido173(ENCOMIENDA173);
        WhatsappMessage::create(['client_id' => $client, 'order_id' => $order, 'body' => 'Plantilla: confirmacion_pedido_nuevo_competencia', 'template_name' => 'confirmacion_pedido_nuevo_competencia',
            'is_from_client' => false, 'message_type' => WhatsappMessage::TYPE_AUTOMATED, 'sent_at' => '2026-11-21 09:00:00']);

        expect(ventana173($this, $order, $client)['template_tracking'])->toMatchArray(['confirmation_sent_at' => '2026-11-21 09:00:00', 'should_send_next_template' => false]);
    });
});

describe('.173: el número de guía', function () {
    it('la vendedora lo escribe (también con el pedido Entregado); queda en el pedido, en el historial y n8n se entera', function () {
        $seller = rol173('Vendedor');
        [$order] = pedido173(ENCOMIENDA173 + ['agent_id' => $seller->id], 'Entregado');
        Sanctum::actingAs($seller);

        $r = $this->putJson("/api/orders/{$order}/courier-guide", ['courier_guide' => '  1234 5678-ab '])->assertOk();

        $o = Order::find($order);
        expect($r->json('message'))->toBe('Número de guía guardado.')
            ->and([$o->courier_guide, $o->courier_guide_at !== null])->toBe(['1234 5678-AB', true])
            ->and(DB::table('order_activity_logs')->where('action', 'courier_guide')->value('description'))->toBe('Escribió el número de guía de la encomienda: 1234 5678-AB');
        Http::assertSent(fn ($req) => $req->url() === 'https://n8n.prueba/guia' && $req['event'] === 'order.courier_guide' && $req['data']['courier_guide'] === '1234 5678-AB');
        // el aviso de cambio de estado no sale (n8n no repite el mensaje de "Entregado")
        Http::assertNotSent(fn ($req) => $req->url() === 'https://n8n.prueba/estados');
    });

    it('corregirla avisa otra vez; la misma no; borrarla no avisa', function () {
        Sanctum::actingAs(rol173('Admin'));
        [$order] = pedido173(ENCOMIENDA173 + ['courier_guide' => '111111']);

        $this->putJson("/api/orders/{$order}/courier-guide", ['courier_guide' => '111111'])->assertOk()->assertJson(['message' => 'Ese número de guía ya estaba guardado.']);
        Http::assertSentCount(0);
        $this->putJson("/api/orders/{$order}/courier-guide", ['courier_guide' => '222222'])->assertOk();
        Http::assertSentCount(1);
        $this->putJson("/api/orders/{$order}/courier-guide", ['courier_guide' => ''])->assertOk()->assertJson(['message' => 'Número de guía borrado.']);

        expect(Http::recorded())->toHaveCount(1)
            ->and(Order::find($order)->only(['courier_guide', 'courier_guide_at']))->toBe(['courier_guide' => null, 'courier_guide_at' => null])
            ->and(DB::table('order_activity_logs')->where('action', 'courier_guide')->pluck('description')->all())
            ->toBe(['Corrigió el número de guía de la encomienda: 222222 (antes 111111)', 'Borró el número de guía de la encomienda (era 222222)']);
    });

    it('solo en encomiendas, con letras y números, y solo la vendedora, su líder o administración', function () {
        Sanctum::actingAs(rol173('Admin'));
        [$home] = pedido173();
        [$courier] = pedido173(ENCOMIENDA173);

        $this->putJson("/api/orders/{$home}/courier-guide", ['courier_guide' => '123456'])->assertStatus(422)->assertJson(['message' => 'Esta orden no es una encomienda.']);
        $this->putJson("/api/orders/{$courier}/courier-guide", ['courier_guide' => '12'])->assertStatus(422)->assertJsonValidationErrors('courier_guide');
        $this->putJson("/api/orders/{$courier}/courier-guide", ['courier_guide' => 'guía #1?'])->assertStatus(422);
        Sanctum::actingAs(rol173('Agencia'));
        DB::table('orders')->where('id', $courier)->update(['agency_id' => auth()->id()]);
        $this->putJson("/api/orders/{$courier}/courier-guide", ['courier_guide' => '123456'])->assertForbidden();

        expect(Order::find($courier)->courier_guide)->toBeNull();
        Http::assertNothingSent();
    });
});

describe('.173: la encomienda cobrada completa queda «Ya pagó» sola', function () {
    function pagar173($test, int $order, float $amount)
    {
        return $test->putJson("/api/orders/{$order}/payment", ['payments' => [['method' => 'PAGOMOVIL', 'amount' => $amount, 'rate' => 300]]]);
    }

    it('con el total: «Ya pagó» (la agencia no cobra); si los pagos bajan, se le quita', function () {
        Sanctum::actingAs(rol173('Admin'));
        [$order] = pedido173(ENCOMIENDA173);

        expect(pagar173($this, $order, 30)->assertOk()->json('message'))->toBeNull()
            ->and(Order::find($order)->prepaid_at)->toBeNull();
        expect(pagar173($this, $order, 50)->assertOk()->json('message'))->toBe('Pagos guardados. La encomienda quedó «✅ Ya pagó»: la agencia no cobra.')
            ->and(Order::find($order)->prepaid_at)->not->toBeNull();
        // la vendedora y la agencia ven "Ya pagó" con el pago registrado (no "revisando el comprobante" del formulario)
        expect(App\Services\Storefront\PrepaidState::of(Order::find($order)))->toMatchArray(['state' => 'registered', 'source' => 'seller']);
        expect(pagar173($this, $order, 20)->assertOk()->json('message'))->toBe('Pagos guardados. Ya no cubren el total: la encomienda dejó de estar «Ya pagó».')
            ->and(Order::find($order)->prepaid_at)->toBeNull()
            ->and(DB::table('order_activity_logs')->where('action', 'courier_prepaid')->count())->toBe(2);
    });

    it('a domicilio no cambia nada (lo cobra la agencia al entregar); el pago del formulario nunca se quita', function () {
        Sanctum::actingAs(rol173('Admin'));
        [$home] = pedido173();
        pagar173($this, $home, 50)->assertOk();
        [$form] = pedido173(ENCOMIENDA173 + ['prepaid_at' => '2026-11-21 10:00:00']);
        DB::statement('PRAGMA foreign_keys = OFF'); // el pedido del formulario sin su producto ni su ciudad
        DB::table('storefront_orders')->insert(['order_id' => $form, 'product_id' => 1, 'city_id' => 1, 'phone_last10' => '4121234567', 'total_usd' => 50,
            'event_id' => 'nuv-web-1', 'payment' => 'pago_movil', 'created_at' => now(), 'updated_at' => now()]);
        pagar173($this, $form, 10)->assertOk();

        expect(Order::find($home)->prepaid_at)->toBeNull()
            ->and(Order::find($form)->prepaid_at->toDateTimeString())->toBe('2026-11-21 10:00:00');
    });
});
