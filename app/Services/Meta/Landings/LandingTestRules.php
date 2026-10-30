<?php

namespace App\Services\Meta\Landings;

use App\Services\Meta\Decisions\Rules\AdRules;

/**
 * El test de una landing publicada (Etapa 5, paso 6b), en PHP puro: los mismos anuncios antes y después.
 * - Antes: los `landing_test_before_days` (7) días anteriores al día en que se publicó.
 * - Después: desde el día siguiente, con los días en que esos anuncios llevaban a la landing nueva.
 * - Hace falta `landing_test_min_purchases` (10) compras y `landing_test_min_visits` (300) visitas de cada lado, y al
 *   menos `landing_test_min_days` (3) días después; si a los `landing_test_max_days` (14) no hay muestra, termina "sin
 *   muestra".
 * - Gana si el % de compra (compras ÷ visitas) sube `landing_test_win_pct` (15 %) o más; pierde si baja eso o más; si no,
 *   empate. El % de visita se muestra también (una landing más liviana sube la visita).
 * Los números se cambian en Meta Ads › Reglas.
 */
final class LandingTestRules
{
    public const STATES = ['waiting' => 'En test', 'won' => 'Ganó', 'lost' => 'Perdió', 'tie' => 'Empate', 'no_sample' => 'Sin muestra'];

    /**
     * @param array $before totales de los mismos anuncios antes (LandingRules::SUMS)
     * @param array $after totales después, solo los días que llevaban a la landing nueva
     * @param int $afterDays días completos después de publicar que ya pasaron
     * @return array{state: string, label: string, done: bool, change: ?float, visit_change: ?float, before: array, after: array, text: string}
     */
    public static function evaluate(array $before, array $after, int $afterDays, array $s): array
    {
        $enough = fn (array $t) => $t['purchases'] >= $s['landing_test_min_purchases'] && $t['landing_page_views'] >= $s['landing_test_min_visits'];
        $mb = LandingRules::metrics($before);
        $ma = LandingRules::metrics($after);
        $change = $mb['conversion'] && $ma['conversion'] !== null ? round(($ma['conversion'] / $mb['conversion'] - 1) * 100, 1) : null;
        $visitChange = $mb['visit'] && $ma['visit'] !== null ? round($ma['visit'] - $mb['visit'], 1) : null;
        $max = (int) $s['landing_test_max_days'];

        [$state, $done] = match (true) {
            !$enough($before) => ['no_sample', $afterDays >= (int) $s['landing_test_min_days']],
            $afterDays < (int) $s['landing_test_min_days'] || !$enough($after) => $afterDays >= $max ? ['no_sample', true] : ['waiting', false],
            $change >= $s['landing_test_win_pct'] => ['won', true],
            $change <= -$s['landing_test_win_pct'] => ['lost', true],
            default => ['tie', true],
        };

        $numbers = 'Compra ' . self::pct($mb['conversion']) . ' antes → ' . self::pct($ma['conversion']) . ' después'
            . ($change !== null ? ' (' . ($change > 0 ? '+' : '') . AdRules::num($change) . ' %)' : '')
            . '; visita ' . self::pct($mb['visit']) . ' → ' . self::pct($ma['visit']) . '.';
        $text = match ($state) {
            'won' => "Ganó: {$numbers} Dejarla.",
            'lost' => "Perdió: {$numbers} Volver a la anterior.",
            'tie' => "Empate: {$numbers} Se puede dejar cualquiera de las dos.",
            'no_sample' => !$enough($before)
                ? 'Sin muestra: antes no había suficientes compras o visitas con esos anuncios (' . self::int($before['purchases']) . ' compras y '
                    . self::int($before['landing_page_views']) . ' visitas). ' . $numbers
                : "Sin muestra: a los {$max} días no llegó a " . self::int($s['landing_test_min_purchases']) . ' compras y '
                    . self::int($s['landing_test_min_visits']) . " visitas. {$numbers}",
            default => "En test: lleva {$afterDays} " . ($afterDays === 1 ? 'día' : 'días') . ', ' . self::int($after['purchases']) . ' de '
                . self::int($s['landing_test_min_purchases']) . ' compras y ' . self::int($after['landing_page_views']) . ' de '
                . self::int($s['landing_test_min_visits']) . " visitas. {$numbers}",
        };

        return ['state' => $state, 'label' => self::STATES[$state], 'done' => $done, 'change' => $change, 'visit_change' => $visitChange,
            'before' => self::block($before), 'after' => self::block($after), 'text' => $text];
    }

    /** Los días del test: antes [desde, hasta] y después [desde, hasta], con el día de publicar afuera. */
    public static function windows(string $publishedDate, string $yesterday, array $s): array
    {
        $d0 = new \DateTimeImmutable($publishedDate);
        $afterTo = min($d0->modify('+' . (int) $s['landing_test_max_days'] . ' days')->format('Y-m-d'), $yesterday);
        $afterFrom = $d0->modify('+1 day')->format('Y-m-d');

        return [
            'before' => [$d0->modify('-' . (int) $s['landing_test_before_days'] . ' days')->format('Y-m-d'), $d0->modify('-1 day')->format('Y-m-d')],
            'after' => [$afterFrom, $afterTo],
            'after_days' => $afterTo >= $afterFrom ? (int) (new \DateTimeImmutable($afterFrom))->diff(new \DateTimeImmutable($afterTo))->days + 1 : 0,
        ];
    }

    private static function block(array $t): array
    {
        return ['totals' => ['spend' => round($t['spend'], 2), 'link_clicks' => (int) $t['link_clicks'], 'visits' => (int) $t['landing_page_views'],
            'purchases' => (int) round($t['purchases'])], 'metrics' => LandingRules::metrics($t)];
    }

    private static function pct(?float $v): string
    {
        return $v === null ? 'N/A' : AdRules::num($v) . ' %';
    }

    private static function int(float $v): string
    {
        return number_format($v, 0, ',', '.');
    }
}
