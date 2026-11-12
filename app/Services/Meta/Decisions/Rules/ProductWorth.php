<?php

namespace App\Services\Meta\Decisions\Rules;

/**
 * `.139` (dueño, 2026-11-12: "los recursos son finitos, es decir, tenemos editores finitos y su tiempo también es finito…
 * determinar si un producto es bueno, es regular y vale la pena mantenerlo y seguir renovando creativos, o por el contrario,
 * si un producto es malo"): la etiqueta de cada producto, solo por su efectividad (entregados ÷ pedidos), con sus pedidos
 * maduros y sin los que esperaron stock (OpsFacts::worth). PHP puro. Lo que eligió en su entrevista:
 * - Bueno desde 55 %, Malo debajo de 45 %, Regular en el medio (con sus números reales; Solo Brillo, la excepción, no cuenta);
 * - "en dos tiempos", para saberlo rápido sin equivocarse: con 30 a 49 pedidos solo es Malo si está claramente mal (debajo de
 *   40 %); con 50 o más, las líneas normales. Con menos de 30, todavía sin datos;
 * - "tiene que dejar una ganancia interesante como para marcarse regular": con $500 o más en 4 semanas, un Malo queda Regular;
 * - un Malo frena los encargos nuevos de ese producto hasta que el dueño decida (liquidarlo o abandonarlo); con "No estoy de
 *   acuerdo", sigue normal 14 días. Un Regular va después de los Buenos en las listas de los editores.
 */
class ProductWorth
{
    public const LABELS = ['good' => 'Bueno', 'regular' => 'Regular', 'bad' => 'Malo', 'no_data' => 'Todavía sin datos'];

    /** El orden en las listas de los editores: lo de los productos buenos primero, lo de los regulares después. */
    private const RANK = ['good' => 0, 'no_data' => 1, 'regular' => 2, 'bad' => 3];

    /** "No estoy de acuerdo" vale estos días. */
    public const DISMISS_DAYS = 14;

    /**
     * @param array{orders: int, delivered: int}|null $sample OpsFacts::worth
     * @param float|null $gain28 lo que dejó en 4 semanas (compras de Meta × BE − gasto); null si no se sabe
     * @return array{level: string, label: string, early: bool, effectiveness: ?float, orders: int, missing: int, rescued: bool, gain28: ?float, why: string}
     */
    public static function verdict(?array $sample, ?float $gain28, array $s): array
    {
        $orders = (int) ($sample['orders'] ?? 0);
        $eff = $orders > 0 ? round(100 * (int) $sample['delivered'] / $orders, 1) : null;
        $early = (int) $s['worth_early_orders'];
        $full = max($early, (int) $s['worth_full_orders']);
        $out = ['early' => false, 'effectiveness' => $eff, 'orders' => $orders, 'missing' => 0, 'rescued' => false, 'gain28' => $gain28];
        if ($orders < $early) {
            $missing = $early - $orders;

            return ['level' => 'no_data', 'label' => self::LABELS['no_data'], 'missing' => $missing] + $out
                + ['why' => ($orders === 0 ? 'Todavía no tiene pedidos que cuenten' : "Tiene {$orders} " . ($orders === 1 ? 'pedido' : 'pedidos') . ' que cuentan')
                    . ': ' . self::missing($missing) . ' para la primera lectura.'];
        }
        $isEarly = $orders < $full;
        $level = $eff >= (float) $s['worth_good_pct'] ? 'good'
            : ($eff < (float) ($isEarly ? $s['worth_early_bad_pct'] : $s['worth_bad_pct']) ? 'bad' : 'regular');
        $rescued = $level === 'bad' && $gain28 !== null && $gain28 >= (float) $s['worth_rescue_gain'];
        if ($rescued) {
            $level = 'regular';
        }
        $why = 'Efectividad ' . AdRules::num($eff) . " % en sus últimos {$orders} pedidos";
        if ($rescued) {
            $why .= ': está debajo de ' . AdRules::num((float) $s['worth_bad_pct']) . ' %, pero dejó ' . AdRules::money($gain28) . ' en 4 semanas';
        }
        if ($isEarly) {
            $why .= ' (primera lectura: ' . self::missing($full - $orders) . ' para la completa)';
        }

        return ['level' => $level, 'label' => self::LABELS[$level], 'early' => $isEarly, 'missing' => $isEarly ? $full - $orders : 0, 'rescued' => $rescued] + $out
            + ['why' => "{$why}."];
    }

    private static function missing(int $n): string
    {
        return $n === 1 ? 'falta 1 pedido' : "faltan {$n} pedidos";
    }

    /** El lugar de sus encargos en las listas de los editores (sin etiqueta, como "todavía sin datos"). */
    public static function rank(?string $level): int
    {
        return self::RANK[$level ?? 'no_data'] ?? self::RANK['no_data'];
    }

    /**
     * ¿Se frenan sus encargos nuevos? Malo, en modo normal (los demás modos ya no reciben encargos) y sin un "No estoy de
     * acuerdo" de los últimos 14 días.
     *
     * @param array<string, string> $dismissed clave de la misión => fecha
     */
    public static function stops(?array $worth, string $mode, array $dismissed, int $productId, string $today): bool
    {
        $at = $dismissed["{$productId}:worth_bad"] ?? null;

        return ($worth['level'] ?? null) === 'bad' && $mode === 'normal'
            && ($at === null || $at < date('Y-m-d', strtotime("{$today} -" . self::DISMISS_DAYS . ' days')));
    }
}
