<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;

/**
 * `.133` (dueño, 2026-11-10, B0104 y B0108: "me parece raro que el sistema pida dos veces que haga iteraciones de hook de
 * un mismo creativo, creo que eso no es correcto"; eligió "una a la vez y otro tipo"):
 * - de un creativo, una variación a la vez: mientras la anterior no tenga resultado (pedida, en producción, en revisión, en
 *   la banca o en test), no se pide otra; lo que falte se pide como concepto nuevo;
 * - la siguiente no repite el tipo de la anterior: si fue de hook, la siguiente es de formato (y al revés); si la anterior
 *   ya probó los dos, ese creativo no se itera ahora.
 * Las reglas, en PHP puro; lo de la base, en `state`.
 */
final class IterationRules
{
    /** Una variación que todavía no tiene resultado. */
    public const BUSY = ['approved', 'production', 'review', 'bank', 'testing'];

    /** El tipo de cada prueba: todo lo que cambia los primeros segundos es "hook"; el formato; cada tramo del video. */
    public static function family(?string $test): string
    {
        return match (true) {
            $test === 'format' => 'format',
            in_array($test, ['after_hook', 'middle', 'close', 'click'], true) => (string) $test,
            // hook, fatigue, winner_hook y los encargos viejos que no guardaron qué variar (se piden como hook, BatchTask)
            default => 'hook',
        };
    }

    /** @param list<?string> $tests @return list<string> */
    public static function families(array $tests): array
    {
        return array_values(array_unique(array_map([self::class, 'family'], $tests)));
    }

    /**
     * Lo que prueba la siguiente variación de un creativo, sin repetir el tipo de la anterior ($prev): lo que toca ($natural)
     * si es otro tipo; si no, otro formato; si la anterior ya fue de formato, hooks nuevos; si probó los dos, null.
     *
     * @param list<string> $prev los tipos de la variación anterior de ese creativo
     */
    public static function nextTest(string $natural, array $prev): ?string
    {
        return match (true) {
            !in_array(self::family($natural), $prev, true) => $natural,
            !in_array('format', $prev, true) => 'format',
            !in_array('hook', $prev, true) => 'winner_hook',
            default => null,
        };
    }

    /**
     * Las piezas de la siguiente variación (PieceRules::plan) sin repetir el tipo de la anterior: se quedan las de otro tipo y
     * las que faltan son de otro formato ($formats, PieceRules::pickFormats) o, si la anterior fue de formato, hooks nuevos.
     * null: ya se probó todo lo que se le puede variar ahora a este creativo.
     *
     * @param list<array{test: string, style: ?string, format: string, hypothesis: ?string, reason: ?string}> $plan
     * @param list<string> $prev
     * @param list<array{style: string, reason: string}> $formats
     */
    public static function avoidRepeat(array $plan, array $prev, array $formats, string $base): ?array
    {
        if ($prev === []) {
            return $plan;
        }
        $keep = array_values(array_filter($plan, fn ($p) => !in_array(self::family($p['test']), $prev, true)));
        $missing = count($plan) - count($keep);
        if ($missing === 0) {
            return $plan;
        }
        // los formatos que no lleva ya otra pieza (si no alcanzan, se repiten)
        $used = array_filter(array_column($keep, 'style'));
        $formats = array_values(array_filter($formats, fn ($f) => !in_array($f['style'], $used, true))) ?: $formats;
        if (!in_array('format', $prev, true) && $formats !== []) {
            for ($i = 0; $i < $missing; $i++) {
                $f = $formats[$i % count($formats)];
                $keep[] = PieceRules::piece('format', $f['style'], $f['style'] === 'imagen' ? 'image' : 'video', $f['reason']);
            }

            return $keep;
        }
        if (!in_array('hook', $prev, true)) {
            return [...$keep, ...array_fill(0, $missing, PieceRules::piece('winner_hook', null, $base))];
        }

        return $keep !== [] ? $keep : null;
    }

    /**
     * De la base: qué creativos tienen una variación sin resultado y los tipos de la última variación de cada uno.
     *
     * @param list<int> $creativeIds
     * @return array{busy: list<int>, prev: array<int, list<string>>}
     */
    public static function state(array $creativeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $creativeIds))));
        $out = ['busy' => [], 'prev' => []];
        if ($ids === []) {
            return $out;
        }
        $busy = [];
        // .141: un concepto nuevo que lo tiene de referencia no es una variación suya
        foreach (CreativeBatch::with('pieces:id,creative_batch_id,test')->where('kind', 'iteration')->whereIn('reference_creative_id', $ids)->whereNotIn('status', ['proposed', 'discarded'])
            ->orderByDesc('id')->get(['id', 'reference_creative_id', 'status']) as $b) {
            $id = (int) $b->reference_creative_id;
            if (in_array($b->status, self::BUSY, true)) {
                $busy[$id] = true;
            }
            $out['prev'][$id] ??= self::families($b->pieces->pluck('test')->all());
        }
        $out['busy'] = array_keys($busy);

        return $out;
    }
}
