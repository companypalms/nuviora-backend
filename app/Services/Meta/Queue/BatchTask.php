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

    /**
     * `.128` (dueño, 2026-11-09: "nunca deberían ser recomendaciones específicas de textos que debe poner, o de speech que
     * debe realizar… porque entonces se confunde el editor"; "la hipótesis y lo que queremos cambiar"): en una variación, el
     * editor y el líder leen textos fijos: qué variar, la hipótesis y cómo hacerlo, con ideas generales. La IA no escribe ninguno.
     */
    private const HOOK_HOW = 'Busca hooks ya validados de este mismo ángulo, en la biblioteca de anuncios o en redes sociales, y conéctalos a este creativo. Cambia solo los primeros 3 segundos.';

    private const HOOK_WHY = [
        'hook' => 'Este creativo tuvo un hook rate bajo. Queremos probar si variando el hook aumentamos su hook rate y su efectividad.',
        'fatigue' => 'La gente ya vio mucho este creativo. Queremos probar si variando el hook vuelve a enganchar y recupera su efectividad.',
        'winner_hook' => 'Este creativo es ganador. Queremos probar si variando el hook aumentamos su efectividad y dura más.',
    ];

    /** Un encargo que no guardó qué había que variar: no se afirma que el hook rate fue bajo. */
    private const HOOK_UNKNOWN = 'Queremos probar si variando el hook aumentamos la efectividad de este creativo.';

    private const FORMAT_WHY = 'Queremos probar si el mismo mensaje en otro formato aumenta la efectividad de este creativo.';

    private const MIX_WHY = 'Este creativo es ganador. Queremos probar si variando el hook y el formato aumentamos su efectividad y dura más.';

    private const RETENTION_HOW = 'Ideas generales: hacer el video un poco más corto, hacer más dinámicas las tomas, cambiar por completo las tomas del medio o hacer más directo el speech del medio.';

    /** Las que mejoran un tramo del video. */
    private const FIXES = [
        'after_hook' => ['what' => 'para mejorar el hold rate', 'how' => self::RETENTION_HOW . ' El hook queda igual.',
            'why' => 'Este creativo tuvo un hold rate que se puede mejorar. Queremos probar si mejorando el hold rate aumentamos su efectividad.'],
        'middle' => ['what' => 'para mejorar el medio del video', 'how' => self::RETENTION_HOW . ' El inicio queda igual.',
            'why' => 'Este creativo pierde gente en el medio del video. Queremos probar si mejorando esa parte aumentamos su efectividad.'],
        'close' => ['what' => 'del cierre', 'how' => 'Ideas generales: un cierre más corto o llamar a comprar antes. Lo de antes del cierre queda igual.',
            'why' => 'Este creativo pierde gente antes del final. Queremos probar si mejorando el cierre aumentamos su efectividad.'],
        'click' => ['what' => 'de call to action', 'how' => 'Busca llamados a la acción ya validados, en la biblioteca de anuncios o en redes sociales. Cambia solo esa parte.',
            'why' => 'A este creativo poca gente le hace clic. Queremos probar si variando el call to action aumentamos sus clics y su efectividad.'],
    ];

    /**
     * @param array{kind: ?string, angle_code: ?string, angle_name: ?string, iterates: ?string, imported?: bool, testing?: bool, rules?: bool,
     *     pieces: list<array{test: ?string, style: ?string, format: ?string}>} $b iterates: el creativo que se itera (C0146 POSP);
     *     rules: lo pidieron las reglas (no lo escribió el dueño)
     * @return array{title: string, detail: ?string, hypothesis?: ?string}|null null: un batch ya hecho que se cargó (no hay nada
     *     que hacer), salvo que sean variaciones de un creativo (`.99`: "3 variaciones de hook de …")
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
            return self::iteration((string) $b['iterates'], $b['pieces'] ?? [], $n, (bool) ($b['imported'] ?? false), (bool) ($b['rules'] ?? false));
        }
        $testing = (bool) ($b['testing'] ?? false);

        return match ($b['kind'] ?? null) {
            'new_angle' => $testing && $angle
                ? ['title' => ($n === 1 ? '1 pieza' : "{$n} piezas") . " para probar el ángulo {$angle}", 'detail' => 'Producto en testeo: este batch prueba si este ángulo vende.']
                : ['title' => "{$new} de un ángulo nuevo" . ($angle ? ": {$angle}" : ''), 'detail' => 'Un ángulo que todavía no probamos en este producto.'],
            'top_funnel' => ['title' => "{$new} para gente que todavía no conoce el problema" . ($angle ? " (ángulo {$angle})" : ''),
                'detail' => 'Arriba del embudo: primero el problema y después el producto.'],
            // una iteración que se pidió a mano, sin decir de qué creativo
            'iteration' => ['title' => ($n === 1 ? '1 variación' : "{$n} variaciones") . ($angle ? " del ángulo {$angle}" : ''),
                'detail' => 'Variaciones de un creativo que ya funciona: de cuál partir y qué cambiar, en «Ver todo el encargo».'],
            default => ['title' => $angle ? "{$new} del ángulo {$angle}" : "{$new} (concepto nuevo)",
                'detail' => match (true) {
                    $testing => 'Producto en testeo: otro concepto u otro formato para probar este ángulo, distinto del otro batch.',
                    $angle !== null => 'Un concepto nuevo: una idea distinta a lo que ya hay de este ángulo.',
                    default => 'Un concepto nuevo. El ángulo lo eliges tú con la ficha del producto.',
                }],
        };
    }

    /**
     * "3 variaciones de hook de C0146", "3 variaciones para mejorar el hold rate de C0146" o, mezcladas, "3 variaciones de
     * C0146: 1 de hook y 2 de formato", con la hipótesis y cómo hacerlo (`.128`). Un batch ya hecho que se cargó solo dice qué es.
     */
    private static function iteration(string $of, array $pieces, int $n, bool $imported, bool $rules): array
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
        $title = $n === 1 ? '1 variación' : "{$n} variaciones";
        $de = self::de($of);
        // "UGC e imagen" (antes de i, "e"); sin decir cuál, "otro formato"
        $named = array_values(array_unique(array_filter($formats)));
        $list = $named === [] ? 'otro formato' : preg_replace('/ y (?=[iIíÍ])/u', ' e ', TestingRules::join($named));

        $fix = self::FIXES[array_key_first($fixes) ?? ''] ?? null;
        $parts = TestingRules::join(array_filter([
            $hooks ? "{$hooks} de hook" : null,
            $formats ? count($formats) . ' de formato' : null,
            ...array_map(fn ($k, $c) => "{$c} " . self::FIXES[$k]['what'], array_keys($fixes), $fixes),
            $other ? ($other === 1 ? '1 variación libre' : "{$other} variaciones libres") : null,
        ]));
        $task = match (true) {
            $hooks === $n => ['title' => "{$title} de hook {$de}", 'detail' => self::HOOK_HOW, 'hypothesis' => self::HOOK_WHY[$pieces[0]['test']]],
            count($formats) === $n => ['title' => "{$title} de formato {$de}", 'detail' => "El mismo mensaje en {$list}.", 'hypothesis' => self::FORMAT_WHY],
            count($fixes) === 1 && array_sum($fixes) === $n => ['title' => "{$title} {$fix['what']} {$de}", 'detail' => $fix['how'], 'hypothesis' => $fix['why']],
            // Un encargo viejo de las reglas no guardó qué había que variar: de hook, como decide el sistema cuando no lo sabe
            $other === $n && $rules => ['title' => "{$title} de hook {$de}", 'detail' => self::HOOK_HOW, 'hypothesis' => self::HOOK_UNKNOWN],
            $other === $n => ['title' => "{$title} {$de}", 'detail' => 'Variaciones del mismo creativo (qué cambia, en «Ver todo el encargo»).', 'hypothesis' => null],
            default => ['title' => "{$title} {$de}" . ($parts !== '' ? ": {$parts}" : ''),
                'detail' => $hooks && $formats ? 'Para el hook: ' . lcfirst(self::HOOK_HOW) . " Para el formato: el mismo mensaje en {$list}." : null,
                'hypothesis' => $hooks && $formats && !$fixes && !$other ? self::MIX_WHY : null],
        };

        return $imported ? ['title' => $task['title'], 'detail' => null, 'hypothesis' => null] : $task;
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
