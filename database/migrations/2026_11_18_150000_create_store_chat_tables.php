<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.158` (dueño, 2026-11-18): el chat con IA de la tienda. Cada conversación es de un visitante (un número al azar de su
 * navegador, no una persona) en la página de un producto; cada mensaje guarda la pregunta, lo que se respondió y si no lo
 * sabía o se bloqueó (y lo que la IA había escrito, para que el dueño vea por qué). No borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('visitor', 40);                  // el número al azar del navegador
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('source', 10)->default('store'); // store (la tienda) o test (el dueño probando)
            $table->string('ip_hash', 64)->nullable();       // para los topes, sin guardar la IP
            $table->unsignedInteger('questions')->default(0);
            $table->boolean('unanswered')->default(false);   // alguna pregunta que no supo responder
            $table->timestamps();
            $table->index(['visitor', 'product_id']);
            $table->index('created_at');
        });

        Schema::create('store_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('store_chat_conversations')->cascadeOnDelete();
            $table->text('question');                        // con los teléfonos y correos tapados
            $table->text('answer')->nullable();              // lo que vio el cliente
            $table->string('status', 12);                    // answered, unknown, blocked, failed, limit
            $table->string('blocked_reason', 160)->nullable();
            $table->text('raw_answer')->nullable();          // lo que escribió la IA cuando se bloqueó
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_chat_messages');
        Schema::dropIfExists('store_chat_conversations');
    }
};
