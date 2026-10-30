<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los estados automáticos de los creativos (Etapa 5, paso 2a, 2026-10-30): el dueño decidió que los ponga el sistema con
 * sus reglas (Winner, Fatigued, Loser, Archived, Testing) y que el que él ponga a mano no se pise. status_source dice
 * quién lo puso (auto o manual) y status_reason, por qué.
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_creatives', function (Blueprint $table) {
            $table->string('status_source', 8)->nullable()->after('status');
            $table->string('status_reason')->nullable()->after('status_source');
            $table->timestamp('status_auto_at')->nullable()->after('status_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('meta_creatives', function (Blueprint $table) {
            $table->dropColumn(['status_source', 'status_reason', 'status_auto_at']);
        });
    }
};
