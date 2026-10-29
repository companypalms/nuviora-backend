<?php

namespace App\Services\Metrics\Team;

use App\Constants\OrderStatus;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;
use App\Services\Metrics\Rules\FunnelRules;
use App\Services\Metrics\Rules\LossRules;

/**
 * Vendedoras (diccionario de métricas, §3 "Efectividad de cada vendedora" y §4 "Vendedoras"). PHP puro.
 *
 * Las reglas:
 * - A una vendedora "le entra" un pedido cuando pasa a "Asignado a vendedor" o "Reprogramado para hoy" siendo suyo, o
 *   cuando se lo pasan de otra vendedora (reasignación) mientras sigue en ventas. Cuenta el día en que le entró.
 * - Atendidos = pedidos que le entraron en el período (cada pedido una vez, aunque le entre dos veces).
 * - Entregado le cuenta a la que lo tenía cuando se entregó (orders.agent_id, a quien se le paga la comisión), aunque se
 *   entregue después del período. A las demás que lo atendieron, como atendido y no entregado.
 * - Confirmar, reprogramar o cancelar le cuenta a la que lo tenía en ese momento (el historial guarda la vendedora de
 *   cada cambio de estado).
 * - Un mismo cálculo sirve para una vendedora, para un grupo de venta (las líderes) y para todo el equipo: en los grupos
 *   y en el total, un pedido que pasó por dos vendedoras cuenta una vez.
 */
final class SellerKpis extends TeamRole
{
    /** Le entra un pedido cuando pasa a uno de estos estados siendo suyo. */
    public const ENTRY = [OrderStatus::ASIGNADO_VENDEDOR, OrderStatus::REPROGRAMADO_HOY];

    /** Los estados de ventas, antes de confirmar: si se lo pasan de otra vendedora en uno de ellos, también le entra. */
    public const STAGE = [
        OrderStatus::NUEVO, OrderStatus::ASIGNADO_VENDEDOR, OrderStatus::REPROGRAMADO_HOY, 'Reprogramado', OrderStatus::SIN_STOCK,
        OrderStatus::LLAMADO_1, OrderStatus::LLAMADO_2, OrderStatus::LLAMADO_3, OrderStatus::ESPERANDO_UBICACION,
        OrderStatus::PROGRAMADO_MAS_TARDE, OrderStatus::PROGRAMADO_OTRO_DIA,
    ];

    public const RESCHEDULED = [OrderStatus::PROGRAMADO_MAS_TARDE, OrderStatus::PROGRAMADO_OTRO_DIA];

    /** Su embudo (detalle): los estados de su kanban a los que llegaron sus pedidos, en orden. */
    public const FUNNEL = [
        'llamado_1' => ['label' => 'Llamado 1', 'statuses' => [OrderStatus::LLAMADO_1]],
        'llamado_2' => ['label' => 'Llamado 2', 'statuses' => [OrderStatus::LLAMADO_2]],
        'llamado_3' => ['label' => 'Llamado 3', 'statuses' => [OrderStatus::LLAMADO_3]],
        'ubicacion' => ['label' => 'Esperando ubicación', 'statuses' => [OrderStatus::ESPERANDO_UBICACION]],
        'mas_tarde' => ['label' => 'Programado para más tarde', 'statuses' => [OrderStatus::PROGRAMADO_MAS_TARDE]],
        'otro_dia' => ['label' => 'Programado para otro día', 'statuses' => [OrderStatus::PROGRAMADO_OTRO_DIA]],
        'sin_stock' => ['label' => 'Sin stock', 'statuses' => [OrderStatus::SIN_STOCK]],
        'confirmado' => ['label' => 'Pasó a agencia (confirmado)', 'statuses' => FunnelRules::STATUSES['confirmed']],
        'novedades' => ['label' => 'Novedades', 'statuses' => [OrderStatus::NOVEDADES]],
        'solucionada' => ['label' => 'Novedad solucionada', 'statuses' => [OrderStatus::NOVEDAD_SOLUCIONADA]],
        'cancelado' => ['label' => 'Cancelado', 'statuses' => [OrderStatus::CANCELADO]],
        'rechazado' => ['label' => 'Rechazado en puerta', 'statuses' => [OrderStatus::RECHAZADO]],
        'entregado' => ['label' => 'Entregado', 'statuses' => [OrderStatus::ENTREGADO]],
    ];

