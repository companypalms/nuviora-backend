<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El análisis de campañas de cada mañana (Etapa 5, paso 3a, 2026-10-30): lo que dijeron las reglas del dueño (los
 * hechos), la lista urgente que arman esas reglas, el texto que escribe la IA y lo que el dueño marcó como hecho o con lo
 * que no estuvo de acuerdo. "Volver a analizar" crea otra versión del mismo día.
 * Solo agrega una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_analyses', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12);                 // ok, example (sin IA en esta PC), no_ai (servidor sin clave), error
            $table->json('facts');                        // lo que dijeron las reglas de cada producto
            $table->json('urgent');                       // la lista de lo urgente a lo menos urgente (la arman las reglas)
            $table->json('text')->nullable();             // lo que escribió la IA (o la plantilla)
            $table->json('warnings')->nullable();         // montos que la IA escribió y no están en los datos
            $table->json('marks')->nullable();            // hecho / no estoy de acuerdo, por punto de la lista
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete(); // vacío = la de las 07:00
            $table->timestamp('viewed_at')->nullable();   // la primera vez que lo abrió el dueño (tilda la tarea del calendario)
            $table->foreignId('viewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['date', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_analyses');
    }
};
