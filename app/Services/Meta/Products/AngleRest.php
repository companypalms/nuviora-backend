<?php

namespace App\Services\Meta\Products;

use App\Services\Meta\Queue\QueueRules;

/**
 * `.116` (dueño, 2026-11-09: "si hemos testeado ya varios batchs de un mismo ángulo que en algún momento fue ganador, ¿hay
 * alguna regla que nos diga que deberíamos testear otro ángulo?"). PHP puro. Reemplaza la regla de "2 batches perdidos en
 * toda la historia":
 * - si los últimos 3 batches seguidos de un ángulo perdieron, ese ángulo descansa 14 días, aunque antes haya ganado (los dos
 *   números están en Meta Ads › Reglas). No se le piden más encargos; lo que vende sigue prendido;
 * - un batch perdió si perdió en todas las ciudades donde se probó. Los empates y los que siguen en prueba no suman ni
 *   cortan la cuenta; si uno nuevo gana, se acaba el descanso;
 * - al terminar el descanso (o si el dueño elige ese ángulo) la cuenta empieza de cero;
 * - lo mismo con las iteraciones de un creativo.
 */
final class AngleRest
{
    private const WON = ['proven', 'promising'];

    /** Sin datos y apagado: ahí no se llegó a probar. */
    private const NOT_TESTED = ['no_data', 'no_target'];

    /**
     * Cómo terminó un batch con lo que dicen las reglas en cada ciudad: won, lost, tie o null si sigue en prueba.
     *
     * @param list<array{key: string, active: bool}> $sets
     */
    public static function outcome(array $sets): ?string
    {
        $keys = array_column(array_filter($sets, fn ($s) => $s['active'] || !in_array($s['key'], self::NOT_TESTED, true)), 'key');
        if (array_intersect($keys, self::WON)) {
            return 'won';
        }
        if ($keys === [] || array_diff($keys, QueueRules::FINAL_VERDICTS)) {
            return null;
        }

        return array_diff($keys, ['loser']) === [] ? 'lost' : 'tie';
    }

    /**
     * ¿Está descansando? Con los batches de un ángulo (o las iteraciones de un creativo) con resultado.
     *
     * @param list<array{code: string, launched: string, ended: ?string, outcome: string}> $batches
     * @param int $need cuántos perdidos seguidos bastan
     * @param int $days cuántos días descansa
     * @param ?string $floor lo que salió ese día o antes no cuenta (el dueño eligió ese ángulo: empieza de cero)
     * @return array{until: string, codes: list<string>}|null
     */
    public static function state(array $batches, int $need, int $days, string $today, ?string $floor = null): ?array
    {
        usort($batches, fn ($a, $b) => [$a['launched'], $a['code']] <=> [$b['launched'], $b['code']]);
        $streak = [];
        $rest = null;
        foreach ($batches as $b) {
            if ($floor !== null && $b['launched'] <= $floor) {
                continue;
            }
            // Lo que salió mientras descansaba no cuenta para la próxima vez, salvo que haya ganado
            if ($rest !== null && $b['launched'] <= $rest['until']) {
                $rest = $b['outcome'] === 'won' ? null : $rest;
                continue;
            }
            $rest = null;
            if ($b['outcome'] === 'won') {
                $streak = [];
            } elseif ($b['outcome'] === 'lost') {
                $streak[] = $b;
                if (count($streak) >= $need) {
                    $from = $b['ended'] ?? $b['launched'];
                    $rest = ['until' => date('Y-m-d', strtotime("{$from} +{$days} days")), 'codes' => array_column($streak, 'code')];
                    $streak = [];
                }
            }
        }

        return $rest !== null && $today <= $rest['until'] ? $rest : null;
    }

    /**
     * Los ángulos de un producto que están descansando.
     *
     * @param list<array{angle: ?string, code: string, launched: string, ended: ?string, outcome: string}> $batches
     * @param array<string, string> $floors ángulo => desde cuándo cuenta (el dueño lo eligió ese día)
     * @return array<string, array{until: string, codes: list<string>}>
     */
    public static function angles(array $batches, int $need, int $days, string $today, array $floors = []): array
    {
        $by = [];
        foreach ($batches as $b) {
            if (($b['angle'] ?? null) !== null) {
                $by[(string) $b['angle']][] = $b;
            }
        }
        $out = [];
        foreach ($by as $angle => $list) {
            if ($r = self::state($list, $need, $days, $today, $floors[(string) $angle] ?? null)) {
                $out[(string) $angle] = $r;
            }
        }

        return $out;
    }

    /**
     * Los batches de un producto en Meta con su resultado, juntando las ciudades donde salieron. Los que siguen en prueba
     * no van.
     *
     * @param array<string, array> $adsets los ad sets (AdFacts)
     * @param array<string, array> $ads los anuncios (AdFacts), para saber cuándo salió cada uno
     * @param array<string, array> $verdicts el veredicto de cada ad set (MetaDecisions), con last_spend
     * @return list<array{angle: ?string, code: string, launched: string, ended: ?string, outcome: string}>
     */
    public static function batches(array $adsets, array $ads, array $verdicts): array
    {
        $first = [];
        foreach ($ads as $a) {
            $at = substr((string) ($a['created_time'] ?? ''), 0, 10);
            $set = (string) $a['adset_meta_id'];
            if ($at !== '' && (!isset($first[$set]) || $at < $first[$set])) {
                $first[$set] = $at;
            }
        }
        $groups = [];
        foreach ($adsets as $id => $set) {
            $id = (string) $id;
            $key = (string) ($set['batch_code'] ?? $id);
            $g = $groups[$key] ?? ['angle' => $set['angle_code'] ?? null, 'code' => (string) ($set['batch_code'] ?? $set['name'] ?? $id), 'launched' => null, 'ended' => null, 'sets' => []];
            $launched = $first[$id] ?? null;
            $ended = $verdicts[$id]['last_spend'] ?? null;
            $g['launched'] = $launched !== null && ($g['launched'] === null || $launched < $g['launched']) ? $launched : $g['launched'];
            $g['ended'] = $ended !== null && ($g['ended'] === null || $ended > $g['ended']) ? $ended : $g['ended'];
            $g['sets'][] = ['key' => (string) ($verdicts[$id]['key'] ?? 'no_data'), 'active' => (bool) ($set['active'] ?? false)];
            $groups[$key] = $g;
        }
        $out = [];
        foreach ($groups as $g) {
            $outcome = self::outcome($g['sets']);
            if ($outcome !== null && $g['launched'] !== null) {
                $out[] = ['angle' => $g['angle'], 'code' => $g['code'], 'launched' => $g['launched'], 'ended' => $g['ended'], 'outcome' => $outcome];
            }
        }

        return $out;
    }

    /** El ángulo que sigue: primero uno de la ficha sin probar, si no, cualquiera que no haya perdido ni esté descansando. */
    public static function next(array $order, array $blocked, array $tried): ?string
    {
        $available = array_values(array_diff($order, $blocked));

        return array_values(array_diff($available, $tried))[0] ?? $available[0] ?? null;
    }

    /** "hasta el 23/11". */
    public static function untilText(string $date): string
    {
        return 'hasta el ' . date('j/n', strtotime($date));
    }

    /** "B0101, B0102 y B0103". */
    public static function codesText(array $codes): string
    {
        return TestingRules::join($codes);
    }
}
