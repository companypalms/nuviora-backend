<?php

namespace App\Services\Assistant;

use App\Services\Metrics\Insights\CancellationInsights;
use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\GoalRules;

/**
 * Cómo las herramientas del asistente ponen en palabras lo que calculan los paneles, para la IA ("data") y para la
 * pantalla ("display"). Los números van ya escritos como en el panel ("$1.234,56", "57,1 %"), así la IA no los
 * redondea ni los cambia. PHP puro.
 */
final class Present
{
    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Un número con su formato del panel: money, percent, hours, hours_total o count. */
    public static function value(int|float|null $value, string $format): string
    {
        if ($value !== null && $format === 'hours_total') {
            return rtrim(rtrim(number_format((float) $value, 1, ',', '.'), '0'), ',') . ' h';
        }

        return GoalRules::fmt($value, $format);
    }

    /** "+5,2 %" o "−3 pts" (los porcentajes cambian en puntos), o null si no se compara. */
    public static function change(array $card): ?string
    {
        if (($card['change'] ?? null) === null) {
            return null;
        }
        $c = (float) $card['change'];
        $abs = rtrim(rtrim(number_format(abs($c), 1, ',', '.'), '0'), ',');
        $sign = $c > 0 ? '+' : ($c < 0 ? '−' : '');

        return $sign . $abs . (($card['change_unit'] ?? 'percent') === 'points' ? ' pts' : ' %');
    }

    /** El período en palabras, para la IA. */
    public static function period(Period $period): array
    {
        return [
            'nombre' => $period->label,
            'desde' => $period->start->format('d/m/Y'),
            'hasta' => $period->end->format('d/m/Y') . ($period->end->isToday() ? ' (hasta las ' . $period->end->format('H:i') . ')' : ''),
            'se_compara_con' => $period->compareLabel,
        ];
    }

    /**
     * Las tarjetas del panel, cortas para la IA: el dato, su valor, el de antes, el cambio, de dónde sale y la meta.
     *
     * @param array<int, array> $cards las de Rules\Card (con "goal" si el dueño puso una meta)
     */
    public static function cards(array $cards): array
    {
        return array_map(fn (array $c) => array_filter([
            'dato' => $c['label'],
            'valor' => self::value($c['value'], $c['format']),
            'antes' => ($c['compare'] ?? true) ? self::value($c['previous'] ?? null, $c['format']) : null,
            'cambio' => self::change($c),
            'todavia_puede_cambiar' => !empty($c['neutral']) ? true : null,
            'detalle' => $c['detail'] ?? null,
            'nota' => $c['note'] ?? null,
            'meta' => $c['goal']['text'] ?? null,
        ], fn ($v) => $v !== null), $cards);
    }

    /** Un bloque de tarjetas para la pantalla (se ven con MetricCard, igual que en el Panel del dueño). */
    public static function cardsBlock(string $title, string $compareLabel, array $cards): array
    {
        return ['type' => 'cards', 'title' => $title, 'compare_label' => ucfirst($compareLabel), 'cards' => array_values($cards)];
    }

    /**
     * Una gráfica para la pantalla, con los datos del sistema.
     *
     * @param string $kind bar (columnas), hbar (barras acostadas, para rankings) o line
     * @param array<int, array{key: string, label: string}> $series
     * @param array<int, array> $points cada uno con "label" y un valor por serie
     */
    public static function chart(string $kind, string $title, string $format, array $series, array $points): array
    {
        return ['type' => 'chart', 'kind' => $kind, 'title' => $title, 'format' => $format, 'series' => $series, 'points' => array_values($points)];
    }

    /** El nombre de un punto de una serie del panel: "26/10", "sem. 19/10" u "oct 26". */
    public static function bucket(string $date, string $granularity): string
    {
        $d = substr($date, 0, 10);
        [$y, $m, $day] = [(int) substr($d, 0, 4), (int) substr($d, 5, 2), (int) substr($d, 8, 2)];

        return match ($granularity) {
            'week' => "sem. {$day}/{$m}",
            'month' => self::MONTHS[$m - 1] . ' ' . substr((string) $y, 2),
            default => "{$day}/{$m}",
        };
    }

    /**
     * Texto escrito por personas o por el historial, antes de mandarlo a la IA: sin teléfonos, correos ni números largos
     * (el mismo filtro del análisis de cancelaciones). Las fechas, las horas y los montos no son teléfonos: se respetan.
     */
    public static function mask(string $text): string
    {
        $kept = [];
        $keep = '/\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?Z?)?|\d{1,2}\/\d{1,2}\/\d{2,4}(?: \d{1,2}:\d{2})?|(?:\$|Bs\.?)\s?\d[\d.,]*/u';
        $text = preg_replace_callback($keep, function ($m) use (&$kept) {
            $kept[] = $m[0];

            return "\u{E000}" . (count($kept) - 1) . "\u{E001}";
        }, $text);
        $text = CancellationInsights::mask($text);

        return preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn ($m) => $kept[(int) $m[1]], $text);
    }
}
