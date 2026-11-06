<?php

namespace App\Services\Calendar\Rules;

use Carbon\CarbonImmutable;

/**
 * `.108` "Tu día" (dueño, 2026-11-06: "un sistema de tiempo de cuánto me debe tomar cada tarea y con notificaciones de que
 * se agotó el tiempo"): las cuentas del reloj de cada tarea, de la racha y de cuánto sueles tardar. PHP puro.
 *
 * El reloj corre desde que se toca "Empezar"; "Pausar" guarda lo que corrió; "+5 min" le suma tiempo; al acabarse sigue en
 * rojo con lo de más (lo decidió el dueño). Una tarea se hizo "a tiempo" si se hizo dentro de los minutos que tenía al
 * empezar (los "+5" no cuentan).
 */
final class TimerRules
{
    /** Lo más que se le puede sumar con "+5 min". */
    public const MAX_EXTRA = 120;

    public const EXTEND = 5;

    /** "Sueles tardar" sale desde la 3.ª vez, con las últimas 5. */
    public const USUAL_MIN = 3;

    public const USUAL_LAST = 5;

    /** Los segundos que corrió (lo de antes más la vuelta de ahora, si está corriendo). */
    public static function elapsed(int $spentSeconds, ?CarbonImmutable $startedAt, CarbonImmutable $now): int
    {
        $run = $startedAt !== null && $now->greaterThan($startedAt) ? (int) floor(abs($startedAt->diffInSeconds($now))) : 0;

        return $spentSeconds + $run;
    }

    /** Los segundos que tiene en total (sus minutos más los "+5"). */
    public static function limit(int $minutes, int $extraMinutes): int
    {
        return ($minutes + $extraMinutes) * 60;
    }

    /** Cuándo se acaba, si está corriendo (null: en pausa). */
    public static function endsAt(int $minutes, int $extraMinutes, int $spentSeconds, ?CarbonImmutable $startedAt): ?CarbonImmutable
    {
        return $startedAt?->addSeconds(self::limit($minutes, $extraMinutes) - $spentSeconds);
    }

    /** Si se hizo dentro de los minutos que tenía al empezar. */
    public static function onTime(int $elapsedSeconds, int $minutes): bool
    {
        return $elapsedSeconds <= $minutes * 60;
    }

    /**
     * Cuánto sueles tardar, en minutos (redondeado, al menos 1), con las últimas veces que la hiciste con el reloj (de la más
     * nueva a la más vieja). Null si todavía son menos de 3.
     *
     * @param list<int> $seconds
     */
    public static function usual(array $seconds): ?int
    {
        if (count($seconds) < self::USUAL_MIN) {
            return null;
        }
        $last = array_slice($seconds, 0, self::USUAL_LAST);

        return max(1, (int) round(array_sum($last) / count($last) / 60));
    }

    /**
     * La racha: los días de trabajo seguidos (de lunes a sábado; el domingo no corta) con todo hecho, contando hacia atrás
     * desde ayer, y hoy si ya está todo hecho (si hoy todavía no, no corta). Un día de trabajo sin terminar, o en que no se
     * abrió el calendario, la corta.
     *
     * @param array<string, bool> $days "Y-m-d" => si quedó todo hecho
     */
    public static function streak(array $days, string $today): int
    {
        $n = ($days[$today] ?? false) ? 1 : 0;
        $d = CarbonImmutable::parse($today)->subDay();
        $oldest = $days === [] ? $today : min(array_keys($days));
        while ($d->toDateString() >= $oldest) {
            if ($d->dayOfWeekIso !== 7) {
                if (!($days[$d->toDateString()] ?? false)) {
                    break;
                }
                $n++;
            }
            $d = $d->subDay();
        }

        return $n;
    }

    /**
     * La mejor racha que hubo (la de días seguidos más larga; el domingo no corta).
     *
     * @param array<string, bool> $days
     */
    public static function best(array $days): int
    {
        if ($days === []) {
            return 0;
        }
        ksort($days);
        $best = $run = 0;
        $d = CarbonImmutable::parse(array_key_first($days));
        $last = array_key_last($days);
        for (; $d->toDateString() <= $last; $d = $d->addDay()) {
            if ($d->dayOfWeekIso === 7) {
                continue;
            }
            $run = ($days[$d->toDateString()] ?? false) ? $run + 1 : 0;
            $best = max($best, $run);
        }

        return $best;
    }
}
