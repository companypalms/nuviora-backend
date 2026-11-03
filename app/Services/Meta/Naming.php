<?php

namespace App\Services\Meta;

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Support\Str;

/**
 * `.89` (dueño, 2026-11-03: "es importante que el sistema siempre pueda saber de qué creativo, de qué batch y de qué
 * campaña estamos hablando… que siempre me diga qué nombre le tengo que colocar a cada campaña, a cada batch y a cada
 * creativo con una nomenclatura fácil, única y exacta"): la regla de nombres, en un solo lugar. PHP puro.
 *
 * - Campaña: PRODUCTO | CIUDAD | CBO | fecha → "TERMAFIT | CCS | CBO | 03/11/2026" (el nombre corto del producto en
 *   mayúsculas y sin espacios, el código fijo de la ciudad y el día en que se crea; así se clasifica sola).
 * - Ad set (batch): B + su número + ángulo → "B0110 POSP" (QueueRules::metaNames).
 * - Anuncio (creativo): C + su número + ángulo → "C0457 POSP". Los números los da el sistema y nunca se repiten, ni entre
 *   productos. El mismo creativo lleva el mismo nombre en todas las ciudades (al duplicar, sin " - Copia").
 * - Cada archivo de Drive que entrega un editor se llama como su anuncio.
 */
final class Naming
{
    /** Los códigos de las ciudades en los nombres (los que ya se usan; las demás, su nombre en mayúsculas). */
    public const CITIES = ['distrito capital' => 'CCS', 'caracas' => 'CCS', 'carabobo' => 'CBOBO', 'valencia' => 'CBOBO', 'aragua' => 'MCAY', 'maracay' => 'MCAY'];

    /** "Solo Brillo" → SOLOBRILLO, "FlexiStep® Tobillera de compresión" → FLEXISTEP. */
    public static function product(string $name): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii(UrgentList::short($name))));
    }

    /** "Distrito Capital" → CCS; una ciudad sin código, su nombre en mayúsculas ("Lara" → LARA). */
    public static function city(?string $city): string
    {
        $key = mb_strtolower(trim(Str::ascii((string) $city)));

        return self::CITIES[$key] ?? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii((string) $city)));
    }

    /** "TERMAFIT | CCS | CBO | 03/11/2026". */
    public static function campaign(string $product, ?string $city, string $date): string
    {
        return self::product($product) . ' | ' . self::city($city) . ' | CBO | ' . date('d/m/Y', strtotime($date));
    }

    /** "B0110 POSP" y sus anuncios "C0457 POSP"… @return array{adset: ?string, ads: list<string>} */
    public static function batch(?int $number, ?string $angle, array $pieceNumbers): array
    {
        return QueueRules::metaNames($number, $angle, $pieceNumbers);
    }
}