    public const METRICS = [
        'attended' => [
            'label' => 'Atendidos',
            'format' => 'count',
            'better' => 'higher',
            'card' => true,
            'help' => 'Pedidos que le entraron en este período: los que pasaron a "Asignado a vendedor" o "Reprogramado para hoy" siendo suyos, o que le pasaron de otra vendedora. Cada pedido cuenta una vez, aunque le entre dos veces.',
        ],
        'effectiveness' => [
            'label' => 'Efectividad',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De los pedidos que atendió en este período, cuántos entregó ella (los que tenía cuando se entregaron), aunque se hayan entregado después. Si un pedido pasó por dos vendedoras, la entrega le cuenta a la que lo tenía al entregarse. Puede subir en los próximos días.',
        ],
        'confirmation' => [
            'label' => 'Pasan a agencia',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'help' => 'De los pedidos que atendió, cuántos confirmó ella y pasó a reparto (a una agencia, a un repartidor o esperando cupo).',
        ],
        'cancel_rate' => [
            'label' => 'Canceladas',
            'format' => 'percent',
            'better' => 'lower',
            'matures' => true,
            'help' => 'De los pedidos que atendió, cuántos están cancelados hoy y eran suyos al cancelarse. Al hacer clic en su nombre se ven los motivos.',
        ],
        'response_time' => [
            'label' => 'Velocidad de atención',
            'format' => 'hours',
            'better' => 'lower',
            'card' => true,
            'help' => 'El tiempo normal (la mitad tardó menos) desde que le entra un pedido hasta que lo mueve por primera vez, por ejemplo a "Llamado 1". Si le entra de noche, cuenta también la noche.',
        ],
        'upsell_rate' => [
            'label' => 'Con upsell',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'help' => 'De los pedidos que entregó, cuántos llevaron al menos un upsell.',
        ],
        'revenue' => [
            'label' => 'Monto vendido',
            'format' => 'money',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'Lo que suman en dólares los pedidos que entregó (de los que atendió en este período), menos lo que se le devolvió al cliente.',
        ],
        'ticket' => [
            'label' => 'Ticket promedio',
            'format' => 'money',
            'better' => 'higher',
            'help' => 'Monto vendido ÷ pedidos entregados.',
        ],
        'commission' => [
            'label' => 'Comisión ganada',
            'format' => 'money',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'Su comisión (venta y upsells) de los pedidos que entregó, de los que atendió en este período. Puede no coincidir con "Mis ganancias", que cuenta por el día en que se ganó.',
        ],
        'rescheduled' => [
            'label' => 'Reprogramados',
            'format' => 'count',
            'better' => 'none',
            'help' => 'Pedidos que atendió y que ella pasó a "Programado para más tarde" o "Programado para otro día".',
        ],
        'rescheduled_delivered' => [
            'label' => 'Reprogramados que se entregan',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'help' => 'De los pedidos que reprogramó, cuántos terminó entregando.',
        ],
        'rejection_rate' => [
            'label' => 'Rechazos de lo que confirmó',
            'format' => 'percent',
            'better' => 'lower',
            'matures' => true,
            'help' => 'De los pedidos que ella confirmó, cuántos rechazó el cliente en la puerta. Mide qué tan bien confirma: un rechazo ya costó el envío.',
        ],
        'returns' => [
            'label' => 'Devoluciones',
            'format' => 'count',
            'better' => 'lower',
            'help' => 'Pedidos que entregó y que después tuvieron un cambio de producto o una devolución.',
        ],
    ];

    public function key(): string
    {
        return 'sellers';
    }

    public function label(): string
    {
        return 'Vendedoras';
    }

    public function systemRoles(): array
    {
        return ['Vendedor'];
    }

