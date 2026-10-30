<?php

namespace App\Services\Meta;

use Illuminate\Support\Str;

/**
 * Lee los nombres de los anuncios y de los ad sets (Etapa 5, paso 1b, 2026-10-30). Reemplaza al detector del §32 del
 * documento de Fran, que cortaba en el primer espacio ("CRE001-CERV-NERV-REF008- BATCH005" quedaba sin el batch) y no
 * entendía los nombres sin guiones ("C004 NERV", "CREATIVO 6 27/10/2026").
 *
 * Los nombres reales (producción, 2026-10-30) no siguen un solo formato:
 * - anuncios: "CRE001-FLEX-FAS-BATCH004", "CRE001-COM-GINE-BATCH003-REF001 - Copia", "CRE001-CERV-NERV-REF008- BATCH005",
 *   "CREATIVO 6 27/10/2026" y, en los análisis del dueño, "C005 FASP", "ITER3-C003 AGDU" y "C004 NERV REF002";
 * - ad sets: "BATCH 005 - FASP -CONCEPTO OPEN | TEST - Copia", "BATCH 005 | NERV | BROAD | PURCHASE",
 *   "BATCH 002| SIN ANGULO | BROAD | PURCHASE TESTEO", "BATCH 001.1 - GORD -CONCEPTO OPEN" y los nuevos "B0123 FASP".
 *
 * El nombre se pasa a mayúsculas sin acentos, se le quita lo que agrega Meta al duplicar (" - Copia", "Copy 2") y se corta
 * en partes por espacios, guiones, "_", "|" o "/". Cada parte se reconoce:
 * - creativo: CRE001, CR001, C005, o "CREATIVO 6";
 * - iteración: ITER3 (el creativo que la acompaña es su padre: "ITER3-C003" es una iteración de C003);
 * - batch: BATCH004, "BATCH 005", "BATCH 001.1" o el nuevo B0123 (queda como B004, B005, B001.1, B0123);
 * - referencia: REF008;
 * - producto: una parte de 3 letras o más con la que empieza el nombre del producto de la campaña o la primera palabra
 *   del nombre de la campaña (FLEX, COM, CERV; "SOLO" en "SOLOBRILLO | CSS");
 * - ángulo: la primera parte de 2 a 6 letras que no sea producto, ciudad ni una palabra de la lista de abajo.
 *
 * El código de un creativo (su identidad dentro del producto) son las partes de su nombre en un orden fijo, sin el
 * producto, la ciudad ni la copia: así el mismo anuncio en las 3 ciudades, o con otro separador, es el mismo creativo.
 * Sin código de creativo no hay creativo: un nombre como "Nuevo anuncio" juntaría videos distintos (se pone a mano).
 * PHP puro.
 */
class CreativeTrackingId
{
    /** Palabras que no son un ángulo (estructura, objetivo, formato y relleno de los nombres). */
    public const NOT_ANGLES = [
        'BATCH', 'LOTE', 'REF', 'ITER', 'COPIA', 'COPY', 'TEST', 'TESTEO', 'OPEN', 'BROAD', 'PURCHASE', 'CONCEPTO', 'NUEVOS',
        'NUEVO', 'NUEVA', 'NUEVAS', 'SIN', 'ANGULO', 'UGC', 'VSL', 'IMG', 'IMAGEN', 'VIDEO', 'VID', 'CBO', 'ABO', 'HV',
        'PROVEN', 'WINNER', 'WINNERS', 'GANADOR', 'ESCALA', 'CREATIVO', 'ANUNCIO', 'ADS', 'AD', 'CPA', 'OBJ', 'BE', 'DE',
        'DEL', 'LA', 'EL', 'LOS', 'LAS', 'CON', 'PARA', 'POR', 'REELS', 'REEL', 'FEED', 'STORY', 'STORIES', 'CARRUSEL',
        'CAROUSEL', 'VENTAS', 'SALES', 'HOOK', 'HOOKS',
    ];

    /** Ciudades como aparecen en los nombres de las campañas ("CSS" en vez de "CCS" en Solo Brillo y ZenPulse). */
    public const CITIES = ['CCS', 'CSS', 'MCAY', 'MCY', 'CBOBO', 'VLC', 'VAL', 'CARACAS', 'MARACAY', 'VALENCIA', 'ARAGUA', 'CARABOBO'];

    /** Lo que dice la etapa del ad set. */
    private const STAGES = ['TEST' => 'test', 'TESTEO' => 'test', 'PROVEN' => 'proven', 'WINNER' => 'proven', 'WINNERS' => 'proven',
        'GANADOR' => 'proven', 'GANADORES' => 'proven', 'ESCALA' => 'proven'];

    /** Lo que dice el concepto del ad set cuando no viene después de "CONCEPTO". */
    private const CONCEPTS = ['OPEN', 'BROAD', 'ADVANTAGE', 'ANDROMEDA'];

