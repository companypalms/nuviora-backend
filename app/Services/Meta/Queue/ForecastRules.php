<?php

namespace App\Services\Meta\Queue;

/**
 * `.88` (dueño, 2026-11-03: "debemos buscar una mejor manera de predecir si vamos a necesitar ayuda de un flotante o
 * tenemos que contratar a otra persona fija… siempre todo en el negocio se hace en pro a seguir creciendo y escalando… el
 * ingreso de productos nuevos a los que hay que hacerles 3 batchs de inicio"): el pronóstico de las próximas 2 semanas, día
 * por día. Se necesita: lo que ya está pedido (lo atrasado, hoy), lo que se va a cansar (reponer) y los productos nuevos;
 * se puede: lo que entrega el equipo por día. Lo que decidió: si falta solo algunos días, un flotante para esos batches; si
 * falta 2 semanas seguidas, un editor fijo. PHP puro.
 */
final class ForecastRules
{
    public const DAYS = 14;

    /** Menos que esto (en batches) es redondeo, no falta. */
    private const NOISE = 0.5;

    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /**
     * @param array{due: array<int, float>, rate: float, new_rate: float, capacity: float, per_editor: float, reserve_days: int, start: string} $in
     *        due: lo ya pedido y sin entregar, por día desde hoy (0 = hoy, con lo atrasado); rate: lo que se repone por día
     *        (desde después de la reserva); new_rate: los batches de inicio de los productos nuevos por día (desde mañana)
     * @return array{verdict: string, text: string, need: int, can: int, weeks: list<array{need: float, can: float}>, short_days: list<string>, extra: int}
     */
    public static function run(array $in): array
    {
        $cap = max(0.0, (float) $in['capacity']);
        $weeks = [['need' => 0.0, 'can' => 0.0], ['need' => 0.0, 'can' => 0.0]];
        $cum = 0.0;
        $behind = 0.0;
        $worst = 0.0;
        $short = [];
        for ($d = 0; $d < self::DAYS; $d++) {
            $need = (float) ($in['due'][$d] ?? 0) + ($d > (int) $in['reserve_days'] ? (float) $in['rate'] : 0.0) + ($d >= 1 ? (float) $in['new_rate'] : 0.0);
            $cum += $need;
            $weeks[intdiv($d, 7)]['need'] += $need;
            $weeks[intdiv($d, 7)]['can'] += $cap;
            $gap = $cum - $cap * ($d + 1);
            // Un día "corto": el atraso crece ese día
            if ($gap > self::NOISE && $gap > $behind + 0.01) {
                $short[] = date('Y-m-d', strtotime("{$in['start']} +{$d} days"));
            }
            $behind = max(0.0, $gap);
            $worst = max($worst, $gap);
        }
        $weekShort = array_map(fn ($w) => $w['need'] - $w['can'] > self::NOISE, $weeks);
        $need = (int) round($weeks[0]['need'] + $weeks[1]['need']);
        $can = (int) round($weeks[0]['can'] + $weeks[1]['can']);
        $base = ['need' => $need, 'can' => $can, 'weeks' => array_map(fn ($w) => ['need' => round($w['need'], 1), 'can' => round($w['can'], 1)], $weeks),
            'short_days' => $short];

        if ($weekShort[0] && $weekShort[1]) {
            $gap = (($weeks[0]['need'] - $weeks[0]['can']) + ($weeks[1]['need'] - $weeks[1]['can'])) / 2;
            $perWeek = max(0.1, (float) $in['per_editor']) * 7;
            $extra = max(1, (int) ceil($gap / $perWeek));

            return $base + ['verdict' => 'fixed', 'extra' => $extra,
                'text' => 'Contrata ' . ($extra === 1 ? 'un editor fijo' : "{$extra} editores fijos") . ': faltan ~' . (int) ceil($gap)
                    . ' batches por semana, 2 semanas seguidas.'];
        }
        if ($worst > self::NOISE) {
            $extra = (int) ceil($worst);

            return $base + ['verdict' => 'floater', 'extra' => $extra,
                'text' => "Un flotante para ~{$extra} " . ($extra === 1 ? 'batch' : 'batches') . ' (' . self::days(array_slice($short, 0, 3)) . ').'];
        }
        $spare = (int) floor($weeks[1]['can'] - $weeks[1]['need']);

        return $base + ['verdict' => 'ok', 'extra' => 0,
            'text' => 'Tu equipo alcanza' . ($spare > 0 ? ": le sobran ~{$spare} " . ($spare === 1 ? 'batch' : 'batches') . ' por semana.' : '.')];
    }

    /** "el jueves 5 y el viernes 6". */
    public static function days(array $dates): string
    {
        $names = array_map(fn ($d) => 'el ' . self::WEEKDAYS[(int) date('w', strtotime($d))] . ' ' . (int) date('j', strtotime($d)), $dates);

        return count($names) < 2 ? implode('', $names) : implode(', ', array_slice($names, 0, -1)) . ' y ' . end($names);
    }
}
