<?php

use App\Models\NewProductMission;
use App\Models\ProductProfile;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\Products\NewProductMissions;
use App\Services\Meta\Products\ProductQueue;
use App\Services\Meta\Products\TestingPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .154 (dueño, 2026-11-17): "quiero tener una banca de productos por montar. Es decir, que yo vaya montando la estrategia de
// algunos productos, la imagen, etcétera, y el sistema automáticamente se lo vaya pasando al editor líder… siempre dándole
// prioridad a los batch de los productos que están activos actualmente, pero en cuanto tenga un chance, envía cada uno de esos
// productos por orden de cola".

beforeEach(function () {
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description'), $t->timestamps()]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('email')->nullable();
        $t->string('password')->nullable();
        $t->string('phone')->nullable();
        $t->string('address')->nullable();
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('products', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('product_profiles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        foreach (['what_it_is', 'benefits', 'mechanism'] as $c) {
            $t->text($c)->nullable();
        }
        $t->string('phase', 12)->default('iterate');
        $t->unsignedTinyInteger('testing_round')->default(0);
        $t->unsignedInteger('queue_position')->nullable();
        $t->timestamp('phase_at')->nullable();
        $t->timestamp('released_at')->nullable();
        $t->json('testing_results')->nullable();
        $t->timestamps();
    });
    Schema::create('product_personas', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('name');
        $t->text('who')->nullable();
        $t->text('pain')->nullable();
        $t->unsignedInteger('position')->default(0);
        $t->timestamp('archived_at')->nullable();
        $t->timestamps();
    });
    Schema::create('ad_angles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('code');
        $t->string('name')->nullable();
        $t->text('hypothesis')->nullable();
        $t->unsignedBigInteger('persona_id')->nullable();
        $t->unsignedInteger('position')->nullable();
        $t->unsignedBigInteger('alias_of')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->string('status');
        $t->string('kind')->nullable();
        $t->string('angle_code')->nullable();
        $t->unsignedTinyInteger('testing_round')->nullable();
        $t->json('result')->nullable();
        $t->string('concept')->nullable();
        $t->string('style')->nullable();
        $t->date('due_date')->nullable();
        $t->timestamp('delivered_at')->nullable();
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->timestamps();
    });
    Schema::create('product_ad_settings', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->unique(), $t->string('mode')->default('normal'), $t->timestamps()]);
    Schema::create('stored_files', function ($t) {
        $t->id();
        $t->string('driver')->default('r2');
        $t->string('path')->nullable();
        $t->string('name')->default('x');
        $t->string('mime')->default('image/jpeg');
        $t->unsignedBigInteger('size')->default(1);
        $t->string('status')->default('ready');
        $t->string('kind');
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedSmallInteger('version')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->timestamps()]);
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->json('value')->nullable(), $t->timestamps()]);
    Schema::create('meta_analyses', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedInteger('version')->default(1);
        $t->json('urgent')->nullable();
        $t->json('marks')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_09_120000_create_meta_new_product_missions_table.php'))->up();
    Notification::fake();
    $this->today = now(config('app.timezone'))->toDateString();
    $this->queue = new ProductQueue();
    $this->owner = User::forceCreate(['names' => 'Dueño', 'email' => 'd@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Admin'])->id]);
    DB::table('products')->insert(['id' => 5, 'title' => 'FLEXISTEP', 'showable_name' => 'FlexiStep']);
});

/** Un producto con su ficha completa y un ángulo (su testeo pide 2 batches), en la fase que se diga. */
function productoEnCola154(int $id, string $name, string $phase = 'queued', ?int $position = null, bool $complete = true, bool $angles = true): void
{
    DB::table('products')->insert(['id' => $id, 'title' => $name]);
    DB::table('product_profiles')->insert(['product_id' => $id, 'what_it_is' => 'a', 'benefits' => 'b', 'mechanism' => $complete ? 'c' : null,
        'phase' => $phase, 'queue_position' => $position, 'phase_at' => now()->subDays(3)]);
    DB::table('product_personas')->insert(['product_id' => $id, 'name' => 'Ana', 'who' => 'x', 'pain' => 'y']);
    if ($angles) {
        DB::table('ad_angles')->insert(['product_id' => $id, 'code' => 'POSP', 'name' => 'Posparto', 'position' => 1]);
    }
}

