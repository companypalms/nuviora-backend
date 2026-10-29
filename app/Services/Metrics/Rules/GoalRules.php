<?php

namespace App\Services\Metrics\Rules;

use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;

/**
 * Metas y alertas del panel del dueño (diccionario de métricas, §6): qué metas y límites puede poner el dueño, con la
 * explicación que ve, y cómo se comparan los números con ellos. PHP puro. Lo que eligió el dueño (2026-10-29):
 * - Las metas del negocio (ventas, ganancia y efectividad) son de cada mes del calendario: las de octubre, las de
 *   noviembre… Un mes nuevo empieza sin metas (el Dashboard avisa) y se pueden dejar listas las del mes siguiente. Para
 *   otro período se reparten por día con la meta del mes de cada día (un día de un mes de 30 días es 1/30); si algún mes
 *   del período no tiene meta, no se muestra. "Este mes" se compara con la meta del mes entero y dice si se va al ritmo.
 * - Las de vendedoras y agencias y los límites de las alertas no son por mes: quedan hasta que el dueño los cambie.
 * - Vendedoras: efectividad mínima. Agencias: efectividad mínima, tiempo de entrega máximo y rechazos en puerta máximo.
 *   Una meta para todas y, si hace falta, excepciones por persona.
 * - Cada alerta tiene dos límites: amarillo y rojo. En las metas de personas el amarillo es la meta; en una excepción,
 *   el rojo queda a la misma distancia de su meta que en la general.
 * - Solo las ve y las pone el Admin; más adelante se verá si se muestran a cada rol.
 */
final class GoalRules
{
    /** Dónde se guardan (la tabla settings, la misma de la tasa del dólar). */
    public const SETTING = 'owner_panel_goals';

    public const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Metas del negocio. "card": la tarjeta del panel que se compara con la meta. */
    public const BUSINESS = [
        'sales' => [
            'label' => 'Ventas del mes',
            'format' => 'money',
            'better' => 'higher',
            'card' => 'revenue',
            'monthly' => true,
            'help' => 'Cuánto quieres vender en el mes, en dólares: el "Monto vendido" de Ventas (pedidos entregados, por el día en que se crearon). Para otros períodos se reparte por día: un día de un mes de 30 días es 1/30 de la meta. Es del negocio entero: con filtros de tienda, ciudad o producto no se muestra.',
        ],
        'profit' => [
            'label' => 'Ganancia neta del mes',
            'format' => 'money',
            'better' => 'higher',
            'card' => 'profit',
            'monthly' => true,
            'help' => 'Cuánto quieres ganar en el mes después de todos los costos y gastos: la "Ganancia neta (ya ganado)" de Dinero. Se reparte por día igual que las ventas. Con filtros no se muestra.',
        ],
        'effectiveness' => [
            'label' => 'Efectividad general mínima',
            'format' => 'percent',
            'better' => 'higher',
            'card' => 'effectiveness',
            'help' => 'El % de los pedidos creados que quieres que terminen entregados: la "Efectividad general" de Ventas. Con filtros no se muestra.',
        ],
    ];

    /** Metas de cada persona de un rol de Equipo: "metric" es el KPI del rol (Metrics\Team). */
    public const TEAM = [
        'sellers.effectiveness' => [
            'role' => 'sellers',
            'metric' => 'effectiveness',
            'label' => 'Efectividad mínima de cada vendedora',
            'format' => 'percent',
            'better' => 'higher',
            'title' => 'Vendedoras con efectividad baja',
            'help' => 'De los pedidos que le entran a cada vendedora, el % que quieres que termine entregado como mínimo (la "Efectividad" de Equipo). Por debajo sale en amarillo; por debajo del rojo, en rojo.',
        ],
        'agencies.effectiveness' => [
            'role' => 'agencies',
            'metric' => 'effectiveness',
            'label' => 'Efectividad mínima de cada agencia',
            'format' => 'percent',
            'better' => 'higher',
            'title' => 'Agencias con efectividad baja',
            'help' => 'De los pedidos que recibe cada agencia, el % que quieres que entregue como mínimo (la "Efectividad" de Equipo › Agencias).',
        ],
        'agencies.delivery_time' => [
            'role' => 'agencies',
            'metric' => 'delivery_time',
            'label' => 'Tiempo de entrega máximo de cada agencia',
            'format' => 'hours',
            'better' => 'lower',
            'title' => 'Agencias que tardan en entregar',
            'help' => 'Cuánto puede tardar como máximo una agencia, en horas, desde que recibe el pedido hasta que lo entrega (el tiempo normal: la mitad tardó menos). Por encima sale en amarillo; por encima del rojo, en rojo.',
        ],
        'agencies.rejection_rate' => [
            'role' => 'agencies',
            'metric' => 'rejection_rate',
            'label' => 'Rechazos en puerta máximo de cada agencia',
            'format' => 'percent',
            'better' => 'lower',
            'title' => 'Agencias con muchos rechazos en puerta',
            'help' => 'De los pedidos que recibe cada agencia, el % máximo que aceptas que el cliente rechace cuando llega la agencia.',
        ],
    ];