    public function help(): string
    {
        return 'Cada vendedora con los pedidos que le entraron en el período: qué tanto entrega, confirma y cancela, qué tan rápido atiende, cuánto vende y cuánto gana. Con los filtros de tienda, ciudad y producto, solo cuentan esos pedidos.';
    }

    public function metrics(): array
    {
        return self::METRICS;
    }

    public function rankBy(): string
    {
        return 'effectiveness';
    }

    public function base(): array
    {
        return ['key' => 'attended', 'min' => 10, 'label' => 'pedidos atendidos'];
    }

    public function facts(TeamReader $reader, Period $period, Filters $filters): mixed
    {
        return [
            'journeys' => $reader->sellerJourneys($period, $filters, self::ENTRY, self::STAGE),
            'people' => $reader->people($this->systemRoles()),
            'groups' => $reader->salesGroups($period),
        ];
    }

    /**
     * facts: journeys (un pedido con su historial: order_id, agent_id, status, revenue, has_upsell, returns, commission,
     * leader_commission, reason_key y logs: at, status, seller, changed, reassigned), people (id => name, group) y
     * groups (id => name, leader_id, leader, members). Además de people y totals devuelve groups (para las líderes) y
     * el embudo y los motivos de cada vendedora (para su detalle).
     */
    public function compute(mixed $facts, Period $period): array
    {
        $people = $facts['people'];
        $groups = $facts['groups'] ?? [];
        $memberOf = [];
        foreach ($groups as $gid => $g) {
            foreach ($g['members'] as $m) {
                $memberOf[(int) $m][] = $gid;
            }
        }

        $sellers = [];
        $byGroup = [];
        $total = self::emptyRaw();
        $allGroups = self::emptyRaw();
        foreach ($facts['journeys'] as $journey) {
            $o = self::journey((array) $journey, $period, $people);
            if ($o['attendees'] === []) {
                continue;
            }
            foreach ($o['attendees'] as $s) {
                $sellers[$s] ??= self::emptyRaw();
                self::add($sellers[$s], $o, [$s], true);
            }
            $inGroups = [];
            foreach (array_unique(array_merge([], ...array_map(fn ($s) => $memberOf[$s] ?? [], $o['attendees']))) as $gid) {
                $members = array_values(array_intersect($o['attendees'], array_map('intval', $groups[$gid]['members'])));
                $byGroup[$gid] ??= self::emptyRaw();
                self::add($byGroup[$gid], $o, $members, false);
                $inGroups = array_merge($inGroups, $members);
            }
            if ($inGroups !== []) {
                self::add($allGroups, $o, array_values(array_unique($inGroups)), false);
            }
            self::add($total, $o, $o['attendees'], false);
        }

        $out = [];
        foreach ($sellers as $id => $raw) {
            $out[$id] = ['name' => $people[$id]['name'] ?? "Vendedora {$id}", 'group' => $people[$id]['group'] ?? null] + self::values($raw);
        }

        return [
            'people' => $out,
            'totals' => self::values($total),
            'groups' => array_map(fn ($raw) => self::values($raw) + ['sellers' => count($raw['members'])], $byGroup),
            // Los pedidos de las vendedoras que están en algún grupo (el total de las líderes)
            'groups_total' => self::values($allGroups) + ['sellers' => count($allGroups['members'])],
            'funnel' => array_map(fn ($raw) => ['attended' => $raw['attended'], 'reached' => $raw['reached']], $sellers),
            'reasons' => array_map(fn ($raw) => $raw['reasons'], $sellers),
        ];
    }

