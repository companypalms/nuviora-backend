<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El calendario del dueño (Etapa 3): sus notas y lo que hizo con cada cosa del calendario.
 * - calendar_notes: lo que anota el dueño (y, más adelante, el asistente de IA): una tarea o un pago, de un día o
 *   repetido (cada semana, cada mes, o el 15 y el último). No se borra: se archiva.
 * - calendar_marks: qué se hizo con cada aparición ("hecho" o "descartado"), de una nota ("note:12") o de un recordatorio
 *   del sistema ("rule:sellers_pay", "expense:5"). La fecha es el día que le tocaba de verdad (antes de correrla por un
 *   domingo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('details')->nullable();
            $table->date('date');
            $table->time('time')->nullable();
            $table->string('kind', 12)->default('task');      // task | payment
            $table->string('repeat', 20)->default('none');    // none | weekly | monthly | twice_monthly
            $table->date('repeat_until')->nullable();
            $table->string('source', 12)->default('owner');   // owner | assistant
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['archived_at', 'date']);
        });

        Schema::create('calendar_marks', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 60);
            $table->date('occurs_on');
            $table->string('status', 12);                     // done | dismissed
            $table->string('note', 250)->nullable();
            $table->string('source', 12)->default('owner');   // owner | assistant
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_key', 'occurs_on']);
            $table->index('occurs_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_marks');
        Schema::dropIfExists('calendar_notes');
    }
};
