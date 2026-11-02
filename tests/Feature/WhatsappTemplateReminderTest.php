<?php

use App\Events\WhatsappMessageReceived;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// Dueño, 2026-11-02: los recordatorios de "no respondió la confirmación" no salieron en un mes porque la plantilla de
// confirmación estaba importada en el sistema (se guardaba su texto y check-window no la reconocía), y el botón de
// borrar plantillas no borraba nada. En una base en memoria (no toca la base local) y sin hablar con Meta (Http::fake).

beforeEach(function () {
    Schema::create('roles', function ($t) {
        $t->id();
        $t->string('description');
        $t->timestamps();
    });
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('phone')->default('');
        $t->string('address')->default('');
        $t->string('email')->unique();
        $t->string('password');
        $t->rememberToken();
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('whatsapp_templates', function ($t) {
        $t->id();
        $t->string('name')->unique();
        $t->string('label');
        $t->text('body');
        $t->string('language')->nullable();
        $t->boolean('is_official')->default(false);
        $t->boolean('visible_to_sellers')->default(true);
        $t->json('meta_components')->nullable();
        $t->timestamps();
    });
    Schema::create('statuses', function ($t) {
        $t->id();
        $t->string('description');
        $t->timestamps();
    });
    Schema::create('clients', function ($t) {
        $t->id();
        $t->unsignedBigInteger('customer_id')->nullable();
        $t->string('customer_number')->nullable();
        $t->string('first_name')->nullable();
        $t->string('last_name')->nullable();
        $t->string('phone')->nullable();
        $t->string('email')->nullable();
        $t->string('province')->nullable();
        $t->string('city')->nullable();
        $t->string('address1')->nullable();
        $t->string('address2')->nullable();
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
        $t->unsignedBigInteger('client_id');
        $t->unsignedBigInteger('shop_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
        $t->unsignedBigInteger('agency_id')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->unsignedBigInteger('status_id');
        $t->decimal('current_total_price', 10, 2)->default(0);
        $t->string('currency')->nullable();
        $t->timestamps();
    });
    Schema::create('order_products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->timestamps();
    });
    Schema::create('shops', function ($t) {
        $t->id();
        $t->string('name')->default('');
        $t->boolean('auto_schedule_enabled')->default(false);
        $t->timestamps();
    });
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
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key');
        $t->text('value')->nullable();
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

    config(['app.external_api_key' => 'clave-de-prueba']);
    Event::fake([WhatsappMessageReceived::class]);
    // Meta acepta todo lo que se manda (no sale nada de esta PC)
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);
});

function rol(string $name): User
{
    $role = Role::forceCreate(['description' => $name]);

    return User::forceCreate(['names' => $name, 'email' => strtolower($name) . '@test', 'password' => 'x', 'role_id' => $role->id]);
}