/** Un producto en testeo (ronda 1, 2 lugares) con sus batches en esos estados. */
function productoTaller154(int $id, string $name, array $statuses, bool $released = true): void
{
    productoEnCola154($id, $name, 'testing');
    DB::table('product_profiles')->where('product_id', $id)->update(['testing_round' => 1, 'released_at' => $released ? now()->subDay() : null]);
    foreach ($statuses as $i => $s) {
        DB::table('creative_batches')->insert(['product_id' => $id, 'number' => $id * 10 + $i, 'status' => $s, 'angle_code' => 'POSP', 'testing_round' => 1, 'due_date' => now()->addDay()->toDateString()]);
    }
}

function fase154(int $id): array
{
    return (array) DB::table('product_profiles')->where('product_id', $id)->first(['phase', 'testing_round', 'queue_position', 'released_at']);
}

describe('Cuándo sale el siguiente', function () {
    it('sin nada urgente ni atrasado, sale el primero de la cola: empieza su testeo y la cola se vuelve a numerar', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        productoEnCola154(21, 'Glowmax', 'queued', 2);
        expect($this->queue->release([], $this->today, 1))->toBe(20)
            ->and(fase154(20))->toMatchArray(['phase' => 'testing', 'testing_round' => 1, 'queue_position' => null])
            ->and(fase154(20)['released_at'])->not->toBeNull()
            ->and(fase154(21))->toMatchArray(['phase' => 'queued', 'queue_position' => 1])
            ->and(Setting::get(ProductQueue::CHECK)['missing'])->toBe([]);
        Notification::assertSentTo($this->owner, MetaWorkNotification::class);
    });

    it('si una ciudad de un producto activo necesita un batch hoy, no sale y dice por qué', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        $decisions = [5 => ['product' => ['id' => 5, 'name' => 'FlexiStep', 'mode' => 'normal'],
            'campaigns' => [1 => ['active' => true, 'city' => 'Caracas', 'action' => ['key' => 'intervene'], 'numbers' => ['offers' => [], 'live' => 2]]]]];
        expect($this->queue->release($decisions, $this->today, 1))->toBeNull()
            ->and(fase154(20)['phase'])->toBe('queued')
            ->and(Setting::get(ProductQueue::CHECK)['missing'])->toBe(['Hoy necesitan un batch: FlexiStep en Caracas.']);
        Notification::assertNothingSent();
    });

    it('con un encargo atrasado, no sale', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        DB::table('creative_batches')->insert(['product_id' => 5, 'number' => 1, 'status' => 'production', 'due_date' => now()->subDays(2)->toDateString()]);
        expect($this->queue->release([], $this->today, 1))->toBeNull()
            ->and(Setting::get(ProductQueue::CHECK)['missing'])->toBe(['Hay 1 encargo atrasado.']);
    });

    it('uno a la vez: mientras el equipo hace el testeo del anterior no sale; cuando lo entrega a la banca, sí (aunque no se haya lanzado)', function () {
        productoTaller154(30, 'Releev', ['bank', 'production']);
        productoEnCola154(20, 'Termafit', 'queued', 1);
        expect($this->queue->release([], $this->today, 1))->toBeNull()
            ->and(ProductQueue::workshop()['building'])->toBe([['product_id' => 30, 'product' => 'Releev', 'delivered' => 1, 'of' => 2]]);
        DB::table('creative_batches')->where('product_id', 30)->update(['status' => 'bank']);
        expect(ProductQueue::workshop()['ready'])->toBe([['product_id' => 30, 'product' => 'Releev', 'of' => 2]])
            ->and($this->queue->release([], $this->today, 1))->toBe(20)
            ->and(ProductQueue::workshop()['building'])->toBe([['product_id' => 20, 'product' => 'Termafit', 'delivered' => 0, 'of' => 2]]);
    });

    it('al que le falta algo se lo salta; si a todos les falta, lo dice', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1, complete: false);
        productoEnCola154(21, 'Glowmax', 'queued', 2);
        expect($this->queue->release([], $this->today, 1))->toBe(21)
            ->and(fase154(20))->toMatchArray(['phase' => 'queued', 'queue_position' => 1]);
        DB::table('creative_batches')->where('product_id', 21)->delete();
        DB::table('product_profiles')->where('product_id', 21)->update(['phase' => 'iterate']);
        expect($this->queue->release([], $this->today, 1))->toBeNull()
            ->and(Setting::get(ProductQueue::CHECK)['missing'])->toBe(['Al producto de la cola le falta algo: complétalo para que salga.'])
            ->and(ProductQueue::rows()[0]['missing'])->toBe(['En la ficha: cómo funciona.']);
    });

    it('uno en pausa se salta', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        productoEnCola154(21, 'Glowmax', 'queued', 2);
        DB::table('product_ad_settings')->insert(['product_id' => 20, 'mode' => 'paused']);
        expect($this->queue->release([], $this->today, 1))->toBe(21);
    });

    it('el de la misión semanal sale primero', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        productoEnCola154(21, 'Glowmax', 'queued', 2);
        NewProductMission::create(['status' => 'open', 'opened_on' => $this->today, 'product_id' => 21]);
        expect($this->queue->release([], $this->today, 1))->toBe(21);
    });

    it('sin nadie en la cola, no hace nada', function () {
        expect($this->queue->release([], $this->today, 1))->toBeNull()->and(Setting::get(ProductQueue::CHECK))->toBeNull();
    });
});

