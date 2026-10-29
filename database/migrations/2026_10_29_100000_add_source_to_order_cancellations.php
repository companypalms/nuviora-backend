<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivos de cancelación (dueño, 2026-10-29): toda cancelación queda en order_cancellations con su motivo, para
 * analizarlos después. Las que hace el sistema (cierre de la jornada) no tienen usuario: source = 'system'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->string('source', 10)->default('user')->after('reason'); // 'user' o 'system'
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // user_id queda nullable: volver a NOT NULL fallaría con las cancelaciones del sistema ya guardadas,
        // y el código anterior funciona igual con la columna nullable.
        Schema::table('order_cancellations', fn (Blueprint $table) => $table->dropColumn('source'));
    }
};
