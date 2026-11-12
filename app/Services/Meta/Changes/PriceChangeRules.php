<?php

namespace App\Services\Meta\Changes;

use App\Services\Meta\Decisions\Rules\AdRules;

/**
 * `.140` (dueño, 2026-11-12: después de bajar el precio, "lo vigila durante 7 días, pero además me dice si se cumplió la
 * hipótesis o no se cumplió, es decir, si al final por esa decisión estamos ganando más dinero o no"). PHP puro:
 * - cuándo cambió el precio de un producto: el precio más repetido de cada día en los pedidos (sin los upsells, con 3 pedidos
 *   o más ese día); cambia cuando dos días seguidos muestran el mismo precio nuevo ($1 o más distinto). Un día suelto (una
 *   promoción) no cuenta;
 * - la hipótesis y si se cumplió: los 7 días antes contra los 7 días después, por el día en que entró cada pedido (como la
 *   efectividad), con los pedidos ya entregados: pedidos por día, efectividad, CPA de Meta y la ganancia por día en dólares
 *   (lo cobrado de lo entregado − los costos de los pedidos − el gasto de Meta, antes de los gastos fijos).
 */
final class PriceChangeRules
{
    /** Con menos pedidos en un día, su precio no cuenta. */
    public const MIN_ORDERS_DAY = 3;

    /**
     * @param array<string, float> $modal fecha => el precio más repetido ese día
     * @return list<array{date: string, from: float, to: float}>
     */
    public static function detect(array $modal): array
    {
        ksort($modal);
        $dates = array_keys($modal);
        $out = [];
        $current = null;
        foreach ($dates as $i => $date) {
            $price = (float) $modal[$date];
            if ($current === null) {
                $current = $price;
                continue;
            }
            $next = $dates[$i + 1] ?? null;
            if (abs($price - $current) < 1 || $next === null || abs((float) $modal[$next] - $price) >= 1) {
                continue; // igual, o todavía sin confirmar, o un día suelto
            }
            $out[] = ['date' => $date, 'from' => $current, 'to' => $price];
            $current = $price;
        }

        return $out;
    }

    /**
     * Los números de unos días.
     *
     * @param array<string, array{created: int, delivered: int, revenue: float, variable: float}> $orders fecha => sus pedidos
     * @param array<string, array{spend: float, purchases: float}> $meta fecha => lo de Meta
     */
    public static function numbers(array $orders, array $meta, string $from, string $to): array
    {
        $sum = ['created' => 0, 'delivered' => 0, 'revenue' => 0.0, 'variable' => 0.0, 'spend' => 0.0, 'purchases' => 0.0];
        $days = 0;
        for ($d = $from; $d <= $to; $d = ChangeRules::addDays($d, 1)) {
            foreach (['created', 'delivered', 'revenue', 'variable'] as $k) {
                $sum[$k] += $orders[$d][$k] ?? 0;
            }
            $sum['spend'] += (float) ($meta[$d]['spend'] ?? 0);
            $sum['purchases'] += (float) ($meta[$d]['purchases'] ?? 0);
            $days++;
        }
        $days = max(1, $days);

        return ['from' => $from, 'to' => $to, 'days' => $days, 'orders' => (int) $sum['created'], 'orders_day' => round($sum['created'] / $days, 1),
            'effectiveness' => $sum['created'] > 0 ? round(100 * $sum['delivered'] / $sum['created'], 1) : null,
            'spend' => round($sum['spend'], 2), 'cpa' => $sum['purchases'] > 0 ? round($sum['spend'] / $sum['purchases'], 2) : null,
            'gain_day' => round(($sum['revenue'] - $sum['variable'] - $sum['spend']) / $days, 2)];
    }

    /** @return array{hypothesis: string, criterion: string} */
    public static function hypothesis(float $from, float $to, array $before, int $days): array
    {
        $down = $to < $from;

        return ['hypothesis' => ($down ? 'Si bajo' : 'Si subo') . ' el precio de ' . AdRules::money($from) . ' a ' . AdRules::money($to) . ', '
            . ($down ? 'entran más pedidos y gano más dólares por día' : 'gano más dólares por día aunque entren menos pedidos')
            . ' que antes (' . self::gainText($before['gain_day']) . ' por día, con ' . AdRules::num($before['orders_day']) . ' pedidos por día).',
            'criterion' => "Se cumple si en los {$days} días después, con esos pedidos ya entregados, gano más dólares por día que en los {$days} días antes."];
    }

    /**
     * Si se cumplió. $matureTo: el último día cuyos pedidos ya tuvieron tiempo de entregarse (como el CPA objetivo); $today, hoy.
     *
     * @param ?array $after los números de después (hasta donde haya)
     * @param list<string> $others los otros cambios grandes de ese producto en esos días ("subiste el presupuesto en Caracas")
     * @return array{result: string, text: string}
     */
    public static function measure(float $from, float $to, string $date, array $before, ?array $after, string $matureTo, string $today, int $days, array $others): array
    {
        $end = ChangeRules::addDays($date, $days - 1);
        if ($matureTo < $end) {
            $ready = ChangeRules::short(ChangeRules::addDays($end, ChangeRules::diffDays($matureTo, $today)));

            return ['result' => 'measuring', 'text' => "Midiendo: el resultado sale cuando los pedidos de esos {$days} días ya se hayan entregado (hacia el {$ready})."
                . ($after && $after['orders'] > 0 ? ' Por ahora: ' . AdRules::num($after['orders_day']) . ' pedidos por día (antes ' . AdRules::num($before['orders_day']) . ').' : '')];
        }
        if ($before['orders'] === 0 || !$after || $after['orders'] === 0) {
            return ['result' => 'unknown', 'text' => 'No se puede saber: casi no hubo pedidos ' . ($before['orders'] === 0 ? 'antes' : 'después') . ' del cambio.'];
        }
        $ok = $after['gain_day'] > $before['gain_day'];
        $diff = abs($after['gain_day'] - $before['gain_day']);
        $text = ($ok ? 'Se cumplió: ganas ' . AdRules::money($diff) . ' más por día' : 'No se cumplió: ganas ' . AdRules::money($diff) . ' menos por día')
            . ' (con ' . AdRules::money($to) . ', ' . self::gainText($after['gain_day'], true) . ' por día; antes, con ' . AdRules::money($from) . ', '
            . self::gainText($before['gain_day']) . '). '
            . AdRules::num($after['orders_day']) . ' pedidos por día (antes ' . AdRules::num($before['orders_day']) . ')'
            . ', efectividad ' . AdRules::num($after['effectiveness']) . ' % (antes ' . AdRules::num($before['effectiveness']) . ' %)'
            . ($after['cpa'] !== null && $before['cpa'] !== null ? ', CPA ' . AdRules::money($after['cpa']) . ' (antes ' . AdRules::money($before['cpa']) . ')' : '') . '.'
            . ($ok ? '' : ' Vuelve a ' . AdRules::money($from) . '.')
            . ($others ? ' Ojo: en esos días también ' . self::join($others) . '.' : '');

        return ['result' => $ok ? 'met' : 'not_met', 'text' => $text];
    }

    private static function gainText(float $v, bool $now = false): string
    {
        return ($v >= 0 ? ($now ? 'ganas ' : 'ganabas ') : ($now ? 'pierdes ' : 'perdías ')) . AdRules::money(abs($v));
    }

    private static function join(array $parts): string
    {
        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts) : (string) ($parts[0] ?? '');
    }
}
