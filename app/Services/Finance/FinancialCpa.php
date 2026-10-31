<?php

namespace App\Services\Finance;

use App\Models\ProductFinancialSetting;
use App\Models\Setting;
use App\Models\TargetCpaVersion;
use App\Services\Meta\MetaAdsData;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CPA objetivo y break-even reales, por producto + ciudad (§70, el principal) y por producto (§69), en la misma medida
 * que el CPA de Ads Manager: por compra de Meta (§63). Lo pidió el dueño el 2026-10-30: "lo más real posible, nunca
 * optimista, con los gastos fijos adentro, y que avise rápido cuando se pierde".
 * - Cohortes maduras (§60): las órdenes CREADAS entre hoy − (lag + ventana) y hoy − (lag + 1). Se calculan dos, la corta
 *   (7 días) y la larga (28), y se queda la más prudente: la de CPA objetivo más bajo. Si la última semana empeoró se nota
 *   enseguida; si mejoró, no se confía hasta que se sostenga. Con menos de N compras de Meta en 7 días vale la de 28; una
 *   ciudad que tampoco llega usa el número de su producto.
 * - R = lo cobrado de lo entregado (principal + upsell − devuelto). V = costos variables: producto, logística, envío
 *   (también los fallidos), comisiones de todos los roles y otros variables (gastos del producto o de la ciudad y merma).
 *   Una carrera sin costo cuenta lo que cuesta en promedio una carrera en esa ciudad (28 días), no $0.
 * - Gastos fijos: se aparta el mayor entre el mínimo del dueño (10 %) y lo real (gastos generales de los últimos 30 días ÷
 *   lo cobrado de lo entregado en esos días).
 * - Break-even = (R − V − fijos) ÷ P: pagando eso en Meta se queda en cero, ya con los gastos fijos cubiertos.
 *   Objetivo = (R − V − fijos − utilidad) ÷ P: la utilidad del producto (20 % de lo vendido, o sobre el costo), después de
 *   los gastos fijos. Si no queda nada: $0 y "no viable" (§68). Fully Loaded queda igual al break-even (ya los incluye).
 * Se calcula cada día a las 06:30 o a mano; cada cálculo es una versión nueva (§73). No toca Meta (§71).
 * - Regla del dueño (2026-10-31): un producto que nunca tuvo una compra registrada no tiene costo (PurchaseCost), así que no
 *   se calcula su objetivo ni su break-even: queda "falta el costo" (detail.no_cost) hasta que se registre una compra.
 */
class FinancialCpa
{
    public const SETTING_WINDOW = 'finance.analysis_window_days';
    public const SETTING_LONG_WINDOW = 'finance.analysis_long_window_days';
    public const SETTING_FIXED_MIN = 'finance.fixed_cost_min_pct';
    public const SETTING_CITY_MIN_PURCHASES = 'finance.cpa_city_min_purchases';

    /** Los días con que se mide el % real de gastos fijos. */
    public const FIXED_LOOKBACK_DAYS = 30;

    public function __construct(private MetaAdsData $meta, private ExpenseLedger $expenses, private Shrinkage $shrinkage)
    {
    }

    public static function shortDays(): int
    {
        return max(1, (int) Setting::get(self::SETTING_WINDOW, 7));
    }

    public static function longDays(): int
    {
        return max(self::shortDays(), (int) Setting::get(self::SETTING_LONG_WINDOW, 28));
    }

    public static function minFixedPct(): float
    {
        return max(0.0, (float) Setting::get(self::SETTING_FIXED_MIN, 10));
    }

    public static function cityMinPurchases(): int
    {
        return max(0, (int) Setting::get(self::SETTING_CITY_MIN_PURCHASES, 30));
    }

    /** §60: [desde, hasta] de la cohorte madura de $days días (por defecto, la corta). */
    public static function window(?CarbonInterface $today = null, ?int $days = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $lag = max(0, (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4));
        $to = $today->copy()->subDays($lag + 1);

        return [$to->copy()->subDays(max(1, $days ?? self::shortDays()) - 1), $to];
    }

