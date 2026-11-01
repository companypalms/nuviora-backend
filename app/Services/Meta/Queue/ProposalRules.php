<?php

namespace App\Services\Meta\Queue;

/**
 * Qué encargos proponer cada mañana (Etapa 5, paso 4b), en PHP puro y con las reglas del dueño. La IA solo los redacta.
 * - Cuántos: lo que pide la urgencia (ya con la banca restada), menos lo que ya se propuso hoy, hasta la capacidad de los
 *   editores. Primero el mínimo de cada producto y después, si sobra, hasta el máximo.
 * - En qué orden, como decidió el dueño: lo que está en el break-even o encima y todavía se puede salvar (U1, U2), los
 *   ganadores para escalar (U3) y el resto (U4); dentro, más dinero en riesgo primero. Nunca un producto sin stock, en
 *   pausa, abandonado ni en liquidación.
 * - Qué: con un ganador con volumen, iteraciones de ese ganador; si no, un concepto nuevo dentro del ángulo principal, o
 *   un ángulo nuevo solo si ese ángulo ya falló en 2 batches; si la campaña se satura, creativos de arriba del embudo.
 * - El creativo de referencia: consistencia entre ciudades > volumen > distancia al CPA objetivo. La ciudad de
 *   aprendizaje: la que más vendió en 7 días y tiene stock.
 * - `.65`: los batches del testeo (Products\TestingPhase::candidates) van después de U1 y U2 y antes de lo demás. Los
 *   ángulos que perdieron en un testeo no reciben más encargos, y un ángulo nuevo es primero uno de la ficha sin probar.
 */
final class ProposalRules
{
    /** Las ciudades que no sirven para aprender: se pausan, se dejan o no venden el producto. */
    private const NO_CITY = ['pause_city', 'drop', 'last_test'];

    private const CROSS_ORDER = ['transversal' => 0, 'city_sensitive' => 1];

    /**
     * @param array<int, array> $decisions MetaDecisions::compute
     * @param array<int, int> $alreadyToday los encargos que las reglas ya propusieron hoy, por producto
     * @param int $capacity cuántos encargos nuevos caben hoy
     * @param list<list<array>> $testing .65: los batches que faltan de los productos en testeo, por producto (ya armados)
     * @return list<array{product_id: int, product: string, kind: string, angle_code: ?string, reference_id: ?int, reference: ?string,
     *     reference_type: ?string, city_id: ?int, city: ?string, format: string, iterate: ?string, urgency: ?int, reason: string}>
     */
    public static function plan(array $decisions, array $alreadyToday, int $capacity, array $testing = []): array
    {
        if ($capacity <= 0) {
            return [];
        }
        $products = [];
        foreach ($decisions as $pid => $d) {
            $plan = $d['plan'] ?? null;
            $u = $plan['urgency'] ?? null;
            if (!$u || $u['level'] >= 5 || ($d['product']['mode'] ?? 'normal') !== 'normal') {
                continue;
            }
            [$min, $max] = self::range((string) $u['batches']);
            $done = (int) ($alreadyToday[$pid] ?? 0);
            $city = self::city($d['campaigns'] ?? []);
            if ($max - $done <= 0 || $city === null) {
                continue;
            }
            $products[] = ['pid' => (int) $pid, 'd' => $d, 'level' => (int) $u['level'], 'risk' => (float) ($plan['money_at_risk'] ?? 0),
                'min' => max(0, $min - $done), 'max' => $max - $done, 'done' => $done, 'city' => $city, 'n' => 0];
        }
        usort($products, fn ($a, $b) => [$a['level'], -$a['risk'], $a['d']['product']['name']] <=> [$b['level'], -$b['risk'], $b['d']['product']['name']]);

        // Primero el mínimo de cada producto; después, si sobra, hasta el máximo. .65: el testeo va después del mínimo de lo
        // que pierde dinero (U1 y U2) y antes del mínimo de lo demás
        $out = [];
        $urgent = array_keys(array_filter($products, fn ($p) => $p['level'] <= 2));
        $rest = array_keys(array_filter($products, fn ($p) => $p['level'] > 2));
        self::fill($products, $urgent, 'min', $out, $capacity);
        foreach ($testing as $items) {
            foreach ($items as $item) {
                if (count($out) < $capacity) {
                    $out[] = $item;
                }
            }
        }
        self::fill($products, $rest, 'min', $out, $capacity);
        self::fill($products, [...$urgent, ...$rest], 'max', $out, $capacity);

        return $out;
    }

    /** Agrega encargos de esos productos hasta su mínimo (o su máximo) mientras haya capacidad. */
    private static function fill(array &$products, array $keys, string $bound, array &$out, int $capacity): void
    {
        foreach ($keys as $k) {
            while ($products[$k]['n'] < $products[$k][$bound] && count($out) < $capacity) {
                $out[] = self::one($products[$k]['d'], $products[$k]['done'] + $products[$k]['n'], $products[$k]['city']);
                $products[$k]['n']++;
            }
        }
    }

    /** "2–3" → [2, 3]; "1" → [1, 1]; "0–1" → [0, 1]. */
    public static function range(string $batches): array
    {
        $parts = array_map('intval', preg_split('/[–-]/u', $batches));

        return [min($parts), max($parts)];
    }

