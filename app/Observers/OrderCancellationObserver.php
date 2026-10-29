<?php

namespace App\Observers;

use App\Models\OrderCancellation;
use App\Models\OrderActivityLog;

class OrderCancellationObserver
{
    public function created(OrderCancellation $cancellation): void
    {
        // Desde 2026-10-29 se cancela directo (sin aprobación): llega ya aprobada
        $direct = $cancellation->status === 'approved';
        OrderActivityLog::create([
            'order_id' => $cancellation->order_id,
            'user_id' => auth()->id(),
            'action' => $direct ? 'cancelled' : 'cancellation_requested',
            'description' => ($direct ? 'Canceló la orden. Motivo: ' : 'Solicitó la cancelación de la orden. Razón: ') . ($cancellation->reason ?? 'Sin razón'),
            'properties' => $cancellation->toArray()
        ]);
    }

    public function updated(OrderCancellation $cancellation): void
    {
        if ($cancellation->isDirty('status')) {
            $status = $cancellation->status === 'approved' ? 'APROBÓ' : 'RECHAZÓ';
            OrderActivityLog::create([
                'order_id' => $cancellation->order_id,
                'user_id' => auth()->id(),
                'action' => "cancellation_{$cancellation->status}",
                'description' => "{$status} la solicitud de cancelación. Nota: " . ($cancellation->response_note ?? 'Sin nota'),
                'properties' => $cancellation->toArray()
            ]);
        }
    }
}
