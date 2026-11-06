<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\Tools\CalendarMarkTool;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Readers\ReminderFacts;
use App\Services\Calendar\Rules\ReminderRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// `.109` (dueño, 2026-11-06: "arregla lo del asistente con los domingos"): lo que cae domingo se muestra el sábado (un
// pago) o el lunes (una tarea). El asistente lo encuentra por el día que le tocaba o por el que se ve, y lo marca en el que
// le tocaba (como la pantalla). En una base en memoria; los recordatorios del sistema apagados.

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
    $calendar = app(Calendar::class);
    // Cada semana desde un domingo: el pago se adelanta al sábado; la tarea pasa al lunes
    $this->pago = 'note:' . $calendar->createNote(['title' => 'Pagar a los editores', 'date' => '2026-11-15', 'kind' => 'payment', 'repeat' => 'weekly'], $this->admin)->id;
    $this->tarea = 'note:' . $calendar->createNote(['title' => 'Revisar el depósito', 'date' => '2026-11-08', 'kind' => 'task', 'repeat' => 'weekly'], $this->admin)->id;
    $this->tool = app(CalendarMarkTool::class);
    $this->ctx = new ActionContext($this->admin, null, [], CarbonImmutable::now());
});

$args = fn (array $over) => $over + ['clave' => null, 'texto' => null, 'estado' => 'hecho', 'nota' => null];

describe('El asistente marca lo que se corrió por un domingo', function () use ($args) {
    it('un pago del domingo 15: lo encuentra por su día y por el sábado 14 en que se muestra', function () use ($args) {
        foreach (['2026-11-15', '2026-11-14'] as $fecha) {
            $p = $this->tool->propose($args(['clave' => $this->pago, 'fecha' => $fecha]), $this->ctx);
            expect($p->payload)->toMatchArray(['item_key' => $this->pago, 'due' => '2026-11-15', 'status' => 'done', 'title' => 'Pagar a los editores']);
        }
    });

    it('una tarea del domingo 8: la encuentra por su día y por el lunes 9 en que se muestra, también por sus palabras', function () use ($args) {
        foreach (['2026-11-08', '2026-11-09'] as $fecha) {
            expect($this->tool->propose($args(['clave' => $this->tarea, 'fecha' => $fecha]), $this->ctx)->payload)->toMatchArray(['item_key' => $this->tarea, 'due' => '2026-11-08']);
        }
        expect($this->tool->propose($args(['texto' => 'deposito', 'fecha' => '2026-11-08']), $this->ctx)->payload['item_key'])->toBe($this->tarea);
    });

    it('lo marca en el día que le tocaba, como la pantalla', function () use ($args) {
        $p = $this->tool->propose($args(['clave' => $this->pago, 'fecha' => '2026-11-14', 'nota' => 'pagado por Zelle']), $this->ctx);
        $this->tool->execute($p->payload, $this->ctx);

        $mark = DB::table('calendar_marks')->first();
        expect((array) $mark)->toMatchArray(['item_key' => $this->pago, 'status' => 'done', 'note' => 'pagado por Zelle', 'source' => 'assistant']);
        expect(substr($mark->occurs_on, 0, 10))->toBe('2026-11-15');
        expect(fn () => $this->tool->propose($args(['clave' => $this->pago, 'fecha' => '2026-11-15']), $this->ctx))->toThrow(ToolProblem::class, 'Eso ya está hecho.');
    });

    it('lo de los días de al lado no se confunde: el lunes 16 no tiene el pago del domingo 15', function () use ($args) {
        expect(fn () => $this->tool->propose($args(['clave' => $this->pago, 'fecha' => '2026-11-16']), $this->ctx))
            ->toThrow(ToolProblem::class, 'Esa clave no está ese día.');
        expect(fn () => $this->tool->propose($args(['clave' => $this->pago, 'fecha' => '2026-11-20']), $this->ctx))
            ->toThrow(ToolProblem::class, 'Ese día no hay nada en el calendario.');
    });
});
