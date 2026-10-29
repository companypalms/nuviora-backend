<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El chat del asistente del dueño (Etapa 4): un solo chat por persona. Cada pregunta y cada respuesta es una fila.
 * - La respuesta nace "pensando" (thinking) y se completa después de contestarle al navegador: "progress" dice qué está
 *   revisando y "status" termina en done o failed.
 * - "display" son las tarjetas, gráficas y tablas que se ven debajo de la respuesta; salen de los datos del sistema.
 * - "Limpiar chat" no borra: pone cleared_at (así el límite de mensajes del día no se reinicia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 12);                       // user | assistant
            $table->text('text')->nullable();
            $table->string('status', 12)->default('done');    // thinking | done | failed
            $table->string('progress', 150)->nullable();
            $table->json('display')->nullable();
            $table->json('attachments')->nullable();
            $table->json('tool_calls')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
            $table->string('client_id', 40)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'cleared_at', 'id']);
            $table->index(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
