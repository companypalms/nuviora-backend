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
        // Paso 2b — qué hacer con cada campaña (producto × ciudad)
        'scale_min_purchases' => [10, 1, 1000],  // escalar con 10+ compras en los 3 días
        'scale_big_below_pct' => [25, 1, 90],    // +20 % si el CPA de 3 días está un 25 % o más debajo de C
        'scale_big_pct' => [20, 1, 100],
        'scale_small_pct' => [10, 1, 100],
        'decrease_pct' => [20, 1, 90],           // −20 % si sigue en el BE o encima…
        'decrease_after_days' => [2, 1, 30],     // …2 días cerrados después de intervenir
        'pause_after_days' => [5, 1, 60],        // pausar la ciudad a los 5 días cerrados
        'last_test_budget_cpa' => [1.5, 0.5, 20], // último test: 1,5×C por día…
        'last_test_days' => [3, 1, 30],          // …durante 3 días
        'startup_min_mult' => [2.5, 1, 10],      // presupuesto de arranque = C × batches nuevos × 2,5 a 3
        'startup_max_mult' => [3, 1, 10],
        'mins_low_pct' => [25, 0, 100],          // los mínimos de los ad sets: menos del 25 % del presupuesto = bajos
        'mins_ok_pct' => [40, 0, 100],           // hasta 40 % bien
        'mins_review_pct' => [50, 0, 100],       // hasta 50 % revisar; más, no
        'price_step' => [2, 0.1, 100],           // probar bajar el precio de a $2
        'stock_days_min' => [7, 1, 90],          // escalar solo con stock para 7 días de ventas en la ciudad
        // Paso 4 — la cola de producción y la banca (el manual: 1–2 batches aprobados sin testear por producto)
        'bank_min' => [1, 0, 10],
        'bank_max' => [2, 0, 10],
        'batch_pieces' => [3, 1, 10],            // piezas (anuncios) por batch
        'video_editors' => [2, 0, 50],
        'batches_per_editor' => [2, 0.5, 20],    // por editor por día…
        'corrections_pct' => [30, 0, 100],       // …más ≈30 % de correcciones
        'due_days' => [1, 0, 30],                // plazo de un encargo al aprobarlo
        // Paso 6a — las landings: con menos no se opina
        'landing_min_clicks' => [100, 1, 100000],   // de la visita
        'landing_min_visits' => [300, 1, 1000000],  // de la compra
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
