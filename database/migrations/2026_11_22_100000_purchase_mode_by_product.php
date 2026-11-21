<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `.171` (dueño, 2026-11-21: "me parece muy complicado esas pruebas… hay que buscar una manera más sencilla sin duplicar
 * campañas"): "Purchase según cómo paga el cliente" se prende por producto, para todas sus campañas (también las que se
 * creen después). Una fila sin campaña es el producto entero; las de campaña (`.168`) quedan como historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_purchase_modes', function (Blueprint $table) {
            $table->string('campaign_meta_id', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('meta_purchase_modes')->whereNull('campaign_meta_id')->delete();
        Schema::table('meta_purchase_modes', function (Blueprint $table) {
            $table->string('campaign_meta_id', 32)->nullable(false)->change();
        });
    }
};
