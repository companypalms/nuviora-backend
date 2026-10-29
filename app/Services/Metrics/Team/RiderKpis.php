<?php

namespace App\Services\Metrics\Team;

use App\Constants\OrderStatus;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;

/**
 * Repartidores (diccionario de métricas, §3 "Efectividad de cada repartidor propio" y §4 "Repartidores propios"): los
 * de la logística propia y los de las agencias. PHP puro.
 *
 * Las reglas (las mismas de la agencia):
 * - Un repartidor "recibe" un pedido cuando se lo asignan: el operador o la agencia lo eligen, o él confirma la oferta
 *   en la app. Cuenta el día en que lo recibió.
 * - Entregado le cuenta al que lo tenía cuando se entregó, aunque lo entregue después del período.
 * - Lo que se le ofrece y acepta (rondas de la app) y el dinero en mano son solo de la logística propia.
 */
final class RiderKpis extends TeamRole
{
    /** Los estados en que el pedido está con el repartidor. */
    public const STAGE = [
        OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::EN_RUTA, OrderStatus::NOVEDADES,
        OrderStatus::NOVEDAD_SOLUCIONADA,
    ];

    public const METRICS = [
        'received' => [
            'label' => 'Pedidos recibidos',
            'format' => 'count',
            'better' => 'none',
            'help' => 'Pedidos que le asignaron en este período (el operador o la agencia, o él al confirmar la oferta en la app). Cada pedido cuenta una vez.',
        ],
        'delivered' => [
            'label' => 'Entregas',
            'format' => 'count',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De los pedidos que recibió en este período, los que entregó él, aunque los haya entregado después.',
        ],
        'effectiveness' => [
            'label' => 'Efectividad',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De los pedidos que recibió en este período, cuántos entregó. Si un pedido pasó por dos repartidores, la entrega le cuenta al que lo tenía al entregarse.',
        ],
        'delivery_time' => [
            'label' => 'Tiempo de entrega',
            'format' => 'hours',
            'better' => 'lower',
            'card' => true,
            'help' => 'El tiempo normal (la mitad tardó menos) desde que recibe el pedido (lo acepta o se lo asignan) hasta que lo entrega.',
        ],
        'acceptance' => [
            'label' => 'Acepta lo que se le ofrece',
            'format' => 'percent',
            'better' => 'higher',
            'help' => 'Solo logística propia: de los pedidos que la app le ofreció en este período, cuántos confirmó. No cuentan los que tomó otro rider primero ni los que se cancelaron.',
        ],
        'not_delivered' => [
            'label' => 'Sin entregar',
            'format' => 'count',
            'better' => 'lower',
            'matures' => true,
            'help' => 'Pedidos que recibió y ya no tiene, sin haberlos entregado: rechazados, cancelados o pasados a otro repartidor.',
        ],
        'earnings' => [
            'label' => 'Ganancias',
            'format' => 'money',
            'better' => 'higher',
            'matures' => true,
            'help' => 'Lo que ganó con los pedidos que recibió en este período: su comisión por entrega y lo que se le paga por las carreras que no se entregaron.',
        ],
        'cash' => [
            'label' => 'Dinero en mano',
            'format' => 'money',
            'better' => 'lower',
            'now' => true,
            'card' => true,
            'note' => 'Ahora mismo',
            'help' => 'Solo logística propia: el efectivo que cobró y todavía no entregó en el centro. Es lo de ahora mismo: no depende del período ni de los filtros.',
        ],
    ];

    public function key(): string
    {
        return 'riders';
    }

    public function label(): string
    {
        return 'Repartidores';
    }

    public function systemRoles(): array
    {
        return ['Repartidor'];
    }

    public function help(): string
    {
        return 'Cada repartidor con los pedidos que le asignaron en el período: los de la logística propia y los de las agencias (debajo de su nombre dice de dónde es).';
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
        $people = $reader->people($this->systemRoles());

        return [
            'journeys' => $reader->riderJourneys($period, $filters),
            'people' => $people,
            'offers' => $reader->riderOffers($period),
            'cash' => $reader->riderCash(array_keys($people)),
        ];
    }

