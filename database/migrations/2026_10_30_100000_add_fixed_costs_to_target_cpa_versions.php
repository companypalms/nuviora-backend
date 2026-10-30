<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPA objetivo y break-even reales (pedido del dueño, 2026-10-30):
 * - target_cpa_versions guarda el % de gastos fijos que se apartó (el real o el mínimo), la ventana que se usó (7 o 28
 *   días, la más prudente) y el detalle de las dos ventanas y de los envíos completados;
 * - order_financials suma las comisiones de cualquier otro rol (las de roles futuros entran solas).
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_cpa_versions', function (Blueprint $table) {
            $table->decimal('fixed_pct', 5, 2)->nullable()->after('target_pct');
            $table->string('fixed_source', 10)->nullable()->after('fixed_pct'); // real o minimum
            $table->unsignedSmallInteger('window_days')->nullable()->after('period_to');
            $table->json('detail')->nullable()->after('viable');
        });
        Schema::table('order_financials', function (Blueprint $table) {
            $table->decimal('commission_other', 10, 2)->default(0)->after('commission_deliverer');
        });
    }

    public function down(): void
    {
        Schema::table('order_financials', function (Blueprint $table) {
            $table->dropColumn('commission_other');
        });
        Schema::table('target_cpa_versions', function (Blueprint $table) {
            $table->dropColumn(['fixed_pct', 'fixed_source', 'window_days', 'detail']);
        });
    }
};
