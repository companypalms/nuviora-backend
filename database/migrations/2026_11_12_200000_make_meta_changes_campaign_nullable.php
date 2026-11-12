<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `.140` (dueño, 2026-11-12: después de bajar el precio, "me dice si se cumplió la hipótesis o no… si al final por esa
 * decisión estamos ganando más dinero o no"): un cambio de precio es del producto entero, no de una campaña. El Historial de
 * cambios lo guarda sin campaña (vacío). Nada se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_changes', function (Blueprint $table) {
            $table->string('campaign_meta_id', 32)->nullable()->change(); // el índice se queda
        });
    }

    public function down(): void
    {
        // Solo se puede volver si no hay cambios sin campaña (los de precio); si los hay, la columna se queda como está
        if (DB::table('meta_changes')->whereNull('campaign_meta_id')->exists()) {
            return;
        }
        Schema::table('meta_changes', function (Blueprint $table) {
            $table->string('campaign_meta_id', 32)->nullable(false)->change();
        });
    }
};
