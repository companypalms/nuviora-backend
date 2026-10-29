<?php

namespace App\Services\Calendar\Rules;

use Carbon\CarbonImmutable;

/**
 * Las fechas del calendario del dueño (Etapa 3): en qué días aparece cada cosa. PHP puro, para probarlo sin la aplicación.
 *
 * Reglas del dueño (entrevista del 2026-10-29):
 * - Los domingos no se trabaja: lo que se repite y cae domingo se corre. Un pago se adelanta al sábado (para no pagar
 *   tarde); una tarea o una revisión pasa al lunes.
 * - Lo que el dueño anota para un solo día queda en el día que eligió, aunque sea domingo: lo eligió él.
 * - "Cada mes, el día 31" en un mes de 30 días es el último del mes; "el 15 y el último" es el 15 y el último día.
 * - Las tareas de todos los días van de lunes a sábado (el domingo no aparecen) y no se acumulan: la de hoy reemplaza a
 *   la de ayer. Lo demás (lo semanal, lo mensual, los pagos y las notas) sí queda atrasado hasta que se haga.
 *
 * Cada aparición tiene dos fechas: "due" es el día que le toca de verdad (con esa se tilda, así no cambia si cambia la
 * regla del domingo) y "date" es el día en que se muestra.
 */
final class Schedule
{
    /** Cómo se repite una cosa. "daily" y "last_workday" son de los recordatorios del sistema; el dueño elige las primeras. */
    public const REPEATS = [
        'none' => 'Solo ese día',
        'weekly' => 'Cada semana',
        'monthly' => 'Cada mes',
        'twice_monthly' => 'El 15 y el último de cada mes',
    ];

    /** Pago, tarea o revisión de números: cambia el color y la regla del domingo. */
    public const TYPES = ['payment' => 'Pago', 'task' => 'Tarea', 'review' => 'Revisión'];

    public const WEEKDAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    private const SYSTEM_REPEATS = ['daily', 'last_workday'];

