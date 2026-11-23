<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.175` (dueño, 2026-11-23: "la meta mínima diaria por editor son 2 batchs al día. Si resulta que terminan esos batchs antes
 * de su horario laboral…"): el horario de cada editor (el del líder lo pone el dueño; el de sus editores, el líder) y los días
 * que no trabajó por permiso (no cuentan para la meta). Solo agrega tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('days');                      // los días que trabaja: 0 = domingo … 6 = sábado
            $table->string('start_time', 5);           // "08:00", hora de Venezuela
            $table->string('end_time', 5);             // "17:00"
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('editor_days_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('note', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_days_off');
        Schema::dropIfExists('editor_schedules');
    }
};
