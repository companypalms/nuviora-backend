<?php

namespace App\Services\Delivery\Closures;

use App\Models\AgencyClosureSetting;

/**
 * `.136` (dueño, 2026-11-11: "que a mí me diga de una manera notable cuánto es lo que el sistema determina que le debo
 * pagar a la agencia y cuánto dicen ellos, para yo saber qué tanta diferencia hay"): la diferencia de un cierre en
 * palabras y de dónde sale. Puro: no lee la base.
 *
 * La diferencia es lo que cobra la agencia (todas las filas) menos el cálculo interno (las filas emparejadas, cada carrera
 * una vez: ClosureReview::refreshTotals). Por eso cada fila suma a una sola parte:
 * - con otro monto (amount) o que cuadra (sin caso): lo que cobra menos lo calculado;
 * - sin carrera en el sistema (unmatched, ambiguous), repetida (duplicate) o sin cálculo (no_expected): todo lo que cobra.
 */
class ClosureGap
{
    /** El orden en que se muestran las partes. */
    public const PARTS = ['amount', 'unmatched', 'duplicate', 'no_expected', 'small', 'not_charged'];

    /**
     * Las partes de la diferencia, sin las vacías. Cada fila: ['kind' => tipo de su caso o null, 'reported' => lo que
     * cobra, 'expected' => lo que calculó el sistema]. $notCharged: las carreras del sistema que la agencia no cobró (solo
     * se cuentan: no suman a la diferencia).
     *
     * @param list<array{kind: ?string, reported: ?float, expected: ?float}> $rows
     * @return list<array{key: string, text: string, count: int, amount: ?float}>
     */
    public static function parts(array $rows, int $notCharged = 0): array
    {
        $sum = array_fill_keys(self::PARTS, ['count' => 0, 'amount' => 0.0]);
        foreach ($rows as $r) {
            $reported = (float) ($r['reported'] ?? 0);
            $key = match ($r['kind'] ?? null) {
                'amount' => 'amount',
                'unmatched', 'ambiguous' => 'unmatched',
                'duplicate' => 'duplicate',
                'no_expected' => 'no_expected',
                default => 'small',
            };
            $amount = in_array($key, ['amount', 'small'], true) ? $reported - (float) ($r['expected'] ?? 0) : $reported;
            $sum[$key]['count']++;
            $sum[$key]['amount'] += $amount;
        }
        $sum['not_charged']['count'] = $notCharged;

        $out = [];
        foreach (self::PARTS as $key) {
            $n = $sum[$key]['count'];
            $amount = $key === 'not_charged' ? null : round($sum[$key]['amount'], 2);
            // Lo que cuadra solo cuenta si deja centavos (dentro de la tolerancia)
            if ($n === 0 || ($key === 'small' && abs((float) $amount) < 0.005)) {
                continue;
            }
            $out[] = ['key' => $key, 'text' => self::text($key, $n), 'count' => $n, 'amount' => $amount];
        }

        return $out;
    }

    /** "4 carreras con otro monto". */
    public static function text(string $key, int $n): string
    {
        $one = $n === 1;

        return match ($key) {
            'amount' => $one ? '1 carrera con otro monto' : "{$n} carreras con otro monto",
            'unmatched' => $one ? '1 fila que el sistema no encontró' : "{$n} filas que el sistema no encontró",
            'duplicate' => $one ? '1 carrera cobrada dos veces' : "{$n} carreras cobradas dos veces",
            'no_expected' => $one ? '1 carrera sin cálculo del sistema' : "{$n} carreras sin cálculo del sistema",
            'small' => 'Centavos dentro de la tolerancia',
            'not_charged' => $one ? '1 carrera del sistema que la agencia no cobró' : "{$n} carreras del sistema que la agencia no cobró",
        };
    }

    /** "over" cobra de más, "under" de menos, "ok" dentro de la tolerancia; null sin los dos montos. */
    public static function verdict(?float $expected, ?float $reported, AgencyClosureSetting $setting): ?string
    {
        if ($expected === null || $reported === null) {
            return null;
        }

        return $setting->withinTolerance($expected, $reported) ? 'ok' : ($reported > $expected ? 'over' : 'under');
    }

    /**
     * El marcador de arriba: la semana más reciente de la lista. Compara lo mismo con lo mismo: el cálculo interno y lo que
     * cobran, solo de las agencias que ya subieron su archivo. Si ninguna lo subió, lo del sistema es lo provisional (lo
     * que ganaron por sus carreras) y 'provisional' lo dice.
     *
     * @param list<array> $closures los de ClosureList::present
     */
    public static function week(array $closures): ?array
    {
        if ($closures === []) {
            return null;
        }
        $start = max(array_column($closures, 'period_start'));
        $week = array_values(array_filter($closures, fn ($c) => $c['period_start'] === $start));
        $withFile = array_values(array_filter($week, fn ($c) => $c['reported_total'] !== null));
        $both = array_filter($withFile, fn ($c) => $c['expected_total'] !== null);

        return [
            'period_start' => $start,
            'week' => $week[0]['week'],
            'agencies' => count($week),
            'with_file' => count($withFile),
            'system' => round($both === []
                ? array_sum(array_map(fn ($c) => (float) ($c['expected_total'] ?? $c['provisional'] ?? 0), $week))
                : array_sum(array_column($both, 'expected_total')), 2),
            'provisional' => $both === [],
            'reported' => $withFile === [] ? null : round(array_sum(array_column($withFile, 'reported_total')), 2),
            'difference' => $both === [] ? null : round(array_sum(array_map(fn ($c) => $c['reported_total'] - $c['expected_total'], $both)), 2),
            'over' => count(array_filter($week, fn ($c) => ($c['gap_verdict'] ?? null) === 'over')),
        ];
    }
}
