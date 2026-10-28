<?php

namespace App\Services\Logistics;

use App\Models\LogisticsAlert;
use App\Models\LogisticsOrder;
use App\Models\RiderProfile;

/**
 * Entrega y "no responde" en el sitio (sección 06 del informe del 27/10, punto 1; Fran: "que se aplique todo"). Al marcar
 * "Entregado" o al reportar que el cliente no responde, se compara dónde estaba el rider con la ubicación del cliente. Si
 * está más lejos que el radio del centro (300 m de inicio, configurable), queda marcado para administración. No se le
 * bloquea nada al rider: el §12.1 pide solo la foto del comprobante. Frena la entrega falsa y el "no contestó"
 * inventado, que además le paga la ida (R7).
 */
class SiteCheck
{
    /** Más vieja que esto, la última ubicación del rider no dice dónde estaba al entregar. */
    public const MAX_GPS_AGE_SECONDS = 180;

    public function __construct(private LogisticsSettings $settings, private LogisticsOrders $orders, private Alerts $alerts)
    {
    }

    /** @return array{distance_m: ?int, off_site: bool, source: string} */
    public function check(LogisticsOrder $row, string $what, ?float $lat = null, ?float $lng = null, ?int $actorId = null): array
    {
        $order = $row->order;
        $center = $row->center;
        if (!$center || !$row->rider_id || $order?->location_lat === null || $order?->location_lng === null) {
            return ['distance_m' => null, 'off_site' => false, 'source' => 'sin_ubicacion_cliente'];
        }
        $source = 'app';
        if ($lat === null || $lng === null) {
            $profile = RiderProfile::where('user_id', $row->rider_id)->first();
            $recent = $profile?->hasLocation() && $profile->last_location_at->gte(now()->subSeconds(self::MAX_GPS_AGE_SECONDS));
            [$lat, $lng, $source] = $recent ? [(float) $profile->last_lat, (float) $profile->last_lng, 'ultimo_gps'] : [null, null, 'sin_gps'];
        }
        $radius = (int) $this->settings->for($center)['onsite_radius_meters'];
        $distance = $lat !== null ? (int) round(RiderEta::straightKm($lat, $lng, (float) $order->location_lat, (float) $order->location_lng) * 1000) : null;
        // Sin GPS reciente tampoco se sabe si estaba: también se marca
        $offSite = $distance === null || $distance > $radius;
        $this->orders->event($row, $what === 'entrega' ? 'entrega_en_sitio' : 'novedad_en_sitio', $row->status, $row->status, $actorId,
            $offSite ? ($distance === null ? 'Sin GPS reciente del rider.' : "A {$distance} m del cliente.") : null,
            ['distancia_m' => $distance, 'radio_m' => $radius, 'fuera' => $offSite, 'fuente' => $source]);
        if ($offSite) {
            $label = 'Orden ' . ($order->name ?? "#{$order->id}");
            $verb = $what === 'entrega' ? 'marcó "Entregado"' : 'reportó que el cliente no responde';
            $where = $distance === null ? 'sin GPS reciente' : "a {$distance} m del cliente (más de {$radius} m)";
            $this->alerts->raise($center, 'fuera_del_sitio', LogisticsAlert::WARNING, "{$label}: {$row->rider?->names} {$verb} {$where}.",
                "sitio:{$what}:{$row->id}", $order->id, $row->rider_id, ['distancia_m' => $distance]);
        }

        return ['distance_m' => $distance, 'off_site' => $offSite, 'source' => $source];
    }
}
