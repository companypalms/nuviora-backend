<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.66` (dueño, 2026-11-01): "la IA debe ser capaz de aprender de cada hipótesis positiva o negativa que estamos
 * probando… sabiendo qué funciona y qué no, tanto en ángulos, formatos, conceptos, buyers personas". La memoria de cada
 * producto ("Lo que sabemos"), solo para el dueño:
 * - creative_batches.style: el estilo del encargo (testimonio, video animado, venta directa…), para aprender por formato;
 * - product_memories: el resumen de la IA (lo que funciona, lo que no, lo que falta probar) y la huella de los datos con
 *   que se escribió (si cambian, se vuelve a escribir);
 * - product_notes: las notas del dueño (lo que él sabe y el sistema no ve). Borrar una nota la archiva.
 * Solo agrega una columna y dos tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->string('style', 24)->nullable()->after('format');
        });

        Schema::create('product_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->json('summary')->nullable();          // {works: [], fails: [], untested: []}
            $table->string('facts_hash', 64)->nullable(); // los datos con que se escribió
            $table->string('status', 12)->nullable();     // ok, example, error
            $table->string('model', 60)->nullable();
            $table->text('error')->nullable();
            $table->json('unknown_numbers')->nullable();  // números que escribió la IA y no estaban en los datos
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('kind', 12)->default('idea');  // works (funciona), fails (no funciona), idea (para probar)
            $table->text('text');
            $table->string('source', 12)->default('owner'); // owner (pantalla) o assistant (tarjeta)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_notes');
        Schema::dropIfExists('product_memories');
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropColumn('style');
        });
    }
};
