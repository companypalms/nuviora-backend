<?php

namespace App\Services\Calendar\Rules;

/**
 * `.107` "Tu día" (dueño, 2026-11-06: "por orden de prioridad, urgencia y tiempo que toman"): la prioridad y los minutos de
 * cada cosa del calendario, y en qué orden se hace el día. PHP puro.
 *
 * Cada cosa tiene una prioridad (alta, normal o baja) y cuántos minutos toma: los recordatorios del sistema traen los que
 * propuso Claude y el dueño los cambia (se guardan en calendar_item_plans, para todos los días); las notas empiezan en
 * normal y 15 minutos; los gastos fijos, en alta y 5 minutos ("expense:*" cambia los de todos los gastos a la vez).
 *
 * El día va por etapas: urgente (lo atrasado y lo que ya es su hora) → alta → normal → baja, y al final lo que tiene hora
 * más tarde ("a su hora"). Dentro de cada prioridad, primero el orden que puso el dueño arrastrando (rank) y después la más
 * corta primero.
 */
final class DayPlan
{
    public const PRIORITIES = ['alta' => 'Alta', 'normal' => 'Normal', 'baja' => 'Baja'];

    /** Los minutos que se eligen con un toque (se acepta cualquiera entre 1 y MAX_MINUTES). */
    public const MINUTES = [5, 10, 15, 20, 30, 45, 60, 90];

    public const MAX_MINUTES = 240;

    /** Lo que tiene hora pasa a "urgente" este número de minutos antes. */
    public const SOON = 15;

    public const STAGES = ['urgent' => 'Urgente', 'alta' => 'Alta', 'normal' => 'Normal', 'baja' => 'Baja', 'later' => 'A su hora'];

    public const NOTE_DEFAULT = ['priority' => 'normal', 'minutes' => 15];

    public const EXPENSE_DEFAULT = ['priority' => 'alta', 'minutes' => 5];

    /** La clave que cambia los minutos y la prioridad de todos los gastos fijos que no tienen los suyos. */
    public const ALL_EXPENSES = 'expense:*';

    /** Lo que propuso Claude para cada recordatorio del sistema (el dueño lo cambia en la pantalla). */
    public const RULE_DEFAULTS = [
        'sellers_pay' => ['priority' => 'alta', 'minutes' => 15],
        'agencies_pay' => ['priority' => 'alta', 'minutes' => 15],
        'rate' => ['priority' => 'alta', 'minutes' => 5],
        'reconciliation' => ['priority' => 'alta', 'minutes' => 20],
        'approvals' => ['priority' => 'alta', 'minutes' => 10],
        'inventory' => ['priority' => 'normal', 'minutes' => 10],
        'meta_analysis' => ['priority' => 'alta', 'minutes' => 15],
        'meta_queue' => ['priority' => 'normal', 'minutes' => 10],
        'meta_deliveries' => ['priority' => 'normal', 'minutes' => 15],
        'meta_flow' => ['priority' => 'normal', 'minutes' => 5],
        'meta_bank' => ['priority' => 'normal', 'minutes' => 10],
        'review_3days' => ['priority' => 'normal', 'minutes' => 15],
        'review_week' => ['priority' => 'normal', 'minutes' => 30],
        'editors_week' => ['priority' => 'normal', 'minutes' => 20],
        'learnings_week' => ['priority' => 'baja', 'minutes' => 15],
        'review_month' => ['priority' => 'normal', 'minutes' => 45],
        'goals_month' => ['priority' => 'alta', 'minutes' => 20],
    ];

    /** La prioridad y los minutos de una cosa cuando el dueño todavía no los cambió. */
    public static function defaults(string $itemKey, ?array $allExpenses = null): array
    {
        if (str_starts_with($itemKey, 'rule:')) {
            return self::RULE_DEFAULTS[substr($itemKey, 5)] ?? self::NOTE_DEFAULT;
        }
        if (str_starts_with($itemKey, 'expense:')) {
            return $allExpenses !== null ? ['priority' => $allExpenses['priority'], 'minutes' => (int) $allExpenses['minutes']] : self::EXPENSE_DEFAULT;
        }

        return self::NOTE_DEFAULT;
    }

    /**
     * La prioridad, los minutos y el lugar de una cosa: lo que guardó el dueño ($row) o lo de siempre.
     *
     * @return array{priority: string, minutes: int, rank: ?int}
     */
    public static function resolve(string $itemKey, ?array $row, ?array $allExpenses = null): array
    {
        $base = self::defaults($itemKey, $allExpenses);

        return [
            'priority' => isset(self::PRIORITIES[$row['priority'] ?? '']) ? $row['priority'] : $base['priority'],
            'minutes' => (int) ($row['minutes'] ?? 0) > 0 ? (int) $row['minutes'] : $base['minutes'],
            'rank' => isset($row['rank']) ? (int) $row['rank'] : null,
        ];
    }

