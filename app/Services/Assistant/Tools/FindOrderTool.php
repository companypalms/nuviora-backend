<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\OrderFinder;

/** Busca órdenes por número o por cliente (nombre o teléfono), como el buscador de Órdenes. */
class FindOrderTool implements Tool
{
    public function __construct(private OrderFinder $finder)
    {
    }

    public function name(): string
    {
        return 'buscar_orden';
    }

    public function description(): string
    {
        return 'Busca órdenes por su número ("9003", "#P9003") o por el cliente (su nombre o su teléfono). Devuelve las más nuevas primero (máximo 10) con su estado, total, ciudad, vendedora y agencia. Para saber qué pasó con una orden, usa historial_orden.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'numero' => ['type' => ['string', 'null'], 'description' => 'El número de la orden, como lo dijo el dueño.'],
                'cliente' => ['type' => ['string', 'null'], 'description' => 'El nombre o el teléfono del cliente.'],
            ],
            'required' => ['numero', 'cliente'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Buscando la orden…';
    }

    public function run(array $args): ToolResult
    {
        if (trim((string) ($args['numero'] ?? '')) === '' && trim((string) ($args['cliente'] ?? '')) === '') {
            throw new ToolProblem('Dime el número de la orden o el nombre o teléfono del cliente.');
        }
        $orders = $this->finder->search($args['numero'] ?? null, $args['cliente'] ?? null);
        if ($orders->isEmpty()) {
            return ToolResult::problem('No encontré ninguna orden con esos datos.');
        }

        return new ToolResult(
            ['encontradas' => $orders->map(fn ($o) => OrderFinder::summary($o))->all(), 'hay_mas' => $orders->count() >= OrderFinder::MAX ? true : null],
            [OrderFinder::block($orders->count() === 1 ? 'La orden' : 'Las órdenes que encontré', $orders)],
        );
    }
}
