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
        // .101 (dueño, 2026-11-04): con menos de 20 compras, perdedor por el BE solo con el CPA 20 % o más encima
        'loser_be_margin_pct' => [20, 0, 100],
        'loser_be_margin_purchases' => [20, 2, 1000],
        // .120 (dueño, 2026-11-09): una campaña con C y sin BE no se queda sin límite: se estima en C × esto hasta que ponga el real
        'be_estimate_mult' => [1.5, 1, 5],
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
        // .80: "justo después del hook" es el hold rate (hold_weak); el 25 % sobre las reproducciones ya no decide nada
        'retention_body' => [40, 0, 100],       // de los que llegan al 25 %, menos del 40 % llega al 75 % = se van en el medio
        'retention_end' => [50, 0, 100],        // de los que llegan al 75 %, menos del 50 % termina = se van al final
        'watch_good' => [30, 0, 100], 'watch_weak' => [15, 0, 100], // tiempo medio sobre la duración
        'winners_gap_pct' => [20, 1, 100],      // 20 % o más debajo de la mediana de los ganadores del producto = flojo
        // Estados automáticos de los creativos
        'archive_days' => [14, 1, 365],
        // Paso 2b — qué hacer con cada campaña (producto × ciudad)
        'scale_min_purchases' => [10, 1, 1000],  // escalar con 10+ compras en los 3 días
        // .79 (dueño, 2026-11-02): cuánto subir según qué tan debajo de C está el CPA de 3 días; nunca más del 20 % en 24 horas
        'scale_mid_below_pct' => [10, 1, 90],    // +15 % si está entre un 10 % y un 25 % debajo de C
        'scale_big_below_pct' => [25, 1, 90],    // +20 % si está un 25 % o más debajo de C
        'scale_big_pct' => [20, 1, 20],
        'scale_mid_pct' => [15, 1, 20],
        'scale_small_pct' => [10, 1, 20],        // menos de un 10 % debajo de C
        'decrease_pct' => [20, 1, 90],           // −20 % si sigue en el BE o encima…
        'decrease_after_days' => [2, 1, 30],     // …2 días cerrados después de intervenir
        'pause_after_days' => [5, 1, 60],        // pausar la ciudad a los 5 días cerrados
        'relaunch_batches' => [3, 1, 10],        // .117: una campaña apagada se prende con 3 batches nunca publicados en esa ciudad
        'startup_min_mult' => [2.5, 1, 10],      // presupuesto de arranque = C × batches nuevos × 2,5 a 3
        'startup_max_mult' => [3, 1, 10],
        'mins_low_pct' => [25, 0, 100],          // los mínimos de los ad sets: menos del 25 % del presupuesto = bajos
        'mins_ok_pct' => [40, 0, 100],           // hasta 40 % bien
        'mins_review_pct' => [50, 0, 100],       // hasta 50 % revisar; más, no
        'price_step' => [2, 0.1, 100],           // probar bajar el precio de a $2
        'stock_warn_days' => [3, 1, 90],         // .79: avisar "poco stock" con menos de 3 días de ventas (el stock ya no frena la subida)
        // .101: con 5+ pedidos esperando stock (de los últimos 14 días) en una ciudad: aviso con lo gastado y no se sube ahí
        'stock_waiting_min' => [5, 1, 1000],
        // .111 (dueño, 2026-11-06): después de un cambio grande en una campaña (subir o bajar el presupuesto, un batch nuevo,
        // volver a prenderla), 24 horas sin otro cambio grande ahí; y un anuncio nuevo no se apaga antes de 24 horas
        'change_wait_hours' => [24, 0, 168],
        'new_ad_hours' => [24, 0, 168],
        // Paso 4 — la cola de producción y la banca (el manual: 1–2 batches aprobados sin testear por producto)
        // .100: un batch a la vez por producto: la reserva es 1 (o 0: solo cuando una ciudad lo necesita)
        'bank_min' => [1, 0, 1],
        'bank_max' => [2, 0, 10],
        'batch_pieces' => [3, 1, 10],            // piezas (anuncios) por batch
        'video_editors' => [2, 0, 50],
        'batches_per_editor' => [2, 0.5, 20],    // por editor por día…
        'corrections_pct' => [30, 0, 100],       // …más ≈30 % de correcciones
        'due_days' => [1, 0, 30],                // plazo de un encargo al aprobarlo
        // .88: la reserva y los batches de inicio de un producto nuevo, para dentro de 2 días; el pronóstico de los editores
        'reserve_due_days' => [2, 0, 30],
        'corrections_days' => [1, 0, 10],        // .89: después de la entrega, las correcciones del líder (entrega + 1 = lista)
        'new_products_month' => [4, 0, 60],      // productos nuevos al mes (dueño: "1 por semana")…
        'start_batches' => [3, 0, 10],           // …con 3 batches de inicio cada uno
        'batch_life_days' => [7, 1, 90],         // lo que dura un batch bueno, mientras no haya historia en Meta
        // .116: con 3 batches perdidos seguidos un ángulo (o un creativo, con sus iteraciones) descansa 14 días
        'angle_rest_batches' => [3, 1, 20],
        'angle_rest_days' => [14, 1, 365],
        // Paso 6a — las landings: con menos no se opina
        'landing_min_clicks' => [100, 1, 100000],   // de la visita
        'landing_min_visits' => [300, 1, 1000000],  // de la compra
        // Paso 6b — los encargos de landing y su test antes/después con los mismos anuncios
        'landing_due_days' => [2, 0, 30],             // plazo al aprobarlo
        'landing_test_before_days' => [7, 1, 60],     // antes: los 7 días anteriores a publicar
        'landing_test_min_days' => [3, 1, 60],        // después: al menos 3 días…
        'landing_test_max_days' => [14, 1, 90],       // …y como mucho 14
        'landing_test_min_purchases' => [10, 1, 1000], // muestra de cada lado
        'landing_test_min_visits' => [300, 1, 1000000],
        'landing_test_win_pct' => [15, 1, 100],       // gana o pierde si la compra cambia 15 % o más
        // Paso 6c — cuántos encargos de landing proponen las reglas por día (uno por landing, lo más grave primero)
        'landing_proposals_max' => [3, 0, 20],
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
