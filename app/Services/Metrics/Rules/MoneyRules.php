<?php

namespace App\Services\Metrics\Rules;

use App\Services\Metrics\Buckets;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;

/**
 * Dinero del panel del dueño (diccionario de métricas, §1 y §2). PHP puro: recibe los hechos de cada pedido
 * (OrderFactsReader), la publicidad y los gastos (MoneyReader), y calcula los números. Cada número tiene aquí mismo la
 * explicación que ve el dueño al pasar el cursor.
 *
 * Las reglas:
 * - La ganancia es del día en que se CREÓ el pedido (§1): un pedido del lunes entregado el miércoles suma al lunes.
 * - "Ya ganado" = lo vendido (pedidos entregados) − lo que costaron los pedidos de esos días (el producto y las
 *   comisiones de lo entregado; el envío de todos, también de las entregas que fallaron) − la publicidad de esos días −
 *   los gastos de esos días (§2). Cuenta todos los pedidos, también cambios y devoluciones, igual que Finanzas › Cohortes.
 * - "En camino" = ganancia esperada de los pendientes: pendientes × % que normalmente se entrega × lo que deja en
 *   promedio cada entrega (respuesta del dueño, 2026-10-29).
 * - Con filtros no se restan los gastos generales: no son de una tienda, ciudad ni producto. Sí los cargados a ese
 *   producto o ciudad. Con el filtro de tienda, la publicidad de cada producto y ciudad se reparte según cuántos de
 *   sus pedidos de ese día son de la tienda, o de todo el período si ese día no hubo (Meta no sabe de tiendas).
 */
final class MoneyRules
{
    public const METRICS = [
        'profit' => [
            'label' => 'Ganancia neta (ya ganado)',
            'format' => 'money',
            'matures' => true,
            'help' => 'Lo que te dejaron los pedidos creados en este período: lo vendido menos el producto, las comisiones, el envío (también el de las entregas que fallaron), la publicidad de esos días y los gastos de esos días. Puede subir en los próximos días, a medida que se entreguen los que siguen en camino.',
        ],
        'pending_profit' => [
            'label' => 'En camino',
            'format' => 'money',
            'compare' => false,
            'note' => 'Ganancia esperada',
            'help' => 'Lo que todavía pueden sumar los pedidos de este período que no se han entregado ni cancelado, si se entregan como de costumbre: pedidos pendientes × % que normalmente se entrega × lo que deja en promedio cada entrega (antes de publicidad). Es una estimación.',
        ],
        'cost_per_order' => [
            'label' => 'Costo por pedido',
            'format' => 'money',
            'better' => 'lower',
            'help' => 'Cuánto te costó en publicidad cada pedido que entró: publicidad del período ÷ pedidos creados. Mide si los anuncios traen pedidos baratos. Más bajo es mejor.',
        ],
        'cost_per_sale' => [
            'label' => 'Costo por venta',
            'format' => 'money',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Lo que de verdad te costó en publicidad cada venta: publicidad del período ÷ pedidos de ese período que ya se entregaron. Más bajo es mejor. Puede bajar en los próximos días, a medida que se entreguen los que siguen en camino.',
        ],
        'receivable' => [
            'label' => 'Por cobrar',
            'format' => 'money',
            'compare' => false,
            'note' => 'Ahora mismo',
            'help' => 'Efectivo de pedidos ya entregados que las agencias y los repartidores todavía no te han entregado. Es lo de ahora mismo: no depende del período ni de los filtros. Sale de Finanzas › Caja.',
        ],
    ];

    /** Con filtros, la ganancia no resta los gastos generales. */
    public const PROFIT_FILTERED = [
        'label' => 'Ganancia antes de gastos generales',
        'help' => 'Lo que te dejaron los pedidos creados en este período con estos filtros: lo vendido menos el producto, las comisiones, el envío (también el de las entregas que fallaron), su publicidad y los gastos cargados a ese producto o ciudad. No resta los gastos generales del negocio (alquiler, sueldos…), porque no son de una tienda, ciudad ni producto.',
    ];