    /** @return int versiones nuevas */
    public function compute(?int $userId = null, string $trigger = 'auto', ?CarbonInterface $today = null): int
    {
        [$sFrom, $sTo] = self::window($today, self::shortDays());
        [$lFrom, $lTo] = self::window($today, self::longDays());
        $tripCost = $this->tripCost($lFrom, $lTo);
        $short = $this->levels($sFrom, $sTo, $tripCost);
        $long = $this->levels($lFrom, $lTo, $tripCost);
        $keys = array_values(array_unique(array_merge(array_keys($short), array_keys($long))));
        if ($keys === []) {
            return 0;
        }
        // Primero los productos: la ciudad sin muestra usa el número de su producto
        usort($keys, fn ($a, $b) => [str_ends_with($a, '|') ? 0 : 1, $a] <=> [str_ends_with($b, '|') ? 0 : 1, $b]);
        $productIds = array_values(array_unique(array_map(fn ($k) => (int) explode('|', $k)[0], $keys)));
        $margins = ProductFinancialSetting::forProducts($productIds);
        $purchases = PurchaseCost::for($productIds);
        $fixed = $this->fixedShare($today);
        $min = self::cityMinPurchases();

        $versions = DB::table('target_cpa_versions')->groupBy('product_id', 'city_id')
            ->selectRaw('product_id, city_id, MAX(version) v')->get()->mapWithKeys(fn ($r) => ["{$r->product_id}|{$r->city_id}" => (int) $r->v]);
        $windows = [
            'short' => ['from' => $sFrom->toDateString(), 'to' => $sTo->toDateString(), 'days' => self::shortDays()],
            'long' => ['from' => $lFrom->toDateString(), 'to' => $lTo->toDateString(), 'days' => self::longDays()],
        ];
        $now = now();
        $chosen = [];
        $rows = [];
        foreach ($keys as $key) {
            [$productId, $city] = explode('|', $key);
            $productId = (int) $productId;
            $cityId = $city === '' ? null : (int) $city;
            $margin = $margins[$productId];
            $s = isset($short[$key]) ? self::economics($short[$key], $fixed['pct'], $margin) : null;
            $l = isset($long[$key]) ? self::economics($long[$key], $fixed['pct'], $margin) : null;
            $fromProduct = $cityId !== null ? ($chosen["{$productId}|"] ?? null) : null;
            $choice = self::pick($s, $l, $min, $fromProduct['econ'] ?? null);

            if ($choice === 'product') {
                // Los números de la ciudad (28 días), con el objetivo y el break-even de su producto
                $econ = array_merge($l ?? $s, array_intersect_key($fromProduct['econ'], array_flip(['break_even', 'target', 'fully_loaded', 'viable'])));
                $window = $windows[$l !== null ? 'long' : 'short'];
            } else {
                $econ = $choice === 'short' ? $s : $l;
                $window = $windows[$choice];
            }
            // Sin ninguna compra registrada, el costo no se conoce: no se calcula
            $noCost = !$purchases->has($productId);
            if ($noCost) {
                $econ = array_merge($econ, ['break_even' => null, 'target' => null, 'fully_loaded' => null, 'viable' => null]);
            }
            if ($cityId === null) {
                $chosen[$key] = ['econ' => $econ];
            }
            $summary = fn (?array $e, string $w, ?array $cell) => $e === null ? null : $windows[$w] + [
                'purchases' => $e['purchases'], 'effectiveness' => $e['effectiveness'], 'meta_cpa' => $e['meta_cpa'],
                'target' => $e['target'], 'break_even' => $e['break_even'],
                'missing_trips' => (int) ($cell['missing_trips'] ?? 0), 'shipping_filled' => round((float) ($cell['shipping_filled'] ?? 0), 2),
            ];

            $rows[] = [
                'product_id' => $productId,
                'city_id' => $cityId,
                'version' => ($versions["{$productId}|{$cityId}"] ?? 0) + 1,
                'period_from' => $window['from'],
                'period_to' => $window['to'],
                'window_days' => $window['days'],
                'orders_created' => $econ['orders_created'],
                'orders_delivered' => $econ['orders_delivered'],
                'effectiveness' => $econ['effectiveness'],
                'revenue' => $econ['revenue'],
                'variable_costs' => $econ['variable_costs'],
                'cogs' => $econ['cogs'],
                'purchases' => $econ['purchases'],
                'spend' => $econ['spend'],
                'meta_cpa' => $econ['meta_cpa'],
                'target_type' => $margin['type'],
                'target_pct' => $margin['pct'],
                'fixed_pct' => $fixed['pct'],
                'fixed_source' => $fixed['source'],
                'break_even' => $econ['break_even'],
                'target' => $econ['target'],
                'fully_loaded' => $econ['fully_loaded'],
                'viable' => $econ['viable'],
                'detail' => json_encode([
                    'chosen' => $choice,
                    'basis' => $choice === 'product' ? 'product' : 'own',
                    'city_min_purchases' => $min,
                    'short' => $summary($s, 'short', $short[$key] ?? null),
                    'long' => $summary($l, 'long', $long[$key] ?? null),
                    'fixed' => $fixed,
                    'no_cost' => $noCost,
                ], JSON_UNESCAPED_UNICODE),
                'trigger' => $trigger,
                'computed_by' => $userId,
                'computed_at' => $now,
            ];
        }
        DB::table('target_cpa_versions')->insert($rows);

        return count($rows);
    }

