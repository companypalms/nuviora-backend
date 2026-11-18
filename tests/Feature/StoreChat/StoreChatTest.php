<?php

use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StoreChatConversation;
use App\Models\StoreChatMessage;
use App\Models\User;
use App\Services\StoreChat\StoreChat;
use App\Services\StoreChat\StoreChatRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

// .158 (dueño, 2026-11-18): el chat con IA de la tienda. Responde solo con la ficha, el precio solo en bolívares (tasa
// Binance de hoy o ayer), la revisión para lo que no está en los datos y la página del dueño para probarlo.
// En una base en memoria; OpenAI es de mentira (Http::fake).

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
        $t->timestamps();
    });
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->text('value')->nullable(), $t->timestamps()]);
    Schema::create('products', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('name')->nullable();
        $t->string('showable_name')->nullable();
        $t->decimal('price', 10, 2)->default(0);
        $t->timestamps();
    });
    Schema::create('product_profiles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        foreach (['what_it_is', 'benefits', 'mechanism', 'not_this', 'key_info'] as $k) {
            $t->text($k)->nullable();
        }
        $t->timestamps();
    });
    Schema::create('cities', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('product_city_availability', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedBigInteger('city_id');
        $t->boolean('is_available')->default(true);
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_18_150000_create_store_chat_tables.php'))->up();

    DB::table('roles')->insert([['id' => 1, 'description' => 'Admin'], ['id' => 2, 'description' => 'Gerente']]);
    $this->owner = User::forceCreate(['names' => 'Dueño', 'email' => 'd@test', 'password' => 'x', 'role_id' => 1]);
    $this->manager = User::forceCreate(['names' => 'Gerente', 'email' => 'g@test', 'password' => 'x', 'role_id' => 2]);
    DB::table('products')->insert([
        ['id' => 1, 'title' => 'Solo Brillo', 'showable_name' => 'Solo Brillo®', 'price' => 30],
        ['id' => 2, 'title' => 'Sin ficha', 'showable_name' => null, 'price' => 20],
    ]);
    DB::table('product_profiles')->insert(['product_id' => 1, 'what_it_is' => 'Limpiador en crema para manchas difíciles',
        'benefits' => 'Quita el sarro y la grasa', 'mechanism' => 'Despega la mancha sin rayar', 'not_this' => 'No es para la ropa',
        'key_info' => 'Frasco de 500 g']);
    DB::table('cities')->insert([['id' => 1, 'name' => 'Caracas'], ['id' => 2, 'name' => 'Valencia'], ['id' => 3, 'name' => 'Maracay']]);
    DB::table('product_city_availability')->insert(['product_id' => 1, 'city_id' => 3, 'is_available' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    Setting::set('rate_binance_usd', 520);
    Setting::set('rate_updated_at', '2026-11-18 08:00:00');
    config(['services.openai.key' => 'llave-de-prueba']);

    $this->reply = fn (string $text, bool $knows = true, bool $ready = false) => Http::response([
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['respuesta' => $text, 'sabe' => $knows, 'listo' => $ready])]]]],
        'usage' => ['input_tokens' => 900, 'output_tokens' => 40],
    ]);
    $this->ask = fn (string $q, string $source = 'test', string $visitor = 'visitante-1') => app(StoreChat::class)
        ->ask(Product::find(1), $visitor, $q, $source, '1.2.3.4', CarbonImmutable::now());
});

