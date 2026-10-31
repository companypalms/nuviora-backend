<?php

namespace App\Services\Metrics\Rules;

/**
 * La revisión de los números del panel del dueño (`.48a`, pedido del dueño: "los datos tienen que ser 100 % correctos").
 * PHP puro: recibe los números del panel, los mismos calculados por un segundo camino y los datos que pueden hacer que
 * un número salga mal (los trae App\Services\Metrics\NumbersCheck) y decide qué cuadra, con la explicación para el dueño.
 *
 * - Una comparación cuadra si los dos caminos dan lo mismo: al centavo el dinero, exacto los pedidos.
 * - Un pedido está al día si su economía guardada (order_financials) es la que sale de calcularla ahora.
 * - Un problema "grave" hace que un número del panel salga mal; un "aviso" es algo para revisar que no cambia los números
 *   del panel (o los cambia muy poco y ya se avisa en el panel); "info" es normal y solo se cuenta.
 */
final class CheckRules
{
    public const GRAVE = 'grave';
    public const WARNING = 'aviso';
    public const INFO = 'info';

    /** Las columnas de dinero de un pedido que se comparan (las mismas que suma el panel). */
    public const ORDER_MONEY = ['revenue_main', 'revenue_upsell', 'revenue_refunded', 'cogs_main', 'cogs_upsell', 'logistics_cost',
        'delivery_cost', 'commission_seller', 'commission_upsell', 'commission_leader', 'commission_deliverer', 'commission_other'];

    /** Lo que no es dinero y también tiene que ser igual. */
    public const ORDER_FACTS = ['outcome', 'kind', 'product_id', 'city_id', 'created_date', 'delivered_date'];

    /** Los nombres de las columnas, para el dueño. */
    public const FIELD_LABELS = [
        'outcome' => 'qué pasó con el pedido', 'kind' => 'tipo', 'product_id' => 'producto', 'city_id' => 'ciudad',
        'created_date' => 'día de creación', 'delivered_date' => 'día de entrega', 'revenue_main' => 'lo vendido',
        'revenue_upsell' => 'lo vendido en upsells', 'revenue_refunded' => 'lo devuelto', 'cogs_main' => 'costo del producto',
        'cogs_upsell' => 'costo de los upsells', 'logistics_cost' => 'traslado del producto', 'delivery_cost' => 'envío',
        'commission_seller' => 'comisión de la vendedora', 'commission_upsell' => 'comisión del upsell',
        'commission_leader' => 'comisión de la líder', 'commission_deliverer' => 'comisión del repartidor',
        'commission_other' => 'otras comisiones',
    ];

    /**
     * Un número del panel contra el mismo por el segundo camino.
     *
     * @param 'count'|'money' $format
     */
    public static function compare(string $key, string $label, string $format, int|float $panel, int|float $other, string $how): array
    {
        $diff = $format === 'money' ? round($other - $panel, 2) : (int) $other - (int) $panel;

        return [
            'key' => $key,
            'label' => $label,
            'format' => $format,
            'panel' => $format === 'money' ? round((float) $panel, 2) : (int) $panel,
            'other' => $format === 'money' ? round((float) $other, 2) : (int) $other,
            'diff' => $diff,
            'ok' => $format === 'money' ? abs($diff) < 0.01 : $diff === 0,
            'how' => $how,
        ];
    }

    /**
     * Qué tiene distinto un pedido entre lo guardado y lo calculado ahora (vacío si nada), y cuánto cambia su ganancia.
     *
     * @return array{fields: string[], profit_change: float}
     */
    public static function orderDiff(array $stored, array $fresh): array
    {
        $fields = [];
        foreach (self::ORDER_FACTS as $f) {
            $a = $stored[$f] ?? null;
            $b = $fresh[$f] ?? null;
            if (in_array($f, ['created_date', 'delivered_date'], true)) {
                $a = $a !== null ? substr((string) $a, 0, 10) : null;
                $b = $b !== null ? substr((string) $b, 0, 10) : null;
            }
            if ((string) $a !== (string) $b) {
                $fields[] = $f;
            }
        }
        foreach (self::ORDER_MONEY as $f) {
            if (abs((float) ($stored[$f] ?? 0) - (float) ($fresh[$f] ?? 0)) >= 0.005) {
                $fields[] = $f;
            }
        }

        return ['fields' => $fields, 'profit_change' => $fields === [] ? 0.0 : round(self::orderProfit($fresh) - self::orderProfit($stored), 2)];
    }

    /** Lo que deja un pedido antes de publicidad y gastos: vendido − devuelto − sus costos. */
    public static function orderProfit(array $row): float
    {
        $v = fn (string $k) => (float) ($row[$k] ?? 0);

        return round($v('revenue_main') + $v('revenue_upsell') - $v('revenue_refunded') - $v('cogs_main') - $v('cogs_upsell') - $v('logistics_cost')
            - $v('delivery_cost') - $v('commission_seller') - $v('commission_upsell') - $v('commission_leader') - $v('commission_deliverer')
            - $v('commission_other'), 2);
    }

    /** Un dato que puede hacer que un número salga mal. Si no hay ningún caso, no se muestra. */
    public static function problem(string $key, string $severity, string $text, int $count, ?float $amount = null, array $examples = []): array
    {
        return ['key' => $key, 'severity' => $severity, 'text' => $text, 'count' => $count,
            'amount' => $amount !== null ? round($amount, 2) : null, 'examples' => array_values(array_slice($examples, 0, 5))];
    }

    /**
     * El resultado en una frase: si los números se pueden creer.
     *
     * @param array $comparisons de compare()
     * @param array{checked: int, pending: int, different: int} $orders
     * @param array $problems de problem()
     * @return array{ok: bool, text: string}
     */
    public static function verdict(array $comparisons, array $orders, array $problems): array
    {
        $bad = array_values(array_filter($comparisons, fn ($c) => !$c['ok']));
        $grave = array_values(array_filter($problems, fn ($p) => $p['severity'] === self::GRAVE && $p['count'] > 0));
        $ok = $bad === [] && $orders['different'] === 0 && $grave === [];

        if ($ok) {
            $text = 'Todo cuadra: los números del Panel dan lo mismo por los dos caminos y no hay datos que los hagan salir mal.';
            if ($orders['pending'] > 0) {
                $text .= ' ' . Card::plural($orders['pending'], 'pedido se estaba', 'pedidos se estaban') . ' recalculando: no cambia el resultado.';
            }

            return ['ok' => true, 'text' => $text];
        }
        $parts = [];
        if ($bad !== []) {
            $parts[] = Card::plural(count($bad), 'número no cuadra', 'números no cuadran') . ' (' . implode(', ', array_column($bad, 'label')) . ')';
        }
        if ($orders['different'] > 0) {
            $parts[] = Card::plural($orders['different'], 'pedido tiene', 'pedidos tienen') . ' guardado algo distinto de lo que da calcularlo ahora';
        }
        if ($grave !== []) {
            $parts[] = Card::plural(count($grave), 'dato hace', 'datos hacen') . ' que un número salga mal';
        }
        $text = ucfirst(implode('; ', $parts)) . '.';
        if ($orders['pending'] > 0 && ($bad !== [] || $orders['different'] > 0)) {
            $text .= ' Ojo: ' . Card::plural($orders['pending'], 'pedido se estaba', 'pedidos se estaban') . ' recalculando; vuelve a correrlo en unos minutos.';
        }

        return ['ok' => false, 'text' => $text];
    }
}