function plantillaImportada(): int
{
    return DB::table('whatsapp_templates')->insertGetId([
        'name' => 'confirmacion_pedido_nuevo_competencia', 'label' => 'Confirmacion nueva', 'body' => 'Hola {{1}} ¡Gracias por tu compra!',
        'language' => 'es', 'is_official' => true, 'visible_to_sellers' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Un pedido "Asignado a vendedor" de un cliente; devuelve [order_id de Shopify, id interno, client_id]. */
function pedido(string $tel, int $shopifyId): array
{
    $status = DB::table('statuses')->insertGetId(['description' => 'Asignado a vendedor']);
    $client = DB::table('clients')->insertGetId(['first_name' => 'Ana', 'last_name' => 'Prueba', 'phone' => $tel, 'created_at' => now(), 'updated_at' => now()]);
    $order = DB::table('orders')->insertGetId(['order_id' => $shopifyId, 'order_number' => '1001', 'client_id' => $client, 'status_id' => $status, 'current_total_price' => 50, 'created_at' => now(), 'updated_at' => now()]);

    return [$shopifyId, $order, $client];
}

function enviarPlantilla($test, string $tel, int $shopifyId, string $plantilla)
{
    return $test->withHeader('X-API-KEY', 'clave-de-prueba')->postJson('/api/external/whatsapp/send', [
        'phone' => $tel, 'order_id' => $shopifyId,
        'template' => ['name' => $plantilla, 'language' => ['code' => 'es'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]]]],
    ]);
}

function consultar($test, int $orderId, int $clientId): array
{
    return $test->withHeader('X-API-KEY', 'clave-de-prueba')
        ->getJson("/api/external/whatsapp/check-window?internal_id={$orderId}&client_id={$clientId}")
        ->assertOk()->json('template_tracking');
}

describe('Borrar plantillas', function () {
    it('el Admin la borra de verdad', function () {
        Sanctum::actingAs(rol('Admin'));
        $id = plantillaImportada();

        $this->deleteJson("/api/whatsapp-templates/{$id}")->assertOk();

        expect(DB::table('whatsapp_templates')->count())->toBe(0);
    });

    it('una que no existe responde que no la encontró (antes decía "eliminada")', function () {
        Sanctum::actingAs(rol('Admin'));

        $this->deleteJson('/api/whatsapp-templates/999')->assertNotFound();
    });

    it('una vendedora no puede crear, editar ni borrar; sí verlas', function () {
        $id = plantillaImportada();
        DB::table('whatsapp_templates')->where('id', $id)->update(['visible_to_sellers' => true]);
        Sanctum::actingAs(rol('Vendedor'));

        $this->deleteJson("/api/whatsapp-templates/{$id}")->assertForbidden();
        $this->putJson("/api/whatsapp-templates/{$id}", ['label' => 'Otra'])->assertForbidden();
        $this->postJson('/api/whatsapp-templates', ['name' => 'x', 'label' => 'x', 'body' => 'x'])->assertForbidden();
        $this->getJson('/api/whatsapp-templates')->assertOk()->assertJsonCount(1);
        expect(DB::table('whatsapp_templates')->count())->toBe(1);
    });
});

describe('Recordatorios de la confirmación', function () {
    it('sin la plantilla importada: guarda su nombre y toca recordar', function () {
        [$shopify, $order, $client] = pedido('+584120000601', 9200000001);

        enviarPlantilla($this, '584120000601', $shopify, 'confirmacion_pedido_nuevo_competencia')->assertCreated();

        $msg = WhatsappMessage::where('client_id', $client)->first();
        expect($msg->template_name)->toBe('confirmacion_pedido_nuevo_competencia')
            ->and($msg->body)->toBe('Plantilla: confirmacion_pedido_nuevo_competencia');
        $t = consultar($this, $order, $client);
        expect($t['confirmation_sent_at'])->not->toBeNull()->and($t['should_send_next_template'])->toBeTrue();
    });

    it('CON la plantilla importada: guarda el texto completo y aun así toca recordar (era el error)', function () {
        plantillaImportada();
        [$shopify, $order, $client] = pedido('+584120000602', 9200000002);

        enviarPlantilla($this, '584120000602', $shopify, 'confirmacion_pedido_nuevo_competencia')->assertCreated();

        $msg = WhatsappMessage::where('client_id', $client)->first();
        // Importada, se guarda su texto (como en el sistema real hoy) y no "Plantilla: …"
        expect($msg->body)->toStartWith('Hola {{1}}')->and($msg->template_name)->toBe('confirmacion_pedido_nuevo_competencia');
        $t = consultar($this, $order, $client);
        expect($t['confirmation_sent_at'])->not->toBeNull()->and($t['should_send_next_template'])->toBeTrue();
    });

    it('si el cliente responde o la vendedora le escribe, ya no toca (igual que antes)', function () {
        plantillaImportada();
        [$shopify, $order, $client] = pedido('+584120000603', 9200000003);
        enviarPlantilla($this, '584120000603', $shopify, 'confirmacion_pedido_nuevo_competencia')->assertCreated();

        WhatsappMessage::create(['client_id' => $client, 'order_id' => $order, 'body' => 'Sí, confirmar pedido', 'is_from_client' => true,
            'message_type' => WhatsappMessage::TYPE_INCOMING, 'sent_at' => now()->addSecond()]);
        expect(consultar($this, $order, $client)['should_send_next_template'])->toBeFalse();
    });

    it('un recordatorio no cuenta como confirmación (no mueve el ancla)', function () {
        [$shopify, $order, $client] = pedido('+584120000604', 9200000004);

        enviarPlantilla($this, '584120000604', $shopify, 'no_respondio_confirmacion_en_1_hora_2')->assertCreated();

        $t = consultar($this, $order, $client);
        expect($t['confirmation_sent_at'])->toBeNull()->and($t['should_send_next_template'])->toBeFalse();
    });

    it('las confirmaciones viejas (solo con el texto "Plantilla: …") se siguen reconociendo', function () {
        [, $order, $client] = pedido('+584120000605', 9200000005);
        WhatsappMessage::create(['client_id' => $client, 'order_id' => $order, 'body' => 'Plantilla: confirmacion_pedido_nuevo_competencia',
            'is_from_client' => false, 'message_type' => WhatsappMessage::TYPE_AUTOMATED, 'sent_at' => now()->subHour()]);

        expect(consultar($this, $order, $client)['should_send_next_template'])->toBeTrue();
    });
});
