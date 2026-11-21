<?php

namespace App\Services\Storefront;

use App\Constants\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `.164` (dueño, 2026-11-19: "necesito que estipulemos unos KPIs claros para saber si funciona o no"): los 8 KPIs de nuestro
 * formulario, en los primeros 14 días o 100 pedidos, contra los productos de la tienda con Releasit en su arranque (sus
 * primeros 14 días). La regla que aprobó: si conversión, Meta, anuncio identificado y efectividad salen bien, se pasa a más
 * productos; si la conversión sale más de 10 % por debajo, se revisa el embudo.
 *
 * `.168`: en las campañas "al entregar" Meta ve los pedidos por el "pedido hecho" (el sistema las mide con él), y el
 * experimento: "Purchase al pedir" contra "Purchase al entregar", lado a lado, con los días ya cerrados.
 *
 * `.171` (dueño, 2026-11-21: "sin duplicar campañas"): el experimento es el producto con "Purchase según cómo paga"
 * prendido contra el mismo producto antes (sus últimos 14 días con anuncios), o contra el arranque de los demás productos si
 * antes no vendió.
 */
class StorefrontKpis
{
    public const DAYS = 14;

    public const ORDERS = 100;

    /** Con menos que esto todavía no se puede juzgar (sale "midiendo"). */
    private const MIN_ORDERS = 20;

    private const MIN_CLOSED = 10;

