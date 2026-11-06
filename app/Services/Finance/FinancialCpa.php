<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Models\ProductFinancialSetting;
use App\Models\Setting;
use App\Models\Status;
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
 *   enseguida; si mejoró, no se confía hasta que se sostenga. Con menos de N pedidos terminados en 7 días vale la de 28;
 *   una ciudad que tampoco llega usa el número de su producto.
 * - Dueño, 2026-11-02 (Aragua salía "va bien" con un BE de $26 en Comprimax): los pedidos de antes del primer día con datos
 *   de Meta de ese producto en esa ciudad no cuentan (venían de campañas que el sistema no tiene, y 3 semanas de pedidos
 *   sin compras de Meta hacían que cada compra pareciera traer 4 pedidos); y una ciudad que usa la de 28 días por tener
 *   pocas compras nunca queda más generosa que su producto.
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
 * - `.101` (dueño, 2026-11-04: Termafit salió con BE $4,39 por 11 pedidos sin stock en Distrito Capital y solo 2 días de
 *   pedidos): los pedidos que hoy están en "Sin Stock" no cuentan, ni la parte de las compras de Meta (y del gasto) que les
 *   toca en su ciudad, porque no son culpa de la publicidad. Y un número propio pide N pedidos TERMINADOS (entregados o
 *   cancelados), no N compras de Meta. Medido en producción: los que terminan después del día 5 son casi todos
 *   cancelaciones, así que los abiertos siguen contando como no entregados.
 * - `.110` (dueño, 2026-11-06, ZenPulse): tampoco cuentan los que estuvieron más de 12 horas en "Sin Stock" (Finanzas ›
 *   Configuración), aunque después avanzaran, con su parte de Meta.
 */
class FinancialCpa
{
    public const SETTING_WINDOW = 'finance.analysis_window_days';
    public const SETTING_LONG_WINDOW = 'finance.analysis_long_window_days';
    public const SETTING_FIXED_MIN = 'finance.fixed_cost_min_pct';
    public const SETTING_CITY_MIN_PURCHASES = 'finance.cpa_city_min_purchases';
    public const SETTING_SIN_STOCK_HOURS = 'finance.cpa_sin_stock_hours';

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

    /** `.101`: los pedidos terminados que hacen falta para confiar en un número propio (el ajuste conserva su nombre viejo). */
    public static function minClosedOrders(): int
    {
        return max(0, (int) Setting::get(self::SETTING_CITY_MIN_PURCHASES, 30));
    }

    /** `.110`: las horas en "Sin Stock" desde las que un pedido ya no cuenta (el dueño eligió 12). */
    public static function sinStockHours(): float
    {
        return max(0.0, (float) Setting::get(self::SETTING_SIN_STOCK_HOURS, 12));
    }

