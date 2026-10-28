<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El efectivo del centro (informe del 27/10, sección 06 puntos 3 y 4; Fran: "como lo hacemos actualmente para las agencias…
 * me debe salir a mí en el sistema y a ella el dinero que tiene ese almacén recaudado; cuando me lo entregue, se marca como
 * pagado"; el cambio de turno "rápido y fácil").
 * - El efectivo del centro = lo que la operadora recibió de los riders (rider_cash_movements) menos lo que administración
 *   marcó como recibido (center_cash_movements).
 * - logistics_shifts: al cerrar, cuánto deja la operadora; al abrir, cuánto recibe la siguiente; y lo que decía el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->string('type', 20); // entrega_admin
            $table->decimal('usd', 12, 2)->default(0);
            $table->decimal('ves', 14, 2)->default(0);
            $table->string('method', 40)->nullable();
            $table->string('note', 250)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('center_id');
        });
        Schema::table('logistics_shifts', function (Blueprint $table) {
            $table->decimal('open_cash_usd', 12, 2)->nullable()->after('pending_at_close');
            $table->decimal('open_cash_ves', 14, 2)->nullable()->after('open_cash_usd');
            $table->json('open_expected')->nullable()->after('open_cash_ves'); // usd, ves y retornos según el sistema
            $table->decimal('close_cash_usd', 12, 2)->nullable()->after('open_expected');
            $table->decimal('close_cash_ves', 14, 2)->nullable()->after('close_cash_usd');
            $table->json('close_expected')->nullable()->after('close_cash_ves');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_shifts', fn (Blueprint $table) => $table->dropColumn(['open_cash_usd', 'open_cash_ves', 'open_expected', 'close_cash_usd', 'close_cash_ves', 'close_expected']));
        Schema::dropIfExists('center_cash_movements');
    }
};
