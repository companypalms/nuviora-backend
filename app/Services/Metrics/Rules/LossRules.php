<?php

namespace App\Services\Metrics\Rules;

use App\Constants\OrderStatus;
use App\Services\Metrics\Period;
use App\Services\Orders\Cancellations;

/**
 * Pérdidas del panel del dueño (diccionario de métricas, §4 y §8): cancelaciones y sus motivos, rechazos en puerta,
 * devoluciones y cambios, y lo que costó lo que no se vendió. PHP puro: recibe los pedidos con su cancelación
 * (OrderFactsReader::losses) y cuenta. Cada número tiene aquí mismo su explicación.
 *
 * Las reglas:
 * - Cuenta los pedidos CREADOS en el período (§1). Cancelados y rechazados = los que hoy están así (sin cambios ni
 *   devoluciones, que se cuentan aparte).
 * - El motivo, el momento y quién canceló salen de la última cancelación del pedido (order_cancellations). Las de antes
 *   del 29/10/2026 no tienen motivo de la lista; las más viejas pueden no tener ni el registro.
 * - El rechazo en puerta no pide motivo: se agrupa por la última novedad que tuvo el pedido.
 */
final class LossRules
{
    public const METRICS = [
        'cancelled' => [
            'label' => 'Cancelados',
            'format' => 'count',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Pedidos creados en este período que hoy están cancelados, por la vendedora, por administración o por el cierre automático de la jornada. Puede subir en los próximos días, si se cancelan pedidos que siguen en proceso.',
        ],
        'rejected' => [
            'label' => 'Rechazados en puerta',
            'format' => 'count',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Pedidos creados en este período que el cliente no recibió cuando el repartidor llegó (hoy están en "Rechazado"). Ya costaron el envío.',
        ],
        'returns' => [
            'label' => 'Devoluciones y cambios',
            'format' => 'count',
            'better' => 'lower',
            'help' => 'Pedidos de cambio de producto o de devolución creados en este período. No son ventas nuevas: muestran clientes que no quedaron conformes.',
        ],
        'failed_shipping' => [
            'label' => 'Costo de envíos fallidos',
            'format' => 'money',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Lo que se pagó (o se va a pagar) a agencias y repartidores por llevar pedidos de este período que no se entregaron: rechazados, o cancelados después de salir. Es dinero perdido.',
        ],
        'refunded' => [
            'label' => 'Dinero devuelto a clientes',
            'format' => 'money',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Lo que se le devolvió a clientes por pedidos de este período que ya se habían entregado (reembolsos y devoluciones). Ya está restado del monto vendido.',
        ],
    ];

    /** Los motivos que no son de la lista. */
    public const EXTRA_REASONS = [
        Cancellations::SYSTEM_CATEGORY => 'Cierre de la jornada (sin resolver dos veces)',
        'sin_lista' => 'Sin motivo de la lista (antes del 29/10)',
        'sin_registro' => 'Sin registro del motivo',
    ];

    /** En qué momento se cancelan: el estado que tenía el pedido justo antes. */
    public const STAGES = [
        'before_contact' => ['label' => 'Antes de hablar con el cliente', 'statuses' => [OrderStatus::NUEVO, OrderStatus::ASIGNADO_VENDEDOR, OrderStatus::SIN_STOCK]],
        'calls' => ['label' => 'En las llamadas', 'statuses' => [OrderStatus::LLAMADO_1, OrderStatus::LLAMADO_2, OrderStatus::LLAMADO_3]],
        'scheduled' => ['label' => 'Programados o esperando', 'statuses' => [OrderStatus::PROGRAMADO_MAS_TARDE, OrderStatus::PROGRAMADO_OTRO_DIA, 'Reprogramado', OrderStatus::REPROGRAMADO_HOY, OrderStatus::ESPERANDO_UBICACION]],
        'confirmed' => ['label' => 'Después de confirmar', 'statuses' => ['Confirmado', OrderStatus::PENDIENTE_AGENCIA, OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR]],
        'street' => ['label' => 'Ya en la calle', 'statuses' => [OrderStatus::EN_RUTA, OrderStatus::NOVEDADES, OrderStatus::NOVEDAD_SOLUCIONADA]],
        'unknown' => ['label' => 'Sin dato', 'statuses' => []],
    ];

