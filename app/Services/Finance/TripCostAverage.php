<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/**
 * Lo que cuesta en promedio una carrera con costo, por ciudad y en total (de order_financials, por fecha de creación), para
 * completar las que todavía no tienen costo: una carrera sin costo no es gratis. Lo usan Finanzas › CPA y, desde la `.49`
 * (decisión del dueño del 2026-10-31), el panel del dueño y sus reportes.
 */
final class TripCostAverage
{
    /** El panel usa las carreras de estos días, hasta el último día que mira. */
    public const PANEL_DAYS = 28;

    /** @return array{cities: array<int, float>, all: float} */
    public static function between(string $from, string $to): array
    {
        $rows = DB::table('order_financials')->whereBetween('created_date', [$from, $to])
            ->groupBy('city_id')->selectRaw('city_id, SUM(delivery_cost) cost, SUM(delivery_trips - delivery_missing_trips) trips')->get();
        $cities = [];
        foreach ($rows as $r) {
            if ($r->city_id !== null && (int) $r->trips > 0) {
                $cities[(int) $r->city_id] = (float) $r->cost / (int) $r->trips;
            }
        }
        $trips = (int) $rows->sum('trips');

        return ['cities' => $cities, 'all' => $trips > 0 ? (float) $rows->sum('cost') / $trips : 0.0];
    }

    /** Los 28 días que terminan en $to (el panel). */
    public static function forPanel(string $to): array
    {
        $from = \Carbon\CarbonImmutable::parse($to)->subDays(self::PANEL_DAYS - 1)->toDateString();

        return self::between($from, $to);
    }

    /** Lo que se le suma a un pedido por sus carreras sin costo: cada una, el promedio de su ciudad (o el de todas). */
    public static function fill(int $missingTrips, ?int $cityId, array $average): float
    {
        if ($missingTrips <= 0) {
            return 0.0;
        }

        return $missingTrips * ($cityId !== null && isset($average['cities'][$cityId]) ? $average['cities'][$cityId] : $average['all']);
    }
}
