<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las landings medidas una por una (Etapa 5, paso 6a, 2026-10-30):
 * - landings: cada página de destino de los anuncios (la URL sin UTM ni variante), con el nombre y el ángulo que le pone
 *   el dueño ("Base", "BASU");
 * - meta_ad_landing_changes: a qué landing lleva cada anuncio y desde cuándo, para medir cada día con la landing que tenía
 *   ese día (meta_ads.landing_url solo dice la de ahora).
 * Las llena la sincronización de cada hora (LandingTracker), sin llamadas nuevas a Meta. Solo agrega dos tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->char('url_hash', 40)->unique();                     // sha1 de la URL normalizada
            $table->string('url', 1000);                                // normalizada: https://dominio/ruta?view=…
            $table->string('name', 120)->nullable();                    // el que le pone el dueño
            $table->string('angle_code', 12)->nullable();               // el ángulo del que habla (vacío = la base)
            $table->text('notes')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('product_id');
        });

        Schema::create('meta_ad_landing_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_id')->constrained('meta_ads')->cascadeOnDelete();
            $table->foreignId('landing_id')->constrained('landings')->cascadeOnDelete();
            $table->text('url')->nullable();                            // la URL tal como vino de Meta
            $table->timestamp('changed_at');                            // la primera de cada anuncio vale también para antes
            $table->timestamps();
            $table->index(['meta_ad_id', 'changed_at']);
            $table->index('landing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_landing_changes');
        Schema::dropIfExists('landings');
    }
};