    /**
     * Los KPIs con su meta y cómo van (PHP puro).
     *
     * @param array{orders: int, visits: float, meta_purchases: float, capi_sent: int, capi_failed: int, attributed: int, delivered: int, closed: int,
     *     duplicates: int, express: int, fallback: int, days: int, funnel: array<string, int>, fields: array<string, int>} $w el formulario
     * @param array{orders: int, visits: float, attributed: int, delivered: int, closed: int, duplicates: int, express: int, products: int} $b Releasit
     * @return array{kpis: list<array{key: string, label: string, value: ?float, base: ?float, goal: string, state: string, unit: string, note: ?string}>, verdict: array{key: string, text: string}, window: array{days: int, orders: int}}
     */
    public static function evaluate(array $w, array $b): array
    {
        $pct = fn (float $a, float $c) => $c > 0 ? round(100 * $a / $c, 1) : null;
        $enough = $w['orders'] >= self::MIN_ORDERS;
        $conv = $pct($w['orders'], $w['visits']);
        $convBase = $pct($b['orders'], $b['visits']);
        $meta = $pct($w['meta_purchases'], $w['orders']);
        $attr = $pct($w['attributed'], $w['orders']);
        $attrBase = $pct($b['attributed'], $b['orders']);
        $eff = $pct($w['delivered'], $w['closed']);
        $effBase = $pct($b['delivered'], $b['closed']);
        $dup = $pct($w['duplicates'], $w['orders']);
        $dupBase = $pct($b['duplicates'], $b['orders']);
        $exp = $pct($w['express'], $w['orders']);
        $expBase = $pct($b['express'], $b['orders']);
        $state = fn (?float $v, bool $ok, bool $ready = true) => $v === null || !$ready ? 'wait' : ($ok ? 'ok' : 'bad');

        $kpis = [
            ['key' => 'conversion', 'label' => 'Conversión', 'value' => $conv, 'base' => $convBase, 'unit' => 'pedidos por cada 100 visitas',
                'goal' => 'Igual o mejor que tus productos con Releasit en su arranque', 'state' => $state($conv, $conv !== null && $convBase !== null && $conv >= $convBase, $enough),
                'note' => 'Pedidos del formulario ÷ visitas a la landing que cuenta Meta, × 100.'],
            ['key' => 'meta', 'label' => 'Meta ve los pedidos', 'value' => $meta, 'base' => null, 'unit' => '%', 'goal' => '95 % o más',
                'state' => $state($meta, $meta !== null && $meta >= 95, $enough),
                'note' => 'Compras que Meta le atribuye a tus campañas de este producto ÷ pedidos del formulario (en las campañas "al entregar", el "pedido hecho"). La API de conversiones mandó ' . $w['capi_sent'] . ' compras y ' . ($w['placed_sent'] ?? 0) . ' "pedido hecho".'],
            ['key' => 'attribution', 'label' => 'Pedidos con su anuncio identificado', 'value' => $attr, 'base' => $attrBase, 'unit' => '%', 'goal' => 'Igual o más que con Releasit',
                'state' => $state($attr, $attr !== null && $attrBase !== null && $attr >= $attrBase, $enough), 'note' => 'Pedidos que dicen de qué anuncio vinieron (parámetros de URL).'],
            ['key' => 'effectiveness', 'label' => 'Efectividad', 'value' => $eff, 'base' => $effBase, 'unit' => '%', 'goal' => 'Igual o mejor que la tienda',
                'state' => $state($eff, $eff !== null && $effBase !== null && $eff >= $effBase, $w['closed'] >= self::MIN_CLOSED), 'note' => 'Entregados ÷ cerrados (entregados, cancelados y rechazados).'],
            ['key' => 'duplicates', 'label' => 'Pedidos falsos o repetidos', 'value' => $dup, 'base' => $dupBase, 'unit' => '%', 'goal' => 'Igual o menos que con Releasit',
                'state' => $state($dup, $dup !== null && $dupBase !== null && $dup <= $dupBase, $enough), 'note' => 'Cancelados como "Pedido duplicado o de prueba".'],
            ['key' => 'funnel', 'label' => 'Embudo del formulario', 'value' => $pct((float) ($w['funnel']['submit'] ?? 0), (float) ($w['funnel']['view'] ?? 0)), 'base' => null,
                'unit' => '% de los que lo vieron lo enviaron', 'goal' => 'Para saber en qué paso se van', 'state' => 'info',
                'note' => 'Lo vieron ' . ($w['funnel']['view'] ?? 0) . ' · empezaron ' . ($w['funnel']['start'] ?? 0) . ' · lo enviaron ' . ($w['funnel']['submit'] ?? 0)
                    . ' · pasaron a WhatsApp ' . ($w['funnel']['whatsapp'] ?? 0) . '.'],
            ['key' => 'express', 'label' => 'Delivery Express', 'value' => $exp, 'base' => $expBase, 'unit' => '% de los pedidos', 'goal' => 'Hoy, 1 de cada 4', 'state' => 'info',
                'note' => 'Pedidos que agregaron el Delivery Express.'],
            ['key' => 'technical', 'label' => 'Técnico', 'value' => (float) ($w['fallback'] + $w['capi_failed']), 'base' => null, 'unit' => 'fallas',
                'goal' => '0 pedidos perdidos', 'state' => $w['fallback'] + $w['capi_failed'] === 0 ? 'ok' : 'bad',
                'note' => "Veces que se mostró Releasit de respaldo: {$w['fallback']}. Compras que no llegaron a la API de Meta: {$w['capi_failed']}."],
        ];
        $by = array_column($kpis, 'state', 'key');
        $core = [$by['conversion'], $by['meta'], $by['attribution'], $by['effectiveness']];
        $complete = $w['days'] >= self::DAYS || $w['orders'] >= self::ORDERS;
        $verdict = match (true) {
            $w['orders'] === 0 => ['key' => 'empty', 'text' => 'Todavía no hay pedidos del formulario.'],
            $conv !== null && $convBase !== null && $enough && $conv < $convBase * 0.9 => ['key' => 'review', 'text' => 'La conversión sale más de 10 % por debajo de Releasit: revisa el embudo (en qué paso se van).'],
            $complete && !in_array('bad', $core, true) && !in_array('wait', $core, true) => ['key' => 'go', 'text' => 'Los 4 KPIs principales salen bien: puedes pasar el formulario a más productos.'],
            default => ['key' => 'measuring', 'text' => 'Midiendo: se decide a los ' . self::DAYS . ' días o ' . self::ORDERS . ' pedidos, lo que llegue primero.'],
        };

        return ['kpis' => $kpis, 'verdict' => $verdict, 'window' => ['days' => $w['days'], 'orders' => $w['orders']]];
    }

