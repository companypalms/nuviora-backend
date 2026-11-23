<?php

namespace App\Services\Meta\Queue;

/**
 * `.175` (dueño, 2026-11-23: "el líder de editores sí debe ver absolutamente todo lo que se tiene que hacer, no solo por día,
 * sino todo lo que se tiene que hacer para que él vaya asignando y adelantando creativos si ve que al editor le sobra tiempo
 * o a él mismo"): lo que se puede adelantar. PHP puro.
 * - De cada producto que vende: los batches que va a necesitar en las próximas 2 semanas (lo que se cansa por día, como en
 *   "¿Alcanza el equipo?"), menos lo que ya tiene pedido o listo en la banca. Lo que pide el sistema solo no cambia (`.100`:
 *   uno a la vez); esto es lo que el líder puede hacer antes.
 * - Los productos nuevos de la cola de productos por montar a los que no les falta nada, en su orden, con sus batches del
 *   testeo (`.154`: salen solos cuando hay chance; adelantarlos los saca ya).
 * Primero lo de los productos que venden, del que lo necesita antes; después los nuevos.
 */
final class AdvanceRules
{
    /** Cuántos días hacia adelante (los mismos de "¿Alcanza el equipo?"). */
    public const DAYS = ForecastRules::DAYS;

    /** Cuántos batches más va a necesitar un producto en las próximas 2 semanas, contando lo que ya tiene pedido o listo. */
    public static function left(float $rate, int $open): int
    {
        return max(0, (int) round($rate * self::DAYS) - $open);
    }

    /** Para cuándo hace falta el siguiente: lo que ya tiene pedido o listo alcanza para open ÷ rate días. */
    public static function nextDate(float $rate, int $open, string $today): string
    {
        $days = $rate > 0 ? (int) ceil($open / $rate) : self::DAYS;

        return date('Y-m-d', strtotime("{$today} +" . min(self::DAYS, $days) . ' days'));
    }

    /**
     * @param list<array{product_id: int, product: string, rate: float, open: int}> $selling
     * @param list<array{product_id: int, product: string, position: int, batches: int}> $queued los de la cola que están listos, en orden
     * @return list<array{key: string, kind: string, product_id: int, product: string, left: int, next: ?string, why: string}>
     */
    public static function items(array $selling, array $queued, string $today): array
    {
        $out = [];
        foreach ($selling as $p) {
            $left = self::left((float) $p['rate'], (int) $p['open']);
            if ($left === 0) {
                continue;
            }
            $next = self::nextDate((float) $p['rate'], (int) $p['open'], $today);
            $out[] = ['key' => "next-{$p['product_id']}", 'kind' => 'next', 'product_id' => (int) $p['product_id'], 'product' => $p['product'], 'left' => $left,
                'next' => $next, 'why' => 'Va a necesitar ~' . ($left === 1 ? '1 batch más' : "{$left} batches más") . ' en las próximas 2 semanas, para reponer los que se cansan.'];
        }
        usort($out, fn ($a, $b) => [$a['next'], $a['product']] <=> [$b['next'], $b['product']]);
        foreach ($queued as $q) {
            $n = max(1, (int) $q['batches']);
            $out[] = ['key' => "new-{$q['product_id']}", 'kind' => 'new', 'product_id' => (int) $q['product_id'], 'product' => $q['product'], 'left' => $n, 'next' => null,
                'why' => "Producto nuevo (lugar {$q['position']} de la cola del dueño): sus {$n} batches del testeo, para probar sus ángulos."];
        }

        return $out;
    }
}
