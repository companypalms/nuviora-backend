<?php

namespace App\Services\Meta\Analysis;

use App\Models\MetaAnalysis;

/**
 * `.82` (dueño, 2026-11-02: "que todo tenga la lógica y la usabilidad de un juego"): la racha del análisis de campañas,
 * cuántos días seguidos se resolvieron todas las misiones del día (hecho o "no estoy de acuerdo"), contando la última
 * versión de cada día. Un día sin misiones o sin análisis no suma ni corta la racha; un día con misiones sin resolver la
 * corta. Hoy se suma en la pantalla cuando se termina.
 */
final class DayStreak
{
    /** Cuántos días hacia atrás se miran. */
    public const LOOKBACK_DAYS = 120;

    /**
     * PHP puro: los días seguidos con todo resuelto hasta el día anterior a $date.
     *
     * @param array<string, array{tasks: int, resolved: int}> $days fecha => misiones del día (la última versión)
     */
    public static function count(array $days, string $date): int
    {
        krsort($days);
        $streak = 0;
        foreach ($days as $day => $t) {
            if ($day >= $date || $t['tasks'] === 0) {
                continue;
            }
            if ($t['resolved'] < $t['tasks']) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /** La racha hasta el día anterior a $date, con los análisis guardados. */
    public static function before(string $date): int
    {
        $since = (new \DateTimeImmutable($date))->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');
        // la última versión de cada día (las versiones se crean en orden: la de id más alto)
        $ids = MetaAnalysis::where('date', '>=', $since)->where('date', '<', $date)->groupBy('date')->selectRaw('MAX(id) as id')->pluck('id');
        $days = [];
        foreach (MetaAnalysis::whereIn('id', $ids)->get(['id', 'date', 'urgent', 'marks']) as $a) {
            $days[$a->date->toDateString()] = UrgentList::tally($a->urgent ?? [], $a->marks ?? []);
        }

        return self::count($days, $date);
    }
}