    /** Los KPIs de hoy (10 minutos en caché). */
    public function current(array $productIds): array
    {
        return Cache::remember('storefront:kpis:' . implode(',', $productIds), 600, fn () => self::evaluate($this->web($productIds), $this->baseline($productIds)));
    }

    /** Lo del formulario, desde su primer pedido (o desde hoy). */
    public function web(array $productIds): array
    {
        $first = DB::table('storefront_orders')->whereIn('product_id', $productIds ?: [0])->whereNotNull('order_id')->min('created_at');
        $start = $first ? Carbon::parse($first)->startOfDay() : today();
        $ids = DB::table('storefront_orders')->whereIn('product_id', $productIds ?: [0])->whereNotNull('order_id')->where('created_at', '>=', $start)->pluck('order_id')->all();
        $campaigns = DB::table('meta_campaigns')->whereIn('product_id', $productIds ?: [0])->pluck('meta_id')->map(fn ($v) => (string) $v)->all();
        $insights = DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaigns ?: ['-'])->where('date', '>=', $start->toDateString())
            ->selectRaw('COALESCE(SUM(landing_page_views),0) v, COALESCE(SUM(purchases),0) p')->first();
        $front = DB::table('storefront_orders')->whereIn('order_id', $ids ?: [0]);
        $events = DB::table('storefront_events')->whereIn('product_id', $productIds ?: [0])->where('date', '>=', $start->toDateString())
            ->groupBy('kind')->selectRaw('kind, SUM(count) n')->pluck('n', 'kind')->map(fn ($v) => (int) $v)->all();

