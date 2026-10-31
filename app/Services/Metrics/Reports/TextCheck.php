<?php

namespace App\Services\Metrics\Reports;

use App\Services\Meta\Analysis\MoneyCheck;

/**
 * Para que la IA no invente números en el reporte (`.48b`), en PHP puro: los montos en dólares (MoneyCheck, como el
 * análisis de Meta) y los porcentajes que escribió y que no están en los datos que recibió.
 */
final class TextCheck
{
    private const PERCENT = '/(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?)\s?%/u';

    /** @return string[] lo que escribió y no estaba en los datos */
    public static function unknown(string $written, string $source): array
    {
        $out = MoneyCheck::unknown($written, $source);
        $known = self::percents($source);
        foreach (self::percents($written, true) as $raw => $value) {
            $found = false;
            foreach ($known as $k) {
                if (abs($k - $value) < 0.051) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $out[] = $raw;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int|string, float> los porcentajes de un texto ("45,6 %" → 45.6) */
    public static function percents(string $text, bool $keyByRaw = false): array
    {
        preg_match_all(self::PERCENT, $text, $m);
        $out = [];
        foreach ($m[1] as $i => $raw) {
            $n = (float) str_replace(['.', ','], ['', '.'], $raw);
            if ($keyByRaw) {
                $out[$m[0][$i]] = $n;
            } else {
                $out[] = $n;
            }
        }

        return $out;
    }
}
