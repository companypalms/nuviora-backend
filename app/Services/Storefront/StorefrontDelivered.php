<?php

namespace App\Services\Storefront;

use App\Jobs\SendStorefrontEvent;
use App\Models\Order;
use App\Models\StorefrontOrder;

/**
 * `.168` (dueño, 2026-11-21: "si por el contrario es pago contraentrega o quiere primero hablar con una vendedora, entonces
 * el evento purchase se enviaría a facebook cuando el pedido esté entregado en el sistema"): al pasar a Entregado (por
 * cualquier camino: el panel, el motorizado, la revisión de entrega), el pedido de una campaña "al entregar" manda su
 * Purchase. Una sola vez: un retorno o un cambio no cuentan, y uno que ya lo mandó (pagó por adelantado) no lo repite.
 */
final class StorefrontDelivered
{
    public static function handle(Order $order): void
    {
        if ($order->is_return || $order->is_exchange || StorefrontCatalog::capiToken() === null) {
            return;
        }
        $front = StorefrontOrder::where('order_id', $order->id)->first();
        if (!$front || $front->purchase_mode !== 'delivery' || $front->capi_status === 'sent') {
            return;
        }
        $created = $front->created_at?->timestamp ?? time();
        SendStorefrontEvent::dispatch($front->id, MetaCapi::PURCHASE, StorefrontRules::deliveredTime($created, time()))->afterCommit();
    }
}