    /** La última versión de cada producto + ciudad y de cada producto ("p|c" y "p|"). */
    public function latest(): array
    {
        $ids = DB::table('target_cpa_versions')->groupBy('product_id', 'city_id')->selectRaw('MAX(id) id')->pluck('id');

        return TargetCpaVersion::whereIn('id', $ids)->get()->mapWithKeys(fn ($v) => ["{$v->product_id}|{$v->city_id}" => $v])->all();
    }

    /** El % de gastos fijos de hoy: el real de los últimos 30 días o el mínimo, el mayor. */
    public function fixedShare(?CarbonInterface $today = null): array
    {
        $to = ($today ?? now())->copy()->startOfDay()->subDay();
        $from = $to->copy()->subDays(self::FIXED_LOOKBACK_DAYS - 1);
        $expenses = (float) $this->expenses->forRange($from, $to)['general'];
        $revenue = (float) DB::table('order_financials')->whereBetween('delivered_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(revenue_main + revenue_upsell - revenue_refunded) r')->value('r');

        return self::fixedPct($expenses, $revenue, self::minFixedPct()) + ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * El % de gastos fijos (PHP puro): lo real (gastos generales ÷ lo cobrado) si pasa del mínimo; si no, el mínimo.
     *
     * @return array{pct: float, source: string, real_pct: ?float, min_pct: float, expenses: float, revenue: float}
     */
    public static function fixedPct(float $expenses, float $revenue, float $minPct): array
    {
        $real = $revenue > 0 ? min(100.0, round($expenses / $revenue * 100, 2)) : null;
        $useReal = $real !== null && $real > $minPct;

        return ['pct' => $useReal ? $real : round($minPct, 2), 'source' => $useReal ? 'real' : 'minimum', 'real_pct' => $real,
            'min_pct' => round($minPct, 2), 'expenses' => round($expenses, 2), 'revenue' => round($revenue, 2)];
    }

    /**
     * El objetivo y el break-even de una cohorte (PHP puro).
     *
     * @param array $c created, delivered, revenue, variable, other_variable, cogs, purchases, spend
     * @param array{type: string, pct: float} $margin la utilidad del producto: % de lo vendido (sales) o del costo (cost)
     */
    public static function economics(array $c, float $fixedPct, array $margin): array
    {
        $R = round((float) $c['revenue'], 2);
        $V = round((float) $c['variable'] + (float) ($c['other_variable'] ?? 0), 2);
        $P = round((float) $c['purchases'], 2);
        $fixed = $R * $fixedPct / 100;
        $desired = $margin['type'] === 'cost' ? (float) $c['cogs'] * $margin['pct'] / 100 : $R * $margin['pct'] / 100;
        $available = $R - $V - $fixed;
        $breakEven = $P > 0 ? round(max(0, $available) / $P, 2) : null;

        return [
            'orders_created' => (int) $c['created'],
            'orders_delivered' => (int) $c['delivered'],
            'effectiveness' => $c['created'] > 0 ? round($c['delivered'] / $c['created'] * 100, 2) : null,
            'revenue' => $R,
            'variable_costs' => $V,
            'cogs' => round((float) $c['cogs'], 2),
            'purchases' => $P,
            'spend' => round((float) $c['spend'], 2),
            'meta_cpa' => $P > 0 ? round((float) $c['spend'] / $P, 2) : null,
            'break_even' => $breakEven,
            'target' => $P > 0 ? round(max(0, $available - $desired) / $P, 2) : null,
            'fully_loaded' => $breakEven,
            'viable' => $P > 0 ? ($available - $desired) > 0 : null,
        ];
    }

    /**
     * Qué cohorte vale (PHP puro): con muestra en 7 días, la más prudente de las dos (objetivo más bajo); si no, la de 28
     * con muestra; si tampoco, el producto (solo para una ciudad); si no hay nada mejor, la que tenga datos.
     *
     * @return string short, long o product
     */
    public static function pick(?array $short, ?array $long, int $minPurchases, ?array $product = null): string
    {
        $enough = fn (?array $e) => $e !== null && $e['purchases'] > 0 && $e['purchases'] >= $minPurchases;
        if ($enough($short)) {
            if ($long === null || $long['target'] === null) {
                return 'short';
            }

            return [(float) $long['target'], (float) $long['break_even']] < [(float) $short['target'], (float) $short['break_even']] ? 'long' : 'short';
        }
        if ($enough($long)) {
            return 'long';
        }
        if ($product !== null && $product['target'] !== null) {
            return 'product';
        }

        return $long !== null && $long['purchases'] > 0 ? 'long' : ($short !== null ? 'short' : 'long');
    }

    /** Lo que cuesta en promedio una carrera con costo, por ciudad y en total, para completar las que no lo tienen. */
    private function tripCost(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('order_financials')->whereBetween('created_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('city_id')->selectRaw('city_id, SUM(delivery_cost) cost, SUM(delivery_trips - delivery_missing_trips) trips')->get();
        $cities = [];
        foreach ($rows as $r) {
            if ($r->city_id !== null && (int) $r->trips > 0) {
                $cities[(int) $r->city_id] = (float) $r->cost / (int) $r->trips;
            }
        }
        $trips = (int) $rows->sum('trips');

        return ['cities' => $cities, 'all' => $trips > 0 ? (float) $rows->sum('cost') / $trips : 0.0];
    }

    /** Las sumas de una cohorte por producto + ciudad ("p|c") y por producto ("p|"). */
    private function levels(CarbonInterface $from, CarbonInterface $to, array $tripCost): array
    {
        $cells = $this->cells($from, $to, $tripCost);
        // §69: el producto es la suma de sus ciudades
        $byProduct = [];
        foreach ($cells as $c) {
            $p = &$byProduct[$c['product_id']];
            $p ??= ['product_id' => $c['product_id'], 'city_id' => null] + array_fill_keys(array_keys(array_diff_key($c, ['product_id' => 1, 'city_id' => 1])), 0.0);
            foreach ($c as $k => $v) {
                if (!in_array($k, ['product_id', 'city_id'], true)) {
                    $p[$k] += $v;
                }
            }
            unset($p);
        }
        // Los gastos de un producto sin ciudad solo cuentan en el nivel producto
        foreach ($this->productOnlyExpenses($from, $to) as $productId => $amount) {
            if (isset($byProduct[$productId])) {
                $byProduct[$productId]['other_variable'] += $amount;
            }
        }

        $out = [];
        foreach ($cells as $c) {
            $out["{$c['product_id']}|{$c['city_id']}"] = $c;
        }
        foreach ($byProduct as $productId => $p) {
            $out["{$productId}|"] = $p;
        }

        return $out;
    }

    /** R, V, P y la efectividad de la cohorte, por producto + ciudad. */
    private function cells(CarbonInterface $from, CarbonInterface $to, array $tripCost): array
    {
        $cells = [];
        $cell = function (int $product, int $city) use (&$cells): string {
            $key = "{$product}|{$city}";
            $cells[$key] ??= ['product_id' => $product, 'city_id' => $city, 'created' => 0, 'delivered' => 0, 'revenue' => 0.0, 'variable' => 0.0,
                'cogs' => 0.0, 'other_variable' => 0.0, 'purchases' => 0.0, 'spend' => 0.0, 'missing_trips' => 0, 'shipping_filled' => 0.0];

            return $key;
        };
        $rows = DB::table('order_financials')->whereBetween('created_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('product_id')->whereNotNull('city_id')->groupBy('product_id', 'city_id')
            ->selectRaw("product_id, city_id,
                SUM(kind = 'normal') AS created, SUM(kind = 'normal' AND outcome = 'delivered') AS delivered,
                SUM(revenue_main + revenue_upsell - revenue_refunded) AS revenue,
                SUM(cogs_main + cogs_upsell) AS cogs,
                SUM(cogs_main + cogs_upsell + logistics_cost + delivery_cost + commission_deliverer + commission_seller + commission_upsell
                    + commission_leader + commission_other) AS variable,
                SUM(delivery_missing_trips) AS missing_trips")
            ->get();
        foreach ($rows as $r) {
            $k = $cell((int) $r->product_id, (int) $r->city_id);
            foreach (['created', 'delivered', 'revenue', 'cogs', 'variable', 'missing_trips'] as $f) {
                $cells[$k][$f] += (float) $r->{$f};
            }
            // Una carrera sin costo no es gratis: lo que cuesta en promedio una carrera en esa ciudad
            $fill = (int) $r->missing_trips * ($tripCost['cities'][(int) $r->city_id] ?? $tripCost['all']);
            $cells[$k]['variable'] += $fill;
            $cells[$k]['shipping_filled'] += $fill;
        }
        foreach ($this->meta->dailySpend($from->toDateString(), $to->toDateString(), ['product', 'city']) as $s) {
            $k = $cell((int) $s['product_id'], (int) $s['city_id']);
            $cells[$k]['purchases'] += (float) ($s['purchases'] ?? 0);
            $cells[$k]['spend'] += (float) $s['spend'];
        }
        foreach ($this->expenses->forRange($from, $to)['items'] as $i) {
            if ($i['product_id'] && $i['city_id']) {
                $cells[$cell($i['product_id'], $i['city_id'])]['other_variable'] += $i['amount_usd'];
            }
        }
        // §64: "otros costes variables" incluye la merma de ese producto en esa ciudad (2d)
        foreach ($this->shrinkage->forRange($from, $to)['items'] as $i) {
            if ($i['city_id']) {
                $cells[$cell($i['product_id'], $i['city_id'])]['other_variable'] += $i['amount_usd'];
            }
        }

        return $cells;
    }

    private function productOnlyExpenses(CarbonInterface $from, CarbonInterface $to): array
    {
        $out = [];
        foreach ($this->expenses->forRange($from, $to)['items'] as $i) {
            if ($i['product_id'] && !$i['city_id']) {
                $out[$i['product_id']] = ($out[$i['product_id']] ?? 0) + $i['amount_usd'];
            }
        }
        // La merma de un almacén sin ciudad es solo del producto
        foreach ($this->shrinkage->forRange($from, $to)['items'] as $i) {
            if (!$i['city_id']) {
                $out[$i['product_id']] = ($out[$i['product_id']] ?? 0) + $i['amount_usd'];
            }
        }

        return $out;
    }
}
