<?php

namespace App\Services\Metrics;

/**
 * Cómo se agrupan los días de un período en los gráficos del panel del dueño: por día hasta 45 días, por semana
 * (de lunes a domingo) hasta 200, y si no, por mes. PHP puro.
 */
final class Buckets
{
    public static function granularity(Period $period): string
    {
        $days = $period->days();

        return $days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month');
    }

    /** El grupo de una fecha: la misma fecha, el lunes de su semana o el primero de su mes. */
    public static function key(string $date, string $granularity): string
    {
        $ts = strtotime($date);

        return match ($granularity) {
            'week' => date('Y-m-d', strtotime('-' . ((int) date('N', $ts) - 1) . ' days', $ts)),
            'month' => date('Y-m-01', $ts),
            default => $date,
        };
    }

    /**
     * Los grupos del período, en orden, con su primer y último día dentro del período.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ranges(Period $period, string $granularity): array
    {
        $out = [];
        foreach ($period->dates() as $date) {
            $key = self::key($date, $granularity);
            $out[$key] ??= [$date, $date];
            $out[$key][1] = $date;
        }

        return $out;
    }
}
