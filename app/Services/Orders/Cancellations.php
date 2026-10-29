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
    public static function recordByUser(Order $order, int $previousStatusId, string $reason, int $userId): OrderCancellation
    {
        Order::whereKey($order->id)->update(['cancelled_at' => now()]);

        return OrderCancellation::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'reason' => $reason,
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
            'source' => 'system',
            'status' => 'approved',
            'reviewed_at' => $now,
            'previous_status_id' => $o->status_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }
}
