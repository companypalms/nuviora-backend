<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderCancellation;
use Illuminate\Support\Collection;

/**
 * Motivos de cancelación (dueño, 2026-10-29): quien cancela una orden escribe por qué, y el motivo queda en
 * order_cancellations para analizarlo después y mejorar la operación. Lo que cancela el sistema queda con su motivo.
 */
class Cancellations
{
    public const MIN_REASON = 5;

    /**
     * El motivo de la lista (dueño, 2026-10-29), además del escrito. La misma lista está en el panel
     * (nuviora-frontend/src/components/orders/cancelReason.ts): si se cambia aquí, se cambia allá.
     */
    public const CATEGORIES = [
        'no_contesta' => 'No contesta / no se pudo contactar',
        'no_lo_quiere' => 'Ya no lo quiere / se arrepintió',
        'dinero' => 'No tiene el dinero / precio',
        'duplicado' => 'Pedido duplicado o de prueba',
        'zona' => 'Fuera de zona / dirección no clara',
        'sin_stock' => 'Sin stock',
        'demora' => 'Tardó mucho la entrega',
        'otro' => 'Otro',
    ];

    /** Lo que cancela el cierre de la jornada (no se elige: lo pone el sistema). */
    public const SYSTEM_CATEGORY = 'sistema';

    /** El motivo de la lista, o null si no es uno de la lista. */
    public static function category(?string $key): ?string
    {
        return $key !== null && array_key_exists($key, self::CATEGORIES) ? $key : null;
    }

    public static function missingCategoryMessage(): string
    {
        return 'Elige el motivo de la cancelación de la lista.';
    }

    /** El motivo escrito, o null si no llega al mínimo. */
    public static function reason(?string $text): ?string
    {
        $text = trim((string) $text);

        return mb_strlen($text) >= self::MIN_REASON ? $text : null;
    }

    public static function missingReasonMessage(): string
    {
        return 'Escribe el motivo de la cancelación (mínimo ' . self::MIN_REASON . ' caracteres).';
    }

    /** Una persona canceló la orden (ya está en "Cancelado"). */
    public static function recordByUser(Order $order, int $previousStatusId, string $reason, int $userId, ?string $category = null): OrderCancellation
    {
        Order::whereKey($order->id)->update(['cancelled_at' => now()]);

        return OrderCancellation::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'reason' => $reason,
            'category' => $category,
            'source' => 'user',
            'status' => 'approved',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'previous_status_id' => $previousStatusId,
        ]);
    }

    /**
     * El sistema canceló estas órdenes en bloque (ya están en "Cancelado"). Sin observer: quien cancela ya deja su
     * propia nota en el historial.
     *
     * @param Collection<int, Order> $orders  con el estado que tenían antes de cancelarse
     * @param callable(Order): string $reason
     */
    public static function recordBySystem(Collection $orders, callable $reason): void
    {
        $now = now();
        OrderCancellation::insert($orders->map(fn (Order $o) => [
            'order_id' => $o->id,
            'user_id' => null,
            'reason' => $reason($o),
            'category' => self::SYSTEM_CATEGORY,
            'source' => 'system',
            'status' => 'approved',
            'reviewed_at' => $now,
            'previous_status_id' => $o->status_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }
}
