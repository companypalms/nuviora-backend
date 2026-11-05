<?php

use App\Events\WhatsappMessageReceived;
use App\Models\DeliveryPdfSend;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DeliveryPdfSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// `.106` (dueño, 2026-11-05): el PDF del producto al cliente 10 min después de Entregado, solo con la ventana de 24 h
// abierta y una vez por cliente y producto. En una base en memoria y sin hablar con Meta (Http::fake).

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
    Schema::create('statuses', function ($t) {
        $t->id();
        $t->string('description');
        $t->timestamps();
    });
    Schema::create('clients', function ($t) {
        $t->id();
        $t->string('first_name')->nullable();
        $t->string('last_name')->nullable();
        $t->string('phone')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->timestamp('last_whatsapp_received_at')->nullable();
        $t->timestamp('last_interaction_at')->nullable();
        $t->timestamps();
    });
    Schema::create('products', function ($t) {
        $t->id();
        $t->string('title')->nullable();
        $t->string('name')->nullable();
        $t->string('showable_name')->nullable();
        $t->decimal('price', 10, 2)->default(0);
        $t->string('delivery_pdf_path')->nullable();
        $t->string('delivery_pdf_name')->nullable();
        $t->text('delivery_pdf_caption')->nullable();
        $t->timestamp('delivery_pdf_since')->nullable();
        $t->timestamps();
    });
    Schema::create('orders', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->unsignedBigInteger('status_id');
        $t->timestamp('delivered_at')->nullable();
        $t->timestamps();
    });
    Schema::create('order_products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('product_id')->nullable();
        $t->integer('quantity')->default(1);
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
    Schema::create('delivery_pdf_sends', function ($t) {
        $t->id();
        $t->unsignedBigInteger('client_id');
        $t->unsignedBigInteger('product_id');
        $t->unsignedBigInteger('order_id')->nullable();
        $t->string('status', 10);
        $t->unsignedTinyInteger('attempts')->default(0);
        $t->string('error', 500)->nullable();
        $t->timestamp('last_attempt_at')->nullable();
        $t->timestamp('sent_at')->nullable();
        $t->timestamps();
        $t->unique(['client_id', 'product_id']);
    });

    Carbon::setTestNow('2026-11-05 15:00:00');
    Storage::fake('public');
    Event::fake([WhatsappMessageReceived::class]);
    entregaPdfMetaResponde(true);
    entregaPdfFakeMeta();

    $this->entregado = DB::table('statuses')->insertGetId(['description' => 'Entregado']);
    $this->enRuta = DB::table('statuses')->insertGetId(['description' => 'En ruta']);
    Storage::disk('public')->put('products/pdfs/guia.pdf', '%PDF-1.4 guia');
    $this->producto = DB::table('products')->insertGetId([
        'title' => 'Comprimax', 'showable_name' => 'Comprimax - Chaleco termico',
        'delivery_pdf_path' => 'products/pdfs/guia.pdf', 'delivery_pdf_name' => 'Guia Comprimax.pdf',
        'delivery_pdf_since' => '2026-11-01 00:00:00',
    ]);
    $this->sinPdf = DB::table('products')->insertGetId(['title' => 'FlexiStep', 'showable_name' => 'FlexiStep']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Meta: la subida del PDF devuelve un id y el envío un wamid; o todo falla. Http::fake se registra una sola vez (el
 * primer fake que responde gana), así que se cambia con una bandera.
 */
function entregaPdfMetaResponde(bool $ok): void
{
    $GLOBALS['entregaPdfMetaOk'] = $ok;
}

function entregaPdfFakeMeta(): void
{
    Http::fake(['graph.facebook.com/*' => fn ($request) => !($GLOBALS['entregaPdfMetaOk'] ?? true)
        ? Http::response(['error' => ['code' => 131000, 'message' => 'Something went wrong']], 400)
        : (str_ends_with($request->url(), '/media')
            ? Http::response(['id' => 'media-1'], 200)
            : Http::response(['messages' => [['id' => 'wamid.PDF']]], 200))]);
}

function entregaPdfRol(string $name): User
{
    $role = Role::forceCreate(['description' => $name]);

    return User::forceCreate(['names' => $name, 'email' => strtolower($name) . '@test', 'password' => 'x', 'role_id' => $role->id]);
}

/** Un cliente (ventana abierta si escribió hace $escribioHace minutos) y su pedido entregado hace $entregadoHace minutos. */
function entregaPdfPedido($test, int $entregadoHace, ?int $escribioHace = 60, array $productos = null, ?int $cliente = null, ?int $estado = null): int
{
    $cliente ??= DB::table('clients')->insertGetId([
        'first_name' => 'Maria Jose', 'phone' => '584121112233',
        'last_whatsapp_received_at' => $escribioHace === null ? null : now()->subMinutes($escribioHace),
    ]);
    $orden = DB::table('orders')->insertGetId([
        'client_id' => $cliente, 'status_id' => $estado ?? $test->entregado,
        'delivered_at' => now()->subMinutes($entregadoHace),
    ]);
    foreach ($productos ?? [$test->producto] as $p) {
        DB::table('order_products')->insert(['order_id' => $orden, 'product_id' => $p]);
    }

    return $orden;
}

function entregaPdfEnvios(): \Illuminate\Support\Collection
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/messages'))->values();
}

function entregaPdfCorrer(): array
{
    return app(DeliveryPdfSender::class)->run();
}

describe('Cuándo sale el PDF', function () {
    it('entregado hace 11 minutos con la ventana abierta: sale el documento con su texto y su nombre', function () {
        $orden = entregaPdfPedido($this, 11);

        $r = entregaPdfCorrer();

        expect($r['sent'])->toBe(1);
        $envio = entregaPdfEnvios()->first();
        expect($envio['type'])->toBe('document')
            ->and($envio['document']['id'])->toBe('media-1')
            ->and($envio['document']['filename'])->toBe('Guia Comprimax.pdf')
            ->and($envio['document']['caption'])->toStartWith('¡Hola Maria! 💕')
            ->and($envio['document']['caption'])->toContain('la guía de tu Comprimax - Chaleco termico');
        $msg = WhatsappMessage::first();
        expect($msg->order_id)->toBe($orden)
            ->and($msg->status)->toBe('sent')
            ->and($msg->message_type)->toBe(WhatsappMessage::TYPE_AUTOMATED)
            ->and($msg->media['type'])->toBe('document');
        $send = DeliveryPdfSend::first();
        expect($send->status)->toBe('sent')->and($send->order_id)->toBe($orden);
        Event::assertDispatched(WhatsappMessageReceived::class);
    });

    it('entregado hace 5 minutos: todavía no', function () {
        entregaPdfPedido($this, 5);

        expect(entregaPdfCorrer()['sent'])->toBe(0);
        expect(entregaPdfEnvios())->toHaveCount(0);
    });

    it('la orden ya no está en Entregado (la corrigieron): no sale', function () {
        entregaPdfPedido($this, 11, 60, null, null, $this->enRuta);

        expect(entregaPdfCorrer()['sent'])->toBe(0);
    });

    it('un producto sin PDF no manda nada', function () {
        entregaPdfPedido($this, 11, 60, [$this->sinPdf]);

        expect(entregaPdfCorrer()['sent'])->toBe(0);
        expect(Http::recorded())->toHaveCount(0);
    });

    it('entregado antes de subir el PDF: a los clientes viejos no se les manda', function () {
        DB::table('products')->where('id', $this->producto)->update(['delivery_pdf_since' => now()->subMinutes(5)]);
        entregaPdfPedido($this, 11);

        expect(entregaPdfCorrer()['sent'])->toBe(0);
    });

    it('dos productos con PDF en el mismo pedido: salen 2 mensajes', function () {
        Storage::disk('public')->put('products/pdfs/otra.pdf', '%PDF-1.4 otra');
        DB::table('products')->where('id', $this->sinPdf)->update([
            'delivery_pdf_path' => 'products/pdfs/otra.pdf', 'delivery_pdf_name' => 'Guia FlexiStep.pdf', 'delivery_pdf_since' => '2026-11-01 00:00:00',
        ]);
        entregaPdfPedido($this, 11, 60, [$this->producto, $this->sinPdf]);

        expect(entregaPdfCorrer()['sent'])->toBe(2);
        expect(entregaPdfEnvios()->pluck('document.filename')->sort()->values()->all())->toBe(['Guia Comprimax.pdf', 'Guia FlexiStep.pdf']);
    });

    it('el texto del producto se usa si lo cambiaron, con su nombre y el producto', function () {
        DB::table('products')->where('id', $this->producto)->update(['delivery_pdf_caption' => 'Hola {nombre}, tu guía de {producto} 📘']);
        entregaPdfPedido($this, 11);

        entregaPdfCorrer();

        expect(entregaPdfEnvios()->first()['document']['caption'])->toBe('Hola Maria, tu guía de Comprimax - Chaleco termico 📘');
    });
});

describe('La ventana de 24 horas', function () {
    it('cerrada: no sale y espera sin gastar intentos', function () {
        entregaPdfPedido($this, 11, 60 * 30);

        $r = entregaPdfCorrer();

        expect($r['sent'])->toBe(0)->and($r['waiting'])->toBe(1);
        expect(entregaPdfEnvios())->toHaveCount(0);
        expect(DeliveryPdfSend::count())->toBe(0);
    });

    it('si el cliente escribe 3 horas después de la entrega, sale en ese momento', function () {
        $orden = entregaPdfPedido($this, 11, null);
        expect(entregaPdfCorrer()['waiting'])->toBe(1);

        Carbon::setTestNow(now()->addHours(3));
        DB::table('clients')->update(['last_whatsapp_received_at' => now()->subMinute()]);

        expect(entregaPdfCorrer()['sent'])->toBe(1);
        expect(DeliveryPdfSend::first()->order_id)->toBe($orden);
    });

    it('pasaron 24 horas desde la entrega sin que escriba: ya no sale', function () {
        entregaPdfPedido($this, 11, null);
        Carbon::setTestNow(now()->addHours(24));
        DB::table('clients')->update(['last_whatsapp_received_at' => now()->subMinute()]);

        expect(entregaPdfCorrer()['sent'])->toBe(0);
    });
});

describe('Una vez por cliente y producto', function () {
    it('el cliente que ya lo recibió en otro pedido no lo recibe de nuevo', function () {
        $primera = entregaPdfPedido($this, 60 * 5);
        entregaPdfCorrer();
        $cliente = DB::table('orders')->where('id', $primera)->value('client_id');
        entregaPdfPedido($this, 11, 60, null, $cliente);

        expect(entregaPdfCorrer()['sent'])->toBe(0);
        expect(entregaPdfEnvios())->toHaveCount(1);
    });

    it('volver a correr el minuto siguiente no lo repite', function () {
        entregaPdfPedido($this, 11);
        entregaPdfCorrer();
        Carbon::setTestNow(now()->addMinute());

        expect(entregaPdfCorrer()['sent'])->toBe(0);
        expect(entregaPdfEnvios())->toHaveCount(1);
    });
});

describe('Si Meta falla', function () {
    it('queda anotado, se reintenta a los 10 minutos y se rinde a los 3 intentos', function () {
        entregaPdfMetaResponde(false);
        entregaPdfPedido($this, 11);

        expect(entregaPdfCorrer()['failed'])->toBe(1);
        expect(WhatsappMessage::first()->status)->toBe('failed');
        Carbon::setTestNow(now()->addMinutes(5));
        expect(entregaPdfCorrer()['failed'])->toBe(0); // todavía no pasan 10 minutos
        Carbon::setTestNow(now()->addMinutes(5));
        expect(entregaPdfCorrer()['failed'])->toBe(1);
        Carbon::setTestNow(now()->addMinutes(10));
        expect(entregaPdfCorrer()['failed'])->toBe(1);
        Carbon::setTestNow(now()->addMinutes(10));
        expect(entregaPdfCorrer()['failed'])->toBe(0);
        expect(DeliveryPdfSend::first()->attempts)->toBe(3);
    });

    it('si en el reintento sale, queda como enviado', function () {
        entregaPdfMetaResponde(false);
        entregaPdfPedido($this, 11);
        entregaPdfCorrer();
        entregaPdfMetaResponde(true);
        Carbon::setTestNow(now()->addMinutes(10));

        expect(entregaPdfCorrer()['sent'])->toBe(1);
        expect(DeliveryPdfSend::first()->status)->toBe('sent');
    });

    it('si el PDF ya no está en el servidor, no se manda y se anota el motivo', function () {
        Storage::disk('public')->delete('products/pdfs/guia.pdf');
        entregaPdfPedido($this, 11);

        expect(entregaPdfCorrer()['failed'])->toBe(1);
        expect(DeliveryPdfSend::first()->error)->toContain('vuelve a subirlo');
        expect(entregaPdfEnvios())->toHaveCount(0);
    });
});

describe('Solo lectura (--dry-run)', function () {
    it('dice qué mandaría y quién espera, sin mandar nada', function () {
        entregaPdfPedido($this, 11);
        entregaPdfPedido($this, 11, null);

        $r = app(DeliveryPdfSender::class)->run(true);

        expect($r['sent'])->toBe(0)->and($r['waiting'])->toBe(1)->and($r['items'])->toHaveCount(2);
        expect(Http::recorded())->toHaveCount(0);
        expect(WhatsappMessage::count())->toBe(0)->and(DeliveryPdfSend::count())->toBe(0);
    });
});

describe('Subir el PDF en el producto', function () {
    it('Admin sube un PDF: queda con su nombre y desde ahora', function () {
        Sanctum::actingAs(entregaPdfRol('Admin'));
        DB::table('products')->where('id', $this->sinPdf)->update(['delivery_pdf_since' => null]);

        $res = $this->post("/api/products/{$this->sinPdf}/delivery-pdf", ['file' => UploadedFile::fake()->create('Guía FlexiStep.pdf', 100, 'application/pdf')]);

        $res->assertOk()->assertJsonPath('name', 'Guía FlexiStep.pdf')->assertJsonPath('since', '2026-11-05 15:00:00');
        Storage::disk('public')->assertExists(DB::table('products')->where('id', $this->sinPdf)->value('delivery_pdf_path'));
    });

    it('no acepta lo que no es PDF', function () {
        Sanctum::actingAs(entregaPdfRol('Admin'));

        $this->postJson("/api/products/{$this->sinPdf}/delivery-pdf", ['file' => UploadedFile::fake()->image('foto.jpg')])->assertStatus(422);
    });

    it('una vendedora no puede subirlo', function () {
        Sanctum::actingAs(entregaPdfRol('Vendedor'));

        $this->postJson("/api/products/{$this->sinPdf}/delivery-pdf", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertStatus(403);
    });

    it('guardar el texto y quitar el PDF (quitarlo apaga el envío)', function () {
        Sanctum::actingAs(entregaPdfRol('Master'));

        $this->putJson("/api/products/{$this->producto}/delivery-pdf", ['caption' => 'Hola {nombre}'])->assertOk()->assertJsonPath('caption', 'Hola {nombre}');
        $this->deleteJson("/api/products/{$this->producto}/delivery-pdf")->assertOk()->assertJsonPath('url', null);
        Storage::disk('public')->assertMissing('products/pdfs/guia.pdf');

        entregaPdfPedido($this, 11);
        expect(entregaPdfCorrer()['sent'])->toBe(0);
    });
});

it('sendMedia sin nombre de archivo manda lo mismo que antes', function () {
    app(\App\Services\WhatsAppService::class)->sendMedia('584121112233', 'media-9', 'document', 'hola');

    $body = entregaPdfEnvios()->first()->data();
    expect($body['document'])->toBe(['id' => 'media-9', 'caption' => 'hola']);
});
