<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avales y pruebas reales de cada producto (Etapa 5, paso 4b, 2026-10-30): el registro sanitario, un médico que de
 * verdad lo recomienda, testimonios reales… Los escribe el dueño y la IA los usa tal cual en los encargos: nunca inventa
 * otros. Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_ad_settings', function (Blueprint $table) {
            $table->text('proof')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('product_ad_settings', function (Blueprint $table) {
            $table->dropColumn('proof');
        });
    }
};
