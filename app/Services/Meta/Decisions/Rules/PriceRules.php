<?php

namespace App\Services\Meta\Decisions\Rules;

/**
 * `.140` (dueño, 2026-11-12: "que el sistema me recomiende bajarle de precio a un producto… si vemos que el CPA de Facebook
 * objetivo es muy alto debido a que el porcentaje de efectividad de ese producto es bueno… para tratar de ganar más volumen
 * sin sacrificar ni CPA ni rentabilidad"). PHP puro. Lo que eligió en su entrevista:
 * - solo un producto Bueno (`.139`), activo y fuera del testeo, con su C calculado con sus pedidos (no el estimado ni el del
 *   nombre de la campaña), y con su CPA real de 14 días un 40 % o más debajo del C ("solo cuando el espacio es muy claro");
 * - un escalón a la vez, como mucho 10 %, en dólares enteros, y nunca tanto que el C nuevo quede a menos de 25 % encima del
 *   CPA real;
 * - no se recomienda otra vez mientras se mide el cambio anterior (Historial de cambios, `PriceChangeRules`).
 * Bajar el precio baja en lo mismo lo cobrado por entrega; con eso bajan la utilidad y los gastos fijos (que son % de lo
 * cobrado) y el C, por cada compra de Meta, según las entregas que trae cada compra (como `CampaignRules::minPrice`).
 */
final class PriceRules
{
    /** Con menos entregas, su C todavía no es confiable para mover el precio (el mismo mínimo del CPA de finanzas). */
    public const MIN_DELIVERED = 30;

    /**
     * El C después de bajar el precio $drop por unidad. Null si la versión no alcanza para calcularlo.
     *
     * @param array $v la versión del CPA del producto: target, orders_delivered, purchases, fixed_pct, target_type, target_pct
     */
    public static function newTarget(array $v, float $drop): ?float
    {
        $delivered = (float) ($v['orders_delivered'] ?? 0);
        $purchases = (float) ($v['purchases'] ?? 0);
        if (($v['target'] ?? null) === null || $delivered <= 0 || $purchases <= 0) {
            return null;
        }
        $fixed = (float) ($v['fixed_pct'] ?? 0) / 100;
        // Con utilidad sobre lo vendido, también baja la utilidad; sobre el costo, no
        $keep = 1 - $fixed - (($v['target_type'] ?? 'sales') === 'cost' ? 0 : (float) ($v['target_pct'] ?? 0) / 100);

        return round(max(0.0, (float) $v['target'] - $drop * $keep * $delivered / $purchases), 2);
    }

    /**
     * La recomendación de bajar el precio, o null.
     *
     * @param array{price: ?float, version: ?array, cpa: ?float, purchases: float, worth: ?string, mode: string, phase: string, recent: bool, dismissed: bool} $in
     * @return array{from: float, to: float, c: float, c_new: float, cpa: float, room: int, room_new: int}|null
     */
    public static function suggest(array $in, array $s): ?array
    {
        $price = (float) ($in['price'] ?? 0);
        $v = $in['version'] ?? null;
        $c = isset($v['target']) ? (float) $v['target'] : 0.0;
        $cpa = $in['cpa'] ?? null;
        if ($in['worth'] !== 'good' || $in['mode'] !== 'normal' || $in['phase'] === 'testing' || $in['recent'] || $in['dismissed']
            || $price < 2 || $c <= 0 || $cpa === null || $cpa <= 0 || (float) ($v['orders_delivered'] ?? 0) < self::MIN_DELIVERED
            || $in['purchases'] < (float) $s['price_min_purchases'] || $cpa > $c * (1 - (float) $s['price_room_pct'] / 100)) {
            return null;
        }
        $floor = $cpa * (float) $s['price_cushion'];
        // El escalón más grande (hasta 10 %) en dólares enteros que todavía deja el C nuevo con su colchón sobre el CPA real
        for ($to = (float) ceil($price * (1 - (float) $s['price_step_max_pct'] / 100)); $to <= $price - 1; $to++) {
            $new = self::newTarget($v, $price - $to);
            if ($new !== null && $new >= $floor) {
                return ['from' => $price, 'to' => $to, 'c' => $c, 'c_new' => $new, 'cpa' => round($cpa, 2),
                    'room' => (int) round((1 - $cpa / $c) * 100), 'room_new' => (int) round(($new / $cpa - 1) * 100)];
            }
        }

        return null;
    }
}
