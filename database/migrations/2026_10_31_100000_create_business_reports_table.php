<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los reportes del negocio del asistente (`.48b`, 2026-10-31): diario (los últimos 3 días), semanal (la semana pasada) y
 * mensual (el mes pasado), en los días de las revisiones del calendario. Los números son del panel del dueño y la revisión
 * de la 48a; el texto lo escribe la IA (o una plantilla). "Volver a generar" crea otra versión del mismo reporte.
 * Solo agrega una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_reports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                   // daily, weekly, monthly
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12);                 // ok, example (sin IA en esta PC), no_ai (servidor sin clave), error
            $table->json('facts');                        // los números del panel y la revisión, como los vio la IA
            $table->json('text')->nullable();             // lo que escribió la IA (o la plantilla)
            $table->json('warnings')->nullable();         // montos o porcentajes que la IA escribió y no están en los datos
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete(); // vacío = el automático
            $table->timestamp('viewed_at')->nullable();   // la primera vez que lo abrió el dueño (48b-2: tilda la revisión)
            $table->foreignId('viewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'period_from', 'period_to', 'version']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_reports');
    }
};
