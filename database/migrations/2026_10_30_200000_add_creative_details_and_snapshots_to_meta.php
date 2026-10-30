<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los datos de Meta que necesita la IA para analizar como el dueño (Etapa 5, paso 1a, 2026-10-30):
 * - meta_ads: el texto, el título, la imagen, los parámetros de URL (UTM) y la landing de cada anuncio, y la duración de
 *   su video (para leer el tiempo promedio y la retención contra el largo);
 * - meta_insights_daily y meta_insights_current: los clics únicos en el enlace (el CTR único que eligió el dueño);
 * - meta_entity_snapshots: el historial de presupuestos y estados (una fila cada vez que algo cambia), para saber cuál
 *   fue la última decisión y cuántos días pasaron desde una intervención.
 * Solo agrega columnas y una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads', function (Blueprint $table) {
            $table->text('ad_body')->nullable()->after('thumbnail_url');
            $table->string('ad_title', 500)->nullable()->after('ad_body');
            $table->text('image_url')->nullable()->after('ad_title');
            $table->text('url_tags')->nullable()->after('image_url');
            $table->text('landing_url')->nullable()->after('url_tags');
            $table->decimal('video_length', 8, 2)->nullable()->after('video_id'); // segundos
        });
        foreach (['meta_insights_daily', 'meta_insights_current'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('unique_link_clicks')->nullable()->after('link_clicks');
            });
        }
        Schema::create('meta_entity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('level', 8); // campaign, adset, ad
            $table->string('meta_id', 32);
            $table->string('status', 24)->nullable();
            $table->string('effective_status', 32)->nullable();
            $table->decimal('daily_budget', 12, 2)->nullable();   // en la moneda de la cuenta (no en centavos)
            $table->decimal('lifetime_budget', 12, 2)->nullable();
            $table->decimal('min_spend', 12, 2)->nullable();      // mínimo de gasto del ad set en una CBO
            $table->decimal('spend_cap', 12, 2)->nullable();      // tope de gasto del ad set en una CBO
            $table->string('bid_strategy', 48)->nullable();
            $table->timestamp('recorded_at');
            $table->index(['level', 'meta_id', 'recorded_at']);
            $table->index(['meta_ad_account_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_entity_snapshots');
        foreach (['meta_insights_daily', 'meta_insights_current'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('unique_link_clicks');
            });
        }
        Schema::table('meta_ads', function (Blueprint $table) {
            $table->dropColumn(['ad_body', 'ad_title', 'image_url', 'url_tags', 'landing_url', 'video_length']);
        });
    }
};
