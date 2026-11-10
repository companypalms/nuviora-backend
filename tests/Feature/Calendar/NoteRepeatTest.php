<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\Tools\CalendarNoteTool;
use App\Services\Calendar\Readers\ReminderFacts;
use App\Services\Calendar\Rules\ReminderRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// `.129` (dueño, 2026-11-10: "más opciones de cuándo quiero que se repita: si todos los días, si dos días a la semana, si en
// un rango de fechas"). En una base en memoria; los recordatorios del sistema apagados. Hoy es el viernes 6/11/2026.

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
    (require database_path('migrations/2026_11_10_100000_add_repeat_days_to_calendar_notes.php'))->up();
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
    $this->note = fn (array $over) => $this->postJson('/api/calendar/notes', $over + ['title' => 'Revisar el depósito', 'kind' => 'task'])->assertCreated()->json('id');
    // Los días en que sale una nota en el mes de noviembre ("16 (15)": le tocaba el domingo 15 y se ve el lunes 16)
    $this->shown = function (int $id): array {
        $out = [];
        foreach ($this->getJson('/api/calendar?from=2026-11-01&to=2026-11-30')->assertOk()->json('days') as $date => $items) {
            foreach ($items as $i) {
                if ($i['item_key'] === "note:{$id}") {
                    $out[] = substr($date, 8) . ($i['due_date'] !== $date ? ' (' . substr($i['due_date'], 8) . ')' : '');
                }
            }
        }

        return $out;
    };
});

describe('Más formas de repetir una nota', function () {
    it('todos los días: de lunes a sábado, en un rango de fechas', function () {
        $id = ($this->note)(['date' => '2026-11-04', 'repeat' => 'daily', 'repeat_until' => '2026-11-12']);

        // el 4 y el 5 ya pasaron sin hacerse: no se acumulan, como las tareas de todos los días; el domingo 8 no sale
        expect(($this->shown)($id))->toBe(['06', '07', '09', '10', '11', '12']);
    });

    it('la de todos los días no se acumula: lo que no se hizo ayer no queda atrasado', function () {
        $daily = ($this->note)(['date' => '2026-11-02', 'repeat' => 'daily']);
        $weekly = ($this->note)(['title' => 'Pagar a los editores', 'kind' => 'payment', 'date' => '2026-11-03', 'repeat' => 'weekly', 'repeat_days' => [2]]);

        $today = $this->getJson('/api/calendar/today?fresh=1')->assertOk();
        expect(array_column($today->json('items'), 'item_key'))->toContain("note:{$daily}")
            ->and(array_column($today->json('overdue'), 'item_key'))->toBe(["note:{$weekly}"]);
    });

    it('algunos días de la semana: martes y jueves, o cada 2 semanas', function () {
        $twice = ($this->note)(['date' => '2026-11-03', 'repeat' => 'weekly', 'repeat_days' => [4, 2], 'repeat_until' => '2026-11-19']);
        $every2 = ($this->note)(['date' => '2026-11-11', 'repeat' => 'weekly', 'repeat_days' => [1, 5], 'repeat_every' => 2]);

        expect(($this->shown)($twice))->toBe(['03', '05', '10', '12', '17', '19'])
            // desde el miércoles 11: el viernes de esa semana, y después cada 2 semanas (nunca antes de la fecha)
            ->and(($this->shown)($every2))->toBe(['13', '23', '27']);
    });

    it('el domingo que marca el dueño se queda el domingo; cada cierto número de días se corre como siempre', function () {
        $sunday = ($this->note)(['kind' => 'payment', 'date' => '2026-11-01', 'repeat' => 'weekly', 'repeat_days' => [7]]);
        $every3 = ($this->note)(['date' => '2026-11-09', 'repeat' => 'every_days', 'repeat_every' => 3]);

        expect(($this->shown)($sunday))->toBe(['01', '08', '15', '22', '29'])
            ->and(($this->shown)($every3))->toBe(['09', '12', '16 (15)', '18', '21', '24', '27', '30']);
    });

    it('avisa qué falta en palabras simples', function () {
        $this->postJson('/api/calendar/notes', ['title' => 'Algo', 'kind' => 'task', 'date' => '2026-11-06', 'repeat' => 'weekly', 'repeat_days' => []])
            ->assertStatus(422)->assertJsonPath('errors.repeat_days.0', 'Marca al menos un día de la semana.');
        $this->postJson('/api/calendar/notes', ['title' => 'Algo', 'kind' => 'task', 'date' => '2026-11-06', 'repeat' => 'every_days', 'repeat_every' => 1])
            ->assertStatus(422)->assertJsonPath('errors.repeat_every.0', 'Escribe cada cuántos días: de 2 a 60.');
    });

    it('una nota de "cada semana" de antes sigue igual y se puede pasar a otros días', function () {
        $id = ($this->note)(['date' => '2026-11-05', 'repeat' => 'weekly']);
        expect(($this->shown)($id))->toBe(['05', '12', '19', '26']);

        $this->putJson("/api/calendar/notes/{$id}", ['title' => 'Revisar el depósito', 'kind' => 'task', 'date' => '2026-11-05', 'repeat' => 'weekly', 'repeat_days' => [1, 4]])
            ->assertOk();
        expect(($this->shown)($id))->toBe(['05', '09', '12', '16', '19', '23', '26', '30']);
    });

    it('el asistente entiende "los martes y jueves, cada 2 semanas" y "todos los días"', function () {
        $tool = app(CalendarNoteTool::class);
        $ctx = new ActionContext($this->admin, null, [], CarbonImmutable::now());
        $args = fn (array $over) => $over + ['titulo' => 'Revisar el depósito', 'fecha' => '2026-11-10', 'hora' => null, 'tipo' => 'tarea', 'repetir' => 'no',
            'dias_semana' => null, 'cada' => null, 'hasta' => null, 'detalles' => null, 'prioridad' => null, 'minutos' => null];

        $p = $tool->propose($args(['repetir' => 'semanal', 'dias_semana' => ['jueves', 'martes'], 'cada' => 2]), $ctx);
        expect($p->payload)->toMatchArray(['repeat' => 'weekly', 'repeat_days' => [2, 4], 'repeat_every' => 2])
            ->and(json_encode($p->lines, JSON_UNESCAPED_UNICODE))->toContain('Cada 2 semanas, los martes y jueves');
        expect($tool->propose($args(['repetir' => 'diario', 'hasta' => '2026-11-20']), $ctx)->payload)->toMatchArray(['repeat' => 'daily', 'repeat_until' => '2026-11-20']);
        expect($tool->propose($args(['repetir' => 'cada_n_dias', 'cada' => 3]), $ctx)->payload)->toMatchArray(['repeat' => 'every_days', 'repeat_every' => 3]);
    });
});
