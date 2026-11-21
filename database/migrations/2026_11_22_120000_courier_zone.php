<?php

use App\Services\Orders\CourierZone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `.174` (dueño, 2026-11-21): la zona "Resto de Venezuela", solo para los números y Meta Ads (la 4.ª campaña y todas las
 * encomiendas). No tiene agencias: no se opera ahí (las encomiendas se siguen despachando desde su ciudad de origen).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cities')->insertOrIgnore(['name' => CourierZone::NAME, 'delivery_cost_usd' => 0, 'agency_id' => null, 'is_working' => true,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Si ya tiene campañas o números guardados, se queda (borrarla rompería esas referencias)
        try {
            DB::table('cities')->where('name', CourierZone::NAME)->delete();
        } catch (QueryException) {
        }
    }
};
