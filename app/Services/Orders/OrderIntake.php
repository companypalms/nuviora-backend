<?php

namespace App\Services\Orders;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Status;
use App\Observers\OrderObserver;
use App\Services\Agencies\AgencyRouter;
use App\Services\Assignment\AssignOrderService;
use App\Services\WebhookService;

/**
 * El cierre del alta de un pedido que no viene de Shopify, con sus productos ya guardados (antes
 * `OrderController::finishManualOrderIntake`; `.164`: también el del formulario de la tienda). Hace lo mismo que el webhook
 * de Shopify después de guardar:
 * - Ya trae vendedora: se avisa a n8n su estado actual (como hacía el observer).
 * - Sin stock: pasa a "Sin Stock" (sin webhook de "Nuevo", regla de Fran) y se reparte si hay stock en otra agencia.
 * - Con stock: webhook de "Nuevo" y luego auto-asignación (que dispara el de "Asignado a vendedor").
 * Nunca rompe el alta: si algo falla, queda en el log y el pedido sigue guardado.
 */
class OrderIntake
{
    /** Guarda el pedido sin que el observer lo asigne todavía (los productos todavía no están). */
    public static function deferring(callable $create): mixed
    {
        OrderObserver::$deferIntake = true;
        try {
            return $create();
        } finally {
            OrderObserver::$deferIntake = false;
        }
    }

    public function finish(Order $order): void
    {
        try {
            $order->refresh();
            $webhooks = app(WebhookService::class);
            $assigner = app(AssignOrderService::class);

            $order->load('products');
            app(AgencyRouter::class)->provisional($order); // otra agencia de la ciudad con stock (tarea 3c)

            // Fran (2026-09-30): aunque ya traiga vendedora, sin stock queda en "Sin Stock", como uno de Shopify
            if ($order->agent_id) {
                if ($order->getStockDetails()['has_warning']) {
                    $this->toNoStock($order);

                    return;
                }
                $webhooks->triggerOrderStatus($order);

                return;
            }

            if ($order->getStockDetails()['has_warning']) {
                $this->toNoStock($order);
                $assigner->assignOne($order);

                return;
            }

            $webhooks->triggerOrderStatus($order);
            $assigner->assignOne($order);
        } catch (\Throwable $e) {
            \Log::error("Cierre del alta falló para {$order->number_label}: " . $e->getMessage());
        }
    }

    private function toNoStock(Order $order): void
    {
        $sinStock = Status::where('description', OrderStatus::SIN_STOCK)->first();
        if ($sinStock && $order->status_id !== $sinStock->id) {
            $order->status_id = $sinStock->id;
            $order->save();
        }
    }
}
