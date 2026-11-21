<?php

namespace App\Services\Storefront;

use App\Models\Order;
use App\Models\StorefrontOrder;

/**
 * `.168`: lo que ve la vendedora de un pedido pagado por adelantado en el formulario de la tienda: que ya pagó (no se le
 * cobra) y cómo va su pago: validando, validado (y si ya se le avisó por WhatsApp o tiene que escribirle ella), o con un
 * problema en el comprobante.
 */
final class PrepaidState
{
    /** @return ?array{at: string, state: string, confirm_sent_at: ?string, source: string} */
    public static function of(Order $order): ?array
    {
        if (!$order->prepaid_at) {
            return null;
        }
        $front = StorefrontOrder::where('order_id', $order->id)->first(['payment', 'confirm_status', 'confirm_sent_at']);
        $receipt = PrepaidMessages::state($order->id);
        // .173: la encomienda que cobró la vendedora (no fue el pago móvil del formulario): el sistema no le escribe al cliente
        if ($front?->payment !== StorefrontOrder::PREPAID) {
            return ['at' => $order->prepaid_at->toDateTimeString(), 'state' => $receipt === 'review' ? 'review' : 'registered',
                'confirm_sent_at' => null, 'source' => 'seller'];
        }
        $state = match (true) {
            $front?->confirm_status === 'sent' => 'confirmed',  // ya se le avisó por WhatsApp
            $front?->confirm_status === 'seller', $receipt === 'manual' => 'seller', // validado sin poder avisarle, o sin revisión automática
            $receipt === 'review' => 'review',                  // el comprobante tiene un problema
            $receipt === 'ok' => 'validated',                   // validado: el aviso sale en unos minutos
            default => 'validating',
        };

        return ['at' => $order->prepaid_at->toDateTimeString(), 'state' => $state, 'confirm_sent_at' => $front?->confirm_sent_at?->toDateTimeString(),
            'source' => 'form'];
    }
}
