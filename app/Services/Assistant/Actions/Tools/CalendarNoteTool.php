<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Rules\NoteRules;
use App\Services\Calendar\Rules\Schedule;

/** Anotar algo en el calendario del dueño (Calendar::createNote, con "La anotó tu asistente"). */
class CalendarNoteTool extends ChangeTool
{
    private const REPEATS = ['no' => 'none', 'semanal' => 'weekly', 'mensual' => 'monthly', 'quincenal' => 'twice_monthly'];

    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'proponer_nota_calendario';
    }

    public function description(): string
    {
        return 'Propone anotar algo en el calendario del dueño (una tarea o un pago, de un día o que se repite). El dueño ve una tarjeta y lo confirma; recién ahí queda anotado. Usa fechas AAAA-MM-DD calculadas desde la fecha de hoy.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'titulo' => ['type' => 'string', 'description' => 'Qué hay que hacer, corto: "Pagar el alquiler del local".'],
                'fecha' => ['type' => 'string', 'description' => 'AAAA-MM-DD: el día (o el primero, si se repite).'],
                'hora' => ['type' => ['string', 'null'], 'description' => 'HH:MM (24 h) o null.'],
                'tipo' => ['type' => 'string', 'enum' => ['tarea', 'pago'], 'description' => 'Un pago que cae domingo se adelanta al sábado; una tarea pasa al lunes.'],
                'repetir' => ['type' => 'string', 'enum' => array_keys(self::REPEATS), 'description' => 'no, semanal, mensual o quincenal (el 15 y el último de cada mes).'],
                'hasta' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD: hasta cuándo se repite, o null (sin fin).'],
                'detalles' => ['type' => ['string', 'null'], 'description' => 'Lo demás que haya que saber, o null.'],
            ],
            'required' => ['titulo', 'fecha', 'hora', 'tipo', 'repetir', 'hasta', 'detalles'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la nota del calendario…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $note = NoteRules::normalize([
            'title' => $args['titulo'] ?? null,
            'details' => $args['detalles'] ?? null,
            'date' => $args['fecha'] ?? null,
            'time' => $args['hora'] ?? null,
            'kind' => ($args['tipo'] ?? 'tarea') === 'pago' ? 'payment' : 'task',
            'repeat' => self::REPEATS[$args['repetir'] ?? 'no'] ?? 'none',
            'repeat_until' => $args['hasta'] ?? null,
        ]);
        if ($problems = NoteRules::problems($note)) {
            throw new ToolProblem(implode(' ', $problems));
        }
        $warnings = [];
        if ($note['repeat'] === 'none' && $note['date'] < $context->now->toDateString()) {
            $warnings[] = 'La fecha ya pasó: la nota sale como atrasada.';
        }

        return new Proposal(
            'Anotar en tu calendario',
            'Anotar en el calendario: ' . $note['title'] . ' (' . Schedule::dayName($note['date']) . ')',
            Proposal::lines([
                Proposal::line('Qué', $note['title']),
                Proposal::line('Tipo', NoteRules::KINDS[$note['kind']]),
                Proposal::line('Cuándo', Schedule::dayName($note['date']) . ($note['time'] ? " a las {$note['time']}" : '')),
                Proposal::line('Se repite', $note['repeat'] === 'none' ? null : Schedule::REPEATS[$note['repeat']] . ($note['repeat_until'] ? ', hasta el ' . Schedule::dayName($note['repeat_until']) : '')),
                Proposal::line('Detalles', $note['details']),
            ]),
            $note,
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $note = $this->calendar->createNote($payload, $context->user, 'assistant');

        return new Outcome("Anotado en tu calendario: {$note->title}.", ['label' => 'Ver el calendario', 'to' => '/calendario']);
    }
}
