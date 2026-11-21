<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.173` (dueño, 2026-11-21): el número de guía de MRW o Zoom de una encomienda lo escribe la vendedora en el pedido; n8n
 * se entera (evento `order.courier_guide`) y le avisa al cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_guide', 40)->nullable()->after('courier_office');
            $table->timestamp('courier_guide_at')->nullable()->after('courier_guide');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_guide', 'courier_guide_at']);
        });
    }
};
