<?php

namespace App\Services\Metrics\Team;

use App\Models\LogisticsShift;
use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;

/**
 * Operadoras logísticas de la logística propia (aprobado por el dueño el 29/10/2026). PHP puro. Sale de lo que la torre
 * de control ya guarda: los turnos, quién hizo cada paso de cada pedido (preparar, confirmar la salida, pasar a
 * agencia, asignar a mano), las novedades, las alertas y las entregas de efectivo de los riders.
 *
 * Las reglas:
 * - Cada acción cuenta el día en que se hizo y es de quien la hizo.
 * - "En su turno": mientras ella tenía un turno abierto en ese centro (si había dos operadoras a la vez, cuenta para
 *   las dos).
 * - A tiempo: entregado dentro de la meta del centro (por defecto 45 minutos) desde que arrancó el reloj del pedido.
 */
final class OperatorKpis extends TeamRole
{
    public const METRICS = [
        'shifts' => [
            'label' => 'Turnos',
            'format' => 'count',
            'better' => 'none',
            'help' => 'Turnos que abrió en este período.',
        ],
        'shift_hours' => [
            'label' => 'Horas de turno',
            'format' => 'hours_total',
            'better' => 'none',
            'help' => 'Horas que estuvo con el turno abierto (si el turno sigue abierto, hasta ahora).',
        ],
        'clean_close' => [
            'label' => 'Turnos cerrados limpios',
            'format' => 'percent',
            'better' => 'higher',
            'help' => 'De los turnos que cerró, cuántos cerró sin pedidos pendientes (sin tener que dejar un traspaso con motivo).',
        ],
        'dispatched' => [
            'label' => 'Pedidos despachados',
            'format' => 'count',
            'better' => 'higher',
            'card' => true,
            'help' => 'Pedidos a los que ella les confirmó la salida con el rider (o se los entregó a la agencia de respaldo) en este período.',
        ],
        'prep_time' => [
            'label' => 'Tiempo de preparación',
            'format' => 'hours',
            'better' => 'lower',
            'card' => true,
            'help' => 'El tiempo normal (la mitad tardó menos) desde que el rider confirma el pedido hasta que ella lo marca listo para despacho.',
        ],
        'on_time' => [
            'label' => 'Entregados a tiempo',
            'format' => 'percent',
            'better' => 'higher',
            'matures' => true,
            'card' => true,
            'help' => 'De los pedidos que despachó y ya se entregaron, cuántos llegaron dentro de la meta del centro (por defecto 45 minutos) desde que arrancó el reloj del pedido.',
        ],
        'agency_rate' => [
            'label' => 'A agencia en su turno',
            'format' => 'percent',
            'better' => 'lower',
            'card' => true,
            'help' => 'De los pedidos que entraron al centro mientras ella estaba de turno, cuántos terminaron yendo a la agencia de respaldo (porque no hubo rider propio a tiempo o porque se pasaron a mano).',
        ],
        'forced' => [
            'label' => 'Pasados a agencia por ella',
            'format' => 'count',
            'better' => 'lower',
            'help' => 'Pedidos que ella pasó a la agencia de respaldo a mano, antes de que el sistema lo hiciera solo en el minuto 5.',
        ],
        'novelty_resolved' => [
            'label' => 'Novedades resueltas por ella',
            'format' => 'percent',
            'better' => 'higher',
            'help' => 'De las novedades de los riders que atendió, cuántas resolvió ella sin tener que pasarlas a ventas.',
        ],
        'novelty_time' => [
            'label' => 'Tiempo para resolver novedades',
            'format' => 'hours',
            'better' => 'lower',
            'help' => 'El tiempo normal desde que el rider reporta la novedad hasta que ella la resuelve o la pasa a ventas.',
        ],
        'alerts' => [
            'label' => 'Alertas atendidas',
            'format' => 'count',
            'better' => 'none',
            'help' => 'Alertas de la torre de control que ella cerró en este período.',
        ],
        'alert_time' => [
            'label' => 'Tiempo de respuesta a alertas',
            'format' => 'hours',
            'better' => 'lower',
            'help' => 'El tiempo normal desde que salta una alerta hasta que ella la cierra.',
        ],
        'cash_diff' => [
            'label' => 'Diferencias de caja',
            'format' => 'count',
            'better' => 'lower',
            'help' => 'Entregas de efectivo de los riders que ella recibió y que no cuadraron con lo que decía el sistema.',
        ],
        'manual' => [
            'label' => 'Asignaciones a mano',
            'format' => 'count',
            'better' => 'none',
            'flag' => true,
            'help' => 'Pedidos que ella le asignó a un rider a mano, sin ronda. No está mal en sí: se marca en amarillo si tiene el doble del promedio o más, porque una pareja operadora-rider que se sale del promedio es una señal a revisar.',
        ],
    ];

