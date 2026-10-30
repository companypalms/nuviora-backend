<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desactivar y reactivar usuarios (Etapa 4, paso 6). "Eliminar" una cuenta ahora es desactivarla: ya no entra ni recibe
 * trabajo, pero su historial queda y se puede reactivar. No se usa el estado viejo (status_id), que está a medias:
 * muchos usuarios no lo tienen.
 * - users.deactivated_at / deactivated_by / deactivation_reason: si está desactivada, desde cuándo, quién y por qué.
 * - user_account_changes: cada desactivación y reactivación, para siempre, con lo que tenía pendiente en ese momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 300)->nullable();
        });

        Schema::create('user_account_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 20); // deactivated | reactivated
            $table->string('reason', 300)->nullable();
            $table->json('details')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_changes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });
    }
};