describe('La cola', function () {
    it('un producto que entra a la cola queda al final; de la cola a testeo a mano cuenta como salido', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        productoEnCola154(21, 'Glowmax', 'iterate');
        TestingPhase::setPhase(21, 'queued');
        expect(ProductQueue::ids())->toBe([20, 21])->and(fase154(21)['queue_position'])->toBe(2);
        TestingPhase::setPhase(20, 'testing');
        expect(fase154(20))->toMatchArray(['phase' => 'testing', 'testing_round' => 1, 'queue_position' => null])
            ->and(fase154(20)['released_at'])->not->toBeNull()
            ->and(fase154(21)['queue_position'])->toBe(1);
    });

    it('la pantalla: la cola en orden con lo que le falta, el taller y lo listo; subir, bajar y sacar', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        productoEnCola154(21, 'Glowmax', 'queued', 2, angles: false);
        productoTaller154(30, 'Releev', ['bank', 'bank']);
        DB::table('stored_files')->insert([['kind' => 'photo', 'product_id' => 20], ['kind' => 'photo', 'product_id' => 20], ['kind' => 'strategy', 'product_id' => 20]]);
        Sanctum::actingAs($this->owner);
        $r = $this->getJson('/api/meta/product-queue')->assertOk();
        expect(array_column($r->json('queue'), 'product'))->toBe(['Termafit', 'Glowmax'])
            ->and($r->json('queue.0'))->toMatchArray(['position' => 1, 'missing' => [], 'photos' => 2, 'strategy' => 1, 'linked' => false])
            ->and($r->json('queue.1.missing'))->toBe(['Los ángulos de venta (del más fuerte al más débil).'])
            ->and($r->json('ready'))->toBe([['product_id' => 30, 'product' => 'Releev', 'of' => 2]])
            ->and($r->json('next'))->toBe(20);

        $r = $this->postJson('/api/meta/product-queue/21/move', ['dir' => 'up'])->assertOk();
        expect(array_column($r->json('queue'), 'product_id'))->toBe([21, 20]);
        $this->postJson('/api/meta/product-queue/21/move', ['dir' => 'sideways'])->assertStatus(422);

        $r = $this->deleteJson('/api/meta/product-queue/21')->assertOk();
        expect(array_column($r->json('queue'), 'product_id'))->toBe([20])->and(fase154(21)['phase'])->toBe('iterate')
            ->and(fase154(20)['queue_position'])->toBe(1);
        $this->deleteJson('/api/meta/product-queue/21')->assertStatus(422);
    });

    it('solo el dueño', function () {
        $seller = User::forceCreate(['names' => 'Vendedora', 'email' => 'v@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Vendedor'])->id]);
        Sanctum::actingAs($seller);
        $this->getJson('/api/meta/product-queue')->assertForbidden();
    });
});