    public function blocks(int $id, array $computed, array $before = []): array
    {
        $funnel = $computed['funnel'][$id] ?? ['attended' => 0, 'reached' => []];
        $reasons = $computed['reasons'][$id] ?? [];
        arsort($reasons);

        return [
            [
                'type' => 'bars',
                'title' => 'Su embudo',
                'subtitle' => 'De los pedidos que atendió, cuántos llegaron a cada estado de su kanban.',
                'help' => 'Cuenta un pedido en un estado si pasó por ahí mientras era suyo, aunque después siguiera. Los porcentajes son sobre los pedidos que atendió en el período, por eso no suman 100 %.',
                'rows' => array_values(array_map(fn ($key, $s) => ['label' => $s['label'], 'count' => $funnel['reached'][$key] ?? 0, 'of' => $funnel['attended']],
                    array_keys(self::FUNNEL), self::FUNNEL)),
            ],
            [
                'type' => 'bars',
                'title' => 'Por qué se cancelan sus pedidos',
                'subtitle' => 'El motivo de la lista de los pedidos que eran suyos al cancelarse.',
                'help' => LossRules::REASONS_HELP,
                'rows' => array_values(array_map(fn ($key, $n) => ['label' => LossRules::reasonLabel($key), 'count' => $n, 'of' => array_sum($reasons)],
                    array_keys($reasons), $reasons)),
                'empty' => 'No tiene pedidos cancelados en este período.',
            ],
        ];
    }

    /**
     * Lo que pasó con un pedido: quiénes lo atendieron en el período (y cuánto tardaron en moverlo), quiénes lo
     * confirmaron o reprogramaron y a qué estados de su kanban llegó con cada una. Solo cuentan las vendedoras de
     * "people" (un pedido asignado a otro usuario no es de nadie del equipo).
     */
    private static function journey(array $j, Period $period, array $people): array
    {
        $logs = array_values($j['logs']);
        $attendees = [];
        $response = [];
        $confirmers = [];
        $reschedulers = [];
        $reached = [];
        $funnelOf = [];
        foreach (self::FUNNEL as $key => $s) {
            foreach ($s['statuses'] as $status) {
                $funnelOf[$status] = $key;
            }
        }

        foreach ($logs as $i => $log) {
            $seller = $log['seller'] !== null ? (int) $log['seller'] : null;
            if ($seller === null || !isset($people[$seller])) {
                continue;
            }
            $status = $log['status'];
            if ($log['changed']) {
                if (in_array($status, FunnelRules::STATUSES['confirmed'], true)) {
                    $confirmers[$seller] = true;
                }
                if (in_array($status, self::RESCHEDULED, true)) {
                    $reschedulers[$seller] = true;
                }
                if (isset($funnelOf[$status])) {
                    $reached[$seller][$funnelOf[$status]] = true;
                }
            }
            $entry = ($log['changed'] && in_array($status, self::ENTRY, true)) || ($log['reassigned'] && in_array($status, self::STAGE, true));
            if (!$entry || !self::inPeriod($log['at'], $period)) {
                continue;
            }
            $attendees[$seller] = true;
            // Lo que tardó en moverlo: el siguiente cambio de estado, si lo hizo mientras seguía siendo suyo
            for ($k = $i + 1; $k < count($logs); $k++) {
                if ((int) $logs[$k]['seller'] !== $seller) {
                    break;
                }
                if ($logs[$k]['changed']) {
                    $hours = self::hoursBetween($log['at'], $logs[$k]['at']);
                    if ($hours !== null) {
                        $response[$seller][] = $hours;
                    }
                    break;
                }
            }
        }

        return [
            'attendees' => array_keys($attendees),
            'response' => $response,
            'confirmers' => $confirmers,
            'reschedulers' => $reschedulers,
            'reached' => $reached,
            'agent' => $j['agent_id'] !== null ? (int) $j['agent_id'] : null,
            'status' => $j['status'],
            'revenue' => (float) $j['revenue'],
            'has_upsell' => (bool) $j['has_upsell'],
            'returns' => (int) $j['returns'],
            'commission' => (float) $j['commission'],
            'leader_commission' => (float) $j['leader_commission'],
            'reason_key' => $j['reason_key'] ?? null,
        ];
    }

    private static function emptyRaw(): array
    {
        return ['attended' => 0, 'delivered' => 0, 'revenue' => 0.0, 'with_upsell' => 0, 'commission' => 0.0, 'leader_commission' => 0.0,
            'returns' => 0, 'confirmed' => 0, 'rejected_confirmed' => 0, 'cancelled' => 0, 'rescheduled' => 0, 'rescheduled_delivered' => 0,
            'response' => [], 'reached' => [], 'reasons' => [], 'members' => []];
    }