    public const SHOP_ADS_NOTE = ' Con el filtro de tienda, la publicidad de cada anuncio se reparte según cuántos de sus pedidos son de esta tienda: los de ese día, o los de todo el período si ese día no trajo pedidos (Meta no sabe de tiendas).';

    /** Los pasos de la cascada "¿a dónde se fue el dinero?". */
    public const STEPS = [
        'revenue' => ['label' => 'Vendido', 'help' => 'Lo que suman los pedidos entregados de este período, con sus upsells, menos lo que se le devolvió al cliente.'],
        'product' => ['label' => 'Producto', 'help' => 'Lo que te costó la mercancía que se entregó, con lo que costó llevarla entre almacenes.'],
        'commissions' => ['label' => 'Comisiones', 'help' => 'Comisiones de las vendedoras, de los upsells y de las líderes por esas ventas.'],
        'shipping' => ['label' => 'Envío', 'help' => 'Lo que se paga a agencias y repartidores por llevar los pedidos de este período, también por las entregas que fallaron. Si una carrera no tiene cierre aprobado, se usa su costo estimado.'],
        'ads' => ['label' => 'Publicidad', 'help' => 'Lo gastado en Meta en esos días.'],
        'expenses' => ['label' => 'Gastos', 'help' => 'Gastos fijos repartidos por día (alquiler, sueldos, herramientas…), gastos puntuales con fecha en esos días y mercancía dada de baja.'],
        'profit' => ['label' => 'Ganancia', 'help' => 'Lo que queda: vendido menos todo lo anterior.'],
    ];

    public const EXPENSES_FILTERED_HELP = 'Solo los gastos cargados a ese producto o ciudad y la mercancía de ese producto o ciudad dada de baja. Los generales del negocio no se restan con filtros.';

    public const SERIES_HELP = 'Por cada día, lo que ya ganaste con los pedidos creados ese día (verde, o rojo si ese día vas perdiendo) y lo que todavía pueden sumar sus pedidos pendientes (claro). Los últimos días suelen empezar en rojo: la publicidad ya se gastó y los pedidos todavía se están entregando.';

    public const PRODUCTS_HELP = 'Lo que dejó cada producto (el principal del pedido) antes de los gastos del negocio: lo vendido menos el costo de sus pedidos y su publicidad.';

    public const RECEIVABLES_HELP = 'El efectivo de pedidos entregados que cada agencia (y los repartidores propios, juntos) todavía no te entregó. Es lo de ahora mismo. Sale de Finanzas › Caja: los bolívares van a la tasa de Binance.';

    private const MONEY_KEYS = ['revenue', 'product', 'commissions', 'shipping', 'ads', 'expenses'];