    /** Las demás alertas: amarillo y rojo cuando el número pasa de cada límite. */
    public const ALERTS = [
        'agency_cash' => [
            'label' => 'Dinero sin cerrar de una agencia',
            'format' => 'money',
            'better' => 'lower',
            'section' => 'team',
            'role' => 'agencies',
            'title' => 'Agencias con mucho dinero sin entregarte',
            'help' => 'Avisa cuando una agencia tiene más que esto de pedidos entregados y todavía no te lo ha entregado (Finanzas › Caja, el mismo "Por cobrar" de Dinero). Es lo de ahora mismo.',
        ],
        'rider_cash' => [
            'label' => 'Dinero en mano de un repartidor propio',
            'format' => 'money',
            'better' => 'lower',
            'section' => 'team',
            'role' => 'riders',
            'title' => 'Repartidores con mucho dinero en mano',
            'help' => 'Avisa cuando un repartidor de la logística propia tiene más que esto en efectivo sin entregar (dólares y bolívares, en dólares). Es lo de ahora mismo.',
        ],
        'ad_cost' => [
            'label' => 'Costo por venta de la publicidad',
            'format' => 'money',
            'better' => 'lower',
            'section' => 'money',
            'role' => null,
            'title' => 'Publicidad cara',
            'help' => 'Avisa cuando cada venta te está costando más que esto en publicidad, mirando los últimos 3 días completos. Como esos pedidos todavía se están entregando, se cuentan los entregados más los que se espera entregar de los que siguen en camino (con el % que normalmente se entrega, igual que "En camino" de Dinero).',
        ],
        'cancellations' => [
            'label' => 'Cancelaciones de hoy',
            'format' => 'percent',
            'better' => 'lower',
            'section' => 'losses',
            'role' => null,
            'title' => 'Muchas cancelaciones hoy',
            'help' => 'Avisa cuando, de los pedidos que entraron hoy, ya se canceló más que este %. Solo avisa cuando ya hay al menos 10 pedidos en el día, para no asustarte temprano con 1 de 3.',
        ],
    ];

