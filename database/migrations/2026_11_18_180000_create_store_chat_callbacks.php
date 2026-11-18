<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.161` (dueño, 2026-11-18: "si algo no puede responder, le pida el número de WhatsApp al cliente y le diga que una
 * asesora humana le responderá esa duda, y automáticamente me muestra a mí en mi panel lo que el cliente está preguntando y
 * su número de teléfono para contactarlo… más adelante deleguemos con alguna vendedora o la líder de ventas"): los clientes
 * por contactar ("Por contactar" y el calendario) y, en cada conversación, si espera el WhatsApp. No borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_chat_callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('store_chat_conversations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('question');                       // lo que no supo responder (con los datos tapados)
            $table->string('phone', 30);                    // el WhatsApp que dejó el cliente, para escribirle
            $table->string('source', 10)->default('store'); // store (la tienda) o test (el dueño probando)
            $table->string('status', 10)->default('pending'); // pending (por contactar) o done (ya lo contactaron)
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // más adelante: una vendedora o la líder
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('store_chat_conversations', function (Blueprint $table) {
            $table->boolean('awaiting_phone')->default(false); // le pidió el WhatsApp y todavía no lo dio
            $table->text('awaiting_question')->nullable();     // lo que no supo responder
        });
    }

    public function down(): void
    {
        Schema::table('store_chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['awaiting_phone', 'awaiting_question']);
        });
        Schema::dropIfExists('store_chat_callbacks');
    }
};
