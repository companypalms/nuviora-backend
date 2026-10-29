<?php

namespace App\Services\Metrics\Team;

use App\Constants\OrderStatus;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;
use App\Services\Metrics\Rules\Card;

/**
 * Agencias (diccionario de métricas, §3 "Efectividad de cada agencia" y §4 "Agencias"). PHP puro.
 *
 * Las reglas:
 * - Una agencia "recibe" un pedido cuando pasa a "Asignar a agencia" con esa agencia, o cuando se lo pasan de otra
 *   agencia mientras está en reparto. Cuenta el día en que lo recibió.
 * - Entregado le cuenta a la agencia que lo tenía cuando se entregó, aunque lo entregue después del período. Si pasó
 *   por dos agencias, a la primera le cuenta como no entregado.
 * - Lo de ahora mismo (el dinero que te debe) y los cierres semanales no dependen de los filtros de tienda, ciudad y
 *   producto.
 */
final class AgencyKpis extends TeamRole
{
    /** Los estados en que el pedido está en manos de la agencia. */
    public const STAGE = [
        OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::EN_RUTA,
        OrderStatus::NOVEDADES, OrderStatus::NOVEDAD_SOLUCIONADA,
    ];

    public const METRICS = [
        'received' => [
            'label' => 'Recibidos',
            'format' => 'count',
            'better' => 'none',
            'help' => 'Pedidos que recibió en este período: los que pasaron a "Asignar a agencia" con esta agencia, o que le pasaron de otra agencia. Cada pedido cuenta una vez.',
        ],
        'effectiveness' => [
            'label' => 'Efectividad',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De los pedidos que recibió en este período, cuántos entregó, aunque los haya entregado después. Si un pedido pasó por dos agencias, a la primera le cuenta como no entregado. Puede subir en los próximos días.',
        ],
        'delivery_time' => [
            'label' => 'Tiempo de entrega',
            'format' => 'hours',
            'better' => 'lower',
            'card' => true,
            'help' => 'El tiempo normal (la mitad tardó menos) desde que recibe el pedido hasta que lo entrega.',
        ],
        'novelty_rate' => [
            'label' => 'Con novedad',
            'format' => 'percent',
            'better' => 'lower',
            'matures' => true,
            'help' => 'De los pedidos que recibió, cuántos pasaron a "Novedades" mientras los tenía (el cliente no atendió, cambió la dirección o el pago…).',
        ],
        'rejection_rate' => [
            'label' => 'Rechazados en puerta',
            'format' => 'percent',
            'better' => 'lower',
            'matures' => true,
            'help' => 'De los pedidos que recibió, cuántos rechazó el cliente cuando la agencia llegó.',
        ],
        'not_delivered' => [
            'label' => 'Sin entregar',
            'format' => 'count',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Pedidos que recibió y ya no tiene, sin haberlos entregado: se cancelaron, se rechazaron, volvieron a ventas o pasaron a otra agencia.',
        ],
        'cost_per_delivery' => [
            'label' => 'Costo de envío por entrega',
            'format' => 'money',
            'better' => 'lower',
            'card' => true,
            'help' => 'Lo que se le paga por todas las carreras de los pedidos que recibió (también los intentos que no se entregaron) ÷ los que entregó. Es lo que de verdad cuesta cada entrega con esta agencia.',
        ],
        'cash' => [
            'label' => 'Dinero que te debe',
            'format' => 'money',
            'better' => 'lower',
            'now' => true,
            'card' => true,
            'note' => 'Ahora mismo',
            'help' => 'Lo que cobró de pedidos entregados y todavía no te ha entregado (Finanzas › Caja, "Efectivo pendiente"). Es lo de ahora mismo: no depende del período ni de los filtros.',
        ],
        'closures_on_time' => [
            'label' => 'Cierres a tiempo',
            'format' => 'percent',
            'better' => 'higher',
            'help' => 'De sus cierres semanales que vencían en este período, cuántos subió antes del día y la hora del aviso (Cierres de agencias). No depende de los filtros.',
        ],
        'closures_with_diff' => [
            'label' => 'Cierres con diferencias',
            'format' => 'count',
            'better' => 'lower',
            'help' => 'De esos cierres, cuántos tuvieron diferencias: carreras que no cuadraron con el sistema, o un total cobrado por la agencia fuera de su tolerancia.',
        ],
    ];

    public function key(): string
    {
        return 'agencies';
    }

    public function label(): string
    {
        return 'Agencias';
    }

    public function systemRoles(): array
    {
        return ['Agencia'];
    }

