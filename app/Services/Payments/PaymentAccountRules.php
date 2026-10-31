<?php

namespace App\Services\Payments;

/**
 * A cuál de las cuentas de pago móvil le paga el cliente de una orden (`.60`, decisión del dueño del 2026-10-31: "por
 * monto del día"). PHP puro.
 * - Va a la cuenta que menos bolívares lleva asignados hoy: así se reparte parejo el dinero (lo que cuenta para los
 *   límites del banco), no solo la cantidad de órdenes.
 * - A igual monto (al empezar el día, o órdenes sin monto todavía), la que menos órdenes lleva; después, la que hace más
 *   tiempo que no recibe una; al final, la más vieja (el id menor). Así, sin montos, queda por turnos.
 * - Solo entran las cuentas de pago móvil activas que reciben órdenes nuevas (eso lo filtra quien llama).
 */
final class PaymentAccountRules
{
    /**
     * @param array<int, array{id: int, bs: float, orders: int, last_at: ?string}> $candidates las cuentas que pueden
     *        recibir la orden, con lo que llevan hoy (bs, orders) y cuándo recibieron la última (cualquier día)
     * @return ?int el id de la cuenta, o null si no hay ninguna
     */
    public static function pick(array $candidates): ?int
    {
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn (array $a, array $b) => [round($a['bs'], 2), $a['orders'], $a['last_at'] ?? '', $a['id']]
            <=> [round($b['bs'], 2), $b['orders'], $b['last_at'] ?? '', $b['id']]);

        return $candidates[0]['id'];
    }

    /**
     * Repartir varias órdenes seguidas (el comando de una vez): cada una se suma a la carga de su cuenta antes de elegir
     * la siguiente.
     * @param array<int, array{id: int, bs: float, orders: int, last_at: ?string}> $candidates
     * @param array<int|string, float> $orders lo que paga cada orden por pago móvil, en Bs, en el orden en que se reparten
     * @return array<int|string, ?int> por orden, el id de la cuenta
     */
    public static function spread(array $candidates, array $orders, string $now): array
    {
        $byId = [];
        foreach ($candidates as $c) {
            $byId[$c['id']] = $c;
        }
        $out = [];
        foreach ($orders as $key => $bs) {
            $id = self::pick(array_values($byId));
            $out[$key] = $id;
            if ($id !== null) {
                $byId[$id]['bs'] += $bs;
                $byId[$id]['orders']++;
                $byId[$id]['last_at'] = $now;
            }
        }

        return $out;
    }

    /** Lo que paga una orden por pago móvil, en Bs: cada pago por su tasa (la del pago o, si no tiene, la de Binance). */
    public static function orderBs(iterable $payments): float
    {
        $bs = 0.0;
        foreach ($payments as $p) {
            $p = is_object($p) && method_exists($p, 'toArray') ? $p->toArray() : (array) $p;
            if (($p['method'] ?? null) === PaymentAccounts::METHOD) {
                $bs += (float) $p['amount'] * (float) (($p['rate'] ?? null) ?: ($p['binance_usd_rate'] ?? 0));
            }
        }

        return round($bs, 2);
    }
}
