<?php

namespace App\Services\Metrics\Rules;

/**
 * Una tarjeta del panel del dueño con su comparación contra el período anterior. La usan todas las secciones, para que
 * todas comparen igual. PHP puro.
 *
 * - Cantidades y dinero: cuánto % cambió. Porcentajes: cuántos puntos (de 50 % a 55 % son 5 puntos, no un 10 %).
 * - "matures": el número de un período reciente todavía puede cambiar (los pedidos se siguen entregando), así que se
 *   compara sin rojo ni verde ("neutral").
 * - "better": si subir es bueno (lo normal) o malo (un costo).
 * - "compare" en false: el número no se compara (lo de ahora mismo, o una estimación).
 */
final class Card
{
    /**
     * @param array{label: string, help: string, format: string, matures?: bool, better?: string, compare?: bool, note?: string} $m
     */
    public static function make(string $key, array $m, int|float|null $value, int|float|null $before, ?string $detail, bool $maturing): array
    {
        $points = $m['format'] === 'percent';
        $compare = $m['compare'] ?? true;
        $change = null;
        if ($compare && $value !== null && $before !== null) {
            $change = $points ? round($value - $before, 1) : ($before != 0 ? round(($value - $before) / abs($before) * 100, 1) : null);
        }

        return [
            'key' => $key,
            'label' => $m['label'],
            'help' => $m['help'],
            'detail' => $detail,
            'format' => $m['format'],
            'value' => $value,
            'previous' => $compare ? $before : null,
            'change' => $change,
            'change_unit' => $points ? 'points' : 'percent',
            'neutral' => $maturing && ($m['matures'] ?? false),
            'better' => $m['better'] ?? 'higher',
            'compare' => $compare,
            'note' => $m['note'] ?? null,
        ];
    }

    public static function money(float $v): string
    {
        return ($v < 0 ? '−' : '') . '$' . number_format(abs($v), 2, ',', '.');
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);
    }
}