describe('La misión semanal usa la cola', function () {
    it('toma el que ya salió de la cola y no se lanzó: Buscar y Estrategia salen hechas', function () {
        productoTaller154(30, 'Releev', ['bank', 'production']);
        $m = NewProductMission::create(['status' => 'open', 'opened_on' => $this->today]);
        (new NewProductMissions())->sync($m);
        $m->refresh();
        expect($m->product_id)->toBe(30)->and($m->search_done_at)->not->toBeNull()->and($m->strategy_done_at)->not->toBeNull()
            ->and($m->creatives_done_at)->toBeNull();
    });

    it('si no salió ninguno, toma el primero de la cola; si lo sacas de la cola, lo deja', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        $m = NewProductMission::create(['status' => 'open', 'opened_on' => $this->today]);
        (new NewProductMissions())->sync($m);
        expect($m->refresh()->product_id)->toBe(20)->and($m->strategy_done_at)->not->toBeNull();
        ProductQueue::remove(20);
        expect($m->refresh()->product_id)->toBeNull()->and($m->strategy_done_at)->toBeNull();
    });

    it('uno que ya se lanzó en Meta o que es de otra misión no lo toma', function () {
        productoTaller154(30, 'Releev', ['testing', 'testing']);
        DB::table('meta_campaigns')->insert(['product_id' => 30]);
        productoTaller154(31, 'Glowmax', ['bank', 'bank']);
        NewProductMission::create(['status' => 'done', 'opened_on' => now()->subDays(8)->toDateString(), 'product_id' => 31]);
        $m = NewProductMission::create(['status' => 'open', 'opened_on' => $this->today]);
        (new NewProductMissions())->sync($m);
        expect($m->refresh()->product_id)->toBeNull();
    });
});

// .177 (dueño, 2026-11-26): "necesito que a él le aparezcan todos los productos que vamos a montar… le debe aparecer por
// completo la ficha, la foto del producto, el nombre del producto, etcétera, para que él pueda decidir si lo van adelantando".
describe('El editor líder ve toda la cola', function () {
    beforeEach(function () {
        Schema::table('creative_batches', fn ($t) => $t->string('source', 12)->nullable());
        Schema::create('creative_batch_deliveries', function ($t) {
            $t->id();
            $t->unsignedBigInteger('creative_batch_id');
            $t->unsignedSmallInteger('version');
            $t->unsignedBigInteger('editor_id')->nullable();
            $t->string('review', 10)->default('pending');
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_23_100000_editor_schedules.php'))->up();
        $this->leader = User::forceCreate(['names' => 'Leo', 'email' => 'l@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Editor líder'])->id]);
        $this->editor = User::forceCreate(['names' => 'Ana', 'email' => 'a@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Editor de video'])->id]);
    });

    it('todos, en orden: la ficha sin precios, los buyer personas, los ángulos, las fotos, la estrategia y qué le falta', function () {
        productoEnCola154(20, 'Termafit', 'queued', 1);
        productoEnCola154(21, 'Glowmax', 'queued', 2, angles: false);
        DB::table('product_profiles')->where('product_id', 20)->update(['what_it_is' => 'Una faja de compresión. Cuesta $25.']);
        DB::table('stored_files')->insert([
            ['kind' => 'photo', 'product_id' => 20, 'name' => 'foto.jpg', 'status' => 'ready'],
            ['kind' => 'strategy', 'product_id' => 20, 'name' => 'estrategia.pdf', 'status' => 'ready'],
            ['kind' => 'photo', 'product_id' => 21, 'name' => 'subiendo.jpg', 'status' => 'pending'],
        ]);
        Sanctum::actingAs($this->leader);
        $r = $this->getJson('/api/editor/products')->assertOk();
        expect(array_column($r->json('queue'), 'product'))->toBe(['Termafit', 'Glowmax'])
            ->and($r->json('queue.0'))->toMatchArray(['position' => 1, 'ready' => true, 'waiting_for' => null, 'next' => true])
            ->and($r->json('queue.0.info.what_it_is'))->toBe('Una faja de compresión.')
            ->and($r->json('queue.0.personas.0.name'))->toBe('Ana')
            ->and($r->json('queue.0.angles.0'))->toMatchArray(['code' => 'POSP', 'name' => 'Posparto'])
            ->and(array_column($r->json('queue.0.photos'), 'name'))->toBe(['foto.jpg'])
            ->and(array_column($r->json('queue.0.strategy'), 'name'))->toBe(['estrategia.pdf'])
            ->and($r->json('queue.1'))->toMatchArray(['position' => 2, 'ready' => false, 'next' => false, 'photos' => [],
                'waiting_for' => 'Todavía no se puede adelantar: el dueño tiene que poner sus ángulos de venta.'])
            ->and(array_column($r->json('team'), 'name'))->toBe(['Leo', 'Ana']);
    });

    it('solo el líder: el editor de video no la ve', function () {
        Sanctum::actingAs($this->editor);
        $this->getJson('/api/editor/products')->assertForbidden();
    });
});