    /** El tipo del encargo número $n de un producto (0 = el primero). */
    public static function kind(array $work, int $n): string
    {
        $winner = $work['winner'] ?? null;
        $primary = $winner ? 'iteration' : ((($work['failed_batches_in_angle'] ?? 0) >= 2 && ($work['main_angle'] ?? null)) ? 'new_angle' : 'new_concept');
        $sequence = [$primary];
        if ($work['saturated'] ?? []) {
            $sequence[] = 'top_funnel';
        }
        $sequence[] = $primary === 'iteration' ? 'new_concept' : ($winner ? 'iteration' : 'new_concept');

        return $sequence[min($n, count($sequence) - 1)];
    }

    private static function one(array $d, int $n, array $city): array
    {
        $work = $d['plan']['work'] ?? [];
        $kind = self::kind($work, $n);
        $winner = $work['winner'] ?? null;
        $failedAngle = $work['main_angle'] ?? null;
        // .65: un ángulo que perdió en un testeo no recibe más encargos; un ángulo nuevo es primero uno de la ficha sin probar
        $pa = ($d['profile_angles'] ?? []) + ['order' => [], 'lost' => [], 'used' => []];
        $mainAngle = $failedAngle !== null && in_array($failedAngle, $pa['lost'], true)
            ? (array_values(array_diff($pa['order'], $pa['lost']))[0] ?? null) : $failedAngle;
        $tried = array_merge($pa['used'], array_filter(array_column($d['creatives'] ?? [], 'angle_code')));
        $fresh = $kind === 'new_angle' ? (array_values(array_diff($pa['order'], $pa['lost'], $tried))[0] ?? null) : null;
        $ref = match ($kind) {
            'iteration' => $winner ? ['id' => $winner['id'], 'label' => $winner['label'], 'type' => $winner['type'] ?? null, 'angle' => $winner['angle'] ?? null] : null,
            'new_angle' => null,
            default => self::reference($d['creatives'] ?? [], $mainAngle),
        };
        $iterate = match ($kind) {
            'iteration' => ($ref['type'] ?? null) === 'image' ? 'el formato: la imagen ganadora pasa a video' : ($winner['iterate'] ?? 'el hook (los primeros segundos)'),
            'top_funnel' => 'creativos de arriba del embudo: para gente que todavía no conoce el problema ni el producto',
            'new_angle' => 'un ángulo nuevo' . ($fresh ? " de tu ficha que todavía no se probó ({$fresh})" : '') . ': '
                . ($failedAngle ? "{$failedAngle} ya falló en " . (int) ($work['failed_batches_in_angle'] ?? 0) . ' batches' : 'sin un ángulo que funcione'),
            default => 'un concepto nuevo' . ($mainAngle ? " dentro de {$mainAngle}" : ''),
        };
        $u = $d['plan']['urgency'];

        return [
            'product_id' => (int) $d['product']['id'], 'product' => $d['product']['name'], 'kind' => $kind,
            'angle_code' => $kind === 'new_angle' ? $fresh : ($kind === 'iteration' ? ($ref['angle'] ?? $mainAngle) : $mainAngle),
            'reference_id' => $ref['id'] ?? null, 'reference' => $ref['label'] ?? null, 'reference_type' => $ref['type'] ?? null,
            'city_id' => $city['city_id'], 'city' => $city['city'], 'format' => 'video', 'iterate' => $iterate, 'urgency' => (int) $u['level'],
            'reason' => $u['label'] . ($u['reasons'] ? ': ' . implode('; ', $u['reasons']) : '') . '.',
        ];
    }

    /** La mejor referencia de un ángulo (o de todo el producto): consistencia entre ciudades > volumen > distancia a C. */
    private static function reference(array $creatives, ?string $angle): ?array
    {
        $pool = array_filter($creatives, fn ($c) => ($c['active'] ?? false) && in_array($c['verdict']['key'] ?? null, ['proven', 'promising', 'intermediate', 'failing'], true));
        if ($angle !== null && array_filter($pool, fn ($c) => ($c['angle_code'] ?? null) === $angle)) {
            $pool = array_filter($pool, fn ($c) => ($c['angle_code'] ?? null) === $angle);
        }
        if (!$pool) {
            return null;
        }
        usort($pool, fn ($a, $b) => [self::CROSS_ORDER[$a['cross']['key'] ?? ''] ?? 2, -($a['week']['purchases'] ?? 0), $a['week']['cpa'] ?? INF]
            <=> [self::CROSS_ORDER[$b['cross']['key'] ?? ''] ?? 2, -($b['week']['purchases'] ?? 0), $b['week']['cpa'] ?? INF]);
        $c = $pool[0];

        return ['id' => $c['id'], 'label' => $c['label'], 'type' => $c['type'] ?? null, 'angle' => $c['angle_code'] ?? null];
    }

    /** La ciudad de aprendizaje: activa, con stock (o sin dato de stock) y la que más vendió en 7 días. */
    public static function city(array $campaigns): ?array
    {
        $ok = array_filter($campaigns, fn ($c) => ($c['active'] ?? false) && ($c['stock'] ?? 1) > 0
            && !in_array($c['action']['key'] ?? null, self::NO_CITY, true) && ($c['action']['label'] ?? null) !== 'No se vende aquí');
        if (!$ok) {
            return null;
        }
        uasort($ok, fn ($a, $b) => ($b['purchases7'] ?? 0) <=> ($a['purchases7'] ?? 0));
        $c = reset($ok);

        return ['city_id' => $c['city_id'] ?? null, 'city' => $c['city'] ?? null];
    }
}
