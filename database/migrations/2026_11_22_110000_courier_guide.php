<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.173` (dueño, 2026-11-21): "la guía no debe ser por número, sino una foto a la guía, que debe tomar la agencia encargada
 * de llevar el pedido a la agencia del envío… no se puede marcar como entregado hasta que esa foto de la guía esté subida".
 * n8n se entera de cada foto nueva (evento `order.courier_guide`) y se la manda al cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_guide_photo')->nullable()->after('courier_office');
            $table->timestamp('courier_guide_at')->nullable()->after('courier_guide_photo');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_guide_photo', 'courier_guide_at']);
        });
    }
};
