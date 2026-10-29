<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo de la lista al cancelar (dueño, 2026-10-29): quien cancela elige un motivo de una lista corta, además de
 * escribirlo (Orders\Cancellations::CATEGORIES). Así se pueden agrupar en el panel del dueño (Pérdidas). Solo agrega
 * una columna vacía: las cancelaciones de antes quedan sin motivo de la lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