    public function key(): string
    {
        return 'operators';
    }

    public function label(): string
    {
        return 'Operadoras logísticas';
    }

    public function systemRoles(): array
    {
        return ['Operador logístico'];
    }

    public function help(): string
    {
        return 'Cada operadora de la logística propia con lo que hizo en el período: sus turnos, cuánto tarda en preparar, si los pedidos que despacha llegan a tiempo, cuántos se van a la agencia, las novedades, las alertas y la caja.';
    }

    public function filtersNote(): ?string
    {
        return 'Los filtros de tienda y producto no se aplican aquí: la operadora trabaja con todos los pedidos de su centro. El de ciudad, sí (la ciudad del centro).';
    }

    public function metrics(): array
    {
        return self::METRICS;
    }

    public function rankBy(): string
    {
        return 'on_time';
    }

    public function base(): array
    {
        return ['key' => 'dispatched', 'min' => 10, 'label' => 'pedidos despachados'];
    }

    public function facts(TeamReader $reader, Period $period, Filters $filters): mixed
    {
        return $reader->operatorFacts($period, $filters) + ['people' => $reader->people($this->systemRoles())];
    }

    /**
     * facts: people; shifts (user, center, started_at, ended_at, close_type) que se cruzan con el período; events (actor,
     * action, at, center, rider_confirmed_at, sla_started_at, delivered_at, target) de las acciones del período;
     * released (center, at): los pedidos que entraron al centro en el período; novelties (resolved_by, escalated_by,
     * opened_at, done_at); alerts (resolved_by, created_at, resolved_at); handovers (actor, has_difference); now.
     */
    public function compute(mixed $facts, Period $period): array
    {
        $people = $facts['people'];
        $raw = [];
        $total = self::emptyRaw();
        $get = function (?int $id) use (&$raw, $people): ?int {
            if ($id === null || !isset($people[$id])) {
                return null;
            }
            $raw[$id] ??= self::emptyRaw();

            return $id;
        };
        $bump = function (?int $id, string $key, int|float $n = 1) use (&$raw, &$total, $get) {
            if (($id = $get($id)) !== null) {
                $raw[$id][$key] += $n;
                $total[$key] += $n;
            }
        };
        $push = function (?int $id, string $key, ?float $v) use (&$raw, &$total, $get) {
            if ($v !== null && ($id = $get($id)) !== null) {
                $raw[$id][$key][] = $v;
                $total[$key][] = $v;
            }
        };

        // Turnos: los que abrió en el período
        $shifts = [];
        foreach ($facts['shifts'] as $s) {
            $user = (int) $s['user'];
            $shifts[] = ['user' => $user, 'center' => (int) $s['center'], 'from' => $s['started_at'], 'to' => $s['ended_at'] ?? $facts['now']];
            if (!self::inPeriod($s['started_at'], $period)) {
                continue;
            }
            $bump($user, 'shifts');
            $bump($user, 'shift_hours', self::hoursBetween($s['started_at'], $s['ended_at'] ?? $facts['now']) ?? 0);
            if ($s['ended_at'] !== null) {
                $bump($user, 'closed');
                $bump($user, 'clean', $s['close_type'] === LogisticsShift::CLOSE_CLEAN ? 1 : 0);
            }
        }
        $onShift = fn (int $center, string $at) => array_values(array_unique(array_map(fn ($s) => $s['user'],
            array_filter($shifts, fn ($s) => $s['center'] === $center && $at >= $s['from'] && $at <= $s['to']))));

        foreach ($facts['events'] as $e) {
            $actor = $e['actor'] !== null ? (int) $e['actor'] : null;
            switch ($e['action']) {
                case 'salida':
                case 'entregada_a_agencia':
                    $bump($actor, 'dispatched');
                    if ($e['delivered_at'] !== null && $e['sla_started_at'] !== null) {
                        $minutes = self::hoursBetween($e['sla_started_at'], $e['delivered_at']) * 60;
                        $bump($actor, 'delivered');
                        $bump($actor, 'on_time', $minutes <= (int) $e['target'] ? 1 : 0);
                    }
                    break;
                case 'listo_despacho':
                    $push($actor, 'prep', self::hoursBetween($e['rider_confirmed_at'], $e['at']));
                    break;
                case 'a_agencia':
                    // A la agencia: le cuenta a quien estaba de turno; si lo pasó ella a mano, también a "pasados por ella"
                    foreach ($onShift((int) $e['center'], $e['at']) as $user) {
                        if ($get($user) !== null) {
                            $raw[$user]['to_agency']++;
                        }
                    }
                    $total['to_agency']++;
                    if ($actor !== null) {
                        $bump($actor, 'forced');
                    }
                    break;
                case 'asignada_a_mano':
                    $bump($actor, 'manual');
                    break;
            }
        }
        foreach ($facts['released'] as $r) {
            foreach ($onShift((int) $r['center'], $r['at']) as $user) {
                if ($get($user) !== null) {
                    $raw[$user]['released']++;
                }
            }
            $total['released']++;
        }
        foreach ($facts['novelties'] as $n) {
            $escalated = $n['escalated_by'] !== null;
            $by = $escalated ? (int) $n['escalated_by'] : ($n['resolved_by'] !== null ? (int) $n['resolved_by'] : null);
            $bump($by, $escalated ? 'escalated' : 'resolved');
            $push($by, 'novelty_hours', self::hoursBetween($n['opened_at'], $n['done_at']));
        }
        foreach ($facts['alerts'] as $a) {
            $by = $a['resolved_by'] !== null ? (int) $a['resolved_by'] : null;
            $bump($by, 'alerts');
            $push($by, 'alert_hours', self::hoursBetween($a['created_at'], $a['resolved_at']));
        }
        foreach ($facts['handovers'] as $h) {
            $by = $h['actor'] !== null ? (int) $h['actor'] : null;
            $bump($by, 'handovers');
            $bump($by, 'cash_diff', $h['has_difference'] ? 1 : 0);
        }

        $out = [];
        foreach ($raw as $id => $r) {
            $out[$id] = ['name' => $people[$id]['name'] ?? "Operadora {$id}", 'group' => $people[$id]['group'] ?? null] + self::values($r);
        }

        return ['people' => $out, 'totals' => self::values($total)];
    }

