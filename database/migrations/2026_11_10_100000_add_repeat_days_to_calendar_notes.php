<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.129` (dueño, 2026-11-10: "más opciones de cuándo quiero que se repita: si todos los días, si dos días a la semana, si en
 * un rango de fechas"): en una nota que se repite, los días de la semana que marcó ([2, 4] = martes y jueves) y cada cuánto
 * (cada 1 a 4 semanas, o cada 2 a 60 días). Las notas que ya existen quedan con las dos en null y siguen igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_notes', function (Blueprint $table) {
            $table->string('repeat_days', 20)->nullable()->after('repeat');               // [2,4]: ISO, 1 = lunes … 7 = domingo
            $table->unsignedTinyInteger('repeat_every')->nullable()->after('repeat_days'); // weekly: semanas; every_days: días
        });
    }

    public function down(): void
    {
        Schema::table('calendar_notes', function (Blueprint $table) {
            $table->dropColumn(['repeat_days', 'repeat_every']);
        });
    }
};
