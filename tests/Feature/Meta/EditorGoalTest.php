<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Meta\Queue\EditorFacts;
use App\Services\Meta\Queue\EditorTeam;
use App\Services\Metrics\Period;
use App\Services\Metrics\Team\EditorKpis;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .175 (dueño, 2026-11-23): meta mínima de 2 batches por día (también el líder); el horario del líder lo pone el dueño y el de
// sus editores, el líder; "nunca se le da la opción al editor, le debe aparecer una misión al líder para que le asigne otro".
// En una base en memoria. 2026-11-23 es lunes.

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
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->text('value')->nullable(), $t->timestamps()]);
    Schema::create('product_ad_settings', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->string('mode')->default('normal'), $t->timestamps()]);
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id')->default(1);
        $t->unsignedInteger('number')->nullable();
        $t->string('status', 12);
        $t->string('source', 12)->nullable();
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->timestamp('assigned_at')->nullable();
        $t->timestamp('status_at')->nullable();
        $t->timestamp('delivered_at')->nullable();
        $t->date('due_date')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_deliveries', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->unsignedSmallInteger('version');
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->json('pieces')->nullable();
        $t->string('review', 10)->default('pending');
        $t->timestamp('reviewed_at')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_23_100000_editor_schedules.php'))->up();

    $role = fn (string $d) => Role::forceCreate(['description' => $d])->id;
    [$leaderRole, $editorRole, $admin] = [$role('Editor líder'), $role('Editor de video'), $role('Admin')];
    $user = fn (string $name, int $roleId) => User::forceCreate(['names' => $name, 'email' => "{$name}@test", 'password' => 'x', 'role_id' => $roleId]);
    $this->leader = $user('Leo', $leaderRole);
    $this->ana = $user('Ana', $editorRole);
    $this->owner = $user('Dueño', $admin);
    Carbon::setTestNow(Carbon::parse('2026-11-23 14:10:00', 'America/Caracas'));

    $this->batch = fn (array $b) => DB::table('creative_batches')->insertGetId($b + ['created_at' => now(), 'updated_at' => now()]);
    $this->deliver = fn (int $batch, int $editor, string $at, int $version = 1, string $review = 'approved', ?string $reviewedAt = null) => DB::table('creative_batch_deliveries')
        ->insertGetId(['creative_batch_id' => $batch, 'version' => $version, 'editor_id' => $editor, 'review' => $review, 'reviewed_at' => $reviewedAt, 'created_at' => $at, 'updated_at' => $at]);
});

afterEach(fn () => Carbon::setTestNow());