describe('Lo que recibe la IA', function () {
    it('solo la ficha, el precio de hoy en bolívares y las ciudades; sin herramientas y sin guardar nada', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('Hoy cuesta 15.600 Bs y pagas al recibir.')]);

        $r = ($this->ask)('¿Cuánto cuesta?');

        Http::assertSent(function ($request) {
            $body = $request->data();
            expect($body)->not->toHaveKey('tools')
                ->and($body['store'])->toBeFalse()
                ->and($body['text']['format']['schema'])->toBe(StoreChatRules::SCHEMA)
                ->and($body['instructions'])->toContain('Precio de hoy: 15.600 Bs')
                ->toContain('Qué es: Limpiador en crema para manchas difíciles')
                ->toContain('Entregas con pago al recibir en: Caracas, Valencia.')
                ->not->toContain('Maracay')
                ->and($body['input'])->toBe([['role' => 'user', 'content' => '¿Cuánto cuesta?']]);

            return true;
        });
        expect($r['status'])->toBe('answered')->and($r['answer'])->toBe('Hoy cuesta 15.600 Bs y pagas al recibir.')
            ->and($r['note'])->toBe(StoreChatRules::FORM_NOTE)->and($r['button'])->toBe(StoreChatRules::BUTTON);
        expect(StoreChatMessage::first())->input_tokens->toBe(900)->output_tokens->toBe(40);
    });

    it('con la tasa de anteayer no hay precio en bolívares', function () {
        Setting::set('rate_updated_at', '2026-11-16 08:00:00');
        Http::fake(['api.openai.com/*' => ($this->reply)(StoreChatRules::NO_PRICE)]);

        ($this->ask)('¿Cuánto cuesta?');

        Http::assertSent(fn ($request) => str_contains($request->data()['instructions'], 'no hay precio en bolívares hoy')
            && !str_contains($request->data()['instructions'], '15.600'));
    });

    it('la conversación sigue: le llega lo que ya se habló', function () {
        Http::fakeSequence('api.openai.com/*')->pushResponse(($this->reply)('Es un limpiador en crema.'))
            ->pushResponse(($this->reply)('Quita el sarro.'));
        ($this->ask)('¿Qué es?');
        ($this->ask)('¿Y para qué sirve?');

        Http::assertSent(fn ($request) => $request->data()['input'] === [
            ['role' => 'user', 'content' => '¿Qué es?'],
            ['role' => 'assistant', 'content' => 'Es un limpiador en crema.'],
            ['role' => 'user', 'content' => '¿Y para qué sirve?'],
        ]);
    });

    it('los teléfonos y correos se tapan antes de guardar y de mandarlos', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('No necesito tus datos aquí.')]);
        ($this->ask)('Mi número es 0414-123-4567');

        Http::assertSent(fn ($request) => end($request->data()['input'])['content'] === 'Mi número es [teléfono]');
        expect(StoreChatMessage::first()->question)->toBe('Mi número es [teléfono]');
    });
});

describe('La revisión antes de enviar', function () {
    it('un monto en dólares no llega al cliente: sale la respuesta segura y el dueño ve lo que dijo la IA', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('Cuesta $30.')]);

        $r = ($this->ask)('¿Cuánto cuesta en dólares?');

        expect($r)->status->toBe('blocked')->answer->toBe(StoreChatRules::SAFE)->reason->toBe('Un monto en dólares')->raw->toBe('Cuesta $30.')
            ->and(StoreChatConversation::first()->unanswered)->toBeTrue();
    });

    it('un número inventado tampoco', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('Te llega en 24 horas.')]);

        expect(($this->ask)('¿Cuándo llega?'))->status->toBe('blocked')->reason->toBe('El número «24» no está en los datos');
    });

    it('lo que no sabe queda en "no supo responder"', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)(StoreChatRules::SAFE, false)]);

        expect(($this->ask)('¿Tiene garantía?'))->status->toBe('unknown');
        expect(StoreChatConversation::first())->unanswered->toBeTrue()->questions->toBe(1);
    });

    it('cuando está decidido, "listo" (el botón se destaca)', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('Haz tu pedido con el botón: es un pedido real.', true, true)]);

        expect(($this->ask)('Lo quiero'))->ready->toBeTrue();
    });

    it('si la IA falla, "intenta de nuevo" (y no cuenta para el tope)', function () {
        Http::fake(['api.openai.com/*' => Http::response('caída', 500)]);

        expect(($this->ask)('Hola'))->status->toBe('failed')->answer->toBe(StoreChatRules::FAILED)
            ->and(app(StoreChat::class)->answeredToday())->toBe(0);
    });
});