    /**
     * Lo guardado, completo y limpio: las metas del negocio de cada mes ("2026-10" => ventas, ganancia, efectividad; los
     * meses sin ninguna meta no se guardan), las de personas y los límites (lo que falta queda sin meta, null; las alertas,
     * prendidas).
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = ['months' => [], 'team' => [], 'alerts' => []];
        foreach (is_array($raw['months'] ?? null) ? $raw['months'] : [] as $month => $business) {
            $business = self::business($business);
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month) && array_filter($business, fn ($v) => $v !== null) !== []) {
                $out['months'][(string) $month] = $business;
            }
        }
        ksort($out['months']);
        foreach (array_keys(self::TEAM) as $key) {
            $g = is_array($raw['team'][$key] ?? null) ? $raw['team'][$key] : [];
            $people = [];
            foreach (is_array($g['people'] ?? null) ? $g['people'] : [] as $id => $value) {
                // Llega como lista [{id, value}] de la pantalla, o como id => valor de lo guardado
                [$id, $value] = is_array($value) ? [$value['id'] ?? 0, $value['value'] ?? null] : [$id, $value];
                if ((int) $id > 0 && self::number($value) !== null) {
                    $people[(int) $id] = self::number($value);
                }
            }
            $out['team'][$key] = [
                'value' => self::number($g['value'] ?? null),
                'red' => self::number($g['red'] ?? null),
                'alert' => (bool) ($g['alert'] ?? true),
                'people' => $people,
            ];
        }
        foreach (array_keys(self::ALERTS) as $key) {
            $a = is_array($raw['alerts'][$key] ?? null) ? $raw['alerts'][$key] : [];
            $out['alerts'][$key] = [
                'yellow' => self::number($a['yellow'] ?? null),
                'red' => self::number($a['red'] ?? null),
                'on' => (bool) ($a['on'] ?? true),
            ];
        }
        $out['updated_by'] = isset($raw['updated_by']) ? (string) $raw['updated_by'] : null;
        $out['updated_at'] = isset($raw['updated_at']) ? (string) $raw['updated_at'] : null;

        return $out;
    }

    /**
     * Lo que no tiene sentido, con un mensaje para el dueño (clave del campo => mensaje). Vacío si todo está bien.
     *
     * @return array<string, string>
     */
    public static function problems(array $config): array
    {
        $out = [];
        foreach ($config['months'] as $month => $business) {
            foreach (self::BUSINESS as $key => $def) {
                if ($msg = self::outOfRange($business[$key], $def['format'])) {
                    $out["months.{$month}.{$key}"] = $msg;
                }
            }
        }
        foreach (self::TEAM as $key => $def) {
            $g = $config['team'][$key];
            foreach (['value', 'red'] as $field) {
                if ($msg = self::outOfRange($g[$field], $def['format'])) {
                    $out["team.{$key}.{$field}"] = $msg;
                }
            }
            if ($g['value'] === null && ($g['red'] !== null || $g['people'] !== [])) {
                $out["team.{$key}.value"] = 'Pon primero la meta para todas; el rojo y las excepciones salen de ahí.';
            } elseif ($g['value'] !== null && $g['red'] !== null && !self::worse($g['red'], $g['value'], $def['better'])) {
                $out["team.{$key}.red"] = $def['better'] === 'lower' ? 'El rojo tiene que ser más alto que la meta.' : 'El rojo tiene que ser más bajo que la meta.';
            }
            foreach ($g['people'] as $id => $value) {
                if ($msg = self::outOfRange($value, $def['format'])) {
                    $out["team.{$key}.people.{$id}"] = $msg;
                }
            }
        }
        foreach (self::ALERTS as $key => $def) {
            $a = $config['alerts'][$key];
            foreach (['yellow', 'red'] as $field) {
                if ($msg = self::outOfRange($a[$field], $def['format'])) {
                    $out["alerts.{$key}.{$field}"] = $msg;
                }
            }
            if ($a['yellow'] === null && $a['red'] !== null) {
                $out["alerts.{$key}.yellow"] = 'Pon primero desde cuánto sale amarillo.';
            } elseif ($a['yellow'] !== null && $a['red'] !== null && !self::worse($a['red'], $a['yellow'], $def['better'])) {
                $out["alerts.{$key}.red"] = 'El rojo tiene que ser más alto que el amarillo.';
            }
        }

        return $out;
    }

