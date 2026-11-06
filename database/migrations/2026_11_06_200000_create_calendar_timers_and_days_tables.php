<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.108` "Tu día": el reloj de cada tarea y el resumen de cada día.
 * - calendar_timers: un reloj por aparición (item_key y el día que le tocaba). minutes = lo que debía tomar al empezar;
 *   extra_minutes = los "+5 min"; spent_seconds = lo que ya corrió antes de la vuelta de ahora; started_at = desde cuándo
 *   corre (null: en pausa). later_at = "Más tarde" (va al final de su etapa). Al hacerla: finished_at y on_time (si se
 *   hizo dentro de los minutos que tenía).
 * - calendar_days: cada día, cuántas había, cuántas se hicieron y cuántas a tiempo; all_done_at = cuándo quedó todo hecho
 *   (para la racha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_timers', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 60);
            $table->date('occurs_on');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedSmallInteger('extra_minutes')->default(0);
            $table->unsignedInteger('spent_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('later_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('on_time')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_key', 'occurs_on']);
            $table->index('finished_at');
        });

        Schema::create('calendar_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedSmallInteger('total')->default(0);
            $table->unsignedSmallInteger('done')->default(0);
            $table->unsignedSmallInteger('timed')->default(0);
            $table->unsignedSmallInteger('on_time')->default(0);
            $table->timestamp('all_done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_days');
        Schema::dropIfExists('calendar_timers');
    }
};
