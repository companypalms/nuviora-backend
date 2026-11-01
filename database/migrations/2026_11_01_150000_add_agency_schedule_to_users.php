<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El horario de cada agencia por día de la semana (dueño, 2026-11-01: "Caracas trabaja de 9 a 7 de lunes a viernes,
     * los sábados hasta las 5"; ninguna agencia trabaja los domingos). {"1": ["09:00", "19:00"], ..., "6": ["09:00",
     * "17:00"]}: 1 = lunes … 7 = domingo; un día que falta o es null no trabaja. Vacío (null) = como antes: el horario
     * "desde/hasta" de todos los días (agency_works_from/until). Ver WorkingHours.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('agency_schedule')->nullable()->after('agency_works_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agency_schedule');
        });
    }
};
