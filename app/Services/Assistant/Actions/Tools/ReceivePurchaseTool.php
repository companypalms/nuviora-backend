<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Http\Controllers\FinancePurchaseController;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Finance\InventoryPurchases;
use Illuminate\Support\Facades\Validator;

/**
 * Recibir una compra que estaba por recibir (paso 7): con la cantidad y el costo reales entra el stock, nace el lote y la
 * deuda (pagada si es al contado). Lo mismo que Compras por recibir › Recibir (Finance\InventoryPurchases::receive).
 * Lo que no dice el dueño queda como se pidió.
 */
class ReceivePurchaseTool extends ChangeTool
{
    public function __construct(private InventoryPurchases $purchases)
    {
    }

    public function name(): string
    {
        return 'proponer_recibir_compra';
    }

    public function description(): string
    {
        return 'Propone recibir una compra que estaba "por recibir" (ver la herramienta en_camino): entra el stock con la cantidad y el costo reales, y queda la deuda o el pago. Lo que el dueño no diga (cantidad, total, moneda, almacén) queda como se pidió. Hace falta saber si se pagó al contado (desde qué cuenta) o queda a crédito (hasta cuándo). El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        $props = PurchaseTool::purchaseProperties();

        return [
            'type' => 'object',
            'properties' => [
                'compra' => ['type' => 'string', 'description' => 'Cuál: su número ("#12") o el producto y el proveedor, como lo dijo el dueño.'],
                'cantidad' => ['type' => ['integer', 'null'], 'description' => 'Las unidades que llegaron de verdad; null = las que se pidieron.'],
                'tallas' => $props['tallas'],
                'total' => ['type' => ['number', 'null'], 'description' => 'Lo que costó de verdad en total; null = lo que se esperaba.'],
                'moneda' => ['anyOf' => [['type' => 'string', 'enum' => ['USD', 'USDT', 'VES'], 'description' => 'null = la de la compra.'], ['type' => 'null']]],
                'tasa' => $props['tasa'],
                'almacen' => ['type' => ['string', 'null'], 'description' => 'Si llegó a otro almacén; null = al que se pidió.'],
                'pago' => ['type' => 'string', 'enum' => ['contado', 'credito']],
                'cuenta' => ['type' => ['string', 'null'], 'description' => 'Al contado: de qué cuenta de la caja salió el dinero.'],
                'vence' => ['type' => ['string', 'null'], 'description' => 'A crédito: AAAA-MM-DD en que vence la deuda.'],
                'condiciones' => ['type' => ['string', 'null']],
                'notas' => ['type' => ['string', 'null']],
            ],
            'required' => ['compra', 'cantidad', 'tallas', 'total', 'moneda', 'tasa', 'almacen', 'pago', 'cuenta', 'vence', 'condiciones', 'notas'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Buscando la compra…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $purchase = self::find((string) ($args['compra'] ?? ''));
        $product = $purchase->product;
        $quantity = $args['cantidad'] !== null ? (int) $args['cantidad'] : (int) $purchase->expected_quantity;
        if ($quantity < 1) {
            throw new ToolProblem('¿Cuántas unidades llegaron?');
        }
        $currency = (string) ($args['moneda'] ?? $purchase->currency);
        $sameCurrency = $currency === $purchase->currency;
        $total = round((float) ($args['total'] ?? ($sameCurrency ? $purchase->expected_total_amount : 0)), 2);
        if ($total <= 0) {
            throw new ToolProblem('¿Cuánto costó de verdad, en total?');
        }
        $rate = FinanceArgs::rateFor($currency, $args['tasa'] ?? ($sameCurrency && $currency === 'VES' ? $purchase->rate : null));
        $warehouse = $args['almacen'] ? InventoryArgs::warehouse($args['almacen']) : $purchase->warehouse;
        $payment = InventoryArgs::payment($args, $currency, $context);
        $split = $args['tallas'] !== null
            ? InventoryArgs::sizes($product, $args['tallas'], $quantity)
            : ($quantity === (int) $purchase->expected_quantity ? array_map('intval', (array) ($purchase->expected_variants ?? [])) : []);
        ksort($split);

        $data = ['quantity' => $quantity, 'variants' => $split ?: null, 'warehouse_id' => $warehouse->id, 'total_amount' => $total, 'currency' => $currency, 'rate' => $rate,
            'notes' => is_string($args['notas'] ?? null) && trim($args['notas']) !== '' ? trim($args['notas']) : null]
            + array_diff_key($payment, ['label' => true]);
        $validator = Validator::make($data, FinancePurchaseController::RECEIVE_RULES, FinancePurchaseController::receiveMessages());
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }
        $usd = Expense::toUsd($total, $currency, $rate);
        $unitUsd = round($usd['amount_usd'] / $quantity, 4);
        $expectedMoney = FinanceArgs::money((float) $purchase->expected_total_amount, $purchase->currency, $purchase->currency === 'VES' ? (float) $purchase->rate : null);
        $money = FinanceArgs::money($total, $currency, $rate);
        $name = InventoryArgs::productName($product);

        return new Proposal(
            'Recibir una compra',
            "Recibir la compra #{$purchase->id} ({$name} de {$purchase->supplier->name}): {$quantity} unidades por {$money}",
            Proposal::lines([
                Proposal::line('Compra', "#{$purchase->id} · {$name} de {$purchase->supplier->name}"),
                Proposal::line('Llegaron', "{$quantity} unidades", $quantity !== (int) $purchase->expected_quantity ? "{$purchase->expected_quantity} pedidas" : null),
                Proposal::line('Tallas', InventoryArgs::sizesLabel($split, $quantity)),
                Proposal::line('Costo total', $money, $money !== $expectedMoney ? $expectedMoney : null),
                Proposal::line('Costo por unidad', '$' . number_format($unitUsd, 2, ',', '.')),
                Proposal::line('Entra a', $warehouse->name, (int) $warehouse->id !== (int) $purchase->warehouse_id ? $purchase->warehouse?->name : null),
                Proposal::line('Pago', $payment['label']),
                Proposal::line('Notas', $data['notes']),
            ]),
            ['purchase_id' => $purchase->id, 'data' => $data],
            PurchaseTool::costWarnings($product->id, $unitUsd),
            array_values(array_filter([InventoryArgs::sinStockNote($product->id)])),
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $purchase = InventoryPurchase::findOrFail($payload['purchase_id']);
        $received = PurchaseTool::guard(fn () => $this->purchases->receive($purchase, $payload['data']));

        return new Outcome(
            "Compra recibida: {$received->quantity} unidades a $" . number_format((float) $received->unit_cost_usd, 2, ',', '.') . ' cada una'
                . ($received->payment_type === 'credit' ? '. La deuda queda en Finanzas › Proveedores.' : ', pagada al contado.'),
            ['label' => 'Ver el inventario', 'to' => '/inventory'],
        );
    }

    /** La compra por recibir: por su número o por el producto y el proveedor. */
    private static function find(string $text): InventoryPurchase
    {
        $pending = InventoryPurchase::with(['product:id,title,showable_name', 'supplier:id,name', 'warehouse:id,name'])
            ->where('status', InventoryPurchase::PENDING)->orderBy('expected_at')->get();
        if ($pending->isEmpty()) {
            throw new ToolProblem('No hay compras por recibir.');
        }
        $labels = $pending->mapWithKeys(fn (InventoryPurchase $p) => [$p->id => "#{$p->id} " . InventoryArgs::productName($p->product) . " de {$p->supplier?->name}"
            . " ({$p->expected_quantity} u., llega el " . $p->expected_at?->format('d/m') . ')'])->all();
        if (preg_match('/#?\s*(\d+)\b/u', $text, $m) && isset($labels[(int) $m[1]])) {
            return $pending->firstWhere('id', (int) $m[1]);
        }
        $found = Lookup::match($text, $labels, 6);
        if (count($found) === 1) {
            return $pending->firstWhere('id', $found[0]['id']);
        }

        throw new ToolProblem(($found !== [] ? '¿Cuál de estas? ' : 'No encontré esa compra. Por recibir: ') . implode(' · ', $found !== [] ? array_column($found, 'name') : array_slice($labels, 0, 10)) . '.');
    }
}
