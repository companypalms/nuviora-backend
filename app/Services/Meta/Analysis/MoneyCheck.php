<?php

namespace App\Services\Meta\Analysis;

/**
 * Para que la IA no invente números (Etapa 5, paso 3a), en PHP puro: los montos en dólares que escribió y que no están en
 * los datos que recibió. Si aparece alguno, el análisis lo marca con un aviso.
 */
final class MoneyCheck
{
    private const PATTERN = '/\$\s?(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)/u';

    /**
     * @param string $written lo que escribió la IA
     * @param string $source los datos que recibió (el mismo texto que se le mandó)
     * @return string[] los montos que no estaban
     */
    public static function unknown(string $written, string $source): array
    {
        $known = array_map(fn ($v) => round($v, 2), self::amounts($source));
        $out = [];
        foreach (self::amounts($written, true) as $raw => $value) {
            $ok = false;
            foreach ($known as $k) {
                if (abs($k - $value) < 0.006) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $out[] = $raw;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int|string, float> los montos de un texto ("$1.412,06" → 1412.06; "$43,56" → 43.56; "$30" → 30) */
    public static function amounts(string $text, bool $keyByRaw = false): array
    {
        preg_match_all(self::PATTERN, $text, $m);
        $out = [];
        foreach ($m[1] as $i => $raw) {
            $n = str_contains($raw, ',') || preg_match('/\.\d{3}(?!\d)/', $raw) ? (float) str_replace(['.', ','], ['', '.'], $raw) : (float) $raw;
            if ($keyByRaw) {
                $out[$m[0][$i]] = $n;
            } else {
                $out[] = $n;
            }
        }

        return $out;
    }
}
