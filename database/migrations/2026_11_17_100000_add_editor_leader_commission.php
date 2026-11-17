<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `.153` (dueño, 2026-11-17: "le pago una comisión en base a las ganancias… de la ganancia total descuento un 10 %… y del
 * resultado le pago 1 %… cada 15 y último"; "cada producto es de un grupo de editores y su líder"): el grupo de editores de
 * cada producto (su líder) y cada quincena ya congelada (lo que se le paga y ya no se mueve).
 *
 * Hoy hay un solo grupo: si hay exactamente un "Editor líder" activo, todos los productos quedan en su grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('editor_leader_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('editor_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // el líder
            $table->date('period_start');
            $table->date('period_end');
            $table->date('payday');                                                   // el día que se paga (domingo → sábado)
            $table->decimal('profit_usd', 12, 2);                                     // la ganancia de sus productos al congelar
            $table->decimal('reserve_pct', 5, 2);                                     // el descuento antes de la comisión (10)
            $table->decimal('pct', 5, 2);                                             // la comisión (1)
            $table->decimal('own_usd', 12, 2);                                        // la comisión de su quincena al congelar
            $table->decimal('carry_usd', 12, 2)->default(0);                          // lo que creció la anterior después de pagarla
            $table->decimal('amount_usd', 12, 2);                                     // lo que se paga
            $table->timestamp('frozen_at');
            $table->timestamps();
            $table->unique(['user_id', 'period_start']);
        });

        $leaders = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.description', 'Editor líder')->whereNull('users.deactivated_at')->pluck('users.id');
        if ($leaders->count() === 1) {
            DB::table('products')->update(['editor_leader_id' => $leaders->first()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_commissions');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editor_leader_id');
        });
    }
};
