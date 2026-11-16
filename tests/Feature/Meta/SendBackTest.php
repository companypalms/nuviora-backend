<?php

use App\Models\CreativeBatch;
use App\Models\CreativeBatchDelivery;
use App\Models\MetaAnalysis;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// .148 (dueño, 2026-11-16: "los videos estaban malos. Es decir, no era el producto indicado… necesito una manera en la que
// yo reenvíe eso al editor líder con el inconveniente… con una nota… y que él a su vez pueda reasignárselo al mismo o a otro
// editor para que corrija esos errores y me lo vuelva a enviar"): BuniCare, el testeo B0133, B0134 y B0135.

beforeEach(function () {
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->nullable();
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->unsignedInteger('position')->nullable();
        $t->string('status');
        $t->string('kind')->nullable();
        $t->string('angle_code')->nullable();
        $t->unsignedInteger('testing_round')->nullable();
        $t->unsignedBigInteger('reference_creative_id')->nullable();
        $t->unsignedBigInteger('suggested_reference_id')->nullable();
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->date('due_date')->nullable();
        $t->timestamp('assigned_at')->nullable();
        $t->unsignedBigInteger('assigned_by')->nullable();
        $t->timestamp('status_at')->nullable();
        $t->unsignedBigInteger('status_by')->nullable();
        $t->timestamp('started_at')->nullable();
        $t->timestamp('delivered_at')->nullable();
        $t->string('discard_reason')->nullable();
        $t->timestamp('sent_back_at')->nullable();
        $t->unsignedBigInteger('sent_back_by')->nullable();
        $t->text('sent_back_note')->nullable();
        $t->json('sent_back_pieces')->nullable();
        $t->unsignedBigInteger('sent_back_editor_id')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_pieces', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->unsignedInteger('number')->nullable();
        $t->unsignedInteger('position')->default(0);
        $t->string('link', 1000)->nullable();
        $t->unsignedBigInteger('file_id')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_deliveries', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->unsignedSmallInteger('version');
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->string('concept')->nullable();
        $t->string('angle')->nullable();
        $t->text('notes')->nullable();
        $t->json('pieces');
        $t->string('review')->default('pending');
        $t->unsignedBigInteger('reviewed_by')->nullable();
        $t->timestamp('reviewed_at')->nullable();
        $t->text('comment')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_analyses', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedSmallInteger('version')->default(1);
        $t->string('status', 12);
        $t->json('facts');
        $t->json('urgent');
        $t->json('text')->nullable();
        $t->json('warnings')->nullable();
        $t->json('marks')->nullable();
        $t->timestamps();
    });
    DB::table('roles')->insert([['id' => 1, 'description' => 'Editor de video'], ['id' => 2, 'description' => 'Admin'], ['id' => 3, 'description' => 'Editor líder']]);
    DB::table('users')->insert([['id' => 10, 'names' => 'Lara', 'role_id' => 1], ['id' => 11, 'names' => 'Otro', 'role_id' => 1],
        ['id' => 1, 'names' => 'Jose', 'role_id' => 2], ['id' => 20, 'names' => 'Líder', 'role_id' => 3]]);
    DB::table('products')->insert(['id' => 7, 'title' => 'BuniCare® crema para juanetes']);
    DB::table('creative_batches')->insert([
        ['id' => 1, 'product_id' => 7, 'number' => 133, 'position' => 9, 'status' => 'bank', 'angle_code' => 'DOLOR', 'testing_round' => 1, 'editor_id' => 10,
            'delivered_at' => '2026-11-14 10:00:00'],
        ['id' => 2, 'product_id' => 7, 'number' => 140, 'position' => 3, 'status' => 'approved', 'angle_code' => 'RUTINA', 'testing_round' => null, 'editor_id' => null, 'delivered_at' => null],
    ]);
    DB::table('creative_batch_pieces')->insert([['id' => 21, 'creative_batch_id' => 1, 'number' => 199, 'position' => 1], ['id' => 22, 'creative_batch_id' => 1, 'number' => 200, 'position' => 2],
        ['id' => 23, 'creative_batch_id' => 1, 'number' => 201, 'position' => 3]]);
    DB::table('creative_batch_deliveries')->insert(['creative_batch_id' => 1, 'version' => 1, 'editor_id' => 10, 'pieces' => '[]', 'review' => 'approved', 'reviewed_by' => 20,
        'created_at' => '2026-11-14 10:00:00']);
    MetaAnalysis::create(['date' => now(config('app.timezone'))->toDateString(), 'version' => 1, 'status' => 'ok', 'facts' => [], 'urgent' => [
        ['key' => '7:test:1', 'kind' => 'launch_test', 'batches' => ['B0133', 'B0134', 'B0135']],
        ['key' => '9:3:scale', 'kind' => 'scale', 'batches' => []],
    ]]);
    Notification::fake();
});

$devolver = fn () => (new EditorWork())->sendBack(CreativeBatch::find(1), User::find(1), 'Los videos son de otro producto, no de BuniCare.', [21, 23]);
$encargo = fn (string $role = 'Editor de video', int $id = 11) => EditorWork::fields(CreativeBatch::with(EditorWork::WITH)->find(1), ['id' => $id, 'role' => $role]);

