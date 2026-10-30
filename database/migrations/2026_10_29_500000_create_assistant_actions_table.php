<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Lo que hizo el asistente" (Etapa 4, paso 5): cada cambio que propone el asistente del dueño es una fila. Nace
 * "proposed" (la tarjeta con Confirmar y Cancelar) y termina done, failed, cancelled o expired (vence a las 2 horas).
 * - args: lo que pidió la IA; card: lo que vio el dueño (título, líneas, avisos); fingerprint: para saber, al
 *   confirmar, si algo cambió desde la propuesta (entonces vence y se propone de nuevo).
 * - Es permanente: "Limpiar chat" no la toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            $table->string('tool', 60);
            $table->json('args');
            $table->string('summary', 300);
            $table->json('card');
            $table->string('fingerprint', 64);
            $table->string('status', 12)->default('proposed');  // proposed | done | failed | cancelled | expired
            $table->json('result')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
    }
};
