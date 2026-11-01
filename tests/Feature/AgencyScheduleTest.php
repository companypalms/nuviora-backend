<?php

use App\Models\User;
use App\Services\Agencies\AgencyRouter;
use App\Services\Agencies\AgencySwitch;
use App\Services\Agencies\WorkingHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// Horario de cada agencia por día de la semana (dueño, 2026-11-01), en una base en memoria (no toca la base local).
// Noviembre 2026: lunes 2, sábado 7, domingo 8.

beforeEach(function () {
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('phone')->default('');
        $t->string('address')->default('');
        $t->string('email')->unique();
        $t->string('password');
        $t->boolean('agency_is_working')->nullable();
        $t->time('agency_works_from')->nullable();
        $t->time('agency_works_until')->nullable();
        $t->json('agency_schedule')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
});

function agencia(array $attrs = []): User
{
    $u = new User();
    $u->forceFill(['names' => 'Agencia', 'email' => uniqid() . '@test', 'password' => 'x', 'agency_is_working' => true] + $attrs)->save();

    return $u->fresh();
}

const CARACAS = [1 => ['09:00', '19:00'], 2 => ['09:00', '19:00'], 3 => ['09:00', '19:00'], 4 => ['09:00', '19:00'], 5 => ['09:00', '19:00'], 6 => ['09:00', '17:00'], 7 => null];

it('respeta el horario de cada día y los días que no trabaja', function () {
    $hours = new WorkingHours(new AgencyRouter());
    $caracas = agencia(['agency_schedule' => CARACAS]);

    expect($hours->agencyStop($caracas, Carbon::parse('2026-11-02 10:00')))->toBeNull()
        ->and($hours->agencyStop($caracas, Carbon::parse('2026-11-02 08:30')))->toBe('los lunes trabaja de 09:00 a 19:00')
        ->and($hours->agencyStop($caracas, Carbon::parse('2026-11-07 16:59')))->toBeNull()
        ->and($hours->agencyStop($caracas, Carbon::parse('2026-11-07 17:30')))->toBe('los sábados trabaja de 09:00 a 17:00')
        ->and($hours->agencyStop($caracas, Carbon::parse('2026-11-08 12:00')))->toBe('no trabaja los domingos');

    $caracas->forceFill(['agency_is_working' => false])->save();
    expect($hours->agencyStop($caracas->fresh(), Carbon::parse('2026-11-02 10:00')))->toBe('está apagada');
});

it('sin horario por día funciona como antes (el mismo desde/hasta todos los días)', function () {
    $hours = new WorkingHours(new AgencyRouter());
    $maracay = agencia(['agency_works_from' => '08:00', 'agency_works_until' => '17:00']);

    expect($hours->agencyStop($maracay, Carbon::parse('2026-11-02 17:30')))->toBe('trabaja de 08:00 a 17:00')
        ->and($hours->agencyStop($maracay, Carbon::parse('2026-11-08 10:00')))->toBeNull()
        ->and($hours->agencyStop(agencia(), Carbon::parse('2026-11-08 03:00')))->toBeNull();
});

it('entrega el horario de la semana para n8n', function () {
    expect(WorkingHours::weekSchedule(agencia()))->toBeNull();

    $legacy = WorkingHours::weekSchedule(agencia(['agency_works_from' => '08:00:00', 'agency_works_until' => '17:00:00']));
    expect($legacy)->toHaveCount(7)->and($legacy[7])->toBe(['08:00', '17:00']);

    $week = WorkingHours::weekSchedule(agencia(['agency_schedule' => CARACAS]));
    expect($week[1])->toBe(['09:00', '19:00'])->and($week[6])->toBe(['09:00', '17:00'])->and($week[7])->toBeNull();
    // En JSON los días quedan como "1" … "7" (lo que lee n8n)
    expect(json_encode($week))->toStartWith('{"1":["09:00","19:00"]');
});

it('describe el horario en palabras, juntando los días iguales', function () {
    expect(WorkingHours::scheduleLabel(CARACAS))->toBe('lun a vie de 09:00 a 19:00 · sáb de 09:00 a 17:00 · dom no trabaja')
        ->and(WorkingHours::scheduleLabel([1 => ['08:00', '17:00'], 2 => ['08:00', '17:00'], 3 => ['08:00', '17:00'], 4 => ['08:00', '17:00'], 5 => ['08:00', '17:00'], 6 => ['08:00', '17:00']]))
        ->toBe('lun a sáb de 08:00 a 17:00 · dom no trabaja')
        ->and(WorkingHours::scheduleLabel([6 => ['08:00', '12:00'], 7 => ['08:00', '12:00']]))->toBe('lun a vie no trabaja · sáb y dom de 08:00 a 12:00')
        ->and(WorkingHours::agencyHoursLabel(agencia()))->toBeNull();
});

it('limpia y revisa el horario por día', function () {
    $clean = AgencySwitch::schedule(true, ['1' => ['09:00', '19:00'], '6' => ['09:00', '17:00'], '7' => null]);
    expect($clean['schedule'])->toHaveCount(7)
        ->and($clean['schedule'][2])->toBeNull()
        ->and($clean['schedule'][6])->toBe(['09:00', '17:00'])
        ->and($clean['works_from'])->toBeNull();

    expect(fn () => AgencySwitch::schedule(true, [1 => ['09:00', '09:00']]))->toThrow(ValidationException::class);
    expect(fn () => AgencySwitch::schedule(true, [7 => null]))->toThrow(ValidationException::class);
});

it('guarda el horario por día y no lo pisa al solo encender o apagar', function () {
    $switch = new AgencySwitch();
    $agency = agencia(['agency_works_from' => '08:00', 'agency_works_until' => '17:00']);

    $switch->setAgency($agency, AgencySwitch::schedule(true, CARACAS), null);
    $agency = $agency->fresh();
    expect($agency->agency_schedule[6])->toBe(['09:00', '17:00'])->and($agency->agency_works_from)->toBeNull();

    // Encender/apagar sin "schedule" (lo que hace el asistente): el horario por día queda
    $switch->setAgency($agency, ['is_working' => false, 'works_from' => null, 'works_until' => null], null);
    expect($agency->fresh()->agency_schedule[6])->toBe(['09:00', '17:00'])->and($agency->fresh()->agency_is_working)->toBeFalse();

    // Volver a un solo horario para todos los días borra el de cada día
    $switch->setAgency($agency, AgencySwitch::hours(true, '08:00', '19:00') + ['schedule' => null], null);
    expect($agency->fresh()->agency_schedule)->toBeNull();
});
