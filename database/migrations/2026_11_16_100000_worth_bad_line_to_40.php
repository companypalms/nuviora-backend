<?php

use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `.147` (dueño, 2026-11-16: "debemos bajar la etiqueta del 45% al 40% igual que la de la primera lectura. y regular tambien
 * debe ser igual que la primera lectura"; "quiero que lo cambies tu"): "Malo debajo de" pasa de 45 % a 40 % en las reglas
 * guardadas. Solo si seguía en 45 (lo que eligió el 12/11): un número que puso él a mano no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->swap(45, 40);
    }

    public function down(): void
    {
        $this->swap(40, 45);
    }

    private function swap(float $from, float $to): void
    {
        $row = DB::table('settings')->where('key', DecisionSettings::KEY)->first();
        $values = $row ? json_decode((string) $row->value, true) : null;
        if (!is_array($values) || !isset($values['worth_bad_pct']) || (float) $values['worth_bad_pct'] !== $from) {
            return;
        }
        $values['worth_bad_pct'] = $to;
        DB::table('settings')->where('key', DecisionSettings::KEY)->update(['value' => json_encode($values), 'updated_at' => now()]);
    }
};
