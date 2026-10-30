<?php

namespace App\Services\Meta\Decisions;

use App\Models\Setting;

/**
 * Los números con los que decide el dueño (Etapa 5, paso 2), en settings con la clave meta.decisions. Se cambian en
 * Meta Ads › Reglas sin tocar código. Los valores iniciales son los de su entrevista y su manual (2026-10-30); los de
 * video los aprobó con la ficha del paso 2 (sus 3 % y 2 % son de CTR único).
 * "×C" = veces el CPA objetivo de esa ciudad.
 */
class DecisionSettings
{
    public const KEY = 'meta.decisions';

    /** clave => [valor inicial, mínimo, máximo] */
    public const FIELDS = [
        // Estados de un anuncio o batch (con su gasto y sus compras de todo el período)
        'no_data_cpa' => [1.0, 0.1, 10],        // gasto < 1×C = sin datos
        'loser_spend_cpa' => [2.0, 0.5, 20],    // gasto ≥ 2×C con 0 o 1 compra = perdedor
        'evaluation_cap_cpa' => [3.0, 1, 30],   // con 2+ compras y CPA ≥ BE: en evaluación hasta 3×C
        'promising_purchases' => [3, 1, 100],   // 3+ compras con CPA ≤ C = prometedor
        'proven_purchases' => [5, 1, 100],      // 5+ compras con CPA ≤ C = PROVEN
        // Embudo y saturación (frecuencia de los 3 días cerrados)
        'funnel_middle_from' => [1.3, 1, 5],
        'funnel_bottom_above' => [1.5, 1, 5],
        'saturation_from' => [1.6, 1, 5],
        // Qué cambió (3 días contra los 3 anteriores o contra todo el período)
        'freq_rise_pct' => [15, 1, 200],
        'ctr_drop_pct' => [15, 1, 100],
        'cpm_rise_pct' => [20, 1, 300],
        'conv_drop_pct' => [25, 1, 100],
        'tracking_low_pct' => [80, 1, 100],     // Meta ve menos del 80 % de los pedidos reales = problema de tracking
        'tracking_high_pct' => [110, 100, 300],
        // Video: bueno desde / flojo debajo de (en %)
        'hook_good' => [40, 0, 100], 'hook_weak' => [30, 0, 100],
        'hold_good' => [40, 0, 100], 'hold_weak' => [30, 0, 100],
        'ctr_good' => [3, 0, 100], 'ctr_weak' => [2, 0, 100],
        'visit_ok' => [70, 0, 100], 'visit_leak' => [55, 0, 100],
        'retention_open' => [40, 0, 100],       // menos del 40 % de las reproducciones llega al 25 % = se van al principio
        'retention_body' => [40, 0, 100],       // de los que llegan al 25 %, menos del 40 % llega al 75 % = se van en el medio
        'retention_end' => [50, 0, 100],        // de los que llegan al 75 %, menos del 50 % termina = se van al final
        'watch_good' => [30, 0, 100], 'watch_weak' => [15, 0, 100], // tiempo medio sobre la duración
        'winners_gap_pct' => [20, 1, 100],      // 20 % o más debajo de la mediana de los ganadores del producto = flojo
        // Estados automáticos de los creativos
        'archive_days' => [14, 1, 365],
    ];

    /** Se puede apagar sin publicar: los estados vuelven a quedar como estén. */
    public const SWITCHES = ['auto_statuses' => true];

    public function get(): array
    {
        return self::normalize(Setting::get(self::KEY));
    }

    /** Guarda lo que mandó la pantalla; lo que no venga o no tenga sentido queda como estaba. */
    public function save(array $input): array
    {
        $values = self::normalize(array_merge($this->get(), $input));
        Setting::set(self::KEY, $values);

        return $values;
    }

    /** Los valores con sus límites (PHP puro). Un número fuera de rango vuelve al inicial. */
    public static function normalize(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : (is_string($stored) ? (json_decode($stored, true) ?: []) : []);
        $out = [];
        foreach (self::FIELDS as $key => [$default, $min, $max]) {
            $v = $stored[$key] ?? null;
            $out[$key] = is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? (float) $v : (float) $default;
        }
        foreach (self::SWITCHES as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? filter_var($stored[$key], FILTER_VALIDATE_BOOL) : $default;
        }

        return $out;
    }

    public static function defaults(): array
    {
        return self::normalize([]);
    }
}
