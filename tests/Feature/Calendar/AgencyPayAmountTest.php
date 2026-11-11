<?php

use App\Services\Calendar\Readers\ReminderFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .135 (dueño, 2026-11-11, con Dalgo, PYP y Rapi2 en "Pendiente de archivo": "por que no aparece cuanto le tengo que pagar
// a cada agencia?"): sin archivo, la misión "Pagar a las agencias" muestra lo que ganó cada una por sus carreras esa semana
// (el provisional de Finanzas › Agencias); con archivo o aprobado, lo de siempre.

beforeEach(function () {
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->timestamps();
    });
    Schema::create('agency_weekly_closures', function ($t) {
        $t->id();
        $t->unsignedBigInteger('agency_id');
        $t->date('period_start');
        $t->date('period_end');
        $t->string('status', 24)->default('pendiente_archivo');
        $t->decimal('expected_total', 12, 2)->nullable();
        $t->decimal('reported_total', 12, 2)->nullable();
        $t->decimal('final_approved_total', 12, 2)->nullable();
        $t->timestamps();
    });
    Schema::create('earnings', function ($t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('role_type');
        $t->decimal('amount_usd', 10, 2);
        $t->date('earning_date');
        $t->timestamps();
    });
    DB::table('users')->insert([['id' => 1, 'names' => 'Dalgo'], ['id' => 2, 'names' => 'PYP'], ['id' => 3, 'names' => 'Rapi2']]);
});

it('sin archivo, el monto es lo que ganó la agencia por sus carreras de esa semana', function () {
    DB::table('agency_weekly_closures')->insert([
        ['agency_id' => 1, 'period_start' => '2026-11-02', 'period_end' => '2026-11-08', 'status' => 'pendiente_archivo', 'reported_total' => null],
        ['agency_id' => 2, 'period_start' => '2026-11-02', 'period_end' => '2026-11-08', 'status' => 'pendiente_revision', 'reported_total' => 70],
        ['agency_id' => 3, 'period_start' => '2026-11-02', 'period_end' => '2026-11-08', 'status' => 'pendiente_archivo', 'reported_total' => null],
    ]);
    DB::table('earnings')->insert([
        ['user_id' => 1, 'role_type' => 'agencia', 'amount_usd' => 30, 'earning_date' => '2026-11-03'],
        ['user_id' => 1, 'role_type' => 'agencia', 'amount_usd' => 12.5, 'earning_date' => '2026-11-08'],
        // fuera de la semana, o no es de la agencia
        ['user_id' => 1, 'role_type' => 'agencia', 'amount_usd' => 99, 'earning_date' => '2026-11-09'],
        ['user_id' => 1, 'role_type' => 'repartidor', 'amount_usd' => 99, 'earning_date' => '2026-11-04'],
        ['user_id' => 2, 'role_type' => 'agencia', 'amount_usd' => 60, 'earning_date' => '2026-11-04'],
    ]);

    $rows = collect(app(ReminderFacts::class)->weeks([], ['2026-11-02'])['closures']['2026-11-02'])->keyBy('agency');

    expect($rows['Dalgo'])->toMatchArray(['amount' => 42.5, 'estimated' => true])
        // con archivo: lo que cobra la agencia, como siempre
        ->and($rows['PYP'])->toMatchArray(['amount' => 70.0, 'estimated' => false])
        // sin carreras esa semana: $0
        ->and($rows['Rapi2'])->toMatchArray(['amount' => 0.0, 'estimated' => true]);
});
