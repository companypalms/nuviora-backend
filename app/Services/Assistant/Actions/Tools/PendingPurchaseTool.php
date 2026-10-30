<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Http\Controllers\FinancePurchaseController;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Finance\InventoryPurchases;
use Illuminate\Support\Facades\Validator;

/**
 * Una compra hecha que todavía no llega (paso 7): queda "por recibir" con lo que debería costar y cuándo llega. No suma
 * stock ni mueve dinero hasta recibirla. Lo mismo que Compras por recibir (Finance\InventoryPurchases::createPending).
 */
class PendingPurchaseTool extends ChangeTool
{
    public function __construct(private InventoryPurchases $purchases)
    {
    }

    public function name(): string
    {
        return 'proponer_compra_por_recibir';
    }

    public function description(): string
    {
        return 'Propone anotar una compra de mercancía que el dueño YA PIDIÓ pero TODAVÍA NO LLEGA: queda "por recibir" con lo que debería costar y la fecha en que llega. No suma stock ni mueve dinero (eso pasa al recibirla, con proponer_recibir_compra). Hace falta: producto, cantidad, proveedor, costo total y moneda, y cuándo llega. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => PurchaseTool::purchaseProperties() + [
                'llega' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD en que debería llegar (hoy o después). Si no lo dijo, null (se le pregunta).'],
                'notas' => ['type' => ['string', 'null']],
            ],
            'required' => ['producto', 'tallas', 'almacen', 'cantidad', 'proveedor', 'total', 'moneda', 'tasa', 'llega', 'notas'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la compra por recibir…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $base = PurchaseTool::base($args);
        if (empty($args['llega'])) {
            throw new ToolProblem('¿Cuándo debería llegar?');
        }
        $data = $base['data'] + [
            'expected_at' => FinanceArgs::date($args['llega'], $context, 'La fecha en que llega'),
            'notes' => is_string($args['notas'] ?? null) && trim($args['notas']) !== '' ? trim($args['notas']) : null,
        ];
        $validator = Validator::make($data, FinancePurchaseController::PENDING_RULES, FinancePurchaseController::pendingMessages());
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }

        return new Proposal(
            'Anotar una compra por recibir',
            "Compra por recibir: {$data['quantity']} × {$base['productName']} de {$base['supplier']->name}, llega el " . Schedule::dayName($data['expected_at']),
            Proposal::lines([
                Proposal::line('Producto', $base['productName']),
                Proposal::line('Cantidad', "{$data['quantity']} unidades"),
                Proposal::line('Tallas', InventoryArgs::sizesLabel($data['variants'] ?? [], $data['quantity'])),
                Proposal::line('Llega a', $base['warehouse']->name),
                Proposal::line('Llega el', Schedule::dayName($data['expected_at'])),
                Proposal::line('Proveedor', $base['supplier']->name),
                Proposal::line('Debería costar', $base['money']),
                Proposal::line('Costo por unidad', $base['unit']),
                Proposal::line('Notas', $data['notes']),
            ]),
            ['data' => $data],
            PurchaseTool::costWarnings($base['product']->id, $base['unitUsd']),
            ['No suma stock ni mueve dinero hasta que la recibas.'],
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $purchase = PurchaseTool::guard(fn () => $this->purchases->createPending($payload['data']));

        return new Outcome(
            "Compra por recibir anotada: {$purchase->quantity} unidades, llega el " . $purchase->expected_at?->format('d/m') . '. No suma stock hasta que la recibas.',
            ['label' => 'Ver el inventario', 'to' => '/inventory'],
        );
    }
}
