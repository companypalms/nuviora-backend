<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, rutas de varias paradas (§9.3, §10, §11, §20.2, §24.1, §26):
 * - logistics_routes: la ruta de un rider con 2 paradas, o 3 como excepción aprobada por el operador (§10). Abierta
 *   mientras el rider no sale del almacén; "Confirmar salida" la bloquea (§11: route_locked_at, departed_warehouse_at).
 *   Una orden que sale sola sigue sin ruta, como hasta ahora (ruta simple, §27).
 * - logistics_orders: cada parada es una orden logística. route_stop es el orden oficial (§10.2), leg_* el tramo desde la
 *   parada anterior o desde el centro (§18.2 lo paga así) y predicted_minutes el SLA proyectado de esa entrega al
 *   armar la ruta (§26: "ETA predichos, distancias"; §27: "ETA previsto vs tiempo real").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->foreignId('rider_id')->constrained('users');
            $table->string('status', 20); // abierta | en_via | interrumpida | finalizada | deshecha (§20.2)
            $table->boolean('exceptional')->default(false); // 3 paradas con aprobación expresa (§10)
            $table->json('plan')->nullable(); // lo que se aprobó: tramos, tiempo de servicio y SLA proyectado por parada
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();   // §11: route_locked_at
            $table->timestamp('departed_at')->nullable(); // §11: departed_warehouse_at
            $table->timestamp('interrupted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['center_id', 'status']);
            $table->index(['rider_id', 'status']);
        });

        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable()->after('pickup_rider_id')->constrained('logistics_routes')->nullOnDelete();
            $table->unsignedTinyInteger('route_stop')->nullable()->after('route_id');
            $table->unsignedInteger('leg_seconds')->nullable()->after('route_stop');
            $table->unsignedInteger('leg_meters')->nullable()->after('leg_seconds');
            $table->decimal('predicted_minutes', 6, 1)->nullable()->after('leg_meters');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('route_id');
            $table->dropColumn(['route_stop', 'leg_seconds', 'leg_meters', 'predicted_minutes']);
        });
        Schema::dropIfExists('logistics_routes');
    }
};
