<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Calendar\Readers\ReminderFacts;
use App\Services\Calendar\Rules\ReminderRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// `.108` "Tu día" (dueño, 2026-11-06: "un sistema de tiempo de cuánto me debe tomar cada tarea y con notificaciones de que
// se agotó el tiempo"): el reloj de cada tarea, lo hecho a tiempo, lo que sueles tardar y la racha. En una base en memoria;
// los recordatorios del sistema apagados (aquí solo cuentan las notas).

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
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamps();
    });
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    Schema::create('recurring_expenses', function ($t) {
        $t->id();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    (require database_path('migrations/2026_10_29_300000_create_calendar_tables.php'))->up();
    (require database_path('migrations/2026_11_06_100000_create_calendar_item_plans_table.php'))->up();
    (require database_path('migrations/2026_11_06_200000_create_calendar_timers_and_days_tables.php'))->up();

    Setting::set('calendar', ['started_on' => '2026-11-01', 'rules' => array_map(fn () => ['on' => false], ReminderRules::RULES)]);
    app()->instance(ReminderFacts::class, new class extends ReminderFacts {
        public function __construct()
        {
        }

        public function forRange(CarbonImmutable $from, CarbonImmutable $to): array
        {
            return [];
        }

        public function now(CarbonImmutable $now): array
        {
            return [];
        }

        public function expenses(CarbonImmutable $until): array
        {
            return [];
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00:00'));
    $role = Role::forceCreate(['description' => 'Admin']);
    $this->admin = User::forceCreate(['names' => 'Admin', 'email' => 'admin@test', 'password' => 'x', 'role_id' => $role->id]);
    Sanctum::actingAs($this->admin);
});

function relojNota($test, array $over = []): array
{
    $id = $test->postJson('/api/calendar/notes', $over + ['title' => 'Llamar al banco', 'date' => '2026-11-06', 'kind' => 'task', 'repeat' => 'none', 'minutes' => 10])
        ->assertCreated()->json('id');

    return ['item_key' => "note:{$id}", 'due_date' => $over['date'] ?? '2026-11-06'];
}

function relojHoy($test): array
{
    return $test->getJson('/api/calendar/today?fresh=1')->assertOk()->json();
}

describe('El reloj de cada tarea', function () {
    it('empezar: corre con los minutos de la tarea y dice cuándo se acaba; la hora del servidor va aparte', function () {
        $n = relojNota($this);
        $this->postJson('/api/calendar/timer/start', $n)->assertOk()->assertJsonPath('message', 'El reloj está corriendo.');

        $today = relojHoy($this);
        $t = $today['timers']["{$n['item_key']}|2026-11-06"];
        expect($t)->toMatchArray(['minutes' => 10, 'extra_minutes' => 0, 'spent_seconds' => 0, 'later_at' => null, 'finished' => false, 'on_time' => null]);
        expect(CarbonImmutable::parse($t['started_at'])->format('H:i'))->toBe('09:00');
        expect(CarbonImmutable::parse($t['ends_at'])->format('H:i'))->toBe('09:10');
        expect(CarbonImmutable::parse($today['server_now'])->format('H:i'))->toBe('09:00');
    });

    it('pausar guarda lo que corrió; +5 min le suma; seguir vuelve a correr', function () {
        $n = relojNota($this);
        $this->postJson('/api/calendar/timer/start', $n)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:04:00'));
        $this->postJson('/api/calendar/timer/pause', $n)->assertOk()->assertJsonPath('message', 'En pausa.');
        $this->postJson('/api/calendar/timer/extend', $n)->assertOk()->assertJsonPath('message', '5 minutos más.');

        $t = relojHoy($this)['timers']["{$n['item_key']}|2026-11-06"];
        expect($t)->toMatchArray(['spent_seconds' => 240, 'started_at' => null, 'ends_at' => null, 'extra_minutes' => 5]);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:30:00'));
        $this->postJson('/api/calendar/timer/start', $n)->assertOk();
        $t = relojHoy($this)['timers']["{$n['item_key']}|2026-11-06"];
        // le quedaban 15 − 4 = 11 minutos
        expect(CarbonImmutable::parse($t['ends_at'])->format('H:i'))->toBe('09:41');
    });

    it('corre uno a la vez: empezar otra pausa la que corría', function () {
        $a = relojNota($this);
        $b = relojNota($this, ['title' => 'Pagar la luz']);
        $this->postJson('/api/calendar/timer/start', $a)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:02:00'));
        $this->postJson('/api/calendar/timer/start', $b)->assertOk();

        $timers = relojHoy($this)['timers'];
        expect($timers["{$a['item_key']}|2026-11-06"])->toMatchArray(['started_at' => null, 'spent_seconds' => 120]);
        expect($timers["{$b['item_key']}|2026-11-06"]['started_at'])->not->toBeNull();
    });

    it('"Más tarde" la pausa y la manda al final de su etapa', function () {
        $a = relojNota($this, ['minutes' => 5]);
        $b = relojNota($this, ['title' => 'Pagar la luz', 'minutes' => 30]);
        expect(relojHoy($this)['plan'][0]['items'][0]['item_key'])->toBe($a['item_key']);

        $this->postJson('/api/calendar/timer/later', $a)->assertOk()->assertJsonPath('message', 'La dejaste para después.');
        $plan = relojHoy($this)['plan'];
        expect(array_column($plan[0]['items'], 'item_key'))->toBe([$b['item_key'], $a['item_key']]);
        expect($plan[0]['items'][1]['reason'])->toBe('La dejaste para después.');

        // empezarla le quita el "Más tarde"
        $this->postJson('/api/calendar/timer/start', $a)->assertOk();
        expect(relojHoy($this)['plan'][0]['items'][0]['item_key'])->toBe($a['item_key']);
    });

    it('"Hecho" termina el reloj y dice si fue a tiempo; "Deshacer" lo deja en pausa otra vez', function () {
        $a = relojNota($this);
        $b = relojNota($this, ['title' => 'Pagar la luz', 'minutes' => 5]);
        $this->postJson('/api/calendar/timer/start', $a)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:08:00'));
        $this->postJson('/api/calendar/marks', $a + ['status' => 'done'])->assertOk();
        $this->postJson('/api/calendar/timer/start', $b)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:20:00'));
        $this->postJson('/api/calendar/marks', $b + ['status' => 'done'])->assertOk();

        $today = relojHoy($this);
        expect($today['timers']["{$a['item_key']}|2026-11-06"])->toMatchArray(['finished' => true, 'on_time' => true, 'spent_seconds' => 480]);
        expect($today['timers']["{$b['item_key']}|2026-11-06"])->toMatchArray(['finished' => true, 'on_time' => false, 'spent_seconds' => 720]);
        expect($today['score'])->toMatchArray(['on_time' => 1, 'timed' => 2]);

        $this->deleteJson('/api/calendar/marks', $b)->assertOk();
        expect(relojHoy($this)['timers']["{$b['item_key']}|2026-11-06"])->toMatchArray(['finished' => false, 'on_time' => null, 'started_at' => null, 'spent_seconds' => 720]);
        $this->postJson('/api/calendar/timer/start', $a)->assertStatus(422)->assertJsonPath('errors.item_key.0', 'Esa ya está hecha. Si quieres seguirla, primero toca "Deshacer".');
    });

    it('sueles tardar: desde la 3.ª vez que la haces con el reloj', function () {
        $n = relojNota($this, ['repeat' => 'weekly', 'date' => '2026-10-16']);
        foreach (['2026-10-16' => 12, '2026-10-23' => 14, '2026-10-30' => 16] as $day => $minutes) {
            DB::table('calendar_timers')->insert(['item_key' => $n['item_key'], 'occurs_on' => $day, 'minutes' => 10, 'spent_seconds' => $minutes * 60,
                'finished_at' => "{$day} 10:00:00", 'on_time' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        expect(relojHoy($this)['usual'])->toBe([$n['item_key'] => 14]);
    });

    it('la racha: el día queda completo cuando no falta nada, y suma a los de antes', function () {
        DB::table('calendar_days')->insert([
            ['date' => '2026-11-04', 'total' => 5, 'done' => 5, 'all_done_at' => '2026-11-04 18:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-11-05', 'total' => 6, 'done' => 6, 'all_done_at' => '2026-11-05 18:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $n = relojNota($this);
        expect(relojHoy($this)['score'])->toMatchArray(['streak' => 2, 'best' => 2]);
        expect(DB::table('calendar_days')->where('date', '2026-11-06')->value('all_done_at'))->toBeNull();

        $this->postJson('/api/calendar/marks', $n + ['status' => 'done'])->assertOk();
        expect(relojHoy($this)['score'])->toMatchArray(['streak' => 3, 'best' => 3]);
        expect(DB::table('calendar_days')->where('date', '2026-11-06')->first())->toMatchObject(['total' => 1, 'done' => 1]);
    });

    it('lo atrasado que se hace hoy cuenta en lo hecho de hoy (resolved), con su reloj', function () {
        $vieja = relojNota($this, ['title' => 'Lo del martes', 'date' => '2026-11-03']);
        expect(relojHoy($this)['overdue'])->toHaveCount(1);
        $this->postJson('/api/calendar/timer/start', $vieja)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:05:00'));
        $this->postJson('/api/calendar/marks', $vieja + ['status' => 'done'])->assertOk();

        $today = relojHoy($this);
        expect($today['overdue'])->toBe([]);
        expect(array_column($today['resolved'], 'item_key'))->toBe([$vieja['item_key']]);
        expect($today['timers']["{$vieja['item_key']}|2026-11-03"])->toMatchArray(['finished' => true, 'on_time' => true]);
        expect($today['score'])->toMatchArray(['on_time' => 1, 'timed' => 1]);
    });

    it('solo para lo de hoy (o atrasado), y solo el Admin', function () {
        $manana = relojNota($this, ['date' => '2026-11-07']);
        $this->postJson('/api/calendar/timer/start', $manana)->assertStatus(422)->assertJsonValidationErrors('item_key');
        $this->postJson('/api/calendar/timer/start', ['item_key' => 'note:999', 'due_date' => '2026-11-06'])->assertStatus(422);
        $this->postJson('/api/calendar/timer/otra', ['item_key' => 'note:1', 'due_date' => '2026-11-06'])->assertNotFound();

        $role = Role::forceCreate(['description' => 'Gerente']);
        Sanctum::actingAs(User::forceCreate(['names' => 'G', 'email' => 'g@test', 'password' => 'x', 'role_id' => $role->id]));
        $this->postJson('/api/calendar/timer/start', $manana)->assertStatus(403);
    });
});