    /** Quién cancela. */
    public const WHO = [
        'seller' => 'Vendedora',
        'admin' => 'Administración',
        'system' => 'Cierre automático',
        'other' => 'Otro',
        'unknown' => 'Sin dato',
    ];

    /** El tipo de la última novedad de un rechazo (ReportNovedadDialog). */
    public const NOVELTIES = ['No atendió', 'Cambió ubicación', 'Cambió método de pago', 'Otro'];

    public const REASONS_HELP = 'Por qué se cancelaron los pedidos de este período: el motivo de la lista que eligió quien canceló. Desde el 29/10/2026 se elige al cancelar; las de antes salen aparte.';
    public const STAGES_HELP = 'En qué momento se cancelaron: el estado que tenía el pedido justo antes. Si muchos se cancelan después de confirmar o ya en la calle, ya costaron trabajo y envío.';
    public const WHO_HELP = 'Quién canceló: la vendedora, administración (Admin, Gerente o Master) o el cierre automático de la jornada (pedidos que llegaron dos veces al cierre sin resolverse).';
    public const REJECTED_HELP = 'Los rechazos en puerta, según la última novedad que tuvo el pedido antes de rechazarse. El sistema no pide un motivo al rechazar.';
    public const LATEST_HELP = 'Los últimos motivos escritos por quien canceló, tal cual. Son los que lee la IA para su análisis.';

    /** Cuántos motivos escritos se muestran en la lista. */
    public const LATEST = 15;

    /**
     * Un hecho es un pedido: ['created_at', 'kind', 'outcome', 'status', 'novedad_type', 'shipping', 'refunded',
     * 'order_number', y su última cancelación: 'category', 'source', 'reason', 'cancelled_at', 'previous_status',
     * 'user_name', 'user_role'].
     *
     * @param iterable<array|object> $facts
     */
    public static function compute(iterable $facts, Period $period): array
    {
        $start = $period->start->format('Y-m-d H:i:s');
        $end = $period->end->format('Y-m-d H:i:s');
        $t = ['created' => 0, 'cancelled' => 0, 'rejected' => 0, 'exchanges' => 0, 'returns_only' => 0, 'failed_shipping' => 0.0,
            'failed_orders' => 0, 'refunded' => 0.0, 'refunded_orders' => 0];
        $reasons = [];
        $stages = array_fill_keys(array_keys(self::STAGES), 0);
        $who = array_fill_keys(array_keys(self::WHO), 0);
        $novelties = [];
        $latest = [];
        $stageOf = [];
        foreach (self::STAGES as $key => $s) {
            foreach ($s['statuses'] as $status) {
                $stageOf[$status] = $key;
            }
        }

        foreach ($facts as $fact) {
            $f = (array) $fact;
            if ((string) $f['created_at'] < $start || (string) $f['created_at'] > $end) {
                continue;
            }
            if ($f['kind'] !== SalesRules::NORMAL) {
                $f['kind'] === 'exchange' ? $t['exchanges']++ : $t['returns_only']++;
                continue;
            }
            $t['created']++;
            if ($f['outcome'] === SalesRules::NOT_DELIVERED && (float) $f['shipping'] > 0) {
                $t['failed_shipping'] += (float) $f['shipping'];
                $t['failed_orders']++;
            }
            if ((float) $f['refunded'] > 0) {
                $t['refunded'] += (float) $f['refunded'];
                $t['refunded_orders']++;
            }
            if ($f['status'] === OrderStatus::RECHAZADO) {
                $t['rejected']++;
                $n = in_array($f['novedad_type'], self::NOVELTIES, true) ? $f['novedad_type'] : 'Sin novedad registrada';
                $novelties[$n] = ($novelties[$n] ?? 0) + 1;
                continue;
            }
            if ($f['status'] !== OrderStatus::CANCELADO) {
                continue;
            }

            $t['cancelled']++;
            $reason = $f['source'] === null && $f['category'] === null && $f['reason'] === null ? 'sin_registro'
                : ($f['category'] ?? ($f['source'] === 'system' ? Cancellations::SYSTEM_CATEGORY : 'sin_lista'));
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            $stages[$stageOf[$f['previous_status'] ?? ''] ?? 'unknown']++;
            $who[self::who($f)]++;
            if ($f['source'] === 'user' && !empty($f['reason'])) {
                $latest[] = [
                    'order_number' => $f['order_number'],
                    'cancelled_at' => $f['cancelled_at'],
                    'category' => self::reasonLabel($reason),
                    'stage' => self::STAGES[$stageOf[$f['previous_status'] ?? ''] ?? 'unknown']['label'],
                    'who' => self::who($f) === 'seller' ? self::firstName($f['user_name']) : self::WHO[self::who($f)],
                    'reason' => $f['reason'],
                ];
            }
        }

        arsort($reasons);
        arsort($novelties);
        usort($latest, fn ($a, $b) => strcmp((string) $b['cancelled_at'], (string) $a['cancelled_at']));
        $t['returns'] = $t['exchanges'] + $t['returns_only'];
        $t['failed_shipping'] = round($t['failed_shipping'], 2);
        $t['refunded'] = round($t['refunded'], 2);

        return [
            'totals' => $t,
            'reasons' => array_map(fn ($k, $n) => ['key' => $k, 'label' => self::reasonLabel($k), 'count' => $n], array_keys($reasons), $reasons),
            'stages' => array_values(array_filter(array_map(fn ($k, $n) => ['key' => $k, 'label' => self::STAGES[$k]['label'], 'count' => $n], array_keys($stages), $stages), fn ($s) => $s['count'] > 0)),
            'who' => array_values(array_filter(array_map(fn ($k, $n) => ['key' => $k, 'label' => self::WHO[$k], 'count' => $n], array_keys($who), $who), fn ($w) => $w['count'] > 0)),
            'novelties' => array_map(fn ($k, $n) => ['label' => $k, 'count' => $n], array_keys($novelties), $novelties),
            'latest' => array_slice($latest, 0, self::LATEST),
        ];
    }

