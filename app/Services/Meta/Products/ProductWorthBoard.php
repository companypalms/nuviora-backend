<?php

namespace App\Services\Meta\Products;

use App\Models\Setting;
use App\Services\Finance\FinancialCpa;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\ProductWorth;
use Illuminate\Support\Facades\DB;

/**
 * `.139`: la etiqueta de cada producto (ProductWorth) del último análisis, guardada en un ajuste para que las pantallas de
 * los editores, el pronóstico y la página de productos la lean sin volver a calcularla. Los productos con campañas traen la
 * suya del análisis (con su ganancia); los demás que tuvieron pedidos (los viejos, sin campañas) solo llevan la etiqueta.
 */
class ProductWorthBoard
{
    public const KEY = 'meta.product_worth';

    /** Lo que se guarda de cada producto (sin dinero: el líder solo ve el orden). */
    private const FIELDS = ['level', 'label', 'early', 'effectiveness', 'orders', 'missing', 'rescued', 'why', 'stop', 'since', 'left_out', 'cities'];

    /** @param array<int, array> $decisions MetaDecisions::compute */
    public static function save(array $decisions, array $s, OpsFacts $ops = new OpsFacts()): void
    {
        $out = [];
        foreach ($decisions as $pid => $d) {
            if (isset($d['product']['worth'])) {
                $out[(int) $pid] = array_intersect_key($d['product']['worth'], array_flip(self::FIELDS));
            }
        }
        [$from] = FinancialCpa::window(now(config('app.timezone')), (int) $s['worth_max_days']);
        $others = DB::table('order_financials')->where('created_date', '>=', $from->toDateString())->whereNotNull('product_id')
            ->whereNotIn('product_id', array_keys($out) ?: [0])->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        foreach ($ops->worth($others, $s) as $pid => $sample) {
            $out[$pid] = array_intersect_key(ProductWorth::verdict($sample, null, $s)
                + ['stop' => false, 'since' => $sample['since'], 'left_out' => $sample['left_out'], 'cities' => $sample['cities']], array_flip(self::FIELDS));
        }
        Setting::set(self::KEY, ['at' => now()->toIso8601String(), 'products' => $out]);
    }

    /** @return array<int, array> producto => su etiqueta */
    public static function all(): array
    {
        $stored = Setting::get(self::KEY);
        $stored = is_string($stored) ? (json_decode($stored, true) ?: []) : (array) $stored;
        $out = [];
        foreach ((array) ($stored['products'] ?? []) as $pid => $w) {
            $out[(int) $pid] = (array) $w;
        }

        return $out;
    }

    /** El lugar de los encargos de cada producto en las listas de los editores (ProductWorth::rank). @return array<int, int> */
    public static function ranks(): array
    {
        return array_map(fn ($w) => ProductWorth::rank($w['level'] ?? null), self::all());
    }

    /** "No estoy de acuerdo" (o quitarlo) en la misión de un producto Malo: se frena o no ya, sin esperar al próximo análisis. */
    public static function brake(int $productId, bool $on): void
    {
        $stored = Setting::get(self::KEY);
        if (!is_array($stored) || !isset($stored['products'][$productId])) {
            return;
        }
        $stored['products'][$productId]['stop'] = $on && ($stored['products'][$productId]['level'] ?? null) === 'bad';
        Setting::set(self::KEY, $stored);
    }

    /** Los productos Malos cuyos encargos nuevos esperan a que el dueño decida. @return list<int> */
    public static function stoppedIds(): array
    {
        return array_keys(array_filter(self::all(), fn ($w) => (bool) ($w['stop'] ?? false)));
    }
}