        return ['orders' => count($ids), 'visits' => (float) ($insights->v ?? 0), 'meta_purchases' => (float) ($insights->p ?? 0),
            'capi_sent' => (clone $front)->where('capi_status', 'sent')->count(), 'capi_failed' => (clone $front)->where('capi_status', 'failed')->count(),
            'placed_sent' => (clone $front)->where('placed_capi_status', 'sent')->count(),
            'express' => (clone $front)->whereNotNull('express_fee')->count(),
            'attributed' => DB::table('order_sources')->whereIn('order_id', $ids ?: [0])->whereNotNull('ad_meta_id')->count(),
            ...$this->closed($ids),
            'fallback' => $events['fallback'] ?? 0,
            'days' => (int) $start->diffInDays(today()) + 1,
            'funnel' => array_intersect_key($events, array_flip(['view', 'start', 'submit', 'error', 'whatsapp', 'fallback'])),
            'fields' => collect($events)->filter(fn ($n, $k) => str_starts_with($k, 'field:'))->mapWithKeys(fn ($n, $k) => [substr($k, 6) => $n])->all()];
    }

    /** La línea de comparación: los productos con Releasit en sus primeros 14 días (los que arrancaron en los últimos 180). */
    public function baseline(array $exclude): array
    {
        $out = ['orders' => 0, 'visits' => 0.0, 'attributed' => 0, 'delivered' => 0, 'closed' => 0, 'duplicates' => 0, 'express' => 0, 'products' => 0,
            'spend' => 0.0, 'revenue' => 0.0]; // .171: el gasto y lo vendido, para el experimento
        $firsts = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')->whereNotIn('op.product_id', $exclude ?: [0])
            ->where('o.name', 'not like', 'WEB-%')->groupBy('op.product_id')->selectRaw('op.product_id, MIN(o.created_at) f')->get()
            ->filter(fn ($r) => $r->f !== null && Carbon::parse($r->f)->gte(now()->subDays(180)) && Carbon::parse($r->f)->lte(now()->subDays(self::DAYS)));
        foreach ($firsts as $r) {
            $from = Carbon::parse($r->f)->startOfDay();
            $to = $from->copy()->addDays(self::DAYS);
            $ids = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')->where('op.product_id', $r->product_id)
                ->whereBetween('o.created_at', [$from, $to])->distinct()->pluck('o.id')->all();
            if ($ids === []) {
                continue;
            }
            $campaigns = DB::table('meta_campaigns')->where('product_id', $r->product_id)->pluck('meta_id')->map(fn ($v) => (string) $v)->all();
            $visits = (float) DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaigns ?: ['-'])
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('landing_page_views');
            if ($visits <= 0) {
                continue; // sin visitas de Meta no se puede comparar la conversión
            }
            $closed = $this->closed($ids);
            $lines = DB::table('order_products')->whereIn('order_id', $ids)->groupBy('order_id')->selectRaw('order_id, SUM(price * quantity) s')->pluck('s', 'order_id');
            $express = DB::table('orders')->whereIn('id', $ids)->get(['id', 'current_total_price'])
                ->filter(fn ($o) => abs((float) $o->current_total_price - (float) ($lines[$o->id] ?? 0) - 2) < 0.6)->count();
            $out['orders'] += count($ids);
            $out['visits'] += $visits;
            $out['attributed'] += DB::table('order_sources')->whereIn('order_id', $ids)->whereNotNull('ad_meta_id')->count();
            $out['delivered'] += $closed['delivered'];
            $out['closed'] += $closed['closed'];
            $out['duplicates'] += $closed['duplicates'];
            $out['express'] += $express;
            $out['products']++;
            $out['spend'] += (float) DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaigns ?: ['-'])
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('spend');
            $out['revenue'] += $this->deliveredRevenue($ids);
        }

        return $out;
    }

    /** Días que tarda un pedido en cerrarse (el mismo desfase del C y el BE): el experimento solo mira días ya cerrados. */
    public const MATURE_DAYS = 4;

    /** Con menos de esto todavía no se decide (en lo de ahora). */
    public const EXPERIMENT_DAYS = 14;

    public const EXPERIMENT_DELIVERED = 30;

    /** "Va mejor" si la publicidad se come 15 % menos de lo vendido (los productos y los momentos no son iguales). */
    public const EXPERIMENT_BETTER = 0.85;

    /** Puntos de efectividad que cuentan como "más efectividad". */
    public const EXPERIMENT_EFFECTIVENESS = 5;

    /**
     * Un lado del experimento con sus números (PHP puro): la efectividad, cuánto de lo vendido (los entregados) se fue en
     * publicidad, el costo por entregado y la ganancia por día.
     *
     * @return array{spend: float, orders: int, delivered: int, closed: int, revenue: float, effectiveness: ?float, ad_share: ?float, cost_per_delivered: ?float, profit_per_day: ?float}
     */
    public static function arm(float $spend, int $orders, int $delivered, int $closed, float $revenue, ?float $profitPerDay): array
    {
        return ['spend' => round($spend, 2), 'orders' => $orders, 'delivered' => $delivered, 'closed' => $closed, 'revenue' => round($revenue, 2),
            'effectiveness' => $closed > 0 ? round(100 * $delivered / $closed, 1) : null,
            'ad_share' => $revenue > 0 && $spend > 0 ? round(100 * $spend / $revenue, 1) : null,
            'cost_per_delivered' => $delivered > 0 && $spend > 0 ? round($spend / $delivered, 2) : null,
            'profit_per_day' => $profitPerDay !== null ? round($profitPerDay, 2) : null];
    }

    /**
     * El veredicto (PHP puro), con 14 días cerrados y 30 entregados ahora: va mejor si la publicidad se come 15 % menos de lo
     * vendido que antes, o si la efectividad sube 5 puntos sin que la publicidad suba; va peor si la publicidad sube y la
     * efectividad no; si no, sale parecido.
     *
     * @param ?array{ad_share: ?float, effectiveness: ?float} $before
     * @param array{delivered: int, ad_share: ?float, effectiveness: ?float} $after
     * @return array{key: string, text: string}
     */
    public static function experimentVerdict(?array $before, array $after, int $days): array
    {
        if ($days < self::EXPERIMENT_DAYS || $after['delivered'] < self::EXPERIMENT_DELIVERED) {
            return ['key' => 'measuring', 'text' => 'Midiendo: se decide con ' . self::EXPERIMENT_DAYS . ' días cerrados y ' . self::EXPERIMENT_DELIVERED
                . " entregados (van {$days} días y {$after['delivered']} entregados)."];
        }
        $a = $before['ad_share'] ?? null;
        $b = $after['ad_share'];
        if ($a === null || $b === null) {
            return ['key' => 'measuring', 'text' => 'No hay con qué comparar: faltan las ventas de antes o el gasto de Meta.'];
        }
        $ea = (float) ($before['effectiveness'] ?? 0);
        $eb = (float) ($after['effectiveness'] ?? 0);
        $numbers = 'la publicidad se come el ' . round($b) . ' % de lo vendido (antes ' . round($a) . ' %) y la efectividad es ' . round($eb)
            . ' % (antes ' . round($ea) . ' %)';
        if ($b <= $a * self::EXPERIMENT_BETTER || ($eb >= $ea + self::EXPERIMENT_EFFECTIVENESS && $b <= $a)) {
            return ['key' => 'go', 'text' => "Va mejor: {$numbers}. Puedes prenderlo en otro producto."];
        }
        if ($b > $a && $eb <= $ea) {
            return ['key' => 'back', 'text' => "Va peor: {$numbers}. Apaga «Purchase según cómo paga»."];
        }

        return ['key' => 'tie', 'text' => "Sale parecido: {$numbers}. No hace falta cambiar los demás productos."];
    }

    /**
     * `.171`: el experimento del producto con "Purchase según cómo paga" prendido (el primero de los del formulario), desde
     * la última vez que lo prendió y con los días ya cerrados, contra el mismo producto antes (sus últimos 14 días con gasto en Meta, si entregó al menos 10) o
     * contra el arranque de los demás productos.
     */
    public function experiment(array $productIds): array
    {
        $first = DB::table('meta_purchase_modes')->whereIn('product_id', $productIds ?: [0])->whereNull('campaign_meta_id')->orderBy('starts_on')->first();
        if (!$first) {
            return ['active' => false];
        }
        $productId = (int) $first->product_id;
        // desde la última vez que lo prendió (prenderlo para una prueba y apagarlo no cuenta)
        $mode = DB::table('meta_purchase_modes')->where('product_id', $productId)->whereNull('campaign_meta_id')->orderByDesc('starts_on')->orderByDesc('id')->first();
        $from = Carbon::parse($mode->starts_on)->startOfDay();
        $to = today()->subDays(self::MATURE_DAYS);
        if ($mode->ends_on !== null) {
            $to = $to->min(Carbon::parse($mode->ends_on));
        }
        $days = $to->gte($from) ? (int) $from->diffInDays($to) + 1 : 0;
        $campaigns = DB::table('meta_campaigns')->where('product_id', $productId)->pluck('meta_id')->map(fn ($v) => (string) $v)->all();

        $after = $days > 0 ? $this->window($productId, $campaigns, $from, $to) : self::arm(0, 0, 0, 0, 0, null);
        $fronts = $days > 0 ? DB::table('storefront_orders')->where('product_id', $productId)->whereNotNull('order_id')
            ->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get(['order_id', 'payment', 'purchase_mode', 'purchase_sent_at']) : collect();
        $metaOrders = $days > 0 ? (float) DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaigns ?: ['-'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('purchases') : 0.0;
        $waiting = $fronts->where('purchase_mode', 'delivery')->where('payment', '!=', 'pago_movil');
        $after += [
            'meta_sees' => $fronts->count() > 0 ? round(100 * $metaOrders / $fronts->count(), 1) : null,
            'prepaid' => $fronts->where('payment', 'pago_movil')->count(), 'advisor' => $fronts->where('payment', 'asesora')->count(),
            'courier' => DB::table('orders')->whereIn('id', $fronts->pluck('order_id')->all() ?: [0])->where('courier_shipment', true)->count(),
            // los Purchase que llegaron a Meta al entregar ÷ los entregados que no habían pagado antes
            'purchase_on_delivery' => [$waiting->whereNotNull('purchase_sent_at')->count(), DB::table('orders as o')->join('statuses as s', 's.id', '=', 'o.status_id')
                ->where('s.description', OrderStatus::ENTREGADO)->whereIn('o.id', $waiting->pluck('order_id')->all() ?: [0])->count()],
        ];

        // antes: sus últimos 14 días con gasto en Meta antes de prenderlo; si ahí no entregó al menos 10, el arranque de los demás
        $before = null;
        $basis = null;
        $last = DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaigns ?: ['-'])->where('date', '<', $from->toDateString())
            ->where('spend', '>', 0)->max('date');
        if ($last) {
            $end = Carbon::parse($last);
            $start = $end->copy()->subDays(self::DAYS - 1)->startOfDay();
            $own = $this->window($productId, $campaigns, $start, $end);
            if ($own['delivered'] >= self::MIN_CLOSED) {
                [$before, $basis] = [$own + ['from' => $start->toDateString(), 'to' => $end->toDateString()], 'own'];
            }
        }
        if ($before === null) {
            $b = $this->baseline([$productId]);
            if ($b['delivered'] > 0) {
                [$before, $basis] = [self::arm($b['spend'], $b['orders'], $b['delivered'], $b['closed'], $b['revenue'], null) + ['products' => $b['products']], 'launches'];
            }
        }

        $product = DB::table('products')->where('id', $productId)->first(['showable_name', 'title']);

        return ['active' => true, 'product' => $product?->showable_name ?: $product?->title,
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days, 'before' => $before, 'basis' => $basis, 'after' => $after,
            'verdict' => self::experimentVerdict($before, $after, $days)];
    }

    /** Un producto entre dos días: sus pedidos (por fecha), el gasto de sus campañas, lo vendido entregado y la ganancia por día. */
    private function window(int $productId, array $campaigns, Carbon $from, Carbon $to): array
    {
        $ids = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')->where('op.product_id', $productId)
            ->whereBetween('o.created_at', [$from, $to->copy()->endOfDay()])->distinct()->pluck('o.id')->all();
        $spend = (float) DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaigns ?: ['-'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('spend');
        $closed = $this->closed($ids);
        $money = (float) DB::table('order_financials')->whereIn('order_id', $ids ?: [0])->selectRaw('COALESCE(SUM(revenue_main + revenue_upsell - revenue_refunded - cogs_main - cogs_upsell
            - delivery_cost - delivery_legacy_cost - commission_seller - commission_upsell - commission_leader - commission_deliverer), 0) m')->value('m');
        $days = (int) $from->diffInDays($to) + 1;

        return self::arm($spend, count($ids), $closed['delivered'], $closed['closed'], $this->deliveredRevenue($ids), ($money - $spend) / max(1, $days));
    }

    /** Lo vendido: el total de los pedidos entregados. */
    private function deliveredRevenue(array $ids): float
    {
        return (float) DB::table('orders as o')->join('statuses as s', 's.id', '=', 'o.status_id')->whereIn('o.id', $ids ?: [0])
            ->where('s.description', OrderStatus::ENTREGADO)->sum('o.current_total_price');
    }

    /** @return array{delivered: int, closed: int, duplicates: int} */
    private function closed(array $ids): array
    {
        $statuses = DB::table('orders as o')->join('statuses as s', 's.id', '=', 'o.status_id')->whereIn('o.id', $ids ?: [0])
            ->groupBy('s.description')->selectRaw('s.description d, COUNT(*) n')->pluck('n', 'd');
        $delivered = (int) ($statuses[OrderStatus::ENTREGADO] ?? 0);

        return ['delivered' => $delivered, 'closed' => $delivered + (int) ($statuses[OrderStatus::CANCELADO] ?? 0) + (int) ($statuses[OrderStatus::RECHAZADO] ?? 0),
            'duplicates' => DB::table('order_cancellations')->whereIn('order_id', $ids ?: [0])->where('category', 'duplicado')->distinct()->count('order_id')];
    }
}