    /**
     * Los números de dinero del período, en una sola pasada por los pedidos.
     *
     * @param iterable<array|object> $facts pedidos (OrderFactsReader::facts), ya filtrados por tienda, ciudad y producto
     * @param array<int, array{date: string, product_id: ?int, city_id: ?int, spend: float}> $ads por día
     * @param array<int, array{date: string, kind: string, product_id: ?int, city_id: ?int, amount: float}> $expenses por grupo de días
     * @param array<int, array{date: string, product_id: ?int, city_id: ?int, orders: int}> $cells pedidos de todas las tiendas (solo con filtro de tienda)
     */
    public static function compute(iterable $facts, Period $period, Filters $filters, array $ads, array $expenses, array $cells = []): array
    {
        $granularity = Buckets::granularity($period);
        $start = $period->start->format('Y-m-d H:i:s');
        $end = $period->end->format('Y-m-d H:i:s');
        $dates = array_flip($period->dates());

        $series = [];
        foreach (array_keys(Buckets::ranges($period, $granularity)) as $key) {
            $series[$key] = self::emptyPoint($key);
        }
        $totals = self::emptyPoint(null) + ['created' => 0, 'delivered' => 0];
        $products = [];
        $shopOrders = [];
        $checks = ['catalog_cost' => 0, 'missing_cost_lines' => 0, 'trips_estimated' => 0, 'trips_missing' => 0];

        foreach ($facts as $fact) {
            $f = (array) $fact;
            $createdAt = (string) $f['created_at'];
            // §1: es ganancia del día en que se creó el pedido
            if ($createdAt < $start || $createdAt > $end) {
                continue;
            }
            $date = substr($createdAt, 0, 10);
            $key = Buckets::key($date, $granularity);
            $series[$key] ??= self::emptyPoint($key);
            $delivered = $f['outcome'] === SalesRules::DELIVERED;
            $normal = $f['kind'] === SalesRules::NORMAL;
            $money = [
                'revenue' => $delivered ? (float) $f['revenue'] : 0.0,
                'product' => (float) $f['product_cost'],
                'commissions' => (float) $f['commissions'],
                'shipping' => (float) $f['shipping'],
            ];
            foreach ($money as $k => $v) {
                $series[$key][$k] += $v;
                $totals[$k] += $v;
            }
            if ($normal) {
                $totals['created']++;
                if ($delivered) {
                    $totals['delivered']++;
                } elseif ($f['outcome'] !== SalesRules::NOT_DELIVERED) {
                    $series[$key]['open']++;
                    $totals['open']++;
                }
                $shopOrders[self::cell($date, $f['product_id'], $f['city_id'])] = ($shopOrders[self::cell($date, $f['product_id'], $f['city_id'])] ?? 0) + 1;
            }

            $p = $f['product_id'] !== null ? (int) $f['product_id'] : 0;
            $products[$p] ??= ['product_id' => $p ?: null, 'revenue' => 0.0, 'costs' => 0.0, 'ads' => 0.0, 'delivered' => 0];
            $products[$p]['revenue'] += $money['revenue'];
            $products[$p]['costs'] += $money['product'] + $money['commissions'] + $money['shipping'];
            $products[$p]['delivered'] += $delivered && $normal ? 1 : 0;

            // Lo provisional, para avisar (los mismos avisos de Finanzas › Rentabilidad)
            if ($delivered && $f['kind'] !== 'return' && ($f['cogs_source'] ?? 'catalog') !== 'lots') {
                $checks['catalog_cost']++;
            }
            $checks['missing_cost_lines'] += (int) $f['cogs_missing_lines'];
            $checks['trips_missing'] += (int) $f['delivery_missing_trips'];
            $checks['trips_estimated'] += max(0, (int) $f['delivery_trips'] - (int) $f['delivery_final_trips'] - (int) $f['delivery_missing_trips']);
        }

        // La publicidad de esos días. Con tienda: la parte de los pedidos de cada anuncio (producto y ciudad) que son de
        // la tienda ese día; si ese día el anuncio no trajo pedidos, la parte de todo el período
        $allOrders = [];
        $allPeriod = [];
        foreach ($cells as $c) {
            $allOrders[self::cell($c['date'], $c['product_id'], $c['city_id'])] = $c['orders'];
            $allPeriod[self::cell('*', $c['product_id'], $c['city_id'])] = ($allPeriod[self::cell('*', $c['product_id'], $c['city_id'])] ?? 0) + $c['orders'];
        }
        $shopPeriod = [];
        foreach ($shopOrders as $cell => $n) {
            $any = self::cell('*', ...array_slice(explode('|', $cell), 1));
            $shopPeriod[$any] = ($shopPeriod[$any] ?? 0) + $n;
        }
        $byProductOrCity = $filters->productId !== null || $filters->cityId !== null;
        foreach ($ads as $a) {
            if (!isset($dates[$a['date']])) {
                continue;
            }
            $classified = $a['product_id'] !== null && $a['city_id'] !== null;
            if (($filters->shopId !== null || $byProductOrCity) && !$classified) {
                continue; // la publicidad sin producto ni ciudad solo cuenta para todo el negocio
            }
            if (($filters->productId !== null && $a['product_id'] !== $filters->productId) || ($filters->cityId !== null && $a['city_id'] !== $filters->cityId)) {
                continue;
            }
            $spend = (float) $a['spend'];
            if ($filters->shopId !== null) {
                $cell = self::cell($a['date'], $a['product_id'], $a['city_id']);
                $any = self::cell('*', $a['product_id'], $a['city_id']);
                $spend = ($allOrders[$cell] ?? 0) > 0 ? $spend * ($shopOrders[$cell] ?? 0) / $allOrders[$cell]
                    : (($allPeriod[$any] ?? 0) > 0 ? $spend * ($shopPeriod[$any] ?? 0) / $allPeriod[$any] : 0.0);
            }
            $key = Buckets::key($a['date'], $granularity);
            $series[$key] ??= self::emptyPoint($key);
            $series[$key]['ads'] += $spend;
            $totals['ads'] += $spend;
            if ($classified) {
                $p = (int) $a['product_id'];
                $products[$p] ??= ['product_id' => $p, 'revenue' => 0.0, 'costs' => 0.0, 'ads' => 0.0, 'delivered' => 0];
                $products[$p]['ads'] += $spend;
            }
        }

        // Los gastos de esos días: todos sin filtros; con filtros, solo los de ese producto o ciudad; con tienda, ninguno
        foreach ($expenses as $e) {
            if (!self::expenseCounts($e, $filters)) {
                continue;
            }
            $series[$e['date']] ??= self::emptyPoint($e['date']);
            $series[$e['date']]['expenses'] += (float) $e['amount'];
            $totals['expenses'] += (float) $e['amount'];
        }

        ksort($series);
        $totals = self::finish($totals);
        $totals['cost_per_order'] = $totals['ads'] > 0 && $totals['created'] > 0 ? round($totals['ads'] / $totals['created'], 2) : null;
        $totals['cost_per_sale'] = $totals['ads'] > 0 && $totals['delivered'] > 0 ? round($totals['ads'] / $totals['delivered'], 2) : null;

        return [
            'totals' => $totals,
            'series' => ['granularity' => $granularity, 'points' => array_values(array_map([self::class, 'finish'], $series))],
            'products' => self::products($products),
            'checks' => $checks,
        ];
    }

