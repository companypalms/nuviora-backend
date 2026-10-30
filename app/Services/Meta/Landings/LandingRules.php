<?php

namespace App\Services\Meta\Landings;

use App\Services\Meta\Decisions\Rules\AdRules;

/**
 * Lo que dicen los números de cada landing (Etapa 5, paso 6a). PHP puro; los números de corte son los de Meta Ads › Reglas.
 * - Visita = visitas a la página ÷ clics en el enlace. Debajo de `visit_leak` (55 %) la landing es lenta; si Meta no ve
 *   ninguna visita con muchos clics, falta el píxel en esa página.
 * - Compra = compras ÷ visitas. Un ángulo que hace clic como el resto del producto pero compra `winners_gap_pct` (20 %) o
 *   más debajo del producto: la landing no habla de ese ángulo.
 * - Con el mismo ángulo en dos landings, la que compra `winners_gap_pct` o más debajo de la mejor, pierde contra ella.
 * - No se opina con menos de `landing_min_clicks` clics (la visita) ni de `landing_min_visits` visitas (la compra).
 */
final class LandingRules
{
    public const SUMS = ['spend', 'impressions', 'link_clicks', 'landing_page_views', 'purchases', 'spend_with_c', 'allowed'];

    /** Del peor al mejor: el color de una landing es el de su peor punto. */
    public const LEVELS = ['red', 'orange', 'yellow', 'info', 'green'];

    public static function empty(): array
    {
        return array_fill_keys(self::SUMS, 0.0);
    }

    /** Suma un día de un anuncio; con la C de su ciudad para el CPA contra C. */
    public static function add(array &$t, array $row, ?float $c): void
    {
        foreach (['spend', 'impressions', 'link_clicks', 'landing_page_views', 'purchases'] as $k) {
            $t[$k] += (float) ($row[$k] ?? 0);
        }
        if ($c !== null && $c > 0) {
            $t['spend_with_c'] += (float) ($row['spend'] ?? 0);
            $t['allowed'] += (float) ($row['purchases'] ?? 0) * $c;
        }
    }

    /**
     * La landing de un anuncio un día: la última anotada hasta ese día; antes de la primera, la primera.
     *
     * @param list<array{0: int, 1: string}> $changes [landing_id, changed_at] de la más vieja a la más nueva
     */
    public static function at(array $changes, string $date): ?int
    {
        $found = null;
        foreach ($changes as [$landingId, $changedAt]) {
            if (substr($changedAt, 0, 10) > $date) {
                break;
            }
            $found = $landingId;
        }

        return $found ?? ($changes[0][0] ?? null);
    }

    /** @return array{visit: ?float, conversion: ?float, ctr: ?float, cpa: ?float, cpa_vs_c: ?float} */
    public static function metrics(array $t): array
    {
        $pct = fn (float $a, float $b, int $d = 1) => $b > 0 ? round($a / $b * 100, $d) : null;

        return [
            'visit' => $pct($t['landing_page_views'], $t['link_clicks']),
            'conversion' => $pct($t['purchases'], $t['landing_page_views'], 2),
            'ctr' => $pct($t['link_clicks'], $t['impressions'], 2),
            'cpa' => $t['purchases'] > 0 ? round($t['spend'] / $t['purchases'], 2) : null,
            'cpa_vs_c' => $pct($t['spend_with_c'], $t['allowed']),
        ];
    }

