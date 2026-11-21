<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.168` (dueño, 2026-11-21): en el formulario de la tienda, el pago anticipado (pago móvil con la foto del comprobante),
 * las encomiendas a todo el país (MRW y Zoom, la oficina de una lista) y "hablar con una asesora"; y, solo en las campañas
 * que marque, el Purchase de Meta al entregar (o al pagar por adelantado) en vez de al pedir. No borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table) {
            $table->string('payment', 12)->default('cod');            // cod, pago_movil, asesora
            $table->string('purchase_mode', 10)->default('order');    // order (Purchase al pedir) o delivery (al entregar)
            $table->string('placed_capi_status', 12)->nullable();     // "pedido hecho" (AddPaymentInfo): sent, failed o null
            $table->timestamp('purchase_sent_at')->nullable();        // cuándo salió el Purchase al entregar
            $table->boolean('after_hours')->default(false);           // pidió con la tienda cerrada
            $table->timestamp('ack_sent_at')->nullable();             // el aviso de fuera de horario por WhatsApp
            $table->timestamp('confirm_due_at')->nullable();          // cuándo sale el "ya confirmamos tu pago"
            $table->timestamp('confirm_sent_at')->nullable();
            $table->string('confirm_status', 12)->nullable();         // sent, seller (lo confirma la vendedora), failed, skip
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('prepaid_at')->nullable();              // pagó por adelantado (pago móvil del formulario)
            $table->string('courier_company', 20)->nullable();        // MRW, Zoom
            $table->string('courier_office', 300)->nullable();        // la oficina donde retira (nombre y dirección)
        });

        Schema::create('courier_offices', function (Blueprint $table) {
            $table->id();
            $table->string('company', 20);                            // MRW, Zoom
            $table->string('state', 60);
            $table->string('city', 80)->nullable();
            $table->string('name', 120);
            $table->string('address', 300)->nullable();
            $table->string('code', 80);                               // el de la empresa, o uno propio
            $table->boolean('is_active')->default(true);
            $table->string('source', 10)->default('lista');           // lista (de su página) o dueño (agregada a mano)
            $table->timestamps();
            $table->unique(['company', 'code']);
            $table->index(['company', 'state']);
        });

        Schema::create('meta_purchase_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('campaign_meta_id', 32)->index();          // la campaña que manda el Purchase al entregar
            $table->date('starts_on');
            $table->date('ends_on')->nullable();                      // null = sigue marcada
            $table->timestamps();
        });

        foreach (['meta_insights_daily', 'meta_insights_current'] as $name) {
            if (Schema::hasTable($name)) {
                Schema::table($name, function (Blueprint $table) {
                    // En las campañas "al entregar", purchases son los pedidos ("pedido hecho") y aquí va el Purchase de Meta
                    $table->decimal('real_purchases', 14, 4)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['meta_insights_daily', 'meta_insights_current'] as $name) {
            if (Schema::hasColumn($name, 'real_purchases')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('real_purchases');
                });
            }
        }
        Schema::dropIfExists('meta_purchase_modes');
        Schema::dropIfExists('courier_offices');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['prepaid_at', 'courier_company', 'courier_office']);
        });
        Schema::table('storefront_orders', function (Blueprint $table) {
            $table->dropColumn(['payment', 'purchase_mode', 'placed_capi_status', 'purchase_sent_at', 'after_hours', 'ack_sent_at',
                'confirm_due_at', 'confirm_sent_at', 'confirm_status']);
        });
    }
};