    /**
     * Lo que se espera de un pedido pendiente, sacado de pedidos que ya terminaron de entregarse: qué % se entrega y
     * cuánto deja en promedio cada entrega antes de publicidad.
     *
     * @param iterable<array|object> $facts
     * @return array{created: int, delivered: int, rate: ?float, margin: ?float}
     */
    public static function estimate(iterable $facts): array
    {
        $created = 0;
        $delivered = 0;
        $margin = 0.0;
        foreach ($facts as $fact) {
            $f = (array) $fact;
            if ($f['kind'] !== SalesRules::NORMAL) {
                continue;
            }
            $created++;
            if ($f['outcome'] === SalesRules::DELIVERED) {
                $delivered++;
                $margin += (float) $f['revenue'] - (float) $f['product_cost'] - (float) $f['commissions'] - (float) $f['shipping'];
            }
        }

        return [
            'created' => $created,
            'delivered' => $delivered,
            'rate' => $created > 0 ? round($delivered / $created, 4) : null,
            'margin' => $delivered > 0 ? round($margin / $delivered, 2) : null,
        ];
    }

    /** La ganancia esperada de unos pedidos pendientes. */
    public static function pending(int $open, array $estimate): ?float
    {
        if ($estimate['rate'] === null || $estimate['margin'] === null) {
            return null;
        }

        return round($open * $estimate['rate'] * $estimate['margin'], 2);
    }

    /** Le pone a cada punto del gráfico lo que todavía pueden sumar sus pedidos pendientes. */
    public static function withPending(array $points, array $estimate): array
    {
        return array_map(fn ($p) => $p + ['pending' => self::pending($p['open'], $estimate) ?? 0.0], $points);
    }

