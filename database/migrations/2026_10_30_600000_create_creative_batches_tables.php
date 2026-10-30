<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cola de producción y la banca de creativos (Etapa 5, paso 4a, 2026-10-30): cada encargo es un batch (un ad set de
 * Meta) con sus piezas (los anuncios). Al aprobarlo, el sistema le da un número único B#### y a cada pieza un C####, que
 * no se repiten entre productos. Nada de esto toca Meta: los nombres los pone el dueño.
 * Solo agrega dos tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable()->unique();    // B0123 = 123; se da al aprobar
            $table->unsignedInteger('position')->nullable();            // el orden en la cola
            $table->string('status', 16)->default('proposed');          // propuesto, aprobado, en producción, en revisión, en banca, en test, testeado, descartado
            $table->string('kind', 16)->default('iteration');           // iteración, concepto nuevo, ángulo nuevo, arriba del embudo
            $table->string('angle_code', 20)->nullable();
            $table->string('concept', 120)->nullable();
            $table->text('hypothesis')->nullable();
            $table->string('audience', 255)->nullable();                // el dolor o el público
            $table->foreignId('reference_creative_id')->nullable()->constrained('meta_creatives')->nullOnDelete();
            $table->foreignId('learning_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('format', 16)->nullable();                   // video, imagen o mixto
            $table->text('keep')->nullable();                           // qué se conserva
            $table->string('offer', 255)->nullable();
            $table->text('deliverables')->nullable();
            $table->unsignedTinyInteger('urgency')->nullable();         // U1 a U5 cuando se pidió
            $table->date('due_date')->nullable();
            $table->string('source', 12)->default('owner');             // owner (a mano) o rules (propuesta de las reglas, paso 4b)
            $table->date('proposed_on')->nullable();                    // el día de la propuesta (las de las reglas se reemplazan al día siguiente)
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_at')->nullable();
            $table->foreignId('status_by')->nullable()->constrained('users')->nullOnDelete(); // vacío = lo puso el sistema
            $table->string('discard_reason', 255)->nullable();
            $table->timestamp('testing_at')->nullable();                // cuando apareció en Meta
            $table->timestamp('tested_at')->nullable();
            $table->json('result')->nullable();                         // el veredicto de las reglas por ciudad y sus números
            $table->text('learning')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });

        Schema::create('creative_batch_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_batch_id')->constrained('creative_batches')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable()->unique();    // C0457 = 457; se da al aprobar
            $table->unsignedTinyInteger('position')->default(1);
            $table->text('change')->nullable();                         // qué cambia en esta pieza (por ejemplo, el hook)
            $table->string('format', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_batch_pieces');
        Schema::dropIfExists('creative_batches');
    }
};