    public function help(): string
    {
        return 'Cada agencia con los pedidos que recibió en el período: qué tanto entrega, cuánto tarda, cuánto cuesta cada entrega, el dinero que te debe y si cierra a tiempo. Al hacer clic en una agencia se ve cómo le va en cada ciudad.';
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
        return ['key' => 'received', 'min' => 10, 'label' => 'pedidos recibidos'];
    }

    public function facts(TeamReader $reader, Period $period, Filters $filters): mixed
    {
        return [
            'journeys' => $reader->agencyJourneys($period, $filters, self::STAGE),
            'people' => $reader->people($this->systemRoles()),
            'cash' => $reader->agencyCash(),
            'closures' => $reader->agencyClosures($period),
        ];
    }

    /**
     * facts: journeys (un pedido: agency_id y status de hoy, delivered_at, city, trips (agencia => USD de sus carreras)
     * y events en orden: at, kind status|agency, status, changed, agency, old), people, cash (agencia => USD) y closures
     * (agency_id, on_time true|false, differences).
     */
    public function compute(mixed $facts, Period $period): array
    {
        $people = $facts['people'];
        $agencies = [];
        $total = self::emptyRaw();
        foreach ($facts['journeys'] as $journey) {
            $j = (array) $journey;
            [$received, $lastEntry, $novelty] = self::walk($j, $period, $people);
            if ($received === []) {
                continue;
            }
            $now = $j['agency_id'] !== null ? (int) $j['agency_id'] : null;
            foreach ($received as $a) {
                $agencies[$a] ??= self::emptyRaw();
                self::add($agencies[$a], $j, $now === $a, $lastEntry[$a] ?? null, isset($novelty[$a]), (float) ($j['trips'][$a] ?? 0));
            }
            $mine = $now !== null && in_array($now, $received, true);
            $cost = array_sum(array_intersect_key($j['trips'], array_flip($received)));
            self::add($total, $j, $mine, $mine ? ($lastEntry[$now] ?? null) : null, array_intersect_key($novelty, array_flip($received)) !== [], (float) $cost);
        }

        foreach ($facts['closures'] as $c) {
            $a = (int) $c['agency_id'];
            if (!isset($people[$a])) {
                continue;
            }
            $agencies[$a] ??= self::emptyRaw();
            foreach (['closures' => true, 'closures_on_time' => $c['on_time'], 'closures_with_diff' => $c['differences'] > 0] as $key => $yes) {
                $agencies[$a][$key] += $yes ? 1 : 0;
                $total[$key] += $yes ? 1 : 0;
            }
        }
        foreach ($facts['cash'] as $a => $usd) {
            if (isset($people[$a]) && abs($usd) > 0.005) {
                $agencies[$a] ??= self::emptyRaw();
            }
        }

        $out = [];
        foreach ($agencies as $id => $raw) {
            $raw['cash'] = (float) ($facts['cash'][$id] ?? 0);
            $out[$id] = ['name' => $people[$id]['name'] ?? "Agencia {$id}", 'group' => $people[$id]['group'] ?? null] + self::values($raw);
        }
        $total['cash'] = array_sum(array_intersect_key($facts['cash'], $people));

        return [
            'people' => $out,
            'totals' => self::values($total),
            'cities' => array_map(fn ($raw) => $raw['cities'], $agencies),
        ];
    }

    public function blocks(int $id, array $computed, array $before = []): array
    {
        $cities = $computed['cities'][$id] ?? [];
        uasort($cities, fn ($a, $b) => $b['received'] <=> $a['received']);

        return [[
            'type' => 'bars',
            'title' => 'Por ciudad',
            'subtitle' => 'Cuántos entregó de lo que recibió en cada ciudad.',
            'help' => 'La efectividad de la agencia separada por la ciudad del pedido, con las mismas reglas de arriba.',
            'rows' => array_values(array_map(fn ($name, $c) => ['label' => $name, 'count' => $c['delivered'], 'of' => $c['received']], array_keys($cities), $cities)),
            'empty' => 'No recibió pedidos en este período.',
        ]];
    }

