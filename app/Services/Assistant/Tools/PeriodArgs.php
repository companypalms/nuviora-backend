<?php

namespace App\Services\Assistant\Tools;

use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * El período de las preguntas del dueño, en sus palabras: "hoy", "la semana pasada" (lunes a domingo), "este mes"... Se
 * traduce a un Period del panel (los mismos presets, o fechas). PHP puro.
 */
final class PeriodArgs
{
    public const VALUES = ['hoy', 'ayer', 'ultimos_7_dias', 'esta_semana', 'semana_pasada', 'este_mes', 'mes_pasado', 'fechas'];

    public const DEFAULT = 'este_mes';

    /** Los parámetros del período, para el esquema de cada herramienta. */
    public static function schema(): array
    {
        return [
            'periodo' => [
                'type' => 'string',
                'enum' => self::VALUES,
                'description' => 'El período. "esta_semana" va desde el lunes; "semana_pasada" es de lunes a domingo; "fechas" usa desde y hasta. Si el dueño no dice nada, este_mes.',
            ],
            'desde' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, solo con periodo "fechas".'],
            'hasta' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, solo con periodo "fechas".'],
        ];
    }

    /** @throws ToolProblem si las fechas no sirven */
    public static function period(array $args, CarbonImmutable $now): Period
    {
        $value = in_array($args['periodo'] ?? null, self::VALUES, true) ? $args['periodo'] : self::DEFAULT;
        $monday = $now->startOfDay()->subDays($now->dayOfWeekIso - 1);
        try {
            return match ($value) {
                'hoy' => Period::fromPreset('today', null, null, $now),
                'ayer' => Period::fromPreset('yesterday', null, null, $now),
                'ultimos_7_dias' => Period::fromPreset('7d', null, null, $now),
                'este_mes' => Period::fromPreset('month', null, null, $now),
                'mes_pasado' => Period::fromPreset('last_month', null, null, $now),
                'esta_semana' => Period::fromPreset('custom', $monday->toDateString(), $now->toDateString(), $now),
                'semana_pasada' => Period::fromPreset('custom', $monday->subWeek()->toDateString(), $monday->subDay()->toDateString(), $now),
                'fechas' => Period::fromPreset('custom', self::date($args['desde'] ?? null), self::date($args['hasta'] ?? null), $now),
            };
        } catch (InvalidArgumentException $e) {
            throw new ToolProblem($e->getMessage());
        }
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new ToolProblem("La fecha \"{$value}\" no es válida: tiene que ser AAAA-MM-DD.");
        }

        return $value;
    }
}
