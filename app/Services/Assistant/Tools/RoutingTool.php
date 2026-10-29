<?php

namespace App\Services\Assistant\Tools;

use App\Services\Agencies\RoutingOverview;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;

/** Las agencias y las ciudades (Tiendas, ciudades y agencias): quién trabaja, en qué horario, dónde y con qué %. */
class RoutingTool implements Tool
{
    public function __construct(private RoutingOverview $overview)
    {
    }

    public function name(): string
    {
        return 'agencias_ciudades';
    }

    public function description(): string
    {
        return 'Las agencias y las ciudades, como están ahora: qué agencias están trabajando (y su horario), en qué ciudades entregan, cuántos pedidos activos tienen y su máximo, su tarifa; qué ciudades están trabajando, qué agencias reparten en cada una y con qué %, cuántos pedidos esperan agencia y cuántos esperan porque la ciudad está apagada. Se puede pedir una sola agencia o ciudad.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agencia' => ['type' => ['string', 'null'], 'description' => 'Solo esa agencia. null = todas.'],
                'ciudad' => ['type' => ['string', 'null'], 'description' => 'Solo esa ciudad. null = todas.'],
            ],
            'required' => ['agencia', 'ciudad'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando las agencias y las ciudades…';
    }

    public function run(array $args): ToolResult
    {
        $p = $this->overview->payload();
        $agencies = collect($p['agencies']);
        $cities = collect($p['cities']);
        if (!empty($args['agencia'])) {
            $ids = array_column(Lookup::match((string) $args['agencia'], $agencies->pluck('names', 'id')->all()), 'id');
            $agencies = $agencies->whereIn('id', $ids)->values();
            if ($agencies->isEmpty()) {
                throw new ToolProblem("No encontré la agencia \"{$args['agencia']}\".");
            }
        }
        if (!empty($args['ciudad'])) {
            $ids = array_column(Lookup::match((string) $args['ciudad'], $cities->pluck('name', 'id')->all()), 'id');
            $cities = $cities->whereIn('id', $ids)->values();
            if ($cities->isEmpty()) {
                throw new ToolProblem("No encontré la ciudad \"{$args['ciudad']}\".");
            }
        }
        $onlyAgency = !empty($args['agencia']) && empty($args['ciudad']);
        $onlyCity = !empty($args['ciudad']) && empty($args['agencia']);

        $data = array_filter([
            'agencias' => $onlyCity ? null : $agencies->map(fn ($a) => array_filter([
                'agencia' => $a['names'],
                'trabajando' => $a['is_working'] && !$a['stop_reason'] ? 'sí' : 'no' . ($a['stop_reason'] ? " ({$a['stop_reason']})" : ''),
                'horario' => $a['works_from'] && $a['works_until'] ? "{$a['works_from']} a {$a['works_until']}" : null,
                'ciudades' => $a['cities'] ? implode(', ', $a['cities']) : 'ninguna',
                'pedidos_activos' => $a['active'] . ($a['max_active_orders'] ? " de {$a['max_active_orders']} como máximo" . ($a['full'] ? ' (llena)' : '') : ''),
                'tarifa' => Present::value((float) $a['delivery_cost'], 'money'),
            ]))->all(),
            'ciudades' => $onlyAgency ? null : $cities->map(fn ($c) => array_filter([
                'ciudad' => $c['name'],
                'trabajando' => $c['is_working'] && !$c['stop_reason'] ? 'sí' : 'no' . ($c['stop_reason'] ? " ({$c['stop_reason']})" : ''),
                'agencias' => collect($c['agencies'])->where('is_active', true)->map(fn ($a) => $a['names'] . ($a['share'] ? ' (' . round($a['share'] * 100) . ' %)' : ''))->implode(', ') ?: 'ninguna activa',
                'esperan_agencia' => $c['pending'] ?: null,
                'esperan_que_la_ciudad_trabaje' => $c['waiting'] ?: null,
            ]))->all(),
        ], fn ($v) => $v !== null);

        return new ToolResult($data, [['type' => 'link', 'label' => 'Abrir Tiendas, ciudades y agencias', 'to' => '/admin/tiendas-y-ciudades']]);
    }
}
