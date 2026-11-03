<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.92` (dueño, 2026-11-03: "la mayoría de los creativos que nosotros lanzamos vienen de una referencia ya probada en la
 * biblioteca de anuncios de Meta"): la biblioteca de referencias que suben los editores (el video, el link de donde salió,
 * el producto y el ángulo o "cualquier producto", el tipo de video y por qué la eligieron), y de dónde salió cada pieza
 * (una referencia, idea propia o la iteración de un ganador). Solo agrega: una tabla y dos columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();                           // REF0001
            $table->string('link', 1000);                                          // de donde salió (cualquier sitio)
            $table->string('link_key', 64)->index();                               // el link normalizado (para avisar si ya está)
            $table->string('type', 20);                                            // ugc, testimonial, demo, before_after, animated, other
            $table->string('why', 500);                                            // por qué la eligió
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete(); // null = cualquier producto
            $table->string('angle_code', 12)->nullable();
            $table->foreignId('file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();                          // "Quitar" (las piezas que la usaron la siguen mostrando)
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('creative_batch_pieces', function (Blueprint $table) {
            $table->string('source', 12)->nullable()->after('file_id');            // reference, own (idea propia) o iteration
            $table->foreignId('source_reference_id')->nullable()->after('source')->constrained('creative_references')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creative_batch_pieces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_reference_id');
            $table->dropColumn('source');
        });
        Schema::dropIfExists('creative_references');
    }
};