    private static function emptyRaw(): array
    {
        return ['shifts' => 0, 'shift_hours' => 0.0, 'closed' => 0, 'clean' => 0, 'dispatched' => 0, 'delivered' => 0, 'on_time' => 0,
            'prep' => [], 'to_agency' => 0, 'released' => 0, 'forced' => 0, 'manual' => 0, 'resolved' => 0, 'escalated' => 0,
            'novelty_hours' => [], 'alerts' => 0, 'alert_hours' => [], 'handovers' => 0, 'cash_diff' => 0];
    }

    private static function values(array $r): array
    {
        return [
            'values' => [
                'shifts' => $r['shifts'],
                'shift_hours' => round($r['shift_hours'], 1),
                'clean_close' => self::pct($r['clean'], $r['closed']),
                'dispatched' => $r['dispatched'],
                'prep_time' => self::median($r['prep']),
                'on_time' => self::pct($r['on_time'], $r['delivered']),
                'agency_rate' => self::pct($r['to_agency'], $r['released']),
                'forced' => $r['forced'],
                'novelty_resolved' => self::pct($r['resolved'], $r['resolved'] + $r['escalated']),
                'novelty_time' => self::median($r['novelty_hours']),
                'alerts' => $r['alerts'],
                'alert_time' => self::median($r['alert_hours']),
                'cash_diff' => $r['cash_diff'],
                'manual' => $r['manual'],
            ],
            'details' => [
                'clean_close' => $r['closed'] > 0 ? "{$r['clean']} de {$r['closed']} turnos cerrados" : 'Sin turnos cerrados',
                'prep_time' => 'De ' . self::plural(count($r['prep']), 'pedido preparado', 'pedidos preparados'),
                'on_time' => "{$r['on_time']} de " . self::plural($r['delivered'], 'entregado', 'entregados'),
                'agency_rate' => "{$r['to_agency']} de " . self::plural($r['released'], 'pedido', 'pedidos'),
                'novelty_resolved' => "{$r['resolved']} de " . ($r['resolved'] + $r['escalated']) . ' novedades',
                'cash_diff' => 'De ' . self::plural($r['handovers'], 'entrega de efectivo', 'entregas de efectivo'),
            ],
        ];
    }
}