    /**
     * `.110`: las horas que un pedido estuvo en un estado, sumando todas las veces (PHP puro). Sus cambios de estado en
     * orden; si sigue ahí, cuenta hasta $now. Un cambio que deja el mismo estado (una reasignación) no corta.
     *
     * @param list<array{to: int, at: string}> $changes
     */
    public static function hoursIn(array $changes, int $status, string $now): float
    {
        $seconds = 0;
        $since = null;
        foreach ($changes as $c) {
            if ($since === null && $c['to'] === $status) {
                $since = strtotime($c['at']);
            } elseif ($since !== null && $c['to'] !== $status) {
                $seconds += strtotime($c['at']) - $since;
                $since = null;
            }
        }
        if ($since !== null) {
            $seconds += max(0, strtotime($now) - $since);
        }

        return round($seconds / 3600, 2);
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
        $starts = $this->metaStarts();
        $short = $this->levels($sFrom, $sTo, $tripCost, $starts);
        $long = $this->levels($lFrom, $lTo, $tripCost, $starts);
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
        $min = self::minClosedOrders();

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
                'target' => $e['target'], 'break_even' => $e['break_even'], 'closed' => $e['closed'], 'sin_stock' => $e['sin_stock'],
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
                    // .101: los pedidos terminados (para confiar en el número) y los que no cuentan por estar sin stock
                    'closed' => $econ['closed'],
                    'sin_stock' => $econ['sin_stock'],
                    'sin_stock_hours' => self::sinStockHours(),
                    'meta_from' => $cityId !== null ? ($starts[$key] ?? null) : null,
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
     * @param array $c created, delivered, closed, sin_stock, revenue, variable, other_variable, cogs, purchases, spend
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
            'closed' => (int) ($c['closed'] ?? 0),
            'sin_stock' => (int) ($c['sin_stock'] ?? 0),
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
     * con muestra; si tampoco, el producto (solo para una ciudad); si no hay nada mejor, la que tenga datos. `.101`: la
     * muestra son los pedidos terminados ($minClosed), no las compras de Meta.
     *
     * @return string short, long o product
     */
    public static function pick(?array $short, ?array $long, int $minClosed, ?array $product = null): string
    {
        $enough = fn (?array $e) => $e !== null && $e['purchases'] > 0 && $e['closed'] >= $minClosed;
        if ($enough($short)) {
            if ($long === null || $long['target'] === null) {
                return 'short';
            }

            return [(float) $long['target'], (float) $long['break_even']] < [(float) $short['target'], (float) $short['break_even']] ? 'long' : 'short';
        }
        if ($enough($long)) {
            // Una ciudad con pocos pedidos nunca más generosa que su producto (2026-11-02)
            $prudent = $product !== null && $product['target'] !== null && $long['target'] !== null
                && [(float) $product['target'], (float) $product['break_even']] < [(float) $long['target'], (float) $long['break_even']];

            return $prudent ? 'product' : 'long';
        }
        if ($product !== null && $product['target'] !== null) {
            return 'product';
        }

        return $long !== null && $long['purchases'] > 0 ? 'long' : ($short !== null ? 'short' : 'long');
    }

    /** Lo que cuesta en promedio una carrera con costo, por ciudad y en total, para completar las que no lo tienen. */
    private function tripCost(CarbonInterface $from, CarbonInterface $to): array
    {
        return TripCostAverage::between($from->toDateString(), $to->toDateString());
    }

    /**
     * El primer día con gasto en Meta de cada producto + ciudad ("p|c" => "AAAA-MM-DD"): los pedidos de antes no cuentan
     * para su CPA (2026-11-02).
     */
    private function metaStarts(): array
    {
        return DB::table('meta_insights_daily as d')
            ->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->where('d.spend', '>', 0)
            ->whereNotNull('c.product_id')->whereNotNull('c.city_id')
            ->groupBy('c.product_id', 'c.city_id')
            ->selectRaw('c.product_id, c.city_id, MIN(d.date) AS first_day')
            ->get()->mapWithKeys(fn ($r) => ["{$r->product_id}|{$r->city_id}" => substr((string) $r->first_day, 0, 10)])->all();
    }

    /** Las sumas de una cohorte por producto + ciudad ("p|c") y por producto ("p|"). */
    private function levels(CarbonInterface $from, CarbonInterface $to, array $tripCost, array $starts = []): array
    {
        $cells = $this->cells($from, $to, $tripCost, $starts);
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

    /**
     * R, V, P y la efectividad de la cohorte, por producto + ciudad. Los pedidos de antes del primer día con datos de Meta
     * de esa celda ($starts) no cuentan; sin ningún dato de Meta cuentan todos. Los gastos y la merma, del período entero
     * (de más, nunca de menos).
     */
    private function cells(CarbonInterface $from, CarbonInterface $to, array $tripCost, array $starts = []): array
    {
        $cells = [];
        $cell = function (int $product, int $city) use (&$cells): string {
            $key = "{$product}|{$city}";
            $cells[$key] ??= ['product_id' => $product, 'city_id' => $city, 'created' => 0, 'delivered' => 0, 'closed' => 0, 'sin_stock' => 0,
                'revenue' => 0.0, 'variable' => 0.0, 'cogs' => 0.0, 'other_variable' => 0.0, 'purchases' => 0.0, 'spend' => 0.0, 'missing_trips' => 0,
                'shipping_filled' => 0.0];

            return $key;
        };
        // .101: los pedidos que hoy están en "Sin Stock" van aparte (stuck): no cuentan, y abajo se descuenta su parte de Meta.
        // .110: también los que estuvieron más de sinStockHours() horas, terminen como terminen
        $sinStock = (int) Status::where('description', OrderStatus::SIN_STOCK)->value('id');
        $long = $this->longStockouts($from, $to, $sinStock);
        $stuck = 'CASE WHEN status_id = ? THEN 1' . ($long ? ' WHEN order_id IN (' . implode(',', array_fill(0, count($long), '?')) . ') THEN 1' : '') . ' ELSE 0 END';
        $rows = DB::table('order_financials')->whereBetween('created_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('product_id')->whereNotNull('city_id')->groupBy('product_id', 'city_id', 'created_date', 'stuck')
            ->selectRaw("product_id, city_id, created_date, {$stuck} AS stuck,
                SUM(kind = 'normal') AS created, SUM(kind = 'normal' AND outcome = 'delivered') AS delivered,
                SUM(kind = 'normal' AND outcome IN ('delivered', 'not_delivered')) AS closed,
                SUM(revenue_main + revenue_upsell - revenue_refunded) AS revenue,
                SUM(cogs_main + cogs_upsell) AS cogs,
                SUM(cogs_main + cogs_upsell + logistics_cost + delivery_cost + commission_deliverer + commission_seller + commission_upsell
                    + commission_leader + commission_other) AS variable,
                SUM(delivery_missing_trips) AS missing_trips", [$sinStock, ...$long])
            ->get();
        foreach ($rows as $r) {
            $start = $starts["{$r->product_id}|{$r->city_id}"] ?? null;
            if ($start !== null && substr((string) $r->created_date, 0, 10) < $start) {
                continue;
            }
            $k = $cell((int) $r->product_id, (int) $r->city_id);
            if ((int) $r->stuck === 1) {
                $cells[$k]['sin_stock'] += (int) $r->created;
                continue;
            }
            foreach (['created', 'delivered', 'closed', 'revenue', 'cogs', 'variable', 'missing_trips'] as $f) {
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
        // .101: Meta no dice qué compra es qué pedido: a los sin stock les toca su parte de las compras (y del gasto) de su
        // ciudad, en proporción a los pedidos
        foreach ($cells as &$c) {
            $all = $c['created'] + $c['sin_stock'];
            if ($c['sin_stock'] > 0 && $all > 0) {
                $c['purchases'] *= $c['created'] / $all;
                $c['spend'] *= $c['created'] / $all;
            }
        }
        unset($c);
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

    /**
     * `.110` (dueño, 2026-11-06: ZenPulse arrancó el 27/10 sin el producto en las ciudades; en Carabobo 29 de sus 54
     * pedidos pasaron por "Sin Stock" y la C salió $2,72): los pedidos de la cohorte que estuvieron más de sinStockHours()
     * horas en "Sin Stock", sumando todas las veces. No es culpa de la publicidad. Se sacan también los que al final se
     * entregaron (sacar solo los cancelados haría la C optimista); un rato corto (lo resolvió otra agencia) sí cuenta. El
     * historial de estados está en order_tracking_comprehensive_logs desde febrero de 2026 (StatusChangeLog).
     *
     * @return list<int> order_id
     */
    private function longStockouts(CarbonInterface $from, CarbonInterface $to, int $sinStock): array
    {
        if ($sinStock === 0) {
            return [];
        }
        $ids = DB::table('order_financials')->whereBetween('created_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('order_id')->distinct()->pluck('order_id')->all();
        $changes = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $hit = DB::table('order_tracking_comprehensive_logs')->whereIn('order_id', $chunk)->where('to_status_id', $sinStock)->distinct()->pluck('order_id')->all();
            if (!$hit) {
                continue;
            }
            DB::table('order_tracking_comprehensive_logs')->whereIn('order_id', $hit)->whereNotNull('to_status_id')->orderBy('created_at')->orderBy('id')
                ->get(['order_id', 'to_status_id', 'created_at'])
                ->each(function ($l) use (&$changes) {
                    $changes[(int) $l->order_id][] = ['to' => (int) $l->to_status_id, 'at' => (string) $l->created_at];
                });
        }
        if (!$changes) {
            return [];
        }
        $hours = self::sinStockHours();
        $now = now()->toDateTimeString();

        return array_keys(array_filter($changes, fn ($c) => self::hoursIn($c, $sinStock, $now) > $hours));
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
