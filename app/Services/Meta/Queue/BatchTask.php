<?php

namespace App\Services\Meta\Queue;

use App\Services\Meta\Products\TestingRules;

/**
 * `.99` (dueño, 2026-11-04: "no aparece si es una iteración de un creativo ganador, no aparece si son tres piezas
 * totalmente nuevas, pero de qué ángulo es… tengo que desarrollar tres piezas del ángulo nervio vago, o tres piezas que
 * son iteraciones del hook de este creativo, o tres piezas que son iteraciones de formato de este creativo"): qué hay que
 * hacer en un encargo, en una frase y con lo que ya trae (el tipo, el ángulo, el creativo que se itera y lo que prueba
 * cada pieza). PHP puro. El título va en la tarjeta ("Asigna B0101: 3 iteraciones del hook de C0146"); el detalle, debajo.
 */
final class BatchTask
{
    /** Las piezas que cambian solo el hook. */
    private const HOOKS = ['winner_hook', 'hook', 'fatigue'];

    /** Las que arreglan un tramo del video. */
    private const FIXES = ['after_hook' => 'arreglar lo que viene justo después del hook', 'middle' => 'arreglar el medio del video',
        'close' => 'arreglar el cierre', 'click' => 'arreglar el clic (la oferta y el llamado a la acción)'];

    /**
     * @param array{kind: ?string, angle_code: ?string, angle_name: ?string, iterates: ?string, imported?: bool, testing?: bool,
     *     pieces: list<array{test: ?string, style: ?string, format: ?string}>} $b iterates: el creativo que se itera (C0146 POSP)
     * @return array{title: string, detail: ?string}|null null: un batch ya hecho que se cargó (no hay nada que hacer), salvo
     *     que sean variaciones de un creativo (`.99`: "3 iteraciones del hook de …")
     */
    public static function of(array $b): ?array
    {
        if (($b['imported'] ?? false) && !($b['iterates'] ?? null)) {
            return null;
        }
        $n = max(1, count($b['pieces'] ?? []));
        $angle = $b['angle_code'] ? $b['angle_code'] . ($b['angle_name'] ? " · {$b['angle_name']}" : '') : null;
        $new = $n === 1 ? '1 pieza nueva' : "{$n} piezas nuevas";
        if ($b['iterates'] ?? null) {
            return self::iteration((string) $b['iterates'], $b['pieces'] ?? [], $n);
        }
        $testing = (bool) ($b['testing'] ?? false);

        return match ($b['kind'] ?? null) {
            'new_angle' => $testing && $angle
                ? ['title' => ($n === 1 ? '1 pieza' : "{$n} piezas") . " para probar el ángulo {$angle}", 'detail' => 'Producto en testeo: este batch prueba si este ángulo vende.']
                : ['title' => "{$new} de un ángulo nuevo" . ($angle ? ": {$angle}" : ''), 'detail' => 'Un ángulo que todavía no probamos en este producto.'],
            'top_funnel' => ['title' => "{$new} para gente que todavía no conoce el problema" . ($angle ? " (ángulo {$angle})" : ''),
                'detail' => 'Arriba del embudo: primero el problema y después el producto.'],
            // una iteración que se pidió a mano, sin decir de qué creativo
            'iteration' => ['title' => ($n === 1 ? '1 iteración' : "{$n} iteraciones") . ($angle ? " del ángulo {$angle}" : ''),
                'detail' => 'Variaciones de un creativo que ya funciona: de cuál partir y qué cambiar, en «Ver todo el encargo».'],
            default => ['title' => $angle ? "{$new} del ángulo {$angle}" : "{$new} (concepto nuevo)",
                'detail' => match (true) {
                    $testing => 'Producto en testeo: otro concepto u otro formato para probar este ángulo, distinto del otro batch.',
                    $angle !== null => 'Un concepto nuevo: una idea distinta a lo que ya hay de este ángulo.',
                    default => 'Un concepto nuevo. El ángulo lo eliges tú con la ficha del producto.',
                }],
        };
    }

    /** "3 iteraciones del hook de C0146", "3 iteraciones de formato de C0146" o, mezcladas, "3 iteraciones de C0146" con el detalle. */
    private static function iteration(string $of, array $pieces, int $n): array
    {
        $hooks = 0;
        $formats = [];
        $fixes = [];
        $other = 0;
        foreach ($pieces as $p) {
            $test = $p['test'] ?? null;
            match (true) {
                in_array($test, self::HOOKS, true) => $hooks++,
                $test === 'format' => $formats[] = self::formatName($p),
                isset(self::FIXES[$test]) => $fixes[$test] = ($fixes[$test] ?? 0) + 1,
                default => $other++,
            };
        }
        $title = ($n === 1 ? '1 iteración' : "{$n} iteraciones");
        $de = self::de($of);
        // "UGC e imagen" (antes de i, "e"); sin decir cuál, "otro formato"
        $named = array_values(array_unique(array_filter($formats)));
        $list = $named === [] ? 'otro formato' : preg_replace('/ y (?=[iIíÍ])/u', ' e ', TestingRules::join($named));

        return match (true) {
            $hooks === $n => ['title' => "{$title} del hook {$de}", 'detail' => 'Solo cambia el hook (los primeros 3 segundos): el resto del video queda igual.'],
            count($formats) === $n => ['title' => "{$title} de formato {$de}", 'detail' => "El mismo mensaje en {$list}."],
            count($fixes) === 1 && array_sum($fixes) === $n => ['title' => "{$title} {$de}: " . self::FIXES[array_key_first($fixes)],
                'detail' => 'Lo demás del video se queda igual.'],
            $other === $n => ['title' => "{$title} {$de}", 'detail' => 'Variaciones del mismo creativo (qué cambia, en «Ver todo el encargo»).'],
            default => ['title' => "{$title} {$de}", 'detail' => ucfirst(TestingRules::join(array_filter([
                $hooks ? "{$hooks} de hook (hook nuevo, el resto igual)" : null,
                $formats ? count($formats) . " de formato ({$list})" : null,
                ...array_map(fn ($k, $c) => "{$c} para " . self::FIXES[$k], array_keys($fixes), $fixes),
                $other ? ($other === 1 ? '1 variación libre' : "{$other} variaciones libres") : null,
            ]))) . '.'],
        };
    }

    /** `.103`: "de C0146", y "del ganador" (no "de el ganador") cuando no se sabe su nombre. */
    private static function de(string $of): string
    {
        return str_starts_with($of, 'el ') ? 'del ' . substr($of, 3) : "de {$of}";
    }

    /** "UGC", "testimonio", "imagen"; null si no dice cuál (un batch ya hecho, o la imagen ganadora que pasa a video). */
    private static function formatName(array $p): ?string
    {
        $label = PieceRules::formatLabel($p['style'] ?? null);

        return $label === null ? null : ($label === 'UGC' ? $label : mb_strtolower($label));
    }
}
