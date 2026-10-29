<?php

namespace App\Services\Metrics\Team;

use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\TeamReader;

/**
 * Un rol del equipo en el panel del dueño (sección Equipo, diccionario de métricas §4): sus KPIs con su explicación,
 * qué lee y cómo los calcula. Cada rol es una clase y TeamCatalog los junta: para medir un rol nuevo (por ejemplo, el
 * Editor) se escribe su clase y se agrega al catálogo, y la pantalla lo muestra sola.
 *
 * compute() es PHP puro: recibe los hechos que trajo el lector (facts) y devuelve, por persona y para todo el rol, el
 * valor de cada KPI. Los totales del rol se cuentan sobre los pedidos (un pedido que pasó por dos personas cuenta una
 * vez), nunca promediando porcentajes.
 */
abstract class TeamRole
{
    /** La clave en la dirección y en el catálogo: "sellers". */
    abstract public function key(): string;

    /** Cómo se llama el grupo en la pantalla: "Vendedoras". */
    abstract public function label(): string;

    /** Los roles del sistema (roles.description) que mide. Vacío si no es un rol del sistema (las líderes). */
    abstract public function systemRoles(): array;

    /** De qué se trata, para el cursor. */
    abstract public function help(): string;

    /**
     * Los KPIs en el orden de la tabla: clave => label, format (count, money, percent, hours, hours_total), better
     * (higher, lower o none), help y, si hace falta: card (va arriba, como tarjeta del rol), matures (en un período
     * reciente todavía puede cambiar), now (es lo de ahora mismo: no depende del período ni se compara), flag (se marca
     * a quien tiene el doble del promedio o más).
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function metrics(): array;

    /** El KPI por el que se ordena el ranking al entrar. */
    abstract public function rankBy(): string;

    /**
     * El número que dice si una persona tiene datos suficientes y el mínimo: con menos, va al final del ranking en gris
     * ("pocos datos"), para que 2 de 2 no parezca lo mejor.
     *
     * @return array{key: string, min: int, label: string}
     */
    abstract public function base(): array;

    /** Los filtros del panel que no se aplican a este rol, para avisarlo (o null si se aplican todos). */
    public function filtersNote(): ?string
    {
        return null;
    }

    /** Lee los hechos del período (la única parte que toca la base de datos). */
    abstract public function facts(TeamReader $reader, Period $period, Filters $filters): mixed;

    /**
     * Calcula los KPIs. Devuelve:
     * - people: id => ['name', 'group' (o null), 'values' => clave => valor, 'details' => clave => texto];
     * - totals: ['values', 'details'] de todo el rol;
     * - y lo que necesiten sus bloques de detalle.
     */
    abstract public function compute(mixed $facts, Period $period): array;

    /**
     * Lo que se ve al hacer clic en una persona, además de sus KPIs: bloques "bars" (title, subtitle, help, rows de
     * label, count y of) o "table" (title, help, role: lo mismo que la tabla del rol).
     */
    public function blocks(int $id, array $computed, array $before = []): array
    {
        return [];
    }

    /** null cuando no hay base: la pantalla muestra "—" en vez de un 0 % engañoso. */
    protected static function pct(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }

    protected static function ratio(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part / $total, 2) : null;
    }

    /** La mediana: el tiempo "normal" (la mitad tardó menos). */
    protected static function median(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 2);
    }

    /** Horas entre dos fechas "Y-m-d H:i:s" (null si falta una o si la segunda es antes). */
    protected static function hoursBetween(?string $from, ?string $to): ?float
    {
        if (!$from || !$to) {
            return null;
        }
        $seconds = strtotime($to) - strtotime($from);

        return $seconds >= 0 ? $seconds / 3600 : null;
    }

    protected static function inPeriod(?string $at, Period $period): bool
    {
        return $at !== null && $at >= $period->start->format('Y-m-d H:i:s') && $at <= $period->end->format('Y-m-d H:i:s');
    }

    protected static function plural(int $n, string $one, string $many): string
    {
        return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);
    }
}