    /** Suma un pedido a una vendedora, a un grupo o al total ("members": las de ahí que lo atendieron). */
    private static function add(array &$raw, array $o, array $members, bool $single): void
    {
        $raw['attended']++;
        foreach ($members as $m) {
            $raw['members'][$m] = true;
            foreach ($o['response'][$m] ?? [] as $hours) {
                $raw['response'][] = $hours;
            }
        }
        $hers = $o['agent'] !== null && in_array($o['agent'], $members, true);
        $delivered = $hers && $o['status'] === OrderStatus::ENTREGADO;
        if ($delivered) {
            $raw['delivered']++;
            $raw['revenue'] += $o['revenue'];
            $raw['with_upsell'] += $o['has_upsell'] ? 1 : 0;
            $raw['commission'] += $o['commission'];
            $raw['leader_commission'] += $o['leader_commission'];
            $raw['returns'] += $o['returns'] > 0 ? 1 : 0;
        }
        if (array_intersect_key($o['confirmers'], array_flip($members))) {
            $raw['confirmed']++;
            $raw['rejected_confirmed'] += $o['status'] === OrderStatus::RECHAZADO ? 1 : 0;
        }
        if ($hers && $o['status'] === OrderStatus::CANCELADO) {
            $raw['cancelled']++;
            if ($single) {
                $key = $o['reason_key'] ?? 'sin_registro';
                $raw['reasons'][$key] = ($raw['reasons'][$key] ?? 0) + 1;
            }
        }
        if (array_intersect_key($o['reschedulers'], array_flip($members))) {
            $raw['rescheduled']++;
            $raw['rescheduled_delivered'] += $delivered ? 1 : 0;
        }
        if ($single) {
            foreach (array_keys($o['reached'][$members[0]] ?? []) as $key) {
                $raw['reached'][$key] = ($raw['reached'][$key] ?? 0) + 1;
            }
        }
    }

    /** Los KPIs que salen de lo sumado, con el texto de abajo de cada tarjeta. */
    private static function values(array $r): array
    {
        $of = fn (int $part, int $total, string $what) => number_format($part, 0, ',', '.') . ' de ' . number_format($total, 0, ',', '.') . " {$what}";

        return [
            'values' => [
                'attended' => $r['attended'],
                'effectiveness' => self::pct($r['delivered'], $r['attended']),
                'confirmation' => self::pct($r['confirmed'], $r['attended']),
                'cancel_rate' => self::pct($r['cancelled'], $r['attended']),
                'response_time' => self::median($r['response']),
                'upsell_rate' => self::pct($r['with_upsell'], $r['delivered']),
                'revenue' => round($r['revenue'], 2),
                'ticket' => self::ratio($r['revenue'], $r['delivered']),
                'commission' => round($r['commission'], 2),
                'leader_commission' => round($r['leader_commission'], 2),
                'rescheduled' => $r['rescheduled'],
                'rescheduled_delivered' => self::pct($r['rescheduled_delivered'], $r['rescheduled']),
                'rejection_rate' => self::pct($r['rejected_confirmed'], $r['confirmed']),
                'returns' => $r['returns'],
            ],
            'details' => [
                'effectiveness' => $of($r['delivered'], $r['attended'], 'entregados'),
                'confirmation' => $of($r['confirmed'], $r['attended'], 'confirmados'),
                'cancel_rate' => self::plural($r['cancelled'], 'cancelado', 'cancelados'),
                'response_time' => 'De ' . self::plural(count($r['response']), 'pedido movido', 'pedidos movidos'),
                'upsell_rate' => $of($r['with_upsell'], $r['delivered'], 'entregas'),
                'revenue' => self::plural($r['delivered'], 'pedido entregado', 'pedidos entregados'),
                'rescheduled_delivered' => $of($r['rescheduled_delivered'], $r['rescheduled'], 'reprogramados'),
                'rejection_rate' => $of($r['rejected_confirmed'], $r['confirmed'], 'confirmados'),
            ],
        ];
    }
}
