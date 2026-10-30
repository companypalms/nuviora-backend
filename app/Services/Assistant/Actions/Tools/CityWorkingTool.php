<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\City;
use App\Services\Agencies\AgencySwitch;
use App\Services\Agencies\WorkingHours;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;

/**
 * Encender o apagar una ciudad (paso 7), lo mismo que Tiendas, ciudades y agencias › el interruptor de la ciudad
 * (Agencies\AgencySwitch::setCity). Apagada, sus órdenes nuevas esperan sin vendedora; al encenderla, las que esperaban
 * se reparten en el próximo minuto.
 */
class CityWorkingTool extends ChangeTool
{
    public function __construct(private AgencySwitch $switch, private WorkingHours $hours, private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'proponer_ciudad_trabajando';
    }

    public function description(): string
    {
        return 'Propone encender o apagar una ciudad (como el interruptor de Tiendas, ciudades y agencias). Apagada, sus órdenes nuevas esperan sin vendedora; encendida, las que esperaban se reparten en el próximo minuto. Para una agencia, usa proponer_agencia_trabajando. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ciudad' => ['type' => 'string'],
                'trabajando' => ['type' => 'boolean', 'description' => 'true = encenderla; false = apagarla.'],
            ],
            'required' => ['ciudad', 'trabajando'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando la ciudad…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $id = $this->lookup->cityId($args['ciudad'] ?? null);
        if ($id === null) {
            throw new ToolProblem('¿Qué ciudad?');
        }
        $city = City::findOrFail($id);
        $on = (bool) ($args['trabajando'] ?? false);
        $now = $city->is_working !== false;
        if ($on === $now) {
            throw new ToolProblem("{$city->name} ya está " . ($on ? 'encendida' : 'apagada') . '.');
        }
        $waiting = (int) ($this->hours->waitingByCity()[$city->id] ?? 0);
        $warnings = [];
        $notes = [];
        if ($on) {
            $copy = clone $city;
            $copy->is_working = true;
            if ($stop = $this->hours->cityStop($copy)) {
                $warnings[] = "Aunque la enciendas no va a trabajar: {$stop}.";
            }
            if ($waiting > 0) {
                $notes[] = ($waiting === 1 ? 'Hay 1 orden esperando' : "Hay {$waiting} órdenes esperando") . ': se reparten en el próximo minuto.';
            }
        } else {
            $notes[] = 'Sus órdenes nuevas esperan sin vendedora hasta que la enciendas.';
        }

        return new Proposal(
            $on ? 'Encender una ciudad' : 'Apagar una ciudad',
            ($on ? 'Encender ' : 'Apagar ') . $city->name,
            Proposal::lines([
                Proposal::line('Ciudad', $city->name),
                Proposal::line('Estado', $on ? 'Encendida' : 'Apagada', $on ? 'Apagada' : 'Encendida'),
            ]),
            ['city_id' => $city->id, 'working' => $on],
            $warnings,
            $notes,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $city = City::findOrFail($payload['city_id']);
        $this->switch->setCity($city, (bool) $payload['working'], $context->user);

        return new Outcome(
            $payload['working'] ? "{$city->name} trabajando. Las órdenes que esperaban se reparten en el próximo minuto." : "{$city->name} apagada: sus órdenes no pasan a vendedora hasta que la enciendas.",
            ['label' => 'Ver ciudades y agencias', 'to' => '/admin/tiendas-y-ciudades?seccion=ciudades'],
        );
    }
}
