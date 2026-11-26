<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderActivityLog;

/**
 * `.182` (dueño, 2026-11-26: "la idea es hacer un sistema lo más automático posible para que las vendedoras tengan la
 * menor intervención posible"): la ubicación que el cliente manda por WhatsApp queda sola en su pedido activo a domicilio,
 * como si la vendedora hubiera pegado el link (el repartidor la ve con su ruta). Si el pedido ya tenía otra, no se cambia:
 * la vendedora la revisa. Las encomiendas no: retiran en la oficina (su link es el de la oficina de donde sale).
 */
final class WhatsappLocation
{
    public const SET = 'set';
    public const OTHER = 'other';
    public const SAME = 'same';
    public const SKIP = 'skip';

    /** El link de Google Maps de las coordenadas que manda WhatsApp, o null si no son coordenadas. */
    public static function link(mixed $lat, mixed $lng): ?string
    {
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }

        return 'https://www.google.com/maps?q=' . round((float) $lat, 7) . ',' . round((float) $lng, 7);
    }

    /** Qué se hace con la ubicación: ponerla (set), avisar que mandó otra (other), nada porque es la misma (same) o no aplica (skip). */
    public static function decide(?string $current, ?string $link, bool $courier): string
    {
        if ($link === null || $courier) {
            return self::SKIP;
        }
        $current = trim((string) $current);
        if ($current === '') {
            return self::SET;
        }

        return $current === $link ? self::SAME : self::OTHER;
    }

    /** Lo hace en el pedido activo del cliente (el mismo al que se une su mensaje). */
    public function apply(?Order $order, mixed $lat, mixed $lng): string
    {
        if (!$order) {
            return self::SKIP;
        }
        $link = self::link($lat, $lng);
        $what = self::decide($order->location, $link, (bool) $order->courier_shipment);
        if ($what === self::SET) {
            $order->location = $link;
            $order->save();
            OrderActivityLog::create(['order_id' => $order->id, 'action' => 'location_from_whatsapp',
                'description' => 'El cliente mandó su ubicación por WhatsApp y quedó en el pedido.']);
        } elseif ($what === self::OTHER) {
            OrderActivityLog::create(['order_id' => $order->id, 'action' => 'location_from_whatsapp_other',
                'description' => "El cliente mandó otra ubicación por WhatsApp: revísala. La nueva: {$link}"]);
        }

        return $what;
    }
}
