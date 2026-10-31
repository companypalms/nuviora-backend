<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde vino cada pedido nuevo de Shopify (`.53`, 2026-10-31): la landing, el sitio de donde llegó y los parámetros
 * del anuncio (utm_*), y, si dicen el anuncio, el anuncio y la landing del sistema. Nada de datos del cliente.
 * Solo agrega una tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('landing_url', 500)->nullable();     // la página donde entró, normalizada (sin utm ni fbclid)
            $table->string('referring_site', 500)->nullable();  // de dónde llegó (sin los parámetros)
            $table->string('source_name', 60)->nullable();      // lo que dice Shopify: web, una app (Releasit)…
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->boolean('has_fbclid')->default(false);      // vino de un clic en Facebook o Instagram
            $table->string('ad_meta_id', 40)->nullable();       // el id del anuncio en Meta, si los parámetros lo dicen
            $table->string('adset_meta_id', 40)->nullable();
            $table->string('campaign_meta_id', 40)->nullable();
            $table->foreignId('meta_ad_id')->nullable()->constrained('meta_ads')->nullOnDelete();
            $table->foreignId('landing_id')->nullable()->constrained('landings')->nullOnDelete();
            $table->string('landing_from', 10)->nullable();     // visit (la página del pedido) o ad (la del anuncio)
            $table->timestamps();
            $table->index('meta_ad_id');
            $table->index('landing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sources');
    }
};
