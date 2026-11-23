<?php

namespace App\Services\Meta\Queue;

/**
 * `.175` (dueño, 2026-11-23: "¿cómo me aseguro que mis editores no estén flojeando…?"; "la meta mínima diaria por editor son
 * 2 batchs al día. Si resulta que terminan esos batchs antes de su horario laboral…"): el horario, la meta y el ritmo de cada
 * editor. PHP puro; los momentos son de Venezuela ("Y-m-d H:i:s"). Lo que decidió en la ficha:
 * - el horario del líder lo pone el dueño; el de sus editores, el líder;
 * - "nunca se le da la opción al editor": cuando un editor queda libre, al líder le sale la misión de asignarle otro;
 * - el líder también tiene meta, la misma de todos.
 * Cómo se cuenta:
 * - un batch cuenta el día de su primera entrega (la V01); las correcciones no suman otro;
 * - la meta se mide en los días de su horario, menos los de permiso; sin horario no se juzga;
 * - libre = en su horario (o sin horario) y sin nada asignado por entregar.
 */
final class EditorPaceRules
{
    public const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /** Sin horario, para el pronóstico y el ritmo del equipo: de lunes a viernes, de 8 a 5. */
    public const ASSUMED = ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '17:00'];

    /** Quién puede poner el horario de quién: el dueño, el de todos; el líder, el de sus editores de video (no el suyo). */
    public static function canEdit(string $actorRole, int $actorId, string $targetRole, int $targetId): bool
    {
        if (!EditorRules::isEditor($targetRole)) {
            return false;
        }
        if (in_array($actorRole, ['Admin', 'Master'], true)) {
            return true;
        }

        return $actorRole === EditorRules::LEADER && $targetRole === EditorRules::EDITOR && $actorId !== $targetId;
    }

    /** Lo que está mal en un horario (null: está bien). */
    public static function invalid(array $days, string $start, string $end): ?string
    {
        if ($days === []) {
            return 'Elige al menos un día.';
        }
        foreach ($days as $d) {
            if (!is_int($d) || $d < 0 || $d > 6) {
                return 'Los días van de domingo a sábado.';
            }
        }
        if (!self::isTime($start) || !self::isTime($end)) {
            return 'Escribe la hora así: 08:00.';
        }

        return $start < $end ? null : 'La hora de salida tiene que ser después de la de entrada.';
    }

    /** ¿Le toca trabajar ese día? */
    public static function works(?array $s, string $date, array $off): bool
    {
        return $s !== null && in_array((int) date('w', strtotime($date)), $s['days'], true) && !in_array($date, $off, true);
    }

    /** Las horas de su horario entre dos momentos. */
    public static function hours(?array $s, array $off, string $from, string $to): float
    {
        if ($s === null || $to <= $from) {
            return 0.0;
        }
        $seconds = 0;
        for ($d = substr($from, 0, 10); $d <= substr($to, 0, 10); $d = date('Y-m-d', strtotime("{$d} +1 day"))) {
            if (!self::works($s, $d, $off)) {
                continue;
            }
            $a = max($from, "{$d} {$s['start']}:00");
            $b = min($to, "{$d} {$s['end']}:00");
            if ($b > $a) {
                $seconds += strtotime($b) - strtotime($a);
            }
        }

        return round($seconds / 3600, 2);
    }

    /**
     * Cómo está ahora: sin horario, de permiso, no le toca hoy, todavía no entra, trabajando o ya salió; y los minutos que le
     * quedan.
     *
     * @return array{state: string, left: ?int, start: ?string, end: ?string}
     */
    public static function now(?array $s, array $off, string $now): array
    {
        $date = substr($now, 0, 10);
        if ($s === null) {
            return ['state' => 'no_schedule', 'left' => null, 'start' => null, 'end' => null];
        }
        $out = ['left' => null, 'start' => $s['start'], 'end' => $s['end']];
        if (in_array($date, $off, true)) {
            return ['state' => 'day_off'] + $out;
        }
        if (!self::works($s, $date, $off)) {
            return ['state' => 'rest'] + $out;
        }
        $start = "{$date} {$s['start']}:00";
        $end = "{$date} {$s['end']}:00";
        if ($now < $start) {
            return ['state' => 'before'] + $out;
        }
        if ($now >= $end) {
            return ['state' => 'after'] + $out;
        }

        return ['state' => 'in', 'left' => intdiv(strtotime($end) - strtotime($now), 60)] + $out;
    }

    /** ¿Está libre? En su horario (o sin horario) y sin nada asignado por entregar. */
    public static function free(string $state, int $open): bool
    {
        return $open === 0 && in_array($state, ['in', 'no_schedule'], true);
    }

    /** Desde cuándo está libre: lo último entre la hora en que entró hoy y su última entrega de hoy. */
    public static function freeSince(?array $s, string $now, ?string $lastDelivery): ?string
    {
        $date = substr($now, 0, 10);
        $last = $lastDelivery !== null && substr($lastDelivery, 0, 10) === $date ? $lastDelivery : null;
        $start = $s !== null ? "{$date} {$s['start']}:00" : null;

        return $last !== null && ($start === null || $last > $start) ? $last : $start;
    }

    /** Los días de su horario entre dos fechas, sin los de permiso. */
    public static function workDays(?array $s, array $off, string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("{$d} +1 day"))) {
            if (self::works($s, $d, $off)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * En cuántos de sus días de trabajo entregó por primera vez la meta o más.
     *
     * @param array<string, int> $done fecha => batches entregados por primera vez ese día
     * @param list<string> $days sus días de trabajo
     * @return array{days: int, met: int, done: int}
     */
    public static function goalDays(array $done, array $days, int $goal): array
    {
        $met = count(array_filter($days, fn ($d) => (int) ($done[$d] ?? 0) >= $goal));

        return ['days' => count($days), 'met' => $met, 'done' => (int) array_sum(array_map(fn ($d) => (int) ($done[$d] ?? 0), $days))];
    }

    /**
     * Las horas de su horario que tardó cada batch: desde lo último entre que se lo asignaron y su entrega anterior, hasta su
     * primera entrega.
     *
     * @param list<array{start: string, end: string}> $spans
     * @return list<float>
     */
    public static function batchHours(array $spans, ?array $s, array $off): array
    {
        if ($s === null) {
            return [];
        }

        return array_values(array_map(fn ($x) => self::hours($s, $off, $x['start'], $x['end']), array_filter($spans, fn ($x) => $x['end'] > $x['start'])));
    }

    /**
     * Las horas de su horario en las que no tuvo nada asignado por entregar.
     *
     * @param list<array{0: string, 1: string}> $busy los ratos con algo asignado (desde, hasta)
     */
    public static function idleHours(array $busy, ?array $s, array $off, string $from, string $to): float
    {
        if ($s === null) {
            return 0.0;
        }
        usort($busy, fn ($a, $b) => strcmp($a[0], $b[0]));
        $merged = [];
        foreach ($busy as [$a, $b]) {
            $a = max($a, $from);
            $b = min($b, $to);
            if ($b <= $a) {
                continue;
            }
            $n = count($merged);
            if ($n > 0 && $a <= $merged[$n - 1][1]) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $b);
            } else {
                $merged[] = [$a, $b];
            }
        }
        $worked = array_sum(array_map(fn ($m) => self::hours($s, $off, $m[0], $m[1]), $merged));

        return round(max(0.0, self::hours($s, $off, $from, $to) - $worked), 2);
    }

    /**
     * Lo que hace el equipo por día si cada uno cumple su meta, en promedio contando los fines de semana (para "¿Alcanza el
     * equipo?", que mira 14 días seguidos). Sin horario: de lunes a viernes.
     *
     * @param list<?array> $schedules uno por editor
     */
    public static function capacity(array $schedules, int $goal): float
    {
        $share = array_sum(array_map(fn ($s) => count(($s ?? self::ASSUMED)['days']) / 7, $schedules));

        return round($goal * $share, 2);
    }

    /**
     * Cómo va el equipo contra la meta en unos días: lo que entregaron por primera vez contra la meta de sus días de trabajo
     * (sin horario: de lunes a viernes).
     *
     * @param list<array{schedule: ?array, off: list<string>, done: int}> $editors
     * @return array{done: int, goal: int, pct: ?float}
     */
    public static function teamPace(array $editors, int $goal, string $from, string $to): array
    {
        $want = 0;
        $done = 0;
        foreach ($editors as $e) {
            $want += $goal * count(self::workDays($e['schedule'] ?? self::ASSUMED, $e['off'] ?? [], $from, $to));
            $done += (int) $e['done'];
        }

        return ['done' => $done, 'goal' => $want, 'pct' => $want > 0 ? round($done / $want * 100, 1) : null];
    }

    /** "lunes a viernes, de 08:00 a 17:00". */
    public static function describe(?array $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $days = $s['days'];
        sort($days);
        $run = count($days) > 2 && $days === range($days[0], $days[count($days) - 1]);
        $names = $run ? self::WEEKDAYS[$days[0]] . ' a ' . self::WEEKDAYS[end($days)] : self::join(array_map(fn ($d) => self::WEEKDAYS[$d], $days));

        return "{$names}, de {$s['start']} a {$s['end']}";
    }

    private static function join(array $list): string
    {
        return count($list) > 1 ? implode(', ', array_slice($list, 0, -1)) . ' y ' . end($list) : (string) ($list[0] ?? '');
    }

    private static function isTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }
}