describe('El horario', function () {
    it('el líder pone el de sus editores de video', function () {
        Sanctum::actingAs($this->leader);
        $r = $this->putJson("/api/editor/team/{$this->ana->id}/schedule", ['days' => [5, 1, 2, 3, 4], 'start' => '08:00', 'end' => '17:00'])->assertOk();
        expect(DB::table('editor_schedules')->where('user_id', $this->ana->id)->value('days'))->toBe('[1,2,3,4,5]')
            ->and(collect($r->json('editors'))->firstWhere('id', $this->ana->id)['schedule_text'])->toBe('lunes a viernes, de 08:00 a 17:00');
    });

    it('el suyo no: lo pone el dueño', function () {
        Sanctum::actingAs($this->leader);
        $this->putJson("/api/editor/team/{$this->leader->id}/schedule", ['days' => [1], 'start' => '08:00', 'end' => '17:00'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/meta/editor-team/{$this->leader->id}/schedule", ['days' => [1, 2, 3, 4, 5, 6], 'start' => '07:00', 'end' => '16:00'])->assertOk();
        expect(DB::table('editor_schedules')->where('user_id', $this->leader->id)->value('start_time'))->toBe('07:00');
    });

    it('un editor de video no cambia horarios, ni el suyo', function () {
        Sanctum::actingAs($this->ana);
        $this->putJson("/api/editor/team/{$this->ana->id}/schedule", ['days' => [1], 'start' => '08:00', 'end' => '17:00'])->assertForbidden();
        $this->putJson("/api/editor/team/{$this->ana->id}/day-off", ['date' => '2026-11-23', 'off' => true])->assertForbidden();
    });

    it('un horario mal puesto se explica', function () {
        Sanctum::actingAs($this->leader);
        $this->putJson("/api/editor/team/{$this->ana->id}/schedule", ['days' => [1], 'start' => '17:00', 'end' => '08:00'])
            ->assertStatus(422)->assertJsonPath('errors.schedule.0', 'La hora de salida tiene que ser después de la de entrada.');
    });
});

describe('Libre: la misión del líder', function () {
    beforeEach(function () {
        foreach ([$this->ana, $this->leader] as $u) {
            DB::table('editor_schedules')->insert(['user_id' => $u->id, 'days' => '[1,2,3,4,5]', 'start_time' => '08:00', 'end_time' => '17:00']);
        }
    });

    it('Ana entregó y no tiene nada más: está libre desde su entrega; el líder tiene uno en producción: no', function () {
        $done = ($this->batch)(['status' => 'review', 'editor_id' => $this->ana->id, 'assigned_at' => '2026-11-23 08:30:00']);
        ($this->deliver)($done, $this->ana->id, '2026-11-23 13:05:00', 1, 'pending');
        ($this->batch)(['status' => 'production', 'editor_id' => $this->leader->id, 'assigned_at' => '2026-11-23 09:00:00']);
        $team = collect(EditorTeam::today())->keyBy('id');
        expect($team[$this->ana->id])->toMatchArray(['state' => 'in', 'left' => 170, 'done' => 1, 'goal' => 2, 'open' => 0, 'free' => true, 'free_since' => '2026-11-23 13:05:00'])
            ->and($team[$this->leader->id])->toMatchArray(['free' => false, 'open' => 1, 'done' => 0])
            ->and(EditorTeam::freeCount())->toBe(1);
    });

    it('cargar a la banca un batch ya hecho no es un encargo: el líder sigue libre', function () {
        ($this->batch)(['status' => 'production', 'source' => 'imported', 'editor_id' => $this->leader->id, 'assigned_at' => '2026-11-23 09:00:00']);
        expect(collect(EditorTeam::today())->firstWhere('id', $this->leader->id)['free'])->toBeTrue();
    });

    it('de permiso hoy no está libre (y el día no cuenta para su meta)', function () {
        Sanctum::actingAs($this->leader);
        $this->putJson("/api/editor/team/{$this->ana->id}/day-off", ['date' => '2026-11-23', 'off' => true, 'note' => 'cita médica'])->assertOk();
        expect(collect(EditorTeam::today())->firstWhere('id', $this->ana->id))->toMatchArray(['state' => 'day_off', 'free' => false]);
        $this->putJson("/api/editor/team/{$this->ana->id}/day-off", ['date' => '2026-11-23', 'off' => false])->assertOk();
        expect(collect(EditorTeam::today())->firstWhere('id', $this->ana->id)['state'])->toBe('in');
    });

    it('al líder, cada editor libre le suma al número del menú', function () {
        ($this->batch)(['status' => 'production', 'editor_id' => $this->leader->id, 'assigned_at' => '2026-11-23 09:00:00']);
        Sanctum::actingAs($this->leader);
        $this->getJson('/api/editor/summary')->assertOk()->assertJsonPath('pending.free', 1)->assertJsonPath('pending.total', 2); // lo suyo + Ana libre
        Sanctum::actingAs($this->ana);
        expect($this->getJson('/api/editor/summary')->json('pending'))->not->toHaveKey('free');
    });

    it('"¿Alcanza el equipo?" cuenta la meta de cada uno por sus días de trabajo', function () {
        expect(EditorTeam::capacity())->toMatchArray(['people' => 2, 'goal' => 2, 'per_day' => round(2 * 10 / 7, 2), 'missing' => []]);
    });
});

describe('Los números del panel', function () {
    it('días con la meta cumplida, ritmo contra la meta, horas por batch y horas sin encargo', function () {
        DB::table('editor_schedules')->insert(['user_id' => $this->ana->id, 'days' => '[1,2,3,4,5]', 'start_time' => '08:00', 'end_time' => '17:00']);
        // lunes 16: le asignan dos a las 8 y entrega a las 12 y a las 16 (cumple); martes 17: uno de 8 a 11; el resto de la semana, nada
        $a = ($this->batch)(['status' => 'bank', 'editor_id' => $this->ana->id, 'assigned_at' => '2026-11-16 08:00:00', 'status_at' => '2026-11-16 18:00:00']);
        $b = ($this->batch)(['status' => 'bank', 'editor_id' => $this->ana->id, 'assigned_at' => '2026-11-16 08:00:00', 'status_at' => '2026-11-16 18:00:00']);
        $c = ($this->batch)(['status' => 'bank', 'editor_id' => $this->ana->id, 'assigned_at' => '2026-11-17 08:00:00', 'status_at' => '2026-11-17 18:00:00']);
        ($this->deliver)($a, $this->ana->id, '2026-11-16 12:00:00');
        ($this->deliver)($b, $this->ana->id, '2026-11-16 16:00:00');
        ($this->deliver)($c, $this->ana->id, '2026-11-17 11:00:00');
        $period = Period::fromPreset('custom', '2026-11-16', '2026-11-22', CarbonImmutable::now('America/Caracas'));
        $facts = (new EditorFacts())->read($period, [$this->ana->id]) + ['people' => [$this->ana->id => ['name' => 'Ana', 'group' => null]]];
        $ana = (new EditorKpis())->compute($facts, $period)['people'][$this->ana->id];
        // 5 días de trabajo, cumplió 1; 3 de 10; los batches: 4 h, 4 h (desde la entrega anterior) y 3 h; con encargo: lunes 8–16 y martes 8–11
        expect($ana['values'])->toMatchArray(['goal_days' => 20.0, 'pace' => 30.0, 'batch_hours' => 3.7, 'idle_hours' => 34.0])
            ->and($ana['details']['goal_days'])->toBe('1 de 5 días de trabajo')
            ->and($ana['details']['pace'])->toBe('0,6 batches por día trabajado; la meta es 2');
    });

    it('sin horario no se juzga', function () {
        $period = Period::fromPreset('custom', '2026-11-16', '2026-11-22', CarbonImmutable::now('America/Caracas'));
        $a = ($this->batch)(['status' => 'bank', 'editor_id' => $this->ana->id, 'assigned_at' => '2026-11-16 08:00:00']);
        ($this->deliver)($a, $this->ana->id, '2026-11-16 12:00:00');
        $facts = (new EditorFacts())->read($period, [$this->ana->id]) + ['people' => [$this->ana->id => ['name' => 'Ana', 'group' => null]]];
        $ana = (new EditorKpis())->compute($facts, $period)['people'][$this->ana->id] ?? null;
        expect($ana)->toBeNull(); // sin horario ni encargos con número, no hay nada que mostrar
    });
});
