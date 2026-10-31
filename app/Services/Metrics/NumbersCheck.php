<?php

namespace App\Services\Metrics;

use App\Services\Finance\ExpenseLedger;
use App\Services\Finance\OrderEconomics;
use App\Services\Finance\Shrinkage;
use App\Services\Metrics\Readers\CheckReader;
use App\Services\Metrics\Readers\MoneyReader;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Rules\CheckRules;
use App\Services\Metrics\Rules\MoneyRules;
use App\Services\Metrics\Rules\SalesRules;
use Illuminate\Support\Carbon;

/**
 * La revisión de los números del panel del dueño (`.48a`). Solo lee. Para un período, sin filtros:
 * 1. cada pedido: lo guardado en order_financials contra calcularlo ahora (OrderEconomics::preview);
 * 2. cada número de Ventas y Dinero: el del panel (sus mismos lectores y reglas) contra el mismo por un segundo camino,
 *    desde las tablas de origen (CheckReader);
 * 3. los datos que pueden hacer que un número salga mal.
 * Las reglas que deciden qué cuadra y lo explican están en Rules\CheckRules. Lo usa `php artisan metrics:check`.
 */
class NumbersCheck
{
    public function __construct(
        private OrderFactsReader $orders,
        private MoneyReader $money,
        private CheckReader $check,
        private OrderEconomics $economics,
        private ExpenseLedger $expenses,
        private Shrinkage $shrinkage,
    ) {
    }

    public function run(Period $period): array
    {
        $filters = new Filters();

        // Lo que muestra el panel
        $sales = SalesRules::compute($this->orders->facts($period, $filters), $period)['totals'];
        $moneyRun = MoneyRules::compute($this->orders->facts($period, $filters), $period, $filters, $this->money->adSpend($period),
            $this->money->expenses(Buckets::ranges($period, Buckets::granularity($period))));
        $money = $moneyRun['totals'];

        // 1. Cada pedido, guardado contra calculado ahora
        $ids = $this->check->orderIds($period);
        $stored = $this->check->stored($ids);
        $fresh = $this->economics->preview($ids);
        $pending = 0;
        $different = [];
        $freshProduct = 0.0;
        foreach ($ids as $id) {
            $f = $fresh[$id] ?? null;
            if ($f === null) {
                continue;
            }
            $freshProduct += (float) $f['cogs_main'] + (float) $f['cogs_upsell'] + (float) $f['logistics_cost'];
            $s = $stored[$id] ?? null;
            if ($s === null || $s['computed_at'] === null || $s['stale_at'] !== null) {
                $pending++;
                continue;
            }
            $diff = CheckRules::orderDiff($s, $f);
            if ($diff['fields'] !== []) {
                $different[$id] = $diff;
            }
        }
        $names = $this->check->orderNames(array_slice(array_keys($different), 0, 10));
        $orders = [
            'checked' => count($ids),
            'pending' => $pending,
            'different' => count($different),
            'profit_change' => round(array_sum(array_column($different, 'profit_change')), 2),
            'examples' => array_map(fn ($id) => [
                'order' => $names[$id] ?? "id {$id}",
                'fields' => array_map(fn ($f) => CheckRules::FIELD_LABELS[$f] ?? $f, $different[$id]['fields']),
                'profit_change' => $different[$id]['profit_change'],
            ], array_slice(array_keys($different), 0, 10)),
        ];

        // 2. Cada número por el segundo camino
        $raw = $this->check->sales($period);
        $commissions = $this->check->commissions($period);
        $shipping = $this->check->shipping($period);
        $ads = $this->check->ads($period);
        // Carbon que se puede modificar: RecurringExpense::accrued avanza el día con addDay() sobre su copia
        $from = Carbon::parse($period->fromDate());
        $to = Carbon::parse($period->toDate());
        $expenses = round(array_sum(array_column($this->expenses->forRange($from, $to)['items'], 'amount_usd'))
            + $this->shrinkage->forRange($from, $to)['total'], 2);
        $profit = round($raw['revenue_all'] - $freshProduct - $commissions - $shipping - $ads - $expenses, 2);

        $c = [CheckRules::class, 'compare'];
        $comparisons = [
            $c('created', 'Pedidos creados', 'count', $sales['created'], $raw['created'], 'Contados uno por uno en las órdenes (sin cambios ni devoluciones).'),
            $c('delivered', 'Entregados', 'count', $sales['delivered'], $raw['delivered'], 'Los que hoy están en "Entregado", de las órdenes.'),
            $c('open', 'En camino', 'count', $sales['open'], $raw['open'], 'Los que no están entregados, cancelados ni rechazados.'),
            $c('not_delivered', 'No entregados', 'count', $sales['not_delivered'], $raw['not_delivered'], 'Los que hoy están cancelados o rechazados.'),
            $c('revenue_sales', 'Monto vendido (Ventas)', 'money', $sales['revenue'], $raw['revenue_normal'], 'El total de cada orden entregada menos lo devuelto al cliente.'),
            $c('revenue_money', 'Lo vendido (Dinero)', 'money', $money['revenue'], $raw['revenue_all'], 'Lo mismo, contando también los cambios.'),
            $c('product', 'Costo del producto', 'money', $money['product'], $freshProduct, 'Cada pedido calculado de nuevo ahora, con sus lotes y la última compra registrada.'),
            $c('commissions', 'Comisiones', 'money', $money['commissions'], $commissions, 'Las comisiones guardadas de cada pedido entregado y la parte de la líder.'),
            $c('shipping', 'Envíos', 'money', $money['shipping'], $shipping, 'Cada carrera con su costo aprobado o el de su ruta, lo de antes de las carreras y el repartidor propio.'),
            $c('ads', 'Publicidad', 'money', $money['ads'], $ads, 'Todo lo gastado en Meta esos días, campaña por campaña.'),
            $c('expenses', 'Gastos', 'money', $money['expenses'], $expenses, 'El libro de gastos y la mercancía dada de baja de todo el período, de una vez.'),
            $c('profit', 'Ganancia', 'money', $money['profit'], $profit, 'Lo vendido menos todo lo anterior, con los números de este segundo camino.'),
        ];

        // 3. Los datos que pueden hacer que un número salga mal
        $problems = $this->problems($this->check->problems($period, $stored), $pending, $moneyRun['checks']['trips_missing_amount']);

        return [
            'period' => $period->toArray(),
            'orders' => $orders,
            'comparisons' => $comparisons,
            'problems' => $problems,
            'verdict' => CheckRules::verdict($comparisons, $orders, $problems),
        ];
    }