    /**
     * @param array{rate: ?float, margin: ?float} $estimate
     * @param array<int, array{usd: float}> $receivables
     */
    public static function cards(array $now, array $before, array $estimate, array $receivables, bool $maturing, Filters $filters, ?string $estimateText = null): array
    {
        $filtered = $filters->shopId !== null || $filters->cityId !== null || $filters->productId !== null;
        $shopNote = $filters->shopId !== null ? self::SHOP_ADS_NOTE : '';
        $pending = self::pending($now['open'], $estimate);
        $receivable = round(array_sum(array_column($receivables, 'usd')), 2);
        $withCash = count(array_filter($receivables, fn ($r) => $r['usd'] > 0.005));

        $defs = self::METRICS;
        if ($filtered) {
            $defs['profit'] = self::PROFIT_FILTERED + $defs['profit'];
        }
        $defs['cost_per_order']['help'] .= $shopNote;
        $defs['cost_per_sale']['help'] .= $shopNote;

        $money = fn (float $v) => Card::money($v);
        $details = [
            'profit' => $money($now['revenue']) . ' vendido − ' . $money($now['product'] + $now['commissions'] + $now['shipping']) . ' costos de los pedidos − '
                . $money($now['ads']) . ' publicidad − ' . $money($now['expenses']) . ' gastos',
            'pending_profit' => $pending === null ? 'Todavía no hay pedidos terminados para calcular el promedio.'
                : Card::plural($now['open'], 'pendiente', 'pendientes') . ' × ' . number_format($estimate['rate'] * 100, 1, ',', '.') . ' % × ' . $money($estimate['margin']),
            'cost_per_order' => $now['cost_per_order'] === null ? 'No hay publicidad registrada en estas fechas.'
                : $money($now['ads']) . ' ÷ ' . Card::plural($now['created'], 'creado', 'creados'),
            'cost_per_sale' => $now['cost_per_sale'] === null ? ($now['ads'] > 0 ? 'Todavía no hay pedidos entregados.' : 'No hay publicidad registrada en estas fechas.')
                : $money($now['ads']) . ' ÷ ' . Card::plural($now['delivered'], 'entregado', 'entregados'),
            'receivable' => Card::plural($withCash, 'agencia o grupo de repartidores con efectivo', 'agencias o grupos de repartidores con efectivo'),
        ];
        $values = ['profit' => $now['profit'], 'pending_profit' => $pending, 'cost_per_order' => $now['cost_per_order'],
            'cost_per_sale' => $now['cost_per_sale'], 'receivable' => $receivable];
        $previous = ['profit' => $before['profit'], 'pending_profit' => null, 'cost_per_order' => $before['cost_per_order'],
            'cost_per_sale' => $before['cost_per_sale'], 'receivable' => null];

        $cards = [];
        foreach ($defs as $key => $m) {
            $cards[] = Card::make($key, $m, $values[$key], $previous[$key], $details[$key], $maturing)
                + ['extra' => $key === 'pending_profit' ? $estimateText : null];
        }

        return $cards;
    }

    /** De dónde salen el % y el promedio de "En camino". */
    public static function estimateText(array $estimate, Period $history, bool $wholeBusiness): string
    {
        $range = $history->start->format('d/m') . ' al ' . $history->end->format('d/m');
        if ($estimate['rate'] === null || $estimate['margin'] === null) {
            return "No hay pedidos entregados del {$range} para calcular el promedio.";
        }

        return 'El ' . number_format($estimate['rate'] * 100, 1, ',', '.') . ' % y los ' . Card::money($estimate['margin']) . ' salen de los '
            . Card::plural($estimate['created'], 'pedido creado', 'pedidos creados') . " del {$range}, que ya terminaron de entregarse"
            . ($wholeBusiness ? ' (de todo el negocio: con estos filtros hay muy pocos para sacar un promedio).' : '.');
    }