    /** Qué está mal en la prioridad o los minutos que llegaron (vacío: se puede guardar). */
    public static function problems(mixed $priority, mixed $minutes, string $prefix = ''): array
    {
        $problems = [];
        if ($priority !== null && !isset(self::PRIORITIES[$priority])) {
            $problems["{$prefix}priority"] = 'Elige la prioridad: alta, normal o baja.';
        }
        if ($minutes !== null && (!is_numeric($minutes) || (int) $minutes != $minutes || (int) $minutes < 1 || (int) $minutes > self::MAX_MINUTES)) {
            $problems["{$prefix}minutes"] = 'Los minutos van de 1 a ' . self::MAX_MINUTES . '.';
        }

        return $problems;
    }

    /**
     * El día en etapas, de lo primero a lo último. Entra lo pendiente de hoy (en el orden de siempre) y lo atrasado; cada
     * cosa necesita item_key, due_date, date, time, priority, minutes y rank. $nowHm es la hora de Venezuela ("09:40"), o
     * null para un día que no es hoy (lo que tiene hora va "a su hora").
     *
     * @return list<array{key: string, label: string, minutes: int, items: list<array{item_key: string, due_date: string, reason: string}>}>
     */
    public static function order(array $pending, array $overdue, ?string $nowHm): array
    {
        $soon = $nowHm !== null ? self::addMinutes($nowHm, self::SOON) : null;
        $stages = array_fill_keys(array_keys(self::STAGES), []);

        usort($overdue, fn ($a, $b) => [$a['date'], $a['title'] ?? ''] <=> [$b['date'], $b['title'] ?? '']);
        foreach ($overdue as $item) {
            $stages['urgent'][] = $item + ['reason' => 'Atrasada: era del ' . self::dayMonth($item['date']) . '.'];
        }

        $now = [];
        foreach (array_values($pending) as $i => $item) {
            $item['index'] = $i;
            $time = $item['time'] ?? null;
            if ($time !== null && $soon !== null && $time <= $soon) {
                $now[] = $item + ['reason' => ($time <= $nowHm ? "Era a las {$time}." : "Es a las {$time}.")];
            } elseif ($time !== null) {
                $stages['later'][] = $item + ['reason' => "A las {$time} (hora de Venezuela)."];
            } else {
                $stages[$item['priority']][] = $item;
            }
        }
        usort($now, fn ($a, $b) => $a['time'] <=> $b['time']);
        $stages['urgent'] = array_merge($stages['urgent'], $now);
        usort($stages['later'], fn ($a, $b) => [$a['time'], $a['index']] <=> [$b['time'], $b['index']]);

        foreach (array_keys(self::PRIORITIES) as $p) {
            usort($stages[$p], fn ($a, $b) => [$a['rank'] === null ? 1 : 0, $a['rank'] ?? 0, $a['minutes'], $a['index']]
                <=> [$b['rank'] === null ? 1 : 0, $b['rank'] ?? 0, $b['minutes'], $b['index']]);
            foreach ($stages[$p] as $i => $item) {
                $stages[$p][$i]['reason'] = 'Prioridad ' . mb_strtolower(self::PRIORITIES[$p]) . ($item['rank'] !== null
                    ? ': en el orden que pusiste.'
                    : ($i === 0 && isset($stages[$p][1]) && $stages[$p][1]['minutes'] > $item['minutes'] ? ': es la más corta.' : '.'));
            }
        }

        $out = [];
        foreach ($stages as $key => $items) {
            if ($items === []) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => self::STAGES[$key],
                'minutes' => array_sum(array_column($items, 'minutes')),
                'items' => array_map(fn ($i) => ['item_key' => $i['item_key'], 'due_date' => $i['due_date'], 'reason' => $i['reason']], $items),
            ];
        }

        return $out;
    }

    /** "~1 h 25 min", "~45 min" (lo que dice el asistente). */
    public static function minutesLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return "~{$minutes} min";
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return "~{$h} h" . ($m > 0 ? " {$m} min" : '');
    }

    private static function addMinutes(string $hm, int $minutes): string
    {
        [$h, $m] = array_map('intval', explode(':', $hm));
        $total = min(23 * 60 + 59, $h * 60 + $m + $minutes);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private static function dayMonth(string $date): string
    {
        [, $m, $d] = explode('-', $date);

        return (int) $d . '/' . (int) $m;
    }
}
