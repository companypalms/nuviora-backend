<?php

namespace App\Services\Meta\Analysis;

use App\Services\Meta\Decisions\Rules\AdRules;

/**
 * `.156` (dueño, 2026-11-18, al ver "Apaga CRE003-FLEX-FAS-BATCH004: aquí pierde" sin números: "quiero que me expliques el
 * porqué de esta recomendación"; después, "ármame la ficha de los números en el porqué"): por qué se apaga un creativo, en
 * palabras y con sus números. PHP puro: los números los pone la regla que lo apagó (AdRules::why).
 * - short: la línea debajo del título ("En 7 días gastó $9,34 (2×C) sin compras").
 * - lines: el "¿Por qué?": los números en esa ciudad con tu C y tu BE y tu regla en palabras.
 * - others: dónde más está prendido (o que con esto queda apagado del todo).
 */
final class StopWhy
{
    /** La línea corta, debajo del título de la misión. */
    public static function short(array $why): string
    {
        $c = (float) $why['c'];

        return ($why['days'] ? "En {$why['days']} días gastó " : 'Gastó ') . AdRules::money((float) $why['spend'])
            . ' (' . AdRules::num((float) $why['spend'] / $c) . '×C)' . self::bought($why);
    }

    /**
     * El "¿Por qué?" de un creativo que se apaga en una ciudad.
     *
     * @return list<string>
     */
    public static function lines(string $label, string $city, array $why): array
    {
        return array_values(array_filter([self::numbers($label, $city, $why), self::rule($why), self::week($why)]));
    }

    /**
     * "Perdedor en todas": sus números en cada ciudad donde perdió.
     *
     * @param array<string, array> $byCity ciudad => why
     * @return array{short: string, lines: list<string>}
     */
    public static function everywhere(string $label, array $byCity): array
    {
        // Se probó en una sola ciudad: su regla completa, como en una ciudad
        if (count($byCity) === 1) {
            $why = reset($byCity);

            return ['short' => self::short($why), 'lines' => [...self::lines($label, (string) array_key_first($byCity), $why),
                'Es la única ciudad donde se probó: se apaga en todas las campañas.']];
        }
        $lines = [];
        foreach ($byCity as $city => $why) {
            $lines[] = "{$label} en {$city}: " . lcfirst(self::short($why)) . '. ' . self::targets($why);
        }
        $lines[] = 'Tu regla: un creativo que pierde en todas las ciudades donde se probó se apaga en todas las campañas.';

        return ['short' => 'Pierde en ' . count($byCity) . ' ciudades', 'lines' => $lines];
    }

    /** "CRE003… en Distrito Capital, últimos 7 días: gastó $9,34 (2×C) y no tuvo compras. Tu C ahí es $4,65 y tu BE $7,03." */
    private static function numbers(string $label, string $city, array $why): string
    {
        $c = (float) $why['c'];
        $p = (float) $why['purchases'];
        $bought = match (true) {
            $p == 0 => 'no tuvo compras',
            $p == 1 => 'tuvo 1 compra',
            default => 'tuvo ' . AdRules::num($p) . ' compras (CPA ' . AdRules::money((float) $why['spend'] / $p) . ')',
        };

        return "{$label} en {$city}" . ($why['days'] ? ", últimos {$why['days']} días" : ', en toda su vida ahí') . ': gastó '
            . AdRules::money((float) $why['spend']) . ' (' . AdRules::num((float) $why['spend'] / $c) . "×C) y {$bought}. " . self::targets($why);
    }

    /**
     * `.163` (dueño, 2026-11-19: "está diciendo unos gastos… que no estoy viendo en Meta Ads en ninguno de los dos creativos
     * que se llaman así"): un anuncio que se apaga en una campaña, con sus propios números (no los de la campaña), su regla y,
     * si otro anuncio prendido se llama igual, que ese no se toca.
     *
     * @param array $ad MetaDecisions::pruneAd
     * @param list<string> $keep los ad sets de los que se llaman igual y no se apagan
     * @return list<string>
     */
    public static function ad(array $ad, ?string $city, array $keep = []): array
    {
        $why = $ad['why'] ?? null;
        $numbers = 'Anuncio «' . $ad['name'] . '»' . (($ad['adset'] ?? null) ? ' del ad set «' . $ad['adset'] . '»' : '') . ': en 3 días gastó '
            . AdRules::money((float) $ad['spend3']) . self::bought3((float) $ad['spend3'], (float) $ad['purchases3']) . '; en toda su vida, '
            . AdRules::money((float) $ad['spend']) . self::bought3((float) $ad['spend'], (float) $ad['purchases']) . '.';
        $rule = match (true) {
            $why === null => ($ad['reading'] ?? null) ? 'Por qué se apaga: ' . lcfirst((string) $ad['reading']) : null,
            ($ad['rule'] ?? 'ad') === 'creative' => 'Se apaga porque su creativo pierde ' . ($city ? "en {$city}" : 'aquí') . ': ' . lcfirst(self::short($why)) . '. ' . self::rule($why),
            default => self::targets($why) . ' ' . self::rule($why, 'anuncio'),
        };
        $twins = $keep ? 'Ojo: hay otro «' . $ad['name'] . '» prendido en ' . (count($keep) === 1 ? 'el ad set ' : 'los ad sets ')
            . CreativeCalls::join(array_map(fn ($s) => "«{$s}»", $keep)) . ': ' . (count($keep) === 1 ? 'ese no se apaga.' : 'esos no se apagan.') : null;

        return array_values(array_filter([$numbers, $rule, $twins]));
    }