describe('Topes y permisos', function () {
    it('la tienda solo pregunta si el chat está prendido en ese producto', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('Hola.')]);
        expect(fn () => ($this->ask)('Hola', 'store'))->toThrow(HttpException::class, 'no está prendido');

        StoreChat::saveSettings(['enabled' => true, 'product_ids' => [1]]);
        expect(($this->ask)('Hola', 'store'))->status->toBe('answered');
    });

    it('un producto sin ficha no tiene chat', function () {
        Http::fake();
        expect(fn () => app(StoreChat::class)->ask(Product::find(2), 'visitante-1', 'Hola', 'test', null, CarbonImmutable::now()))
            ->toThrow(HttpException::class, 'no tiene ficha');
        Http::assertNothingSent();
    });

    it('con el tope del día sale el mensaje fijo sin llamar a la IA; cada visitante tiene el suyo en la tienda', function () {
        StoreChat::saveSettings(['enabled' => true, 'product_ids' => [1], 'daily_answers' => 10, 'per_visitor' => 3]);
        Http::fake(['api.openai.com/*' => ($this->reply)('Hola.')]);
        foreach (range(1, 3) as $i) {
            expect(($this->ask)("Pregunta {$i}", 'store', 'visitante-a'))->status->toBe('answered');
        }
        expect(($this->ask)('Otra', 'store', 'visitante-a'))->status->toBe('limit')->answer->toBe(StoreChatRules::LIMIT)
            ->and(($this->ask)('Hola', 'store', 'visitante-b'))->status->toBe('answered');
        foreach (range(1, 6) as $i) {
            ($this->ask)("Pregunta {$i}", 'test', "dueno-{$i}abcdef");
        }
        expect(($this->ask)('Una más', 'store', 'visitante-c'))->status->toBe('limit');
        Http::assertSentCount(10);
    });

    it('sin llave en el servidor no responde; en la PC de pruebas responde de ejemplo sin llamar a nadie', function () {
        Http::fake();
        config(['services.openai.key' => null]);
        expect(fn () => ($this->ask)('Hola'))->toThrow(HttpException::class, 'no está disponible');

        app()->detectEnvironment(fn () => 'local');
        $r = ($this->ask)('¿Cuánto cuesta?');
        app()->detectEnvironment(fn () => 'testing');

        expect($r['answer'])->toStartWith('Respuesta de ejemplo')->toContain('15.600 Bs');
        Http::assertNothingSent();
    });
});

describe('La página del dueño', function () {
    it('solo el dueño la ve', function () {
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/store-chat')->assertForbidden();
        $this->putJson('/api/store-chat/settings', ['enabled' => true, 'product_ids' => [1], 'daily_answers' => 300, 'per_visitor' => 20])->assertForbidden();
        $this->postJson('/api/store-chat/test', ['product_id' => 1, 'question' => 'Hola', 'visitor' => 'visitante-1'])->assertForbidden();
    });

    it('muestra la tasa, los productos con ficha y lo que no supo responder', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)(StoreChatRules::SAFE, false)]);
        ($this->ask)('¿Tiene garantía?');
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/store-chat')->assertOk()
            ->assertJsonPath('settings.enabled', false)
            ->assertJsonPath('rate', ['binance' => 520, 'at' => '2026-11-18 08:00:00', 'fresh' => true])
            ->assertJsonPath('products', [['id' => 1, 'name' => 'Solo Brillo®', 'price_bs' => 15600, 'enabled' => false]])
            ->assertJsonPath('unanswered.0.question', '¿Tiene garantía?')
            ->assertJsonPath('unanswered.0.test', true)
            ->assertJsonPath('conversations.0.messages.0.status', 'unknown')
            ->assertJsonPath('today', ['answers' => 1, 'cap' => 300]);
    });

    it('prende el chat solo en productos con ficha', function () {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/store-chat/settings', ['enabled' => true, 'product_ids' => [2], 'daily_answers' => 300, 'per_visitor' => 20])
            ->assertStatus(422);
        $this->putJson('/api/store-chat/settings', ['enabled' => true, 'product_ids' => [1], 'daily_answers' => 200, 'per_visitor' => 10])
            ->assertOk()->assertJsonPath('message', 'Guardado. El chat está prendido.');

        expect(StoreChat::settings())->toBe(['enabled' => true, 'product_ids' => [1], 'daily_answers' => 200, 'per_visitor' => 10]);
    });

    it('el dueño prueba el chat: queda marcado "prueba"', function () {
        Http::fake(['api.openai.com/*' => ($this->reply)('Es un limpiador en crema.')]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/store-chat/test', ['product_id' => 1, 'question' => '¿Qué es?', 'visitor' => 'prueba-12345'])
            ->assertOk()->assertJsonPath('answer', 'Es un limpiador en crema.');
        $this->postJson('/api/store-chat/test', ['product_id' => 1, 'question' => 'x', 'visitor' => 'no válido!'])->assertStatus(422);

        expect(StoreChatConversation::first())->source->toBe('test')->visitor->toBe('prueba-12345');
    });
});