    /**
     * Las apariciones de un patrón que se muestran entre dos días (incluidos), ya corridas por la regla del domingo.
     *
     * @param array{repeat: string, start: string, until?: ?string, type: string, weekday?: ?int, day?: ?int} $pattern
     *        start: desde qué día existe (y, si no se dice otra cosa, de él salen el día de la semana y el del mes).
     * @return list<array{date: string, due: string, moved: bool}>
     */
    public static function dates(array $pattern, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $start = CarbonImmutable::parse($pattern['start'])->startOfDay();
        $until = !empty($pattern['until']) ? CarbonImmutable::parse($pattern['until'])->startOfDay() : null;
        $repeat = $pattern['repeat'];

        // Un día de más a cada lado: un domingo corrido puede caer adentro del rango
        $lo = $from->subDay()->max($start);
        $hi = $to->addDay();
        if ($until && $until->lt($hi)) {
            $hi = $until;
        }
        if ($lo->gt($hi)) {
            return [];
        }

        $out = [];
        foreach (self::dueDates($repeat, $pattern, $start, $lo, $hi) as $due) {
            $date = self::shift($due, $repeat, $pattern['type']);
            if ($date->gte($from) && $date->lte($to)) {
                $out[] = ['date' => $date->toDateString(), 'due' => $due->toDateString(), 'moved' => !$date->eq($due)];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /** Si una cosa tiene una aparición que le toca ese día (para tildarla: no se tilda un día que no le corresponde). */
    public static function isDue(array $pattern, string $due): bool
    {
        $day = CarbonImmutable::parse($due);
        foreach (self::dates($pattern, $day->subDays(2), $day->addDays(2)) as $d) {
            if ($d['due'] === $due) {
                return true;
            }
        }

        return false;
    }

    /** El domingo: el pago se adelanta al sábado, lo demás pasa al lunes. Lo de un solo día no se corre. */
    public static function shift(CarbonImmutable $due, string $repeat, string $type): CarbonImmutable
    {
        if ($repeat === 'none' || in_array($repeat, self::SYSTEM_REPEATS, true) || !$due->isSunday()) {
            return $due;
        }

        return $type === 'payment' ? $due->subDay() : $due->addDay();
    }

    /**
     * El estado de una aparición: hecha o descartada (si se marcó); si no, pendiente, o atrasada si su día ya pasó. Las
     * que no se acumulan (las de todos los días) quedan "missed": pasaron sin hacerse, pero ya no se piden.
     */
    public static function status(?string $mark, string $date, string $today, bool $accumulates): string
    {
        if ($mark === 'done' || $mark === 'dismissed') {
            return $mark;
        }
        if ($date >= $today) {
            return 'pending';
        }

        return $accumulates ? 'overdue' : 'missed';
    }

    /**
     * Una aparición en una frase, para leerla sin mirar la pantalla (la usará el asistente de IA): lo que es, si se corrió
     * por un domingo y cómo está. "Pago: Editores. El 15/11 cae domingo: se adelantó al sábado. Atrasado: era del sábado 14/11."
     */
    public static function sentence(string $text, string $date, string $due, string $status): string
    {
        if ($due !== $date) {
            $text .= ' ' . self::movedText($due, $date);
        }

        return $text . match ($status) {
            'done' => ' Hecho.',
            'dismissed' => ' Descartado.',
            'overdue' => ' Atrasado: era del ' . self::dayName($date) . '.',
            'missed' => ' Ese día pasó sin hacerse.',
            default => '',
        };
    }

    /** "Cada semana, los jueves", "Cada mes, el día 5", "El 15 y el último de cada mes"… con "hasta" si termina. */
    public static function label(array $pattern): string
    {
        $start = CarbonImmutable::parse($pattern['start']);
        $label = match ($pattern['repeat']) {
            'none' => 'Solo el ' . $start->format('d/m/Y'),
            'weekly' => 'Cada semana, los ' . self::plural(self::WEEKDAYS[$pattern['weekday'] ?? $start->dayOfWeekIso]),
            'monthly' => self::monthlyLabel((int) ($pattern['day'] ?? $start->day)),
            'twice_monthly' => 'El 15 y el último de cada mes',
            'daily' => 'De lunes a sábado',
            'last_workday' => 'El último día hábil de cada mes',
            default => '',
        };
        if ($pattern['repeat'] !== 'none' && !empty($pattern['until'])) {
            $label .= ', hasta el ' . CarbonImmutable::parse($pattern['until'])->format('d/m/Y');
        }

        return $label;
    }

    /** "jueves 5/11" (para los textos). */
    public static function dayName(string $ymd): string
    {
        $d = CarbonImmutable::parse($ymd);

        return self::WEEKDAYS[$d->dayOfWeekIso] . ' ' . $d->format('j/n');
    }

    /** Por qué se corrió: "El 15 cae domingo: se adelantó al sábado." */
    public static function movedText(string $due, string $date): string
    {
        $d = CarbonImmutable::parse($due);
        $to = CarbonImmutable::parse($date);

        return 'El ' . $d->format('j/n') . ' cae domingo: ' . ($to->lt($d) ? 'se adelantó al sábado.' : 'pasó al lunes.');
    }

    /** @return list<CarbonImmutable> los días que le tocan, entre lo y hi (incluidos), sin correr. */
    private static function dueDates(string $repeat, array $pattern, CarbonImmutable $start, CarbonImmutable $lo, CarbonImmutable $hi): array
    {
        $days = [];
        switch ($repeat) {
            case 'none':
                if ($start->gte($lo) && $start->lte($hi)) {
                    $days[] = $start;
                }
                break;
            case 'weekly':
                $weekday = (int) ($pattern['weekday'] ?? $start->dayOfWeekIso);
                for ($d = $lo->addDays(($weekday - $lo->dayOfWeekIso + 7) % 7); $d->lte($hi); $d = $d->addWeek()) {
                    $days[] = $d;
                }
                break;
            case 'daily':
                for ($d = $lo; $d->lte($hi); $d = $d->addDay()) {
                    if (!$d->isSunday()) {
                        $days[] = $d;
                    }
                }
                break;
            case 'monthly':
            case 'twice_monthly':
            case 'last_workday':
                for ($m = $lo->startOfMonth(); $m->lte($hi); $m = $m->addMonthNoOverflow()) {
                    foreach (self::monthDays($repeat, $pattern, $start, $m) as $d) {
                        if ($d->gte($lo) && $d->lte($hi)) {
                            $days[] = $d;
                        }
                    }
                }
                break;
        }

        return $days;
    }

    /** @return list<CarbonImmutable> */
    private static function monthDays(string $repeat, array $pattern, CarbonImmutable $start, CarbonImmutable $month): array
    {
        $last = $month->endOfMonth()->startOfDay();

        return match ($repeat) {
            'monthly' => [$month->addDays(min((int) ($pattern['day'] ?? $start->day), $month->daysInMonth) - 1)],
            'twice_monthly' => [$month->addDays(14), $last],
            'last_workday' => [$last->isSunday() ? $last->subDay() : $last],
            default => [],
        };
    }

    private static function monthlyLabel(int $day): string
    {
        return $day >= 31 ? 'Cada mes, el último día' : "Cada mes, el día {$day}";
    }

    private static function plural(string $weekday): string
    {
        return in_array($weekday, ['sábado', 'domingo'], true) ? $weekday . 's' : $weekday;
    }
}
