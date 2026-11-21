<?php

namespace App\Services\Orders;

use App\Models\City;
use App\Services\Storefront\StorefrontCatalog;

/**
 * `.174` (dueño, 2026-11-21): la zona "Resto de Venezuela". Es una ciudad solo para los números y Meta Ads:
 * - la 4.ª campaña (la del resto del país) se clasifica en ella ("RDV" en el nombre);
 * - todas las encomiendas cuentan en ella (dueño: "todas las encomiendas"): CPA objetivo, break-even, ganancia, efectividad,
 *   reportes y "Tu día en Meta Ads". Se siguen despachando desde su ciudad de origen (agencia, stock y motorizado: igual);
 * - su stock es el de la ciudad de donde salen las encomiendas;
 * - lo que cobra MRW o Zoom por envío (un promedio que pone el dueño) es un costo de cada encomienda despachada.
 * No tiene agencias y no sale en las listas para operar (City::operational()).
 */
final class CourierZone
{
    public const NAME = 'Resto de Venezuela';

    public const FEE_MISSING = 'Falta el costo de la encomienda: ponlo en Formulario de la tienda › Pago anticipado, asesora y encomiendas.';

    /** La ciudad con la que cuenta un pedido en los números: la encomienda, en la zona; los demás, en la suya. */
    public static function financeCity(bool $courier, ?int $cityId, ?int $zoneId): ?int
    {
        return $courier && $zoneId !== null ? $zoneId : $cityId;
    }

    /** Lo que cobra MRW o Zoom por una encomienda despachada (sin el promedio puesto, 0: la zona avisa que falta). */
    public static function fee(bool $courier, bool $shipped, ?float $feeUsd): float
    {
        return $courier && $shipped ? round((float) ($feeUsd ?? 0), 2) : 0.0;
    }

    /**
     * Los almacenes de cada ciudad, con los de la zona: los de la ciudad de donde salen las encomiendas.
     *
     * @param array<int, int[]> $byCity
     * @return array<int, int[]>
     */
    public static function withWarehouses(array $byCity, ?int $zoneId, ?int $originId): array
    {
        if ($zoneId !== null) {
            $byCity[$zoneId] = $originId !== null ? ($byCity[$originId] ?? []) : [];
        }

        return $byCity;
    }

    public static function id(): ?int
    {
        $id = City::where('name', self::NAME)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** La ciudad de donde salen las encomiendas (Formulario de la tienda). */
    public static function originId(): ?int
    {
        return StorefrontCatalog::settings()['courier_origin_city_id'];
    }

    /** El promedio que cobra MRW o Zoom por envío, en USD (null = el dueño todavía no lo puso). */
    public static function feeUsd(): ?float
    {
        return StorefrontCatalog::settings()['courier_fee_usd'];
    }
}
