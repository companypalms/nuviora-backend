<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Varias cuentas de pago móvil, una por orden (`.60`, 2026-10-31): a qué cuenta le paga el cliente de cada orden y si
 * una cuenta recibe órdenes nuevas (pausarla sin desactivarla). Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payment_account_id')->nullable()->constrained('company_accounts')->nullOnDelete();
            $table->timestamp('payment_account_at')->nullable(); // cuándo se le dio la cuenta (la carga del día sale de aquí)
            $table->index('payment_account_at');
        });
        Schema::table('company_accounts', function (Blueprint $table) {
            $table->boolean('takes_orders')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_account_id');
            $table->dropIndex(['payment_account_at']);
            $table->dropColumn('payment_account_at');
        });
        Schema::table('company_accounts', function (Blueprint $table) {
            $table->dropColumn('takes_orders');
        });
    }
};