    public static function cards(array $now, array $before, bool $maturing): array
    {
        $share = fn (int $n) => $now['created'] > 0 ? ' (' . number_format($n / $now['created'] * 100, 1, ',', '.') . ' %)' : '';
        $details = [
            'cancelled' => Card::plural($now['cancelled'], 'cancelado', 'cancelados') . ' de ' . Card::plural($now['created'], 'creado', 'creados') . $share($now['cancelled']),
            'rejected' => Card::plural($now['rejected'], 'rechazado', 'rechazados') . ' de ' . Card::plural($now['created'], 'creado', 'creados') . $share($now['rejected']),
            'returns' => Card::plural($now['exchanges'], 'cambio', 'cambios') . ' y ' . Card::plural($now['returns_only'], 'devolución', 'devoluciones'),
            'failed_shipping' => 'Envío de ' . Card::plural($now['failed_orders'], 'pedido que no se entregó', 'pedidos que no se entregaron'),
            'refunded' => Card::plural($now['refunded_orders'], 'pedido con dinero devuelto', 'pedidos con dinero devuelto'),
        ];
        $cards = [];
        foreach (self::METRICS as $key => $m) {
            $cards[] = Card::make($key, $m, $now[$key], $before[$key], $details[$key], $maturing);
        }

        return $cards;
    }

    public static function reasonLabel(string $key): string
    {
        return Cancellations::CATEGORIES[$key] ?? self::EXTRA_REASONS[$key] ?? $key;
    }

    private static function who(array $f): string
    {
        if ($f['source'] === 'system') {
            return 'system';
        }
        if ($f['source'] === null && $f['user_role'] === null) {
            return 'unknown';
        }

        return match ($f['user_role']) {
            'Vendedor' => 'seller',
            'Admin', 'Gerente', 'Master' => 'admin',
            default => 'other',
        };
    }

    /** Solo el primer nombre de la vendedora (dueño, 2026-10-29). */
    public static function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? 'Vendedora' : explode(' ', $name)[0];
    }
}