    /**
     * Las partes del nombre de un anuncio.
     *
     * @param string[] $productNames nombre y nombre visible del producto de la campaña, y la primera palabra del nombre de
     *                               la campaña (para reconocer FLEX, COM…)
     * @return array{code: ?string, label: string, creative: ?string, iteration_of: ?string, angle: ?string, batch: ?string, ref: ?string, product_token: ?string}
     */
    public static function parse(?string $adName, array $productNames = []): array
    {
        $label = self::label($adName);
        $tokens = self::tokens($label);
        $products = self::productKeys($productNames);
        $out = ['code' => null, 'label' => $label, 'creative' => null, 'iteration_of' => null, 'angle' => null, 'batch' => null,
            'ref' => null, 'product_token' => null];
        $iteration = null;
        $others = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if (preg_match('/^ITER(\d*)$/', $t)) {
                $iteration ??= $t;
            } elseif ($t === 'CREATIVO' && $next !== null && ctype_digit($next)) {
                $out['creative'] ??= 'CREATIVO' . (int) $next;
                $i++;
            } elseif (preg_match('/^(CRE|CR|C)\d+$/', $t) && $out['creative'] === null) {
                $out['creative'] = $t;
            } elseif (($batch = self::batch($t, $next)) !== null) {
                if (preg_match('/^(BATCH|LOTE)$/', $t)) {
                    $i++; // "BATCH 005": el número es la parte siguiente
                }
                $out['batch'] ??= $batch;
            } elseif (preg_match('/^REF\d+$/', $t)) {
                $out['ref'] ??= $t;
            } elseif ($out['product_token'] === null && self::isProduct($t, $products)) {
                $out['product_token'] = $t;
            } elseif (in_array($t, ['COPIA', 'COPY'], true) || in_array($t, self::CITIES, true)) {
                continue;
            } elseif ($out['angle'] === null && self::isAngle($t, $products)) {
                $out['angle'] = $t;
            } else {
                $others[] = $t;
            }
        }
        if ($iteration !== null) {
            // "ITER3-C003": la iteración 3 del creativo C003 es un creativo propio, hijo de C003
            $out['iteration_of'] = $out['creative'];
            $out['creative'] = $out['creative'] !== null ? $iteration . '-' . $out['creative'] : $iteration;
        }
        if ($out['creative'] !== null) {
            $code = implode('-', array_filter([$out['creative'], $out['angle'], $out['batch'], $out['ref'], ...$others], fn ($p) => $p !== null && $p !== ''));
            $out['code'] = mb_substr($code, 0, 100);
        }

        return $out;
    }

    /**
     * Las partes del nombre de un ad set: batch, ángulo ("SIN ANGULO" queda vacío), concepto (OPEN, BROAD…) y etapa
     * (test o proven).
     *
     * @return array{batch: ?string, angle: ?string, concept: ?string, stage: ?string}
     */
    public static function parseAdset(?string $name, array $productNames = []): array
    {
        $tokens = self::tokens(self::label($name));
        $products = self::productKeys($productNames);
        $out = ['batch' => null, 'angle' => null, 'concept' => null, 'stage' => null];
        $noAngle = false;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if (($batch = self::batch($t, $next)) !== null) {
                if (preg_match('/^(BATCH|LOTE)$/', $t)) {
                    $i++;
                }
                $out['batch'] ??= $batch;
            } elseif ($t === 'SIN' && $next === 'ANGULO') {
                $noAngle = true;
                $i++;
            } elseif ($t === 'CONCEPTO' && $next !== null) {
                $out['concept'] ??= $next;
                $i++;
            } elseif (isset(self::STAGES[$t])) {
                $out['stage'] ??= self::STAGES[$t];
            } elseif (in_array($t, self::CONCEPTS, true)) {
                $out['concept'] ??= $t;
            } elseif (!$noAngle && $out['angle'] === null && !self::isProduct($t, $products) && !in_array($t, self::CITIES, true) && self::isAngle($t, $products)) {
                $out['angle'] = $t;
            }
        }

        return $out;
    }

    /** El nombre sin lo que agrega Meta al duplicar y con un solo espacio entre palabras (lo que se muestra). */
    public static function label(?string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        do {
            $before = $name;
            $name = trim(preg_replace('/(\s*[-–—]\s*)*\s*\b(copia|copy)(\s*\d+)?\s*$/iu', '', $name) ?? $name);
            $name = preg_replace('/[\s\-–—|]+$/u', '', $name) ?? $name;
        } while ($name !== $before && $name !== '');

        return $name;
    }

    /** @return string[] */
    private static function tokens(string $label): array
    {
        $ascii = strtoupper(Str::ascii($label));

        return array_values(array_filter(preg_split('/[\s\-–—_|\/,:;+#()\[\]]+/u', $ascii) ?: [], fn ($t) => $t !== ''));
    }

    /** El batch normalizado (B004, B001.1, B0123) si la parte lo es; con "BATCH" suelto, el número es la parte siguiente. */
    private static function batch(string $t, ?string $next): ?string
    {
        if (preg_match('/^(?:BATCH|LOTE)(\d+)((?:\.\d+)?)$/', $t, $m) || preg_match('/^B(\d{3,})((?:\.\d+)?)$/', $t, $m)) {
            return 'B' . str_pad($m[1], 3, '0', STR_PAD_LEFT) . $m[2];
        }
        if (in_array($t, ['BATCH', 'LOTE'], true) && $next !== null && preg_match('/^(\d+)((?:\.\d+)?)$/', $next, $m)) {
            return 'B' . str_pad($m[1], 3, '0', STR_PAD_LEFT) . $m[2];
        }

        return null;
    }

    /** @return string[] los nombres del producto en mayúsculas, sin acentos ni espacios ("FLEXISTEPTOBILLERA…") */
    private static function productKeys(array $names): array
    {
        return array_values(array_filter(array_map(
            fn ($n) => preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii((string) $n))) ?? '', $names)));
    }

    private static function isProduct(string $t, array $products): bool
    {
        if (strlen($t) < 3 || !ctype_alpha($t)) {
            return false;
        }
        foreach ($products as $p) {
            if (str_starts_with($p, $t)) {
                return true;
            }
        }

        return false;
    }

    private static function isAngle(string $t, array $products): bool
    {
        return preg_match('/^[A-Z]{2,6}$/', $t) === 1 && !in_array($t, self::NOT_ANGLES, true) && !self::isProduct($t, $products);
    }
}