    private function problems(array $p, int $pending, float $tripsEstimated): array
    {
        $g = CheckRules::GRAVE;
        $w = CheckRules::WARNING;
        $i = CheckRules::INFO;
        $make = [CheckRules::class, 'problem'];
        $list = [
            $make('no_cost', $g, 'Pedidos entregados con un producto que nunca tuvo una compra registrada: no tienen costo y la ganancia sale más alta. Registra su compra en Inventario.',
                $p['no_cost']['count'], null, $p['no_cost']['examples']),
            $make('old_catalog', $g, 'Pedidos que todavía usan el costo de la ficha del producto (no debería quedar ninguno desde la `.46`).', $p['old_catalog']['count']),
            $make('foreign_currency', $g, 'Cuentas de Meta en otra moneda: su gasto se suma como si fueran dólares.', $p['foreign_currency']['count'],
                $p['foreign_currency']['amount'], $p['foreign_currency']['examples']),
            $make('meta_missing_days', $g, 'Días sin datos de Meta: esos días la ganancia no resta publicidad.', $p['meta_missing_days']['count'], null, $p['meta_missing_days']['examples']),
            $make('unclassified_ads', $w, 'Publicidad de campañas sin producto o sin ciudad: cuenta en la ganancia total, pero no en la de ningún producto. Se clasifican en Integraciones › Meta.',
                $p['unclassified_ads']['count'], $p['unclassified_ads']['amount'], $p['unclassified_ads']['examples']),
            // `.49`: cuentan el promedio de una carrera de su ciudad; sin ninguna carrera con costo para promediar, $0 (grave)
            $make('trips_missing', $tripsEstimated > 0 ? $w : $g, $tripsEstimated > 0
                ? 'Envíos sin costo todavía: se cuentan al promedio de una carrera de su ciudad (estimado). Se arreglan con el tarifario de la agencia, aprobando la distancia o con el cierre de la agencia (Tiendas, ciudades y agencias › Tarifas de delivery › Carreras).'
                : 'Envíos sin costo calculado: cuentan $0.', $p['trips_missing']['count'], $tripsEstimated > 0 ? $tripsEstimated : null),
            $make('earnings_not_delivered', $w, 'Comisiones guardadas de pedidos que hoy no están entregados. El Panel no las cuenta, pero Ganancias globales y los pagos sí.',
                $p['earnings_not_delivered']['count'], $p['earnings_not_delivered']['amount'], $p['earnings_not_delivered']['examples']),
            $make('delivered_without_date', $w, 'Pedidos entregados sin fecha de entrega (el sistema la repara al recalcularlos).', $p['delivered_without_date']['count'], null, $p['delivered_without_date']['examples']),
            $make('zero_price', $w, 'Pedidos entregados con total $0: no suman venta.', $p['zero_price']['count'], null, $p['zero_price']['examples']),
            $make('pending', $i, 'Pedidos que el sistema estaba recalculando en ese momento (tarda hasta un minuto).', $pending),
            $make('provisional_cost', $i, 'Pedidos entregados con el costo de la última compra registrada en vez del de su lote (salieron sin stock o antes del inventario por lotes). Es lo que dice tu regla: es provisional, no está mal.',
                $p['provisional_cost']['count']),
            $make('trips_estimated', $i, 'Envíos con su costo estimado de la ruta: pasan al costo aprobado cuando la agencia cierra su semana.', $p['trips_estimated']['count']),
        ];

        return array_values(array_filter($list, fn ($x) => $x['count'] > 0));
    }
}
