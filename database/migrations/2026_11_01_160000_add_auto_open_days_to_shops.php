<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los días en que la tienda abre sola (dueño, 2026-11-01: "que podamos apagar los domingos"): [1, …, 6] con
     * 1 = lunes … 7 = domingo. Vacío (null) = todos los días, como antes. Ver Shop::opensOn y CheckShopSchedule.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('auto_open_days')->nullable()->after('auto_schedule_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('auto_open_days');
        });
    }
};
