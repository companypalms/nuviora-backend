<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * El período que el dueño elige en su panel y el anterior con el que se compara (diccionario de métricas, §1 y §8).
 * PHP puro: "ahora" llega de afuera, para poder probarlo sin la aplicación.
 *
 * - El período nunca pasa de "ahora": hoy se mira hasta esta hora.
 * - El anterior tiene el mismo largo y termina a la misma hora, así "hoy" se compara con "ayer a esta misma hora" y no
 *   con el día completo de ayer. "Este mes" se compara con el mismo tramo del mes pasado.
 */
final class Period
{
    public const PRESETS = ['today', 'yesterday', '7d', 'month', 'last_month', 'custom'];

    public const MAX_DAYS = 400;

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $preset,
        public readonly string $label,
        public readonly string $compareLabel,
    ) {
    }

    public static function fromPreset(string $preset, ?string $from, ?string $to, CarbonImmutable $now): self
    {
        $today = $now->startOfDay();

        [$start, $end, $label, $compare] = match ($preset) {
            'today' => [$today, $now, 'Hoy', 'ayer a esta misma hora'],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay(), 'Ayer', 'anteayer'],
            '7d' => [$today->subDays(6), $now, 'Últimos 7 días', 'los 7 días anteriores'],
            'month' => [$today->startOfMonth(), $now, 'Este mes', 'el mismo tramo del mes pasado'],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth(), 'Mes pasado', 'el mes anterior'],
            'custom' => self::customRange($from, $to, $now),
            default => throw new InvalidArgumentException("Período desconocido: {$preset}"),
        };

        return new self($start, $end->min($now), $preset, $label, $compare);
    }

    /** El período anterior: mismo largo, justo antes. Los meses se comparan con el mes de antes. */
    public function previous(): self
    {
        if ($this->preset === 'month' || $this->preset === 'last_month') {
            $start = $this->start->subMonthNoOverflow()->startOfMonth();
            $end = $this->preset === 'last_month'
                ? $start->endOfMonth()
                : $this->end->subMonthNoOverflow()->min($start->endOfMonth());
        } else {
            $days = $this->days();
            $start = $this->start->subDays($days);
            $end = $this->end->subDays($days);
        }

        return new self($start, $end, 'previous', ucfirst($this->compareLabel), '');
    }

    /** Días de calendario que toca el período (hoy cuenta como un día aunque no haya terminado). */
    public function days(): int
    {
        return (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }

    public function fromDate(): string
    {
        return $this->start->toDateString();
    }

    public function toDate(): string
    {
        return $this->end->toDateString();
    }

    /** Las fechas de cada día del período, en orden. */
    public function dates(): array
    {
        $out = [];
        for ($d = $this->start->startOfDay(); $d <= $this->end; $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /**
     * Si los pedidos del período todavía se están entregando: los de los últimos días (por defecto 4, la misma
     * "madurez" de Finanzas › Cohortes) pueden seguir pasando a Entregado.
     */
    public function isMaturing(CarbonImmutable $now, int $maturityDays): bool
    {
        return $this->end->startOfDay() > $now->startOfDay()->subDays($maturityDays + 1);
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'label' => $this->label,
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'until' => $this->end->format('H:i'),
            'days' => $this->days(),
        ];
    }

    private static function customRange(?string $from, ?string $to, CarbonImmutable $now): array
    {
        if (!$from || !$to) {
            throw new InvalidArgumentException('Elige las dos fechas.');
        }
        $start = CarbonImmutable::parse($from, $now->getTimezone())->startOfDay();
        $end = CarbonImmutable::parse($to, $now->getTimezone())->endOfDay();
        if ($end < $start) {
            throw new InvalidArgumentException('La fecha "hasta" no puede ser antes de "desde".');
        }
        if ($start > $now) {
            throw new InvalidArgumentException('El período no puede empezar en el futuro.');
        }
        if ((int) $start->diffInDays($end->startOfDay()) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('El período no puede pasar de ' . self::MAX_DAYS . ' días.');
        }

        return [$start, $end, 'Del ' . $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y'), 'los mismos días justo antes'];
    }
}
