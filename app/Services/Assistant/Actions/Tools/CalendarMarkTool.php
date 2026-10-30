<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

/**
 * Tildar (hecho) o descartar algo del calendario (Calendar::mark). Se elige por su clave (la trae la herramienta
 * "calendario") o por el texto, en la fecha que le toca.
 */
class CalendarMarkTool extends ChangeTool
{
    private const STATUS = ['hecho' => 'done', 'descartado' => 'dismissed'];

    private const NOW = ['pending' => 'Pendiente', 'overdue' => 'Atrasado', 'done' => 'Hecho', 'dismissed' => 'Descartado'];

    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'proponer_marca_calendario';
    }

    public function description(): string
    {
        return 'Propone marcar algo del calendario como hecho o descartarlo (lo de hoy, lo atrasado o de otra fecha). Elige la cosa por su "clave" (la trae la herramienta calendario, junto con su fecha) o, si no la tienes, por unas palabras de su texto. El dueño lo confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'clave' => ['type' => ['string', 'null'], 'description' => 'La clave que trae la herramienta calendario ("rule:rate", "note:12", "expense:5"), o null.'],
                'texto' => ['type' => ['string', 'null'], 'description' => 'Si no hay clave: unas palabras de lo que hay que marcar ("tasa", "pagar a las vendedoras").'],
                'fecha' => ['type' => 'string', 'description' => 'AAAA-MM-DD: el día que le tocaba (para lo atrasado, el día original).'],
                'estado' => ['type' => 'string', 'enum' => array_keys(self::STATUS), 'description' => 'hecho o descartado.'],
                'nota' => ['type' => ['string', 'null'], 'description' => 'Un comentario corto, o null.'],
            ],
            'required' => ['clave', 'texto', 'fecha', 'estado', 'nota'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Buscando eso en tu calendario…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $date = (string) ($args['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ToolProblem('La fecha tiene que ser AAAA-MM-DD.');
        }
        $day = CarbonImmutable::parse($date);
        $items = $this->calendar->range($day, $day, $context->now)['days'][$date] ?? [];
        $item = $this->find($items, $args['clave'] ?? null, $args['texto'] ?? null);
        $status = self::STATUS[$args['estado'] ?? 'hecho'] ?? 'done';
        if (($item['status'] ?? null) === $status) {
            throw new ToolProblem('Eso ya está ' . ($status === 'done' ? 'hecho' : 'descartado') . '.');
        }
        $note = is_string($args['nota'] ?? null) && trim($args['nota']) !== '' ? mb_substr(trim($args['nota']), 0, 250) : null;
        $label = $status === 'done' ? 'Marcar como hecho' : 'Descartar';

        return new Proposal(
            $label . ' en tu calendario',
            "{$label}: {$item['title']} (" . Schedule::dayName($item['due_date']) . ')',
            Proposal::lines([
                Proposal::line('Qué', (string) $item['title']),
                Proposal::line('Del día', Schedule::dayName($item['due_date'])),
                Proposal::line('Queda', $status === 'done' ? 'Hecho' : 'Descartado', self::NOW[$item['status']] ?? 'Pendiente'),
                Proposal::line('Nota', $note),
            ]),
            ['item_key' => $item['item_key'], 'due' => $item['due_date'], 'status' => $status, 'note' => $note, 'title' => (string) $item['title']],
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $this->calendar->mark($payload['item_key'], $payload['due'], $payload['status'], $payload['note'], $context->user, 'assistant');

        return new Outcome(($payload['status'] === 'done' ? 'Marcado como hecho: ' : 'Descartado: ') . $payload['title'] . '.', ['label' => 'Ver el calendario', 'to' => '/calendario']);
    }

    /** La cosa del día por su clave o por su texto (sin acentos). Si hay dudas, las dice. */
    private function find(array $items, ?string $key, ?string $text): array
    {
        if ($items === []) {
            throw new ToolProblem('Ese día no hay nada en el calendario.');
        }
        if ($key !== null && trim($key) !== '') {
            foreach ($items as $item) {
                if ($item['item_key'] === trim($key)) {
                    return $item;
                }
            }
            throw new ToolProblem('Esa clave no está ese día. Revisa el calendario de esa fecha.');
        }
        $words = array_filter(explode(' ', Lookup::normalize((string) $text)), fn ($w) => mb_strlen($w) > 2);
        $found = array_values(array_filter($items, function ($item) use ($words) {
            $haystack = Lookup::normalize($item['title'] . ' ' . $item['text']);

            return $words !== [] && array_filter($words, fn ($w) => !str_contains($haystack, $w)) === [];
        }));
        if (count($found) === 1) {
            return $found[0];
        }
        $list = implode('; ', array_map(fn ($i) => "{$i['title']} (clave {$i['item_key']})", $found ?: $items));
        throw new ToolProblem(($found ? 'Hay varias cosas así ese día: ' : 'No encontré eso ese día. Lo que hay: ') . $list . '. Usa la clave.');
    }
}
