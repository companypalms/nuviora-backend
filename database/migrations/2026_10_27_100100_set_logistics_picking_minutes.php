<?php

use App\Models\LogisticsSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * §7.1 "tiempo de picking/salida configurado": Fran respondió el 27/10 "menos de 3 minutos, máximo 5" (bolsa de seguridad y
 * etiqueta). Las versiones vigentes que lo tenían vacío pasan a 5 con una versión nueva, como si lo guardara el Admin
 * (§33: la anterior queda para la auditoría). Si alguien ya puso un valor, no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $latest = LogisticsSetting::orderByDesc('valid_from')->orderByDesc('id')->get()->unique(fn ($s) => $s->center_id ?? 'general');
        foreach ($latest as $version) {
            $values = $version->values ?? [];
            if (array_key_exists('picking_minutes', $values) && $values['picking_minutes'] !== null) {
                continue;
            }
            LogisticsSetting::create([
                'center_id' => $version->center_id,
                'values' => ['picking_minutes' => 5] + $values,
                'valid_from' => now(),
                'created_by' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Las versiones quedan: son el historial de los parámetros (§33)
    }
};
