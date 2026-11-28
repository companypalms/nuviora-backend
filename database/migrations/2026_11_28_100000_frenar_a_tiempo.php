<?php

use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `.186` (dueño, 2026-11-28: "me parece que está muy a favor de que pierda dinero, no frena a tiempo"): los números nuevos en las
 * reglas guardadas. Solo los que seguían en el número de antes: uno que el dueño puso a mano en Reglas no se toca.
 */
return new class extends Migration
{
    /** clave => [antes, ahora] */
    private const CHANGES = [
        'loser_be_margin_pct' => [20, 10],
        'loser_be_margin_purchases' => [20, 10],
        'patience_cpa' => [3, 2.5],
        'chronic_days' => [14, 5],
        'chronic_min_cpa' => [10, 5],
        'drop_min_batches' => [2, 1],
    ];

    public function up(): void
    {
        $this->swap(0, 1);
    }

    public function down(): void
    {
        $this->swap(1, 0);
    }

    private function swap(int $from, int $to): void
    {
        $row = DB::table('settings')->where('key', DecisionSettings::KEY)->first();
        $values = $row ? json_decode((string) $row->value, true) : null;
        if (!is_array($values)) {
            return;
        }
        $changed = false;
        foreach (self::CHANGES as $key => $pair) {
            if (isset($values[$key]) && (float) $values[$key] === (float) $pair[$from]) {
                $values[$key] = $pair[$to];
                $changed = true;
            }
        }
        if ($changed) {
            DB::table('settings')->where('key', DecisionSettings::KEY)->update(['value' => json_encode($values), 'updated_at' => now()]);
        }
    }
};