    /** Si hay algo que pueda avisar: una meta de personas con su alerta prendida, o una alerta con su límite. */
    public static function configured(array $config): bool
    {
        foreach ($config['team'] as $g) {
            if ($g['alert'] && $g['value'] !== null) {
                return true;
            }
        }
        foreach ($config['alerts'] as $a) {
            if ($a['on'] && $a['yellow'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Amarillo o rojo (o null si está bien). "Pasar" el límite es quedar estrictamente por debajo (si subir es mejor) o
     * por encima (si bajar es mejor): justo en la meta, la meta está cumplida.
     */
    public static function level(int|float|null $value, ?float $yellow, ?float $red, string $better): ?string
    {
        if ($value === null || $yellow === null) {
            return null;
        }
        if ($red !== null && self::worse((float) $value, $red, $better)) {
            return 'red';
        }

        return self::worse((float) $value, $yellow, $better) ? 'yellow' : null;
    }

    /** La meta y el rojo de una persona: su excepción, o la de todas. El rojo queda a la misma distancia de su meta. */
    public static function personLimits(array $goal, int $id): array
    {
        $own = $goal['people'][$id] ?? null;
        if ($own === null || $goal['value'] === null) {
            return ['yellow' => $goal['value'], 'red' => $goal['red'], 'own' => false];
        }

        return ['yellow' => $own, 'red' => $goal['red'] !== null ? round($own - ($goal['value'] - $goal['red']), 2) : null, 'own' => true];
    }

    /** Una meta del negocio de cada mes que la tiene: "2026-10" => 6000. */
    public static function byMonth(array $config, string $key): array
    {
        return array_filter(array_map(fn ($b) => $b[$key], $config['months']), fn ($v) => $v !== null);
    }

    /** "2026-10" => "octubre". */
    public static function monthName(string $month): string
    {
        return self::MONTHS[(int) substr($month, 5, 2) - 1];
    }

    /** El mes de una fecha: "2026-10". */
    public static function monthOf(CarbonImmutable|string $date): string
    {
        return substr(is_string($date) ? $date : $date->toDateString(), 0, 7);
    }

    /** Los meses cuyas metas se pueden poner: el que corre y el siguiente (para dejarlas listas). */
    public static function editableMonths(CarbonImmutable $now): array
    {
        return [self::monthOf($now), self::monthOf($now->startOfMonth()->addMonth())];
    }

    /**
     * La meta de un período en dinero, con la de cada mes: los meses enteros, la de su mes; si no, se suma día por día
     * con la meta del mes de cada día. null si algún mes del período no tiene meta.
     *
     * @param array<string, float> $byMonth "2026-10" => meta del mes
     */
    public static function forPeriod(array $byMonth, Period $period): ?float
    {
        if (in_array($period->preset, ['month', 'last_month'], true)) {
            $goal = $byMonth[self::monthOf($period->start)] ?? null;

            return $goal !== null ? round($goal, 2) : null;
        }
        $sum = 0.0;
        foreach ($period->dates() as $date) {
            $goal = $byMonth[self::monthOf($date)] ?? null;
            if ($goal === null) {
                return null;
            }
            $sum += $goal / (int) date('t', strtotime($date));
        }

        return round($sum, 2);
    }

    /** La meta de un % en un período: la de su mes, o el promedio de cada día si cruza meses. null si falta un mes. */
    public static function averageForPeriod(array $byMonth, Period $period): ?float
    {
        $dates = in_array($period->preset, ['month', 'last_month'], true) ? [$period->fromDate()] : $period->dates();
        $sum = 0.0;
        foreach ($dates as $date) {
            $goal = $byMonth[self::monthOf($date)] ?? null;
            if ($goal === null) {
                return null;
            }
            $sum += $goal;
        }

        return round($sum / count($dates), 2);
    }

    /** "octubre" o "octubre y noviembre": los meses de las metas de un período. */
    private static function monthsOf(Period $period): string
    {
        $names = array_values(array_unique(array_map(fn ($d) => self::monthName(self::monthOf($d)), $period->dates())));

        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' y ' . end($names) : $names[0];
    }

    /**
     * La meta de una tarjeta de dinero (ventas o ganancia): cuánto se lleva, cuánto falta y, en "Este mes", si se va al
     * ritmo. "pending" es lo que todavía pueden sumar los pedidos en camino (estimado, como "En camino" de Dinero).
     *
     * status: met (cumplida), on_track (va bien), behind (va atrasada, todavía puede llegar), below (no llegó) o
     * neutral (todavía no se puede saber).
     */
    public static function moneyGoal(string $key, array $byMonth, ?float $value, ?float $pending, Period $period, CarbonImmutable $now, bool $maturing): ?array
    {
        $goal = self::forPeriod($byMonth, $period);
        if ($goal === null) {
            return null;
        }
        $value = (float) ($value ?? 0);
        $pending = $pending !== null ? max(0.0, $pending) : null;
        $reach = $value + ($pending ?? 0.0);
        $whole = in_array($period->preset, ['month', 'last_month'], true);
        $months = self::monthsOf($period);
        $scope = match ($period->preset) {
            'month', 'last_month' => 'de ' . $months,
            'today' => 'de hoy',
            'yesterday' => 'de ayer',
            default => 'de estos días',
        };
        $spread = str_contains($months, ' y ') ? " (tus metas de {$months} repartidas por día)" : " (tu meta de {$months} repartida por día)";
        $lines = ['Meta ' . $scope . ': ' . Card::money($goal) . ($whole ? '' : $spread) . '.'];
        $pace = null;

        if ($goal <= 0 || $value >= $goal) {
            $status = 'met';
            $lines[] = 'Cumplida: llevas ' . Card::money($value) . '.';
        } else {
            $share = $value > 0 ? ' (' . self::fmt($value / $goal * 100, 'percent') . ')' : '';
            $lines[] = 'Llevas ' . Card::money($value) . $share . '; faltan ' . Card::money($goal - $value) . '.';
            if ($period->preset === 'month') {
                $monthStart = $period->start->startOfMonth();
                $elapsed = min(1.0, max(0.0, $monthStart->diffInSeconds($now) / ((int) $monthStart->format('t') * 86400)));
                if ($elapsed * (int) $monthStart->format('t') < 3) {
                    $status = 'neutral';
                    $lines[] = 'Todavía es muy pronto en el mes para saber si vas al ritmo.';
                } else {
                    // "cerca de": sin centavos
                    $projection = round($reach / $elapsed);
                    $status = $projection >= $goal ? 'on_track' : 'behind';
                    $lines[] = 'A este ritmo, contando lo que está en camino, terminarías el mes cerca de ' . Card::money($projection)
                        . ($status === 'on_track' ? ': vas bien.' : ': te faltarían ' . Card::money($goal - $projection) . '.');
                    $pace = ['expected' => round($goal * $elapsed, 2), 'projection' => $projection];
                }
            } elseif ($period->preset === 'today') {
                $status = 'neutral';
                $lines[] = 'El día todavía no termina.';
            } elseif ($maturing) {
                $status = $reach >= $goal ? 'on_track' : 'behind';
                if (($pending ?? 0) > 0) {
                    $lines[] = 'Con lo que está en camino podrías llegar a cerca de ' . Card::money($reach) . '.';
                }
            } else {
                $status = 'below';
            }
        }

        return [
            'label' => self::BUSINESS[$key]['label'],
            'value' => $goal,
            'red' => null,
            'format' => 'money',
            'better' => 'higher',
            'progress' => $goal > 0 ? round($value / $goal * 100, 1) : null,
            'pending' => $pending,
            'status' => $status,
            'pace' => $pace,
            'text' => implode(' ', $lines),
        ];
    }

    /**
     * La meta de un número que no se acumula (un %, un tiempo): si la cumple, cuánto le falta, y gris mientras el
     * período se sigue entregando (todavía puede mejorar).
     */
    public static function limitGoal(string $label, string $format, string $better, ?float $yellow, ?float $red, int|float|null $value, bool $stillChanging, bool $own = false): array
    {
        $kind = $better === 'lower' ? 'máximo' : 'meta';
        $lines = [($own ? 'Su ' : 'Tu ') . $kind . ': ' . self::limit($yellow, $format) . ($red !== null ? ' (rojo: ' . self::limit($red, $format) . ')' : '') . '.'];
        $level = self::level($value, $yellow, $red, $better);
        if ($value === null) {
            $status = 'neutral';
        } elseif ($level === null) {
            $status = 'met';
            $lines[] = 'Cumplida.';
        } else {
            $gap = abs((float) $value - (float) $yellow);
            $lines[] = 'Va ' . self::gap($gap, $format) . ($better === 'lower' ? ' arriba.' : ' abajo.');
            if ($stillChanging) {
                $status = 'neutral';
                $lines[] = 'Todavía puede mejorar: este período se sigue entregando.';
            } else {
                $status = $level === 'red' ? 'below' : 'behind';
            }
        }

        return [
            'label' => $label,
            'value' => $yellow,
            'red' => $red,
            'format' => $format,
            'better' => $better,
            'progress' => null,
            'pending' => null,
            'status' => $status,
            'pace' => null,
            'text' => implode(' ', $lines),
        ];
    }

    /**
     * Las metas de un rol de Equipo sobre lo que muestra la pantalla: la meta para todas en cada columna (la rayita del
     * ranking) y, en cada fila, qué KPIs quedaron en amarillo o rojo. Los de pocos datos no se marcan, y los KPIs que
     * todavía pueden mejorar tampoco mientras el período se sigue entregando.
     */
    public static function team(string $roleKey, array $role, array $config, bool $maturing): array
    {
        $goals = self::teamGoals($roleKey, $config);
        if ($goals === []) {
            return $role;
        }
        foreach ($role['columns'] as &$col) {
            if (isset($goals[$col['key']])) {
                $col['goal'] = ['value' => $goals[$col['key']]['value'], 'red' => $goals[$col['key']]['red']];
            }
        }
        unset($col);
        $matures = array_column($role['columns'], 'matures', 'key');
        foreach ($role['rows'] as &$row) {
            $row['goal_levels'] = [];
            foreach ($goals as $metric => $goal) {
                if ($row['low_data'] || ($maturing && !empty($matures[$metric]))) {
                    continue;
                }
                $limits = self::personLimits($goal, (int) $row['id']);
                $row['goal_levels'][$metric] = self::level($row['values'][$metric] ?? null, $limits['yellow'], $limits['red'], $goal['better']) ?? 'ok';
            }
        }
        unset($row);

        return $role;
    }

    /** Las tarjetas de un rol (todos juntos) o de una persona (con su excepción), con su meta. */
    public static function teamCards(string $roleKey, array $cards, array $config, bool $maturing, ?int $personId = null): array
    {
        $goals = self::teamGoals($roleKey, $config);
        foreach ($cards as &$card) {
            $goal = $goals[$card['key']] ?? null;
            if ($goal === null) {
                continue;
            }
            $limits = $personId !== null ? self::personLimits($goal, $personId) : ['yellow' => $goal['value'], 'red' => $goal['red'], 'own' => false];
            // "neutral" de la tarjeta: el KPI todavía puede cambiar porque el período se sigue entregando
            $card['goal'] = self::limitGoal($goal['label'], $goal['format'], $goal['better'], $limits['yellow'], $limits['red'], $card['value'],
                (bool) $card['neutral'], $limits['own']);
        }
        unset($card);

        return $cards;
    }

    /** Las metas puestas de un rol: KPI => la meta (value, red, people) con su definición. */
    private static function teamGoals(string $roleKey, array $config): array
    {
        $out = [];
        foreach (self::TEAM as $key => $def) {
            $goal = $config['team'][$key] ?? null;
            if ($def['role'] === $roleKey && $goal && $goal['value'] !== null) {
                $out[$def['metric']] = $goal + $def;
            }
        }

        return $out;
    }

    /** Lo que necesita la pantalla de "Metas y límites": cada meta y alerta con su explicación, sin las reglas internas. */
    public static function catalog(): array
    {
        $pick = fn (array $d) => array_intersect_key($d, array_flip(['label', 'format', 'better', 'help', 'role', 'title']));

        return [
            'business' => array_map($pick, self::BUSINESS),
            'team' => array_map($pick, self::TEAM),
            'alerts' => array_map($pick, self::ALERTS),
        ];
    }

    /** Lo guardado, para la pantalla: las excepciones como lista (los meses quedan "2026-10" => metas). */
    public static function forScreen(array $config): array
    {
        foreach ($config['team'] as &$g) {
            $g['people'] = array_map(fn ($id, $v) => ['id' => $id, 'value' => $v], array_keys($g['people']), array_values($g['people']));
        }
        unset($g);

        return $config;
    }

    /** "55 %", "55,5 %", "40 min", "3,5 h", "$1.200,00". */
    public static function fmt(int|float|null $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }
        $one = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

        return match ($format) {
            'percent' => $one((float) $value) . ' %',
            'money' => Card::money((float) $value),
            'hours' => $value < 1 ? round($value * 60) . ' min' : ($value < 48 ? $one((float) $value) . ' h' : $one($value / 24) . ' días'),
            default => number_format((float) $value, 0, ',', '.'),
        };
    }

    /** Un límite tal como lo escribió el dueño: los tiempos siempre en horas ("48 h", no "2 días"). */
    public static function limit(?float $value, string $format): string
    {
        return $format === 'hours' && $value !== null ? rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . ' h' : self::fmt($value, $format);
    }

    /** Cuánto falta, en palabras: "8,2 puntos", "3,5 h", "$120,00". */
    private static function gap(float $gap, string $format): string
    {
        return $format === 'percent' ? rtrim(rtrim(number_format($gap, 1, ',', '.'), '0'), ',') . ' puntos' : self::fmt($gap, $format);
    }

    private static function worse(float $value, float $limit, string $better): bool
    {
        return $better === 'lower' ? $value > $limit : $value < $limit;
    }

    /** Las metas del negocio de un mes, limpias: ventas, ganancia y efectividad (null = sin meta). */
    private static function business(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (array_keys(self::BUSINESS) as $key) {
            $out[$key] = self::number($raw[$key] ?? null);
        }

        return $out;
    }

    private static function number(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private static function outOfRange(?float $v, string $format): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v < 0) {
            return 'No puede ser negativo.';
        }

        return match ($format) {
            'percent' => $v > 100 ? 'Tiene que estar entre 0 y 100.' : null,
            'hours' => $v > 720 ? 'Máximo 720 horas (30 días).' : null,
            default => $v > 100000000 ? 'Es demasiado grande.' : null,
        };
    }
}