    /**
     * facts: journeys (un pedido: deliverer_id y status de hoy, delivered_at, earning (lo ganado por el repartidor de
     * hoy) y events en orden: at, deliverer), people, offers (repartidor => sent, confirmed) y cash (repartidor => USD).
     */
    public function compute(mixed $facts, Period $period): array
    {
        $people = $facts['people'];
        $riders = [];
        $total = self::emptyRaw();
        foreach ($facts['journeys'] as $journey) {
            $j = (array) $journey;
            $received = [];
            $lastEntry = [];
            foreach ($j['events'] as $e) {
                $d = $e['deliverer'] !== null ? (int) $e['deliverer'] : null;
                if ($d === null || !isset($people[$d])) {
                    continue;
                }
                $lastEntry[$d] = $e['at'];
                if (self::inPeriod($e['at'], $period)) {
                    $received[$d] = true;
                }
            }
            if ($received === []) {
                continue;
            }
            $now = $j['deliverer_id'] !== null ? (int) $j['deliverer_id'] : null;
            foreach (array_keys($received) as $d) {
                $riders[$d] ??= self::emptyRaw();
                self::add($riders[$d], $j, $now === $d, $lastEntry[$d]);
            }
            $mine = $now !== null && isset($received[$now]);
            self::add($total, $j, $mine, $mine ? $lastEntry[$now] : null);
        }

        foreach ($facts['offers'] as $d => $o) {
            if (!isset($people[$d])) {
                continue;
            }
            $riders[$d] ??= self::emptyRaw();
            foreach (['sent', 'confirmed'] as $key) {
                $riders[$d]["offers_{$key}"] += (int) $o[$key];
                $total["offers_{$key}"] += (int) $o[$key];
            }
        }
        foreach ($facts['cash'] as $d => $usd) {
            if (isset($people[$d]) && abs($usd) > 0.005) {
                $riders[$d] ??= self::emptyRaw();
            }
        }

        $out = [];
        foreach ($riders as $id => $raw) {
            $raw['cash'] = (float) ($facts['cash'][$id] ?? 0);
            $out[$id] = ['name' => $people[$id]['name'] ?? "Repartidor {$id}", 'group' => $people[$id]['group'] ?? null] + self::values($raw);
        }
        $total['cash'] = array_sum(array_intersect_key($facts['cash'], $people));

        return ['people' => $out, 'totals' => self::values($total)];
    }

    private static function emptyRaw(): array
    {
        return ['received' => 0, 'delivered' => 0, 'open' => 0, 'earnings' => 0.0, 'times' => [], 'offers_sent' => 0, 'offers_confirmed' => 0, 'cash' => 0.0];
    }

    private static function add(array &$raw, array $j, bool $mine, ?string $receivedAt): void
    {
        $raw['received']++;
        if (!$mine) {
            return;
        }
        $raw['earnings'] += (float) $j['earning'];
        if ($j['status'] === OrderStatus::ENTREGADO) {
            $raw['delivered']++;
            $hours = self::hoursBetween($receivedAt, $j['delivered_at'] ?? null);
            if ($hours !== null) {
                $raw['times'][] = $hours;
            }
        } elseif (in_array($j['status'], self::STAGE, true)) {
            $raw['open']++;
        }
    }

    private static function values(array $r): array
    {
        return [
            'values' => [
                'received' => $r['received'],
                'delivered' => $r['delivered'],
                'effectiveness' => self::pct($r['delivered'], $r['received']),
                'delivery_time' => self::median($r['times']),
                'acceptance' => self::pct($r['offers_confirmed'], $r['offers_sent']),
                'not_delivered' => $r['received'] - $r['delivered'] - $r['open'],
                'earnings' => round($r['earnings'], 2),
                'cash' => round($r['cash'], 2),
            ],
            'details' => [
                'delivered' => 'De ' . self::plural($r['received'], 'pedido recibido', 'pedidos recibidos'),
                'effectiveness' => number_format($r['delivered'], 0, ',', '.') . ' de ' . number_format($r['received'], 0, ',', '.') . ' entregados'
                    . ($r['open'] > 0 ? ' · ' . self::plural($r['open'], 'sigue', 'siguen') . ' en reparto' : ''),
                'delivery_time' => 'De ' . self::plural(count($r['times']), 'entrega', 'entregas'),
                'acceptance' => $r['offers_sent'] > 0 ? "{$r['offers_confirmed']} de {$r['offers_sent']} ofertas" : 'Sin ofertas de la app',
            ],
        ];
    }
}
