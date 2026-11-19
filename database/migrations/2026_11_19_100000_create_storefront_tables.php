<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.164` (dueño, 2026-11-19): nuestro propio formulario de pedido dentro de la landing (pago al recibir), con Releasit de
 * respaldo. Las ofertas por cantidad de cada producto (como las de Releasit: título, etiqueta, unidades y % de descuento),
 * el diseño de su formulario (textos, colores, letra), cada pedido que entra por ahí (con lo que hace falta para la API de
 * conversiones de Meta y para los KPIs), el embudo del formulario por día (sin datos personales) y la marca de Delivery
 * Express en el pedido (hoy el de Releasit llega solo como $2 de más en el total). No borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedTinyInteger('units');                     // cuántas unidades lleva (1 a 10)
            $table->decimal('discount_pct', 5, 2)->default(0);        // el % de descuento sobre unidades × precio
            $table->string('title', 120)->nullable();                 // "LLEVA 2 GALONES CON 25% DE DESCUENTO"
            $table->string('badge', 60)->nullable();                  // "🏆 LA OPCIÓN FAVORITA"
            $table->boolean('is_default')->default(false);            // la que sale marcada
            $table->unsignedTinyInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });

        Schema::create('storefront_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->json('texts')->nullable();                        // los textos del formulario que cambió el dueño
            $table->json('colors')->nullable();                       // principal, botón, fondo, bordes, texto, ofertas
            $table->string('font', 80)->nullable();                   // null = la letra de la página
            $table->unsignedTinyInteger('radius')->nullable();        // bordes redondeados, en px
            $table->text('custom_css')->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_orders', function (Blueprint $table) {
            $table->id();                                             // el número de WEB-n
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('product_offers')->nullOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedTinyInteger('units')->default(1);
            $table->string('phone_last10', 10);                       // para no crear el mismo pedido dos veces
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->decimal('total_usd', 10, 2);
            $table->unsignedBigInteger('total_bs')->nullable();       // lo que vio el cliente
            $table->decimal('rate', 14, 4)->nullable();               // la tasa con la que se calcularon los Bs
            $table->decimal('express_fee', 8, 2)->nullable();
            $table->string('event_id', 40)->unique();                 // el mismo en el píxel y en la API (no se cuenta doble)
            $table->string('visitor_id', 40)->nullable();
            $table->string('landing_url', 500)->nullable();           // la primera página que vio, con sus parámetros
            $table->string('referrer', 500)->nullable();
            $table->string('page_url', 500)->nullable();              // la página donde pidió
            $table->string('fbp', 120)->nullable();
            $table->string('fbc', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('capi_status', 12)->nullable();            // sent, failed o null (sin clave)
            $table->string('capi_error', 255)->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamps();
            $table->index(['phone_last10', 'product_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('storefront_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 40);                               // view, start, submit, error, fallback, whatsapp, field:<campo>
            $table->unsignedInteger('count')->default(0);
            $table->unique(['product_id', 'date', 'kind']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('express_fee', 8, 2)->nullable();         // Delivery Express: lo que pagó por él (null = no)
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('express_fee');
        });
        Schema::dropIfExists('storefront_events');
        Schema::dropIfExists('storefront_orders');
        Schema::dropIfExists('storefront_designs');
        Schema::dropIfExists('product_offers');
    }
};