    /**
     * @param array<int, array{label: string, angle_code: ?string, totals: array, angles: array<string, array>}> $landings (el ángulo '' = sin ángulo)
     * @param array $product los totales del producto (todas sus landings)
     * @param array<string, string> $angleNames código => nombre
     * @return array<int, list<array{key: string, level: string, text: string}>> por landing
     */
    public static function findings(array $landings, array $product, array $s, array $angleNames = []): array
    {
        $gap = (float) $s['winners_gap_pct'];
        $minClicks = (float) $s['landing_min_clicks'];
        $minVisits = (float) $s['landing_min_visits'];
        $pm = self::metrics($product);
        $angle = fn (string $code) => isset($angleNames[$code]) && $angleNames[$code] !== $code ? "{$code} ({$angleNames[$code]})" : $code;
        $out = [];

        foreach ($landings as $id => $l) {
            $t = $l['totals'];
            $m = self::metrics($t);
            $f = [];
            if ($t['link_clicks'] >= $minClicks && $t['landing_page_views'] <= 0) {
                $f[] = ['key' => 'no_pixel', 'level' => 'red', 'text' => 'Meta no ve ninguna visita con ' . self::int($t['link_clicks'])
                    . ' clics: revisar que el píxel esté en esta página.'];
            } elseif ($t['link_clicks'] >= $minClicks && $m['visit'] < $s['visit_leak']) {
                $f[] = ['key' => 'slow', 'level' => 'red', 'text' => 'Lenta: de cada 100 clics llegan ' . AdRules::num($m['visit']) . ' a la página (menos de '
                    . AdRules::num($s['visit_leak']) . '). Revisar la velocidad: fotos o videos pesados, apps o el tema.'];
            } elseif ($t['link_clicks'] >= $minClicks && $m['visit'] < $s['visit_ok']) {
                $f[] = ['key' => 'visit_low', 'level' => 'yellow', 'text' => 'La visita está un poco baja: ' . AdRules::num($m['visit']) . ' % (normal desde '
                    . AdRules::num($s['visit_ok']) . ' %).'];
            }

            foreach ($l['angles'] as $code => $at) {
                $am = self::metrics($at);
                if ($code === '' || $at['landing_page_views'] < $minVisits || $am['conversion'] === null || $pm['conversion'] === null || $pm['ctr'] === null) {
                    continue;
                }
                $clicksLikeProduct = $am['ctr'] !== null && $am['ctr'] >= $pm['ctr'] * (1 - $gap / 100);
                $buysLess = $am['conversion'] <= $pm['conversion'] * (1 - $gap / 100);
                if ($clicksLikeProduct && $buysLess) {
                    $less = AdRules::num((1 - $am['conversion'] / $pm['conversion']) * 100);
                    $numbers = AdRules::num($am['conversion']) . ' % contra ' . AdRules::num($pm['conversion']) . ' % de las visitas';
                    $f[] = $l['angle_code'] === $code
                        ? ['key' => 'angle_weak', 'level' => 'orange', 'text' => "Es la landing del ángulo {$angle($code)} y compra {$less} % menos que el producto ({$numbers}), aunque los anuncios hacen clic como siempre: revisar la oferta o lo que dice la página."]
                        : ['key' => 'angle_mismatch', 'level' => 'orange', 'text' => "Con el ángulo {$angle($code)} hacen clic como siempre, pero compran {$less} % menos que el producto ({$numbers}): esta landing no habla de ese ángulo. Probar una landing del ángulo, sin cambiar los creativos."];
                }
            }
            $out[$id] = $f;
        }

        // El mismo ángulo en dos o más landings: cuál compra mejor
        $byAngle = [];
        foreach ($landings as $id => $l) {
            foreach ($l['angles'] as $code => $at) {
                $c = self::metrics($at)['conversion'];
                if ($code !== '' && $at['landing_page_views'] >= $minVisits && $c !== null) {
                    $byAngle[$code][$id] = $c;
                }
            }
        }
        foreach ($byAngle as $code => $rates) {
            if (count($rates) < 2) {
                continue;
            }
            arsort($rates);
            $bestId = array_key_first($rates);
            $best = $rates[$bestId];
            $beaten = false;
            foreach ($rates as $id => $rate) {
                if ($id !== $bestId && $best > 0 && $rate <= $best * (1 - $gap / 100)) {
                    $beaten = true;
                    $out[$id][] = ['key' => 'beaten', 'level' => 'yellow', 'text' => "Con el ángulo {$angle($code)}, «{$landings[$bestId]['label']}» compra mejor: "
                        . AdRules::num($best) . ' % contra ' . AdRules::num($rate) . ' % de esta. Conviene llevar esos anuncios a «' . $landings[$bestId]['label'] . '».'];
                }
            }
            if ($beaten) {
                $out[$bestId][] = ['key' => 'best', 'level' => 'green', 'text' => "Con el ángulo {$angle($code)} es la que mejor compra: " . AdRules::num($best) . ' % de las visitas.'];
            }
        }

        foreach ($landings as $id => $l) {
            if ($l['totals']['landing_page_views'] < $minVisits && $l['totals']['link_clicks'] > 0 && !array_filter($out[$id], fn ($f) => $f['level'] === 'red')) {
                $out[$id][] = ['key' => 'little_data', 'level' => 'info', 'text' => 'Todavía pocas visitas para opinar de la compra: ' . self::int($l['totals']['landing_page_views'])
                    . ' de ' . self::int($minVisits) . '.'];
            }
        }

        return $out;
    }

    /** El color de una landing: el de su peor punto, o null si no hay nada que decir. */
    public static function level(array $findings): ?string
    {
        $levels = array_column($findings, 'level');
        foreach (self::LEVELS as $l) {
            if (in_array($l, $levels, true)) {
                return $l;
            }
        }

        return null;
    }

    private static function int(float $v): string
    {
        return number_format($v, 0, ',', '.');
    }
}