    /** `.163`: " sin compras", " con 1 compra" o " con 3 compras ($6,92 por compra)". */
    private static function bought3(float $spend, float $purchases): string
    {
        return match (true) {
            $purchases == 0 => ' sin compras',
            $purchases == 1 => ' con 1 compra',
            default => ' con ' . AdRules::num($purchases) . ' compras (' . AdRules::money($spend / $purchases) . ' por compra)',
        };
    }

    /** Tu regla, en palabras (`.163`: de un anuncio, o de un creativo en una ciudad). */
    private static function rule(array $why, string $unit = 'creativo'): string
    {
        $min = AdRules::num((float) $why['min']);
        $cap = AdRules::num((float) $why['cap']);
        $margin = AdRules::num((float) $why['margin']);
        $selling = "sin vender, o vendiendo con el costo por compra más de un {$margin} % encima de tu BE";
        $week = fn (float $times) => ($why['days'] ?? null) === 7
            ? ' Se miró la semana porque en 3 días gastó solo ' . AdRules::money((float) ($why['spend3'] ?? 0)) . " y no llegó a {$times}×C." : '';

        return match ($why['rule']) {
            'life_spend' => $unit === 'anuncio' ? "Tu regla: un anuncio que gasta {$min} veces tu C con 0 o 1 compra se apaga."
                : "Tu regla: un creativo que en una ciudad gasta {$min} veces tu C con 0 o 1 compra se apaga ahí.",
            'life_be' => "Tu regla: con 2 compras o más se le da hasta {$cap} veces tu C; si llega con el costo por compra encima de tu BE (más de un {$margin} %, o con "
                . AdRules::num((float) $why['margin_n']) . ' compras o más), se apaga ahí.',
            'failing' => "Fue ganador, pero tu regla apaga en una ciudad a un ganador que gasta {$cap} veces tu C {$selling}. Se sigue iterando en batches nuevos."
                . (isset($why['seen_pct']) ? ' Ya cuenta que Meta ve solo el ' . AdRules::num((float) $why['seen_pct']) . ' % de tus pedidos.' : '') . $week((float) $why['cap']),
            default => 'En toda su vida ahí ' . match ($why['life'] ?? null) {
                'prometedor' => 'iba bien (prometedor)',
                'intermedio' => 'iba regular (intermedio)',
                'en evaluación' => 'estaba en evaluación',
                default => 'no perdía',
            } . ", pero tu regla apaga un creativo que en una ciudad gasta {$min} veces tu C {$selling}." . $week((float) $why['min']),
        };
    }

    /** `.152`: con 3 días malos, por qué su semana no lo salvó. */
    private static function week(array $why): ?string
    {
        $w = $why['week'] ?? null;
        if (($why['days'] ?? null) !== 3 || !is_array($w)) {
            return null;
        }
        $p = (float) $w['purchases'];
        $got = 'En su semana gastó ' . AdRules::money((float) $w['spend']) . ($p == 0 ? ' sin compras' : ' con ' . AdRules::num($p) . ($p == 1 ? ' compra' : ' compras')
            . ' (CPA ' . AdRules::money((float) $w['spend'] / $p) . ')');
        $need = 'para no apagarlo hacían falta ' . AdRules::num((float) $why['watch']) . ' compras en 7 días con el costo por compra en tu BE o debajo';

        return $why['estimated'] ? "{$got}. Con un BE estimado, la semana no lo protege." : "{$got}: {$need}.";
    }

    /**
     * Dónde más está prendido (o que con esto queda apagado del todo).
     *
     * @param list<array{city: ?string, active?: bool}> $cities sus ciudades
     */
    public static function others(string $city, array $cities): ?string
    {
        $rest = array_values(array_filter($cities, fn ($c) => ($c['city'] ?? null) !== null && $c['city'] !== $city));
        if ($rest === []) {
            return null;
        }
        $on = array_values(array_unique(array_map(fn ($c) => (string) $c['city'], array_filter($rest, fn ($c) => $c['active'] ?? false))));
        $off = array_values(array_diff(array_unique(array_map(fn ($c) => (string) $c['city'], $rest)), $on));

        return $on === [] ? 'Ya está apagado en ' . CreativeCalls::join($off) . ': con esto queda apagado del todo.'
            : 'En ' . CreativeCalls::join($on) . ' sigue prendido: ahí no lo toques.';
    }

    /** "Tu C ahí es $4,65 y tu BE $7,03." */
    private static function targets(array $why): string
    {
        return 'Tu C ahí es ' . AdRules::money((float) $why['c'])
            . ($why['be'] !== null ? ' y tu BE ' . AdRules::money((float) $why['be']) . ($why['estimated'] ? ' (estimado)' : '') : '') . '.';
    }

    /** " sin compras", " con 1 compra" o " con 3 compras: CPA $15,00, encima de tu BE ($7,03)". */
    private static function bought(array $why): string
    {
        $p = (float) $why['purchases'];
        if ($p == 0) {
            return ' sin compras';
        }
        $cpa = (float) $why['spend'] / $p;
        $above = $why['be'] !== null && $cpa >= (float) $why['be'] ? ', encima de tu BE (' . AdRules::money((float) $why['be']) . ')' : '';

        return ' con ' . AdRules::num($p) . ($p == 1 ? ' compra' : ' compras') . ($p > 1 ? ': CPA ' . AdRules::money($cpa) . $above : '');
    }
}
