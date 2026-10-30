<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\User;
use App\Services\Agencies\AgencySwitch;
use App\Services\Agencies\WorkingHours;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;

/**
 * Encender o apagar una agencia y cambiar su horario (paso 7), lo mismo que Tiendas, ciudades y agencias › la agencia
 * (Agencies\AgencySwitch::setAgency). Apagada o fuera de horario no recibe órdenes: van a las otras agencias de su
 * ciudad, y donde es la única, esperan.
 */
class AgencyWorkingTool extends ChangeTool
{
    public function __construct(private AgencySwitch $switch, private WorkingHours $hours)
    {
    }

    public function name(): string
    {
        return 'proponer_agencia_trabajando';
    }

    public function description(): string
    {
        return 'Propone encender o apagar una agencia y, si el dueño lo dice, cambiar su horario (como en Tiendas, ciudades y agencias). Apagada o fuera de horario no recibe órdenes: van a las otras agencias de su ciudad, y donde es la única, esperan. Para una ciudad, usa proponer_ciudad_trabajando. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agencia' => ['type' => 'string'],
                'trabajando' => ['type' => 'boolean', 'description' => 'true = encenderla; false = apagarla.'],
                'horario' => ['anyOf' => [
                    [
                        'type' => 'object',
                        'description' => 'El horario nuevo, si el dueño lo dijo.',
                        'properties' => [
                            'desde' => ['type' => ['string', 'null'], 'description' => 'HH:MM; null = sin hora de inicio.'],
                            'hasta' => ['type' => ['string', 'null'], 'description' => 'HH:MM; null = sin hora de cierre.'],
                        ],
                        'required' => ['desde', 'hasta'],
                        'additionalProperties' => false,
                    ],
                    ['type' => 'null', 'description' => 'null = el horario queda como está.'],
                ]],
            ],
            'required' => ['agencia', 'trabajando', 'horario'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando la agencia…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $agency = self::agency((string) ($args['agencia'] ?? ''));
        $on = (bool) ($args['trabajando'] ?? false);
        $from = $agency->agency_works_from ? substr($agency->agency_works_from, 0, 5) : null;
        $until = $agency->agency_works_until ? substr($agency->agency_works_until, 0, 5) : null;
        if (is_array($args['horario'] ?? null)) {
            foreach (['desde', 'hasta'] as $key) {
                $value = $args['horario'][$key] ?? null;
                if ($value !== null && $value !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value)) {
                    throw new ToolProblem('El horario va en HH:MM, por ejemplo 08:00 o 17:30.');
                }
            }
            $newFrom = $args['horario']['desde'] ?: null;
            $newUntil = $args['horario']['hasta'] ?: null;
        } else {
            [$newFrom, $newUntil] = [$from, $until];
        }
        $hours = AgencySwitch::hours($on, $newFrom, $newUntil);
        $wasOn = $agency->agency_is_working !== false;
        if ($wasOn === $on && $from === $hours['works_from'] && $until === $hours['works_until']) {
            throw new ToolProblem("{$agency->names} ya está así.");
        }

        $copy = clone $agency;
        $copy->agency_is_working = $on;
        $copy->agency_works_from = $hours['works_from'];
        $copy->agency_works_until = $hours['works_until'];
        $stop = $this->hours->agencyStop($copy);
        $notes = [];
        if (!$on || $stop) {
            $notes[] = 'Mientras no trabaje, sus órdenes nuevas van a las otras agencias de su ciudad; donde es la única, esperan.';
        } else {
            $notes[] = 'Las órdenes que esperaban se reparten en el próximo minuto.';
        }
        if ($on && $stop) {
            $notes[] = "Ahora mismo no trabaja: {$stop}.";
        }
        $label = fn (?string $f, ?string $u) => $f || $u ? WorkingHours::hoursLabel($f, $u) : 'Sin horario (todo el día)';

        return new Proposal(
            $on ? 'Encender una agencia' : 'Apagar una agencia',
            ($on ? 'Encender ' : 'Apagar ') . $agency->names . ($from !== $hours['works_from'] || $until !== $hours['works_until'] ? ', horario ' . $label($hours['works_from'], $hours['works_until']) : ''),
            Proposal::lines([
                Proposal::line('Agencia', $agency->names),
                Proposal::line('Estado', $on ? 'Encendida' : 'Apagada', $wasOn !== $on ? ($wasOn ? 'Encendida' : 'Apagada') : null),
                Proposal::line('Horario', $label($hours['works_from'], $hours['works_until']),
                    $from !== $hours['works_from'] || $until !== $hours['works_until'] ? $label($from, $until) : null),
            ]),
            ['agency_id' => $agency->id, 'hours' => $hours],
            [],
            $notes,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $agency = User::findOrFail($payload['agency_id']);
        $this->switch->setAgency($agency, $payload['hours'], $context->user);
        $stop = $this->hours->agencyStop($agency->fresh());

        return new Outcome(
            !$payload['hours']['is_working'] ? "{$agency->names} apagada: no recibe órdenes hasta que la enciendas."
                : ($stop ? "{$agency->names} guardada. Ahora no trabaja ({$stop})." : "{$agency->names} trabajando. Las órdenes que esperaban se reparten en el próximo minuto."),
            ['label' => 'Ver ciudades y agencias', 'to' => '/admin/tiendas-y-ciudades?seccion=ciudades'],
        );
    }

    /** Una agencia que trabaja (no desactivada), por su nombre. */
    private static function agency(string $name): User
    {
        $all = User::active()->whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->orderBy('names')->pluck('names', 'id')->all();
        $found = Lookup::match($name, $all, 6);
        if (count($found) === 1) {
            return User::findOrFail($found[0]['id']);
        }

        throw new ToolProblem(($found !== [] ? 'Hay varias agencias parecidas: ' . implode(', ', array_column($found, 'name')) : 'No encontré esa agencia. Agencias: ' . implode(', ', $all)) . '. Pregúntale cuál.');
    }
}
