<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\MetaAdsReport;
use Illuminate\Support\Facades\DB;

/**
 * Lo común de las herramientas de Meta Ads del asistente (Etapa 5, paso 3b). Todas solo leen: lo que dicen son las
 * reglas del dueño (Paso 2) y el análisis de la mañana (Paso 3a), con sus montos. Nada se cambia en Meta.
 */
abstract class MetaTool implements Tool
{
    /** El modo del producto en palabras (normal no se dice). */
    protected const MODES = ['liquidation' => 'en liquidación', 'paused' => 'pausado', 'abandoned' => 'abandonado'];

    public function __construct(protected Lookup $lookup, protected MetaDecisions $decisions)
    {
    }

    /** @throws ToolProblem si no dijo el producto, no existe o hay varios */
    protected function productId(?string $name): int
    {
        return $this->lookup->productId($name) ?? throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál.');
    }

    /** Lo que dicen las reglas del dueño de un producto, lo mismo que en Meta Ads. @throws ToolProblem si no tiene campañas */
    protected function decisionsFor(int $productId): array
    {
        $d = $this->decisions->forProducts([$productId])[$productId] ?? null;
        if (!$d || !$d['campaigns']) {
            throw new ToolProblem('Ese producto no tiene campañas de Meta conectadas. Se conectan en Configuración › Meta Ads › Campañas.');
        }

        return $d;
    }

    /**
     * El C y el BE que usan las reglas en cada ciudad, los mismos de Meta Ads (Finanzas › CPA los puede calcular por
     * ciudad). Si todas tienen los mismos, c y be; si no, null y van por ciudad.
     *
     * @param string[]|null $campaignIds null = las campañas activas del producto
     * @return array{c: ?float, be: ?float, source: ?string, by_city: array<string, array{c: ?float, be: ?float}>}
     */
    protected static function targets(int $productId, ?array $campaignIds = null): array
    {
        $q = DB::table('meta_campaigns as c')->leftJoin('cities as ci', 'ci.id', '=', 'c.city_id')->where('c.product_id', $productId);
        $campaignIds !== null ? $q->whereIn('c.meta_id', $campaignIds ?: ['-']) : $q->where('c.effective_status', 'ACTIVE')->whereNull('c.missing_since');
        $report = new MetaAdsReport();
        $byCity = [];
        $source = null;
        foreach ($q->get(['c.city_id', 'ci.name as city']) as $r) {
            $t = $report->targetFor($productId, $r->city_id !== null ? (int) $r->city_id : null);
            $byCity[$r->city ?? 'Sin ciudad'] = ['c' => $t['target_cpa'], 'be' => $t['break_even_cpa']];
            $source ??= $t['source'];
        }
        if (!$byCity) {
            $t = $report->targetFor($productId, null);

            return ['c' => $t['target_cpa'], 'be' => $t['break_even_cpa'], 'source' => $t['source'], 'by_city' => []];
        }
        $first = reset($byCity);
        $same = !array_filter($byCity, fn ($t) => $t !== $first);

        return ['c' => $same ? $first['c'] : null, 'be' => $same ? $first['be'] : null, 'source' => $source, 'by_city' => $same ? [] : $byCity];
    }

    /** El C y el BE para la IA: uno solo, o el de cada ciudad si son distintos. */
    protected static function targetWords(array $t): array
    {
        if (!$t['by_city']) {
            return array_filter(['cpa_objetivo_c' => self::money($t['c']), 'break_even_be' => self::money($t['be'])]);
        }

        return ['c_y_be_por_ciudad' => array_map(fn ($city, $x) => "{$city}: C " . (self::money($x['c']) ?? 'sin poner') . ' · BE '
            . (self::money($x['be']) ?? 'sin poner'), array_keys($t['by_city']), $t['by_city'])];
    }

    /**
     * El color de un punto de una lista: lo más urgente en rojo y escalar en verde (el orden de UrgentList). Público para
     * probarlo.
     */
    public static function level(string $kind): string
    {
        return match (UrgentList::SEVERITY[$kind] ?? null) {
            1 => 'red',
            2 => 'orange',
            3, 5 => 'yellow',
            4 => 'green',
            default => 'pending',
        };
    }

    protected static function money(?float $value): ?string
    {
        return $value === null ? null : Present::value($value, 'money');
    }

    /** "3" o "2,5" compras. */
    protected static function count(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    /** "1 compra", "3 compras". */
    protected static function purchases(float $value): string
    {
        return self::count($value) . ($value == 1 ? ' compra' : ' compras');
    }

    /** Cómo está un CPA contra el objetivo (C) y el break-even (BE). Público para probarlo. */
    public static function against(?float $cpa, ?float $c, ?float $be): ?string
    {
        if ($cpa === null) {
            return 'sin compras';
        }
        if ($c === null || $be === null) {
            return null;
        }

        return match (true) {
            $cpa <= $c => 'debajo del objetivo (bien)',
            $cpa < $be => 'entre el objetivo y el break-even (cuidado)',
            default => 'en el break-even o encima (pierde dinero)',
        };
    }

    /** Gasto, compras y CPA de una ventana, en palabras. */
    protected static function window(float $spend, float $purchases, ?float $c = null, ?float $be = null): array
    {
        $cpa = $purchases > 0 ? round($spend / $purchases, 2) : null;

        return array_filter(['gasto' => self::money($spend), 'compras' => self::count($purchases), 'cpa' => self::money($cpa),
            'contra_el_objetivo' => self::against($cpa, $c, $be)], fn ($v) => $v !== null);
    }
}
