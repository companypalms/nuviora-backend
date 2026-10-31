<?php

namespace App\Services\Metrics\Reports;

use App\Services\Calendar\Rules\ReminderRules;
use Carbon\CarbonImmutable;

/**
 * Qué días mira cada reporte del negocio (`.48b`) y cuándo le toca salir, los mismos de las revisiones del calendario
 * (ReminderRules). PHP puro.
 * - Diario: los últimos 3 días sin contar hoy; sale de lunes a sábado (el domingo no se trabaja: el lunes mira viernes,
 *   sábado y domingo).
 * - Semanal: la semana pasada de lunes a domingo; sale el día de la revisión semanal del calendario (jueves por defecto).
 * - Mensual: el mes pasado completo; sale el día 8 (si cae domingo, el lunes 9).
 */
final class ReportPeriods
{
    public const LABELS = ['daily' => 'Diario', 'weekly' => 'Semanal', 'monthly' => 'Mensual'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} desde y hasta (sin hora) del reporte de ese tipo hecho ese día */
    public static function for(string $type, CarbonImmutable $day): array
    {
        $d = $day->startOfDay();

        return match ($type) {
            'daily' => [$d->subDays(3), $d->subDay()],
            'weekly' => ReminderRules::lastWeek($d),
            'monthly' => [$d->startOfMonth()->subMonthNoOverflow(), $d->startOfMonth()->subDay()],
            default => throw new \InvalidArgumentException("Tipo de reporte desconocido: {$type}"),
        };
    }

    /**
     * Los reportes que salen solos ese día.
     *
     * @param int $reviewWeekday el día de la revisión semanal del calendario (1 = lunes … 6 = sábado)
     * @param array<string, bool> $on qué revisiones del calendario están encendidas (review_3days, review_week, review_month)
     * @return string[]
     */
    public static function dueOn(CarbonImmutable $day, int $reviewWeekday, array $on = []): array
    {
        $out = [];
        if (!$day->isSunday() && ($on['review_3days'] ?? true)) {
            $out[] = 'daily';
        }
        if ($day->dayOfWeekIso === $reviewWeekday && ($on['review_week'] ?? true)) {
            $out[] = 'weekly';
        }
        $eighth = $day->startOfMonth()->addDays(7);
        $monthDay = $eighth->isSunday() ? $eighth->addDay() : $eighth;
        if ($day->isSameDay($monthDay) && ($on['review_month'] ?? true)) {
            $out[] = 'monthly';
        }

        return $out;
    }

    /** "Del lunes 26/10 al domingo 1/11". */
    public static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $f = fn (CarbonImmutable $d) => $days[$d->dayOfWeekIso] . ' ' . $d->format('j/n');

        return $from->isSameDay($to) ? 'El ' . $f($from) : 'Del ' . $f($from) . ' al ' . $f($to);
    }
}