    /**
     * El recorrido del pedido entre agencias: qué agencias lo recibieron en el período, la última vez que lo recibió
     * cada una y cuáles tuvieron una novedad mientras lo tenían. La agencia de antes del primer cambio registrado es la
     * "old" de ese cambio; sin cambios, la de hoy.
     */
    private static function walk(array $j, Period $period, array $people): array
    {
        $events = $j['events'];
        $firstChange = null;
        foreach ($events as $e) {
            if ($e['kind'] === 'agency') {
                $firstChange = $e;
                break;
            }
        }
        $agency = $firstChange ? ($firstChange['old'] !== null ? (int) $firstChange['old'] : null) : ($j['agency_id'] !== null ? (int) $j['agency_id'] : null);
        $status = null;
        $holder = null;
        $received = [];
        $lastEntry = [];
        $novelty = [];
        $enter = function (int $a, string $at) use (&$holder, &$received, &$lastEntry, $period, $people) {
            if (!isset($people[$a])) {
                return;
            }
            $holder = $a;
            $lastEntry[$a] = $at;
            if (self::inPeriod($at, $period)) {
                $received[$a] = true;
            }
        };

        foreach ($events as $e) {
            if ($e['kind'] === 'agency') {
                $agency = $e['agency'] !== null ? (int) $e['agency'] : null;
                if ($agency !== null && in_array($status, self::STAGE, true)) {
                    $enter($agency, $e['at']);
                }
                continue;
            }
            if (!$e['changed']) {
                continue;
            }
            $status = $e['status'];
            if ($status === OrderStatus::ASIGNAR_A_AGENCIA && $agency !== null) {
                $enter($agency, $e['at']);
            } elseif ($status === OrderStatus::NOVEDADES && $holder !== null && $holder === $agency) {
                $novelty[$holder] = true;
            }
        }

        return [array_keys($received), $lastEntry, $novelty];
    }

    private static function emptyRaw(): array
    {
        return ['received' => 0, 'delivered' => 0, 'open' => 0, 'rejected' => 0, 'novelty' => 0, 'trips' => 0.0, 'times' => [],
            'cash' => 0.0, 'closures' => 0, 'closures_on_time' => 0, 'closures_with_diff' => 0, 'cities' => []];
    }

    /** Suma un pedido a una agencia o al total ("mine": la agencia que lo tiene hoy lo recibió). */
    private static function add(array &$raw, array $j, bool $mine, ?string $receivedAt, bool $novelty, float $trips): void
    {
        $raw['received']++;
        $raw['trips'] += $trips;
        $raw['novelty'] += $novelty ? 1 : 0;
        $city = $j['city'] ?? 'Sin ciudad';
        $raw['cities'][$city] ??= ['received' => 0, 'delivered' => 0];
        $raw['cities'][$city]['received']++;
        if (!$mine) {
            return;
        }
        if ($j['status'] === OrderStatus::ENTREGADO) {
            $raw['delivered']++;
            $raw['cities'][$city]['delivered']++;
            $hours = self::hoursBetween($receivedAt, $j['delivered_at'] ?? null);
            if ($hours !== null) {
                $raw['times'][] = $hours;
            }
        } elseif ($j['status'] === OrderStatus::RECHAZADO) {
            $raw['rejected']++;
        } elseif (in_array($j['status'], self::STAGE, true)) {
            $raw['open']++;
        }
    }

    private static function values(array $r): array
    {
        $notDelivered = $r['received'] - $r['delivered'] - $r['open'];

        return [
            'values' => [
                'received' => $r['received'],
                'effectiveness' => self::pct($r['delivered'], $r['received']),
                'delivery_time' => self::median($r['times']),
                'novelty_rate' => self::pct($r['novelty'], $r['received']),
                'rejection_rate' => self::pct($r['rejected'], $r['received']),
                'not_delivered' => $notDelivered,
                'cost_per_delivery' => self::ratio($r['trips'], $r['delivered']),
                'cash' => round($r['cash'], 2),
                'closures_on_time' => self::pct($r['closures_on_time'], $r['closures']),
                'closures_with_diff' => $r['closures'] > 0 ? $r['closures_with_diff'] : null,
            ],
            'details' => [
                'effectiveness' => number_format($r['delivered'], 0, ',', '.') . ' de ' . number_format($r['received'], 0, ',', '.') . ' entregados'
                    . ($r['open'] > 0 ? ' · ' . self::plural($r['open'], 'sigue', 'siguen') . ' en reparto' : ''),
                'delivery_time' => 'De ' . self::plural(count($r['times']), 'entrega', 'entregas'),
                'novelty_rate' => self::plural($r['novelty'], 'pedido', 'pedidos'),
                'rejection_rate' => self::plural($r['rejected'], 'rechazo', 'rechazos'),
                'cost_per_delivery' => Card::money($r['trips']) . ' en carreras',
                'closures_on_time' => $r['closures'] > 0 ? "{$r['closures_on_time']} de {$r['closures']} cierres" : 'Sin cierres vencidos',
            ],
        ];
    }
}
