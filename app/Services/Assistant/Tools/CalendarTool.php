<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

/** El calendario del dueño (Etapa 3): lo de hoy con lo atrasado, o lo de unas fechas. Cada cosa en una frase. */
class CalendarTool implements Tool
{
    private const MAX_DAYS = 31;

    private const STATUS = ['pending' => 'pendiente', 'done' => 'hecho', 'dismissed' => 'descartado', 'overdue' => 'atrasado'];

    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'calendario';
    }

    public function description(): string
    {
        return 'El calendario del dueño: sus pagos (vendedoras, agencias, gastos fijos), su rutina diaria, las revisiones de números y sus notas, con lo hecho, lo pendiente y lo atrasado. "hoy" trae lo de hoy y lo atrasado; "fechas" trae los días de desde a hasta (máximo 31). Solo lee: todavía no puede anotar ni tildar.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cuando' => ['type' => 'string', 'enum' => ['hoy', 'fechas'], 'description' => 'hoy, o unas fechas (desde y hasta).'],
                'desde' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, solo con "fechas".'],
                'hasta' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, solo con "fechas".'],
            ],
            'required' => ['cuando', 'desde', 'hasta'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando tu calendario…';
    }

    public function run(array $args): ToolResult
    {
        $now = CarbonImmutable::now();
        $link = [['type' => 'link', 'label' => 'Abrir el calendario', 'to' => '/calendario']];
        if (($args['cuando'] ?? 'hoy') !== 'fechas') {
            $d = $this->calendar->today($now);

            return new ToolResult([
                'hoy' => $d['label'],
                'resumen' => $d['text'],
                'lo_de_hoy' => array_map([self::class, 'item'], $d['items']),
                'atrasado' => array_map([self::class, 'item'], $d['overdue']),
            ], $link);
        }

        [$from, $to] = [self::date($args['desde'] ?? null), self::date($args['hasta'] ?? null)];
        if ($to->lt($from)) {
            throw new ToolProblem('La fecha "hasta" no puede ser antes de "desde".');
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw new ToolProblem('Puedo revisar como mucho ' . self::MAX_DAYS . ' días de una vez.');
        }
        $days = [];
        foreach ($this->calendar->range($from, $to, $now)['days'] as $date => $items) {
            if ($items !== []) {
                $days[] = ['dia' => Schedule::dayName($date), 'cosas' => array_map([self::class, 'item'], $items)];
            }
        }

        return new ToolResult(['dias' => $days ?: 'No hay nada en esas fechas.'], $link);
    }

    /** Una cosa del calendario en una frase, con su estado. Lo que anotó una persona va sin teléfonos. */
    private static function item(array $i): string
    {
        $text = ($i['source'] ?? null) === 'system' ? $i['text'] : Present::mask((string) $i['text']);

        return $text . ' [' . (self::STATUS[$i['status']] ?? $i['status']) . ']';
    }

    private static function date(?string $value): CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ToolProblem('Las fechas tienen que ser AAAA-MM-DD.');
        }

        return CarbonImmutable::parse($value);
    }
}
