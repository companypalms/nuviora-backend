<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Lookup;
use App\Services\Metrics\Period;
use App\Services\Metrics\Team\TeamCatalog;
use Carbon\CarbonImmutable;

/** Busca a alguien del equipo por su nombre, en un rol o en todos. Solo nombres y grupos, sin números. */
class FindPersonTool implements Tool
{
    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'buscar_persona';
    }

    public function description(): string
    {
        return 'Busca a alguien del equipo por su nombre (sin importar acentos ni mayúsculas): vendedoras, grupos de venta (líderes), agencias, repartidores u operadoras. Devuelve su id, su rol y su grupo. Úsala cuando no sepas de qué rol es una persona o haya que distinguir entre varias con el mismo nombre.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nombre' => ['type' => 'string', 'description' => 'El nombre como lo dijo el dueño.'],
                'rol' => ['type' => ['string', 'null'], 'enum' => [...array_keys(Lookup::ROLES), null], 'description' => 'Si se sabe, el rol. null = buscar en todos.'],
            ],
            'required' => ['nombre', 'rol'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Buscando a ' . mb_strimwidth((string) ($args['nombre'] ?? ''), 0, 40, '…') . '…';
    }

    public function run(array $args): ToolResult
    {
        $name = trim((string) ($args['nombre'] ?? ''));
        if ($name === '') {
            throw new ToolProblem('Falta el nombre.');
        }
        $roles = isset($args['rol']) && isset(Lookup::ROLES[$args['rol']]) ? [$args['rol'] => Lookup::ROLES[$args['rol']]] : Lookup::ROLES;
        $month = Period::fromPreset('month', null, null, CarbonImmutable::now());

        $found = [];
        foreach ($roles as $word => $key) {
            foreach ($this->lookup->findPeople($key, $name, $month) as $p) {
                $found[] = array_filter(['id' => $p['id'], 'nombre' => $p['name'], 'rol' => $word, 'grupo' => $p['group']]);
            }
        }
        if ($found === []) {
            return ToolResult::problem("No encontré a nadie que se llame \"{$name}\"" . (count($roles) === 1 ? ' en ' . TeamCatalog::find(reset($roles))->label() : '') . '.');
        }

        return new ToolResult(['encontradas' => $found]);
    }
}
