<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Solicitar ajuste" del operador (§16.2.3, §29 punto 22; idea 3 de Fran): el operador no ajusta el stock, propone. La
 * solicitud dice qué producto (y variante), cuántas piezas sobran o faltan, por qué, y puede llevar una foto. No se aplica
 * hasta que administración la aprueba; entonces se registra el movimiento de inventario con el ajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->integer('quantity'); // + sobran piezas (entran), - faltan (salen)
            $table->string('reason', 250);
            $table->string('photo_path')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status', 20)->default('pendiente'); // pendiente | aprobada | rechazada
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 250)->nullable();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['center_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_requests');
    }
};
