<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.64` (dueño, 2026-11-01): "la IA siempre debe tener un excelente contexto del producto" (Comprimax es un chaleco
 * térmico para rebajar, sudar, definir y la ginecomastia; no es para el frío). La ficha de cada producto: qué es, para
 * qué sirve, cómo funciona, lo que NO es, la información clave, las fotos y sus buyer personas. La llena el dueño o el
 * asistente (con su tarjeta) y la IA la lee antes de escribir encargos o landings. Cada encargo dice a qué buyer persona
 * le habla. Solo agrega dos tablas y una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->text('what_it_is')->nullable();     // qué es
            $table->text('benefits')->nullable();       // para qué sirve
            $table->text('mechanism')->nullable();      // cómo funciona
            $table->text('not_this')->nullable();       // lo que NO es y nunca se dice
            $table->text('key_info')->nullable();       // tallas, cómo se usa, garantía…
            $table->string('photos_url', 500)->nullable(); // carpeta de Drive con las fotos
            $table->string('source', 12)->default('owner'); // quién la guardó la última vez: owner (pantalla) o assistant (tarjeta)
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 80);                 // "Hombre con ginecomastia"
            $table->text('who')->nullable();            // quién es
            $table->text('pain')->nullable();           // qué le duele
            $table->text('wants')->nullable();          // qué quiere
            $table->text('objections')->nullable();     // qué lo frena
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamp('archived_at')->nullable(); // quitado de la ficha: los encargos viejos lo siguen mostrando
            $table->timestamps();
            $table->index(['product_id', 'archived_at']);
        });

        Schema::table('creative_batches', function (Blueprint $table) {
            $table->foreignId('persona_id')->nullable()->after('audience')->constrained('product_personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
        });
        Schema::dropIfExists('product_personas');
        Schema::dropIfExists('product_profiles');
    }
};