describe('.148: devolver al líder un batch de la banca', function () use ($devolver, $encargo) {
    it('B0133 de BuniCare: queda sin asignar, primero en la cola, con 2 días, tu nota, las piezas y quién lo hizo', function () use ($devolver) {
        $devolver();
        $b = CreativeBatch::find(1);

        expect($b->status)->toBe('approved')->and($b->editor_id)->toBeNull()
            // la fecha "lista" (lo que ven el líder y el editor) es en 2 días: la entrega mañana + 1 día de correcciones del líder
            ->and($b->due_date->toDateString())->toBe(now(config('app.timezone'))->addDay()->toDateString())
            ->and($b->position)->toBe(2)   // antes que B0140 (3)
            ->and($b->sent_back_note)->toBe('Los videos son de otro producto, no de BuniCare.')
            ->and($b->sent_back_pieces)->toBe(['C0199', 'C0201'])
            ->and($b->sent_back_by)->toBe(1)->and($b->sent_back_editor_id)->toBe(10);
    });

    it('la misión de lanzar el testeo sale de tu día (las otras se quedan) y al líder le llega el aviso', function () use ($devolver) {
        $devolver();

        expect(array_column(MetaAnalysis::first()->urgent, 'key'))->toBe(['9:3:scale']);
        Notification::assertSentTo(User::find(20), MetaWorkNotification::class, fn ($n) => $n->toDatabase(User::find(20)) === ['type' => 'owner_returned',
            'message' => 'El dueño te devolvió B0133 de BuniCare (C0199, C0201): Los videos son de otro producto, no de BuniCare. Asígnalo para corregirlo.', 'url' => '/editor/equipo']);
        Notification::assertNotSentTo(User::find(10), MetaWorkNotification::class);
    });

    it('el líder lo ve primero para asignar (se le sugiere Lara, que lo hizo) y el editor ve "Te lo devolvieron" con tu nota', function () use ($devolver, $encargo) {
        $devolver();
        $lider = $encargo('Editor líder', 20);
        expect($lider['sent_back'])->toMatchArray(['note' => 'Los videos son de otro producto, no de BuniCare.', 'pieces' => ['C0199', 'C0201'], 'by' => 'Jose', 'editor_id' => 10, 'editor' => 'Lara'])
            ->and($lider['returned'])->toBe(['version' => 'V01', 'comment' => "Los videos son de otro producto, no de BuniCare.\nPiezas a corregir: C0199, C0201.", 'by' => 'Jose'])
            ->and($lider['can']['assign'])->toBeTrue();

        // El líder se lo pasa a Otro: lo ve en sus encargos como devuelto
        (new EditorWork())->assign(CreativeBatch::find(1), 11, User::find(20));
        expect($encargo()['returned']['comment'])->toStartWith('Los videos son de otro producto');
    });

    it('cuando lo entregan de nuevo ya no es "te lo devolvieron" (el líder revisa con tu nota a la vista); en la banca, la nota se va', function () use ($devolver, $encargo) {
        $devolver();
        DB::table('creative_batches')->where('id', 1)->update(['status' => 'review', 'editor_id' => 11]);
        CreativeBatchDelivery::create(['creative_batch_id' => 1, 'version' => 2, 'editor_id' => 11, 'pieces' => [], 'review' => 'pending']);
        DB::table('creative_batch_deliveries')->where('version', 2)->update(['created_at' => now()->addMinute()]);

        expect($encargo('Editor líder', 20))->toMatchArray(['returned' => null])->and($encargo('Editor líder', 20)['sent_back']['note'])->toContain('otro producto');

        // Si el líder devuelve la corrección, manda lo que pidió el líder
        DB::table('creative_batches')->where('id', 1)->update(['status' => 'production']);
        DB::table('creative_batch_deliveries')->where('version', 2)->update(['review' => 'returned', 'comment' => 'C0199: sigue siendo otro producto', 'reviewed_by' => 20]);
        expect($encargo()['returned'])->toMatchArray(['version' => 'V02', 'comment' => 'C0199: sigue siendo otro producto', 'by' => 'Líder']);

        DB::table('creative_batches')->where('id', 1)->update(['status' => 'bank']);
        expect($encargo()['sent_back'])->toBeNull()->and($encargo()['returned'])->toBeNull();
    });

    it('solo desde la banca, con una nota que se entienda y al menos una pieza', function () use ($devolver) {
        expect(fn () => (new EditorWork())->sendBack(CreativeBatch::find(1), User::find(1), 'mal', [21]))->toThrow(ValidationException::class)
            ->and(fn () => (new EditorWork())->sendBack(CreativeBatch::find(1), User::find(1), 'Los videos son de otro producto.', []))->toThrow(ValidationException::class)
            ->and(fn () => (new EditorWork())->sendBack(CreativeBatch::find(1), User::find(1), 'Los videos son de otro producto.', [99]))->toThrow(ValidationException::class)
            ->and(fn () => (new EditorWork())->sendBack(CreativeBatch::find(2), User::find(1), 'Los videos son de otro producto.', [21]))->toThrow(ValidationException::class);
        expect(CreativeBatch::find(1)->status)->toBe('bank');
    });

    it('lo hace solo el dueño (la ruta es de Admin)', function () {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods(), true) && $r->uri() === 'api/meta/queue/{batch}/send-back');

        expect($route)->not->toBeNull()->and($route->gatherMiddleware())->toContain('role:Admin');
    });
});