    /** La cascada: de lo vendido a la ganancia, paso por paso. */
    public static function waterfall(array $t, Filters $filters): array
    {
        $filtered = $filters->shopId !== null || $filters->cityId !== null || $filters->productId !== null;
        $steps = [];
        foreach (self::STEPS as $key => $s) {
            $amount = $key === 'profit' ? $t['profit'] : $t[$key];
            $help = $s['help'];
            if ($key === 'expenses' && $filtered) {
                $help = self::EXPENSES_FILTERED_HELP;
            } elseif ($key === 'ads' && $filters->shopId !== null) {
                $help .= self::SHOP_ADS_NOTE;
            }
            $steps[] = [
                'key' => $key,
                'label' => $key === 'expenses' && $filtered ? 'Gastos de ese producto o ciudad' : $s['label'],
                'help' => $help,
                'kind' => $key === 'revenue' ? 'start' : ($key === 'profit' ? 'total' : 'cost'),
                'amount' => round($amount, 2),
            ];
        }

        return $steps;
    }

    /** Avisos de lo que todavía es provisional o falta, para no confiar de más en un número. */
    public static function warnings(array $totals, array $checks): array
    {
        $w = [];
        if ($checks['missing_cost_lines'] > 0) {
            $w[] = Card::plural($checks['missing_cost_lines'], 'producto entregado no tiene', 'productos entregados no tienen') . ' costo cargado: la ganancia sale más alta de lo real. Se carga en Finanzas › Configuración o en el inventario.';
        }
        if ($checks['catalog_cost'] > 0) {
            $w[] = Card::plural($checks['catalog_cost'], 'pedido entregado usa', 'pedidos entregados usan') . ' el costo de catálogo del producto en vez del de sus lotes: es provisional.';
        }
        if ($checks['trips_estimated'] > 0) {
            $w[] = Card::plural($checks['trips_estimated'], 'envío tiene', 'envíos tienen') . ' su costo estimado: todavía no está en un cierre aprobado.';
        }
        if ($checks['trips_missing'] > 0) {
            $w[] = Card::plural($checks['trips_missing'], 'envío no tiene', 'envíos no tienen') . ' costo calculado: cuentan $0.';
        }
        if ($totals['ads'] <= 0) {
            $w[] = 'No hay publicidad de Meta registrada en estas fechas: la ganancia no resta publicidad.';
        }

        return $w;
    }

    private static function expenseCounts(array $e, Filters $f): bool
    {
        if ($f->shopId !== null) {
            return false;
        }
        if ($f->productId === null && $f->cityId === null) {
            return true;
        }
        if ($e['product_id'] === null && $e['city_id'] === null) {
            return false; // general del negocio
        }

        return ($f->productId === null || $e['product_id'] === $f->productId) && ($f->cityId === null || $e['city_id'] === $f->cityId);
    }

    private static function products(array $products): array
    {
        $rows = array_map(function ($p) {
            $p['profit'] = round($p['revenue'] - $p['costs'] - $p['ads'], 2);
            foreach (['revenue', 'costs', 'ads'] as $k) {
                $p[$k] = round($p[$k], 2);
            }

            return $p;
        }, array_values($products));
        usort($rows, fn ($a, $b) => [$b['revenue'], $b['ads']] <=> [$a['revenue'], $a['ads']]);
        if (count($rows) <= 10) {
            return $rows;
        }
        $others = ['product_id' => null, 'others' => true, 'revenue' => 0.0, 'costs' => 0.0, 'ads' => 0.0, 'delivered' => 0, 'profit' => 0.0];
        foreach (array_slice($rows, 9) as $r) {
            foreach (['revenue', 'costs', 'ads', 'delivered', 'profit'] as $k) {
                $others[$k] += $r[$k];
            }
        }

        return [...array_slice($rows, 0, 9), $others];
    }

    private static function cell(string $date, $productId, $cityId): string
    {
        return $date . '|' . ($productId ?? '-') . '|' . ($cityId ?? '-');
    }

    private static function emptyPoint(?string $date): array
    {
        return ($date !== null ? ['date' => $date] : []) + array_fill_keys(self::MONEY_KEYS, 0.0) + ['open' => 0];
    }

    private static function finish(array $row): array
    {
        foreach (self::MONEY_KEYS as $k) {
            $row[$k] = round($row[$k], 2);
        }
        $row['profit'] = round($row['revenue'] - $row['product'] - $row['commissions'] - $row['shipping'] - $row['ads'] - $row['expenses'], 2);

        return $row;
    }
}
