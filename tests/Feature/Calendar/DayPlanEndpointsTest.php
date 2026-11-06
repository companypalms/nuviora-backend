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

// `.107` "Tu día" (dueño, 2026-11-06): la prioridad, los minutos y el orden arrastrado de cada cosa del calendario, para
// todos los días. En una base en memoria; los recordatorios del sistema apagados (sus datos son de otras pantallas).

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

    // Los recordatorios del sistema apagados: aquí solo cuentan las notas y lo que se guarda
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
    $this->gasto = DB::table('recurring_expenses')->insertGetId(['is_active' => true]);
});

function tuDiaRol(string $name): User
{
    $role = Role::forceCreate(['description' => $name]);

    return User::forceCreate(['names' => $name, 'email' => strtolower($name) . '@test', 'password' => 'x', 'role_id' => $role->id]);
}

function tuDiaNota($test, array $over = []): int
{
    return $test->postJson('/api/calendar/notes', $over + ['title' => 'Llamar al banco', 'date' => '2026-11-06', 'kind' => 'task', 'repeat' => 'none'])
        ->assertCreated()->json('id');
}

describe('Tu día: prioridad, minutos y orden', function () {
    it('una nota nueva empieza en normal y 15 minutos, o con lo que se eligió al anotar', function () {
        Sanctum::actingAs(tuDiaRol('Admin'));
        $normal = tuDiaNota($this);
        $alta = tuDiaNota($this, ['title' => 'Pagar el alquiler', 'priority' => 'alta', 'minutes' => 30]);

        $today = $this->getJson('/api/calendar/today?fresh=1')->assertOk();
        $items = collect($today->json('items'))->keyBy('item_key');
        expect($items["note:{$normal}"])->toMatchArray(['priority' => 'normal', 'minutes' => 15, 'rank' => null]);
        expect($items["note:{$alta}"])->toMatchArray(['priority' => 'alta', 'minutes' => 30]);
        expect($today->json('minutes_left'))->toBe(45);
        expect(array_column($today->json('plan'), 'key'))->toBe(['alta', 'normal']);
        expect($today->json('plan.0.items.0'))->toMatchArray(['item_key' => "note:{$alta}", 'due_date' => '2026-11-06']);
    });

    it('lo atrasado y lo que ya es su hora van en urgente; lo de más tarde, a su hora', function () {
        Sanctum::actingAs(tuDiaRol('Admin'));
        $vieja = tuDiaNota($this, ['title' => 'Lo del martes', 'date' => '2026-11-03']);
        $ahora = tuDiaNota($this, ['title' => 'Reunión', 'time' => '09:10']);
        $tarde = tuDiaNota($this, ['title' => 'Llamada', 'time' => '16:00']);

        $plan = $this->getJson('/api/calendar/today?fresh=1')->json('plan');
        expect(array_column($plan, 'key'))->toBe(['urgent', 'later']);
        expect(array_column($plan[0]['items'], 'item_key'))->toBe(["note:{$vieja}", "note:{$ahora}"]);
        expect(array_column($plan[1]['items'], 'item_key'))->toBe(["note:{$tarde}"]);
    });

    it('cambiar la prioridad o los minutos se guarda para todos los días: una nota, un recordatorio y los gastos fijos', function () {
        Sanctum::actingAs(tuDiaRol('Admin'));
        $nota = tuDiaNota($this);

        $this->putJson('/api/calendar/item-plan', ['items' => [
            ['item_key' => "note:{$nota}", 'minutes' => 45],
            ['item_key' => 'rule:rate', 'priority' => 'baja'],
            ['item_key' => 'expense:*', 'minutes' => 10],
            ['item_key' => "expense:{$this->gasto}", 'priority' => 'normal'],
        ]])->assertOk()->assertJsonPath('message', 'Guardado para todos los días.');

        $plans = DB::table('calendar_item_plans')->get()->keyBy('item_key');
        expect((array) $plans["note:{$nota}"])->toMatchArray(['priority' => 'normal', 'minutes' => 45]);
        expect((array) $plans['rule:rate'])->toMatchArray(['priority' => 'baja', 'minutes' => 5]);
        expect((array) $plans['expense:*'])->toMatchArray(['priority' => 'alta', 'minutes' => 10]);
        // el gasto toma los minutos de "todos los gastos" porque no tenía los suyos
        expect((array) $plans["expense:{$this->gasto}"])->toMatchArray(['priority' => 'normal', 'minutes' => 10]);

        $settings = $this->getJson('/api/calendar/settings')->assertOk();
        expect(collect($settings->json('rules'))->firstWhere('key', 'rate'))->toMatchArray(['priority' => 'baja', 'minutes' => 5]);
        expect(collect($settings->json('rules'))->firstWhere('key', 'meta_analysis'))->toMatchArray(['priority' => 'alta', 'minutes' => 15]);
        expect($settings->json('expenses'))->toBe(['priority' => 'alta', 'minutes' => 10]);
    });

    it('arrastrar: el grupo queda en ese orden y la que llegó de otro grupo toma su prioridad; se puede volver al automático', function () {
        Sanctum::actingAs(tuDiaRol('Admin'));
        $a = tuDiaNota($this, ['title' => 'Tarea A', 'minutes' => 5]);
        $b = tuDiaNota($this, ['title' => 'Tarea B', 'minutes' => 10]);
        $c = tuDiaNota($this, ['title' => 'Tarea C', 'priority' => 'baja', 'minutes' => 20]);

        $this->putJson('/api/calendar/order', ['priority' => 'normal', 'item_keys' => ["note:{$c}", "note:{$b}", "note:{$a}"]])
            ->assertOk()->assertJsonPath('message', 'Orden guardado para todos los días.');

        $plan = $this->getJson('/api/calendar/today?fresh=1')->json('plan');
        expect(array_column($plan, 'key'))->toBe(['normal']);
        expect(array_column($plan[0]['items'], 'item_key'))->toBe(["note:{$c}", "note:{$b}", "note:{$a}"]);
        expect($plan[0]['items'][0]['reason'])->toBe('Prioridad normal: en el orden que pusiste.');
        expect(DB::table('calendar_item_plans')->where('item_key', "note:{$c}")->value('minutes'))->toBe(20);

        // cambiar la prioridad con un toque la saca de su lugar (va al final de su nuevo grupo)
        $this->putJson('/api/calendar/item-plan', ['items' => [['item_key' => "note:{$b}", 'priority' => 'alta']]])->assertOk();
        expect(DB::table('calendar_item_plans')->where('item_key', "note:{$b}")->value('rank'))->toBeNull();

        $this->deleteJson('/api/calendar/order')->assertOk()->assertJsonPath('message', 'Volvió el orden automático.');
        expect(DB::table('calendar_item_plans')->whereNotNull('rank')->count())->toBe(0);
        $plan = $this->getJson('/api/calendar/today?fresh=1')->json('plan');
        expect(array_column($plan[1]['items'], 'item_key'))->toBe(["note:{$a}", "note:{$c}"]);
    });

    it('editar una nota cambia su prioridad y sus minutos; sin mandarlos, quedan como estaban', function () {
        Sanctum::actingAs(tuDiaRol('Admin'));
        $nota = tuDiaNota($this, ['minutes' => 30]);
        $campos = ['title' => 'Llamar al banco', 'date' => '2026-11-06', 'kind' => 'task', 'repeat' => 'none'];

        $this->putJson("/api/calendar/notes/{$nota}", $campos)->assertOk();
        expect(DB::table('calendar_item_plans')->where('item_key', "note:{$nota}")->value('minutes'))->toBe(30);

        $this->putJson("/api/calendar/notes/{$nota}", $campos + ['priority' => 'baja', 'minutes' => '10'])->assertOk();
        expect((array) DB::table('calendar_item_plans')->where('item_key', "note:{$nota}")->first())->toMatchArray(['priority' => 'baja', 'minutes' => 10]);
    });

    it('avisa en palabras simples lo que está mal y no guarda nada', function () {
        Sanctum::actingAs(tuDiaRol('Admin'));
        $nota = tuDiaNota($this);

        $this->putJson('/api/calendar/item-plan', ['items' => [['item_key' => "note:{$nota}", 'priority' => 'urgente', 'minutes' => 0]]])
            ->assertStatus(422)->assertJsonPath('errors', [
                'items.0.priority' => ['Elige la prioridad: alta, normal o baja.'],
                'items.0.minutes' => ['Los minutos van de 1 a 240.'],
            ]);
        $this->putJson('/api/calendar/item-plan', ['items' => [['item_key' => 'rule:no_existe', 'minutes' => 5]]])
            ->assertStatus(422)->assertJsonPath('errors', ['items.0.item_key' => ['Eso ya no está en el calendario. Actualiza la página.']]);
        $this->putJson('/api/calendar/order', ['priority' => 'normal', 'item_keys' => ['expense:*']])->assertStatus(422);
        $this->postJson('/api/calendar/notes', ['title' => 'X y', 'date' => '2026-11-06', 'minutes' => 999])->assertStatus(422)->assertJsonValidationErrors('minutes');
        expect(DB::table('calendar_item_plans')->count())->toBe(1);
    });

    it('solo el Admin', function () {
        Sanctum::actingAs(tuDiaRol('Gerente'));

        $this->putJson('/api/calendar/item-plan', ['items' => [['item_key' => 'rule:rate', 'minutes' => 5]]])->assertStatus(403);
        $this->putJson('/api/calendar/order', ['priority' => 'alta', 'item_keys' => ['rule:rate']])->assertStatus(403);
        $this->deleteJson('/api/calendar/order')->assertStatus(403);
    });
});
