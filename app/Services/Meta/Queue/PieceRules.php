<?php

namespace App\Services\Meta\Queue;

/**
 * `.81` (dueño, 2026-11-02): "el sistema siempre debe saber qué hipótesis se está probando en cada creativo, para que
 * aprendamos de lo que funciona y lo que no". PHP puro:
 * - qué prueba cada pieza de una iteración del análisis y su hipótesis (`.128`: ya no se escribe qué hacer en cada una);
 * - un ganador sano: 2 formatos distintos + 1 hook nuevo (el formato lo elige el sistema: primero el que nunca se probó en
 *   ese producto);
 * - el resultado de cada pieza al terminar el test (Ganó, Perdió o Empate), para "Lo que sabemos".
 */
final class PieceRules
{
    /** Qué prueba una pieza. Las de los encargos de la IA o hechos a mano toman el tipo del encargo (KINDS). */
    public const TESTS = [
        'winner_hook' => 'Hook nuevo en un ganador',
        'hook' => 'Arreglar el hook',
        'after_hook' => 'Arreglar justo después del hook',
        'middle' => 'Arreglar el medio',
        'close' => 'Arreglar el cierre',
        'click' => 'Arreglar el clic',
        'fatigue' => 'Hook nuevo por cansancio',
        'format' => 'Otro formato',
        'iteration' => 'Iteración', 'new_concept' => 'Concepto nuevo', 'new_angle' => 'Ángulo nuevo', 'top_funnel' => 'Arriba del embudo',
    ];

    /** Los formatos que se prueban en una pieza, en el orden del dueño ("imagen, ugc, testimonio, entrevista, animado…"). */
    public const FORMAT_ORDER = ['imagen', 'ugc', 'testimonio', 'entrevista', 'animado', 'demostracion', 'antes_despues', 'problema_solucion', 'venta_directa'];

    /** "Imagen", "UGC", "Testimonio"… */
    public static function formatLabel(?string $style): ?string
    {
        return $style === null ? null : ($style === 'imagen' ? 'Imagen' : (QueueRules::STYLES[$style] ?? $style));
    }

    /**
     * Las piezas de una iteración del análisis.
     *
     * @param ?string $fix el primer tramo flojo (AdRules::STAGES), "fatigue", "format" (probar en imagen) o null (ganador sano)
     * @param list<array{style: string, reason: string}> $formats los formatos elegidos (pickFormats), para un ganador sano
     * @return list<array{test: string, style: ?string, format: string, hypothesis: string, reason: ?string}>
     */
    public static function plan(?string $fix, int $pieces, array $formats = [], string $baseFormat = 'video'): array
    {
        $pieces = max(1, $pieces);
        if ($fix === null) {
            $out = [self::piece('winner_hook', null, $baseFormat)];
            foreach (array_slice($formats, 0, $pieces - 1) as $f) {
                $out[] = self::piece('format', $f['style'], $f['style'] === 'imagen' ? 'image' : 'video', $f['reason']);
            }
            // Si no queda ningún formato para probar, más hooks nuevos
            while (count($out) < $pieces) {
                $out[] = self::piece('winner_hook', null, $baseFormat);
            }

            return $out;
        }
        if ($fix === 'format') {
            return array_fill(0, $pieces, self::piece('format', 'imagen', 'image', 'En video el CPM sale caro y no compensa.'));
        }
        $test = isset(self::TESTS[$fix]) ? $fix : 'fatigue';

        return array_fill(0, $pieces, self::piece($test, null, $baseFormat));
    }

