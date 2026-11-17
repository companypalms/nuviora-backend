<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.154` (dueño, 2026-11-17: "quiero tener una banca de productos por montar… y el sistema automáticamente se lo vaya
 * pasando al editor líder… por orden de cola"): el lugar de cada producto en la cola (fase "queued") y cuándo salió de ella
 * al editor líder. No borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_profiles', function (Blueprint $table) {
            $table->unsignedInteger('queue_position')->nullable()->after('testing_round'); // su lugar en la cola (1 = el siguiente)
            $table->timestamp('released_at')->nullable()->after('phase_at');               // cuándo salió de la cola al editor líder
        });
    }

    public function down(): void
    {
        Schema::table('product_profiles', function (Blueprint $table) {
            $table->dropColumn(['queue_position', 'released_at']);
        });
    }
};
