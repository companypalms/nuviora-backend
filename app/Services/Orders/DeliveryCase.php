<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\StorefrontOrder;
use Illuminate\Support\Facades\Auth;

/**
 * `.173` (dueño, 2026-11-21: "tenemos que cambiar todo el flujo de n8n dependiendo de cómo paga el cliente y cómo va a
 * recibir"): el caso de cada pedido, para que n8n le mande a cada uno lo suyo. Una encomienda siempre se paga antes: con el
 * pago móvil del formulario, o con la asesora (la vendedora registra el pago y queda "✅ Ya pagó" sola).
 */
final class DeliveryCase
{
    public const HOME_COD = 'domicilio_al_recibir';
    public const HOME_PAID = 'domicilio_pagado';
    public const COURIER_PAID = 'encomienda_pagada';
    public const COURIER_UNPAID = 'encomienda_por_pagar';

    /** El número de guía de MRW o Zoom: letras, números, espacios y guiones. */
    public const GUIDE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 \-]{3,39}$/';

    public static function of(bool $courier, bool $paid): string
    {
        return match (true) {
            $courier && $paid => self::COURIER_PAID,
            $courier => self::COURIER_UNPAID,
            $paid => self::HOME_PAID,
            default => self::HOME_COD,
        };
    }

    /** Lo que lee n8n de un pedido (check-window): su caso, si se cobra al entregar y los datos de la encomienda. */
    public static function forOrder(Order $order): array
    {
        $courier = (bool) $order->courier_shipment;
        $paid = $order->prepaid_at !== null;

        return [
            'case' => self::of($courier, $paid),
            'delivery' => $courier ? 'encomienda' : 'domicilio',
            'paid' => $paid,
            'collect_on_delivery' => !$courier && !$paid,
            'courier' => $courier ? [
                'company' => $order->courier_company,
                'office' => $order->courier_office,
                'recipient_name' => $order->courier_recipient_name,
                'recipient_id_number' => $order->courier_recipient_id_number,
                'guide' => $order->courier_guide,
                'guide_at' => $order->courier_guide_at?->toDateTimeString(),
            ] : null,
        ];
    }

    /** El número de guía como se guarda: sin espacios de más y en mayúsculas; null si quedó vacío. */
    public static function normalizeGuide(?string $guide): ?string
    {
        $guide = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $guide)));

        return $guide === '' ? null : $guide;
    }

    /**
     * Si los pagos que se registraron dejan la encomienda "Ya pagó" (true), se lo quitan (false) o no cambian nada (null).
     * Solo en encomiendas (dueño, 21/11: "sí, solo"); el pago por adelantado del formulario nunca se quita.
     */
    public static function prepaidByPayments(bool $courier, bool $prepaid, bool $fromForm, float $paid, float $total): ?bool
    {
        if (!$courier || $fromForm) {
            return null;
        }
        $covers = $total > 0 && $paid + 0.005 >= $total;

        return match (true) {
            $covers && !$prepaid => true,
            !$covers && $prepaid => false,
            default => null,
        };
    }

    /** Después de guardar los pagos de una orden: la encomienda cobrada completa queda "Ya pagó" (o deja de estarlo). */
    public static function syncPrepaid(Order $order, float $paid): ?bool
    {
        $fromForm = $order->prepaid_at !== null
            && StorefrontOrder::where('order_id', $order->id)->where('payment', StorefrontOrder::PREPAID)->exists();
        $change = self::prepaidByPayments((bool) $order->courier_shipment, $order->prepaid_at !== null, $fromForm, $paid,
            (float) $order->current_total_price);
        if ($change === null) {
            return null;
        }
        $order->forceFill(['prepaid_at' => $change ? now() : null])->save();
        OrderActivityLog::create(['order_id' => $order->id, 'user_id' => Auth::id(), 'action' => 'courier_prepaid',
            'description' => $change
                ? 'La encomienda quedó pagada completa: «✅ Ya pagó» (la agencia no cobra).'
                : 'Los pagos ya no cubren el total de la encomienda: se quitó «Ya pagó».']);

        return $change;
    }
}
