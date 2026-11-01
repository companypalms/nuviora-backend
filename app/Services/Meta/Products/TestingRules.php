<?php

namespace App\Services\Meta\Products;

use App\Services\Meta\Queue\EditorRules;

/**
 * Las reglas de la fase de testeo (`.65`, dueño 2026-11-01). PHP puro.
 * - "En testeo siempre probamos solamente 3 batch, 1 por cada ángulo principal": los 3 primeros ángulos de la ficha (del
 *   más fuerte al más débil). Con 2 ángulos: 2 batches del más fuerte y 1 del otro. Con 1 ángulo: 2 batches.
 * - Cuando un ángulo lleva más de un batch, cada uno prueba una hipótesis distinta (otro concepto u otro formato).
 * - Una ronda termina cuando todos sus batches se testearon. Un ángulo ganó si alguno de sus batches ganó, perdió si todos
 *   perdieron, y si no, empate. Con algún ganador, el producto pasa a "Iterar lo que funciona"; si ninguno ganó, se
 *   pueden probar los siguientes ángulos de la ficha (otra ronda).
 * - Los ángulos que perdieron en un testeo no reciben más encargos de las reglas.
 */
final class TestingRules
{
    public const PHASES = ['testing' => 'En testeo', 'iterate' => 'Iterar lo que funciona'];

    public const MAX_ANGLES = 10;

    /**
     * Los ángulos de los batches de una ronda, en orden.
     *
     * @param list<string> $codes los ángulos disponibles, del más fuerte al más débil
     * @return list<string>
     */
    public static function slots(array $codes): array
    {
        $codes = array_values($codes);

        return match (count($codes)) {
            0 => [],
            1 => [$codes[0], $codes[0]],
            2 => [$codes[0], $codes[0], $codes[1]],
            default => array_slice($codes, 0, 3),
        };
    }

    /**
     * Los ángulos de la ficha que todavía se pueden probar en una ronda nueva: los que no salieron en una ronda anterior.
     *
     * @param list<string> $ordered los ángulos de la ficha, del más fuerte al más débil
     * @param list<string> $usedBefore los que ya tuvieron batches en rondas anteriores
     * @return list<string>
     */
    public static function available(array $ordered, array $usedBefore): array
    {
        return array_values(array_diff($ordered, $usedBefore));
    }

    /**
     * Lo que falta pedir: los batches de la ronda menos los que ya existen (por ángulo).
     *
     * @param list<string> $slots
     * @param list<string> $existing el ángulo de cada batch de la ronda que ya existe (no descartado)
     * @return list<array{angle: string, n: int, of: int}> n: el número de batch de ese ángulo (1, 2); of: cuántos lleva
     */
    public static function missing(array $slots, array $existing): array
    {
        $have = array_count_values($existing);
        $need = array_count_values($slots);
        $out = [];
        $seen = [];
        foreach ($slots as $angle) {
            $seen[$angle] = ($seen[$angle] ?? 0) + 1;
            if ($seen[$angle] > ($have[$angle] ?? 0)) {
                $out[] = ['angle' => $angle, 'n' => $seen[$angle], 'of' => $need[$angle]];
            }
        }

        return $out;
    }

    /**
     * Cómo terminó una ronda, o null si todavía falta algún batch por testear.
     *
     * @param list<string> $slots
     * @param list<array{angle: string, status: string, verdicts: array}> $batches los batches de la ronda (sin los descartados)
     * @return array{won: list<string>, lost: list<string>, tie: list<string>}|null
     */
    public static function outcome(array $slots, array $batches): ?array
    {
        if ($slots === []) {
            return null;
        }
        $results = [];
        foreach ($batches as $b) {
            if ($b['status'] === 'tested') {
                $results[$b['angle']][] = EditorRules::result('tested', $b['verdicts'] ?? []);
            }
        }
        foreach (array_count_values($slots) as $angle => $need) {
            if (count($results[$angle] ?? []) < $need) {
                return null;
            }
        }
        $out = ['won' => [], 'lost' => [], 'tie' => []];
        foreach (array_values(array_unique($slots)) as $angle) {
            $r = $results[$angle];
            $out[match (true) {
                in_array('Ganó', $r, true) => 'won',
                !array_diff($r, ['Perdió']) => 'lost',
                default => 'tie',
            }][] = $angle;
        }

        return $out;
    }

    /**
     * El estado de cada batch de la ronda, para la pantalla: "Por pedir", "Propuesto", "En producción (B0123)"…
     *
     * @param array{status: string, code: ?string, verdicts: array}|null $batch
     */
    public static function slotLabel(?array $batch): string
    {
        if ($batch === null) {
            return 'Por pedir';
        }
        $code = $batch['code'] ? " ({$batch['code']})" : '';

        return match ($batch['status']) {
            'proposed' => 'Propuesto: falta aprobarlo',
            'approved', 'production', 'review' => 'En producción' . $code,
            'bank' => 'En banca, listo para lanzar' . $code,
            'testing' => 'En test' . $code,
            'tested' => EditorRules::result('tested', $batch['verdicts'] ?? []) . $code,
            default => $batch['status'] . $code,
        };
    }

    /** "GINE, ABDO y FAJA". */
    public static function join(array $parts): string
    {
        $parts = array_values($parts);

        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts) : ($parts[0] ?? '');
    }
}
