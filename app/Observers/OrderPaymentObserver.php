<?php

namespace App\Observers;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsOrder;
use App\Models\OrderPayment;
use App\Models\OrderActivityLog;
use App\Services\Logistics\Alerts;
use App\Services\Logistics\LogisticsOrders;

class OrderPaymentObserver
{
    public function created(OrderPayment $orderPayment): void
    {
        OrderActivityLog::create([
            'order_id' => $orderPayment->order_id,
            'user_id' => auth()->id(),
            'action' => 'payment_added',
            'description' => "Se añadió un pago de {$orderPayment->amount} {$orderPayment->order->currency} con el método '{$orderPayment->method}'",
            'properties' => $orderPayment->toArray()
        ]);
        $this->guardInTransit($orderPayment, 'agregó', "{$orderPayment->method} {$orderPayment->amount}");
    }

    public function updated(OrderPayment $orderPayment): void
    {
        $changes = $orderPayment->getChanges();
        unset($changes['updated_at']);
        if (empty($changes)) return;

        $descriptions = [];
        foreach ($changes as $key => $newValue) {
            $oldValue = $orderPayment->getOriginal($key);
            $descriptions[] = "Actualizó '{$key}' de '{$oldValue}' a '{$newValue}' en el pago #{$orderPayment->id}";
        }

        OrderActivityLog::create([
            'order_id' => $orderPayment->order_id,
            'user_id' => auth()->id(),
            'action' => 'payment_updated',
            'description' => implode(' | ', $descriptions),
            'properties' => ['changes' => $changes, 'id' => $orderPayment->id]
        ]);
        $this->guardInTransit($orderPayment, 'cambió', implode(', ', $descriptions));
    }

    public function deleted(OrderPayment $orderPayment): void
    {
        OrderActivityLog::create([
            'order_id' => $orderPayment->order_id,
            'user_id' => auth()->id(),
            'action' => 'payment_removed',
            'description' => "Se eliminó el pago de {$orderPayment->amount} {$orderPayment->order->currency} con el método '{$orderPayment->method}'",
            'properties' => $orderPayment->toArray()
        ]);
        $this->guardInTransit($orderPayment, 'quitó', "{$orderPayment->method} {$orderPayment->amount}");
    }

    /**
     * Logística propia (sección 06 del informe del 27/10; Fran: "que se aplique todo"): con la orden en la calle con un
     * rider propio, tocar sus pagos le avisa a administración, aunque lo haga la vendedora. Es la trampa del efectivo:
     * pasar "efectivo" a "pago móvil" deja al rider sin deuda de caja (RiderCash sale de los pagos).
     */
    private function guardInTransit(OrderPayment $payment, string $what, string $detail): void
    {
        try {
            $row = LogisticsOrder::with('center', 'order:id,name')->where('order_id', $payment->order_id)->whereNotNull('departed_at')
                ->whereIn('status', [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT])->first();
            if (!$row?->center) {
                return;
            }
            $user = auth()->user();
            $who = $user?->names ?? 'Alguien';
            app(Alerts::class)->raise($row->center, 'pago_en_ruta', LogisticsAlert::RED,
                "Orden {$row->order?->name}: {$who} {$what} un pago con la orden en la calle ({$detail}). Revisa el cobro con el rider.",
                "pago_en_ruta:{$payment->id}:" . now()->format('YmdHis'), $payment->order_id, $row->rider_id);
            app(LogisticsOrders::class)->event($row, 'pago_en_ruta', $row->status, $row->status, $user?->id, mb_substr("{$who} {$what}: {$detail}", 0, 250),
                ['pago_id' => $payment->id]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
