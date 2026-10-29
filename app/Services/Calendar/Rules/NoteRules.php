<?php

namespace App\Services\Calendar\Rules;

use Carbon\CarbonImmutable;

/**
 * Lo que el dueño anota en su calendario (Etapa 3): qué se revisa al guardar y cómo se lee. PHP puro.
 *
 * Una nota es una tarea o un pago (cambia la regla del domingo, ver Schedule), de un día o repetida (cada semana, cada
 * mes, o el 15 y el último), con hora si quiere. Más adelante el asistente de IA también anota aquí (source "assistant").
 */
final class NoteRules
{
    public const KINDS = ['task' => 'Tarea', 'payment' => 'Pago'];

    public const SOURCES = ['owner', 'assistant'];

    public const MAX_TITLE = 150;

    public const MAX_DETAILS = 2000;

    /**
     * Lo que llegó de la pantalla, limpio: textos sin espacios de más, vacío = null, y sin "hasta" si no se repite.
     *
     * @return array{title: string, details: ?string, date: ?string, time: ?string, kind: string, repeat: string, repeat_until: ?string}
     */
    public static function normalize(array $input): array
    {
        $text = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $repeat = $text($input['repeat'] ?? null) ?? 'none';

        return [
            'title' => (string) $text($input['title'] ?? null),
            'details' => $text($input['details'] ?? null),
            'date' => $text($input['date'] ?? null),
            'time' => $text($input['time'] ?? null),
            'kind' => $text($input['kind'] ?? null) ?? 'task',
            'repeat' => $repeat,
            'repeat_until' => $repeat === 'none' ? null : $text($input['repeat_until'] ?? null),
        ];
    }

    /** Qué casilla está mal y por qué, en palabras simples (vacío: se puede guardar). */
    public static function problems(array $note): array
    {
        $problems = [];
        $title = $note['title'] ?? '';
        if (mb_strlen($title) < 2) {
            $problems['title'] = 'Escribe qué hay que hacer.';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $problems['title'] = 'El título es muy largo: máximo ' . self::MAX_TITLE . ' letras. Lo demás va en "Detalles".';
        }
        if (mb_strlen((string) ($note['details'] ?? '')) > self::MAX_DETAILS) {
            $problems['details'] = 'Los detalles son muy largos: máximo ' . self::MAX_DETAILS . ' letras.';
        }
        if (!self::isDate($note['date'] ?? null)) {
            $problems['date'] = 'Elige la fecha.';
        }
        if (($note['time'] ?? null) !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $note['time'])) {
            $problems['time'] = 'La hora no es válida.';
        }
        if (!isset(self::KINDS[$note['kind'] ?? ''])) {
            $problems['kind'] = 'Elige si es una tarea o un pago.';
        }
        if (!isset(Schedule::REPEATS[$note['repeat'] ?? ''])) {
            $problems['repeat'] = 'Elige si se repite.';
        }
        $until = $note['repeat_until'] ?? null;
        if ($until !== null) {
            if (!self::isDate($until)) {
                $problems['repeat_until'] = 'La fecha de "hasta" no es válida.';
            } elseif (self::isDate($note['date'] ?? null) && $until < $note['date']) {
                $problems['repeat_until'] = '"Hasta" no puede ser antes de la fecha de la nota.';
            }
        }

        return $problems;
    }

    /** El patrón de fechas de la nota, para Schedule. */
    public static function pattern(array $note): array
    {
        return ['repeat' => $note['repeat'], 'start' => $note['date'], 'until' => $note['repeat_until'] ?? null, 'type' => $note['kind']];
    }

    /** La nota en una frase (Schedule::sentence le suma si se corrió y su estado): "Pago: Alquiler del local, a las 10:00." */
    public static function text(array $note): string
    {
        return self::KINDS[$note['kind']] . ': ' . $note['title'] . ($note['time'] ? ', a las ' . $note['time'] : '') . '.';
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) && CarbonImmutable::parse($value)->year >= 2020;
    }
}