    /**
     * Qué formatos probar en un ganador sano: primero los que nunca se probaron en este producto (entre ellos, primero el que
     * más ganó en los otros productos; si no, el orden del dueño); si faltan, los que ya ganaron en este producto. Nunca uno
     * que perdió sin ganar nunca, el del creativo original ni uno que ya está en otro encargo en camino.
     *
     * @param array<string, array{won: int, lost: int, tie: int, total: int}> $product por formato, en este producto
     * @param array<string, array{won: int, lost: int, tie: int, total: int}> $others por formato, en los demás productos
     * @param list<string> $exclude
     * @return list<array{style: string, reason: string}>
     */
    public static function pickFormats(int $n, array $product, array $others, array $exclude = []): array
    {
        if ($n <= 0) {
            return [];
        }
        $order = fn (string $s) => (int) array_search($s, self::FORMAT_ORDER, true);
        $candidates = array_values(array_diff(self::FORMAT_ORDER, $exclude));
        $untried = array_values(array_filter($candidates, fn ($s) => ($product[$s]['total'] ?? 0) === 0));
        usort($untried, fn ($a, $b) => [-($others[$a]['won'] ?? 0), $order($a)] <=> [-($others[$b]['won'] ?? 0), $order($b)]);
        $out = [];
        foreach ($untried as $s) {
            $won = (int) ($others[$s]['won'] ?? 0);
            $out[] = ['style' => $s, 'reason' => 'Nunca se probó en este producto' . ($won > 0 ? " y ganó {$won} " . ($won === 1 ? 'vez' : 'veces') . ' en tus otros productos' : '') . '.'];
        }
        $won = array_values(array_filter($candidates, fn ($s) => ($product[$s]['won'] ?? 0) > 0));
        usort($won, fn ($a, $b) => [-$product[$a]['won'], $product[$a]['lost'], $order($a)] <=> [-$product[$b]['won'], $product[$b]['lost'], $order($b)]);
        foreach ($won as $s) {
            $out[] = ['style' => $s, 'reason' => "Ya ganó en este producto ({$product[$s]['won']} de {$product[$s]['total']})."];
        }

        return array_slice($out, 0, $n);
    }

    /**
     * El resultado de una pieza al terminar el test, con el veredicto de sus anuncios en cada ciudad: ganó si en alguna
     * compró con el CPA debajo de C (prometedor o PROVEN), perdió si perdió en todas, si no empate. Sin anuncios, null.
     *
     * @param list<string> $keys los veredictos (AdRules::STATES) de sus anuncios
     */
    public static function outcome(array $keys): ?string
    {
        if ($keys === []) {
            return null;
        }
        if (array_intersect($keys, ['promising', 'proven'])) {
            return 'Ganó';
        }

        return array_diff($keys, ['loser']) === [] ? 'Perdió' : 'Empate';
    }

    /** "Formato UGC", "Hook nuevo en un ganador": el grupo de una pieza en "Lo que sabemos". */
    public static function groupLabel(?string $test, ?string $style): string
    {
        return $test === 'format' && $style !== null ? 'Formato ' . self::formatLabel($style) : (self::TESTS[$test] ?? 'Sin dato');
    }

    private static function piece(string $test, ?string $style, string $format, ?string $reason = null): array
    {
        $label = self::formatLabel($style);
        $in = $style === 'imagen' ? 'en imagen' : 'en formato ' . ($style === 'ugc' ? 'UGC' : mb_strtolower((string) $label));
        $hypothesis = match ($test) {
            'winner_hook' => 'Probamos si un hook nuevo, con el mismo cuerpo, hace que este ganador dure más.',
            'format' => "Probamos si el mismo mensaje {$in} también vende.",
            'hook' => 'Probamos si un hook más fuerte hace que más gente se quede los primeros 3 segundos.',
            'after_hook' => 'Probamos si, yendo más rápido al problema y a la promesa, más gente se queda hasta los 15 segundos.',
            'middle' => 'Probamos si un medio más corto y con más ritmo hace que más gente llegue al final.',
            'close' => 'Probamos si un cierre más claro hace que más gente termine el video y compre.',
            'click' => 'Probamos si una oferta y un llamado más claros hacen que más gente haga clic.',
            default => 'Probamos si con un hook y primeras imágenes nuevos la gente que ya lo vio vuelve a detenerse.',
        };

        return ['test' => $test, 'style' => $style, 'format' => $format, 'hypothesis' => $hypothesis, 'reason' => $reason];
    }
}
