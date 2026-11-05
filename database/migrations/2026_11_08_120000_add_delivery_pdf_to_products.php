<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.106` (dueño, 2026-11-05): el PDF que recibe por WhatsApp el cliente de un producto (ej. la guía de uso) 10 minutos
 * después de que su pedido se marca Entregado, solo con la ventana de 24 horas abierta y una vez por cliente y producto.
 * `delivery_pdf_since`: solo los pedidos entregados desde que se subió el PDF (a los clientes viejos no se les manda).
 * `delivery_pdf_sends`: un registro por cliente y producto (enviado, o fallido con sus intentos). Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('delivery_pdf_path')->nullable();
            $table->string('delivery_pdf_name')->nullable();
            $table->text('delivery_pdf_caption')->nullable();
            $table->timestamp('delivery_pdf_since')->nullable();
        });

        Schema::create('delivery_pdf_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10); // sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_pdf_sends');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['delivery_pdf_path', 'delivery_pdf_name', 'delivery_pdf_caption', 'delivery_pdf_since']);
        });
    }
};
