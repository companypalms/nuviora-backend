<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Finance\InventoryPurchases;
use Illuminate\Support\Facades\Validator;

/**
 * Una compra que ya llegó (paso 7): entran las unidades al almacén con su costo (el lote FIFO) y nace la deuda con el
 * proveedor, pagada si es al contado. Lo mismo que Inventario › Ingreso con compra (Finance\InventoryPurchases::create).
 * El stock solo entra como compra (lo decidió el dueño): así el costo de lo vendido sale bien.
 */
class PurchaseTool extends ChangeTool
{
    public function __construct(private InventoryPurchases $purchases)
    {
    }

    public function name(): string
    {
        return 'proponer_compra';
    }

    public function description(): string
    {
        return 'Propone registrar una compra de mercancía que YA LLEGÓ: entra el stock al almacén con su costo y queda la deuda con el proveedor (pagada si fue al contado). Hace falta: producto, cantidad (y por talla si el producto tiene tallas y el dueño lo dijo), proveedor, costo total y moneda (en bolívares, la tasa), y si se pagó al contado (desde qué cuenta) o queda a crédito (hasta cuándo). Sin almacén va al principal. Si falta algo, pregúntalo antes. Si todavía no llega, usa proponer_compra_por_recibir. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => self::purchaseProperties() + [
                'pago' => ['type' => 'string', 'enum' => ['contado', 'credito']],
                'cuenta' => ['type' => ['string', 'null'], 'description' => 'Al contado: de qué cuenta de la caja salió el dinero.'],
                'vence' => ['type' => ['string', 'null'], 'description' => 'A crédito: AAAA-MM-DD en que vence la deuda.'],
                'condiciones' => ['type' => ['string', 'null'], 'description' => 'A crédito: las condiciones, si las dijo ("30 días", "50 % al recibir"…).'],
                'notas' => ['type' => ['string', 'null']],
            ],
            'required' => ['producto', 'tallas', 'almacen', 'cantidad', 'proveedor', 'total', 'moneda', 'tasa', 'pago', 'cuenta', 'vence', 'condiciones', 'notas'],
            'additionalProperties' => false,
        ];
    }

    /** Lo que comparten las tres tarjetas de compras. */
    public static function purchaseProperties(): array
    {
        return [
            'producto' => ['type' => 'string', 'description' => 'El nombre del producto, como lo dijo el dueño.'],
            'tallas' => ['anyOf' => [
                ['type' => 'array', 'description' => 'Cuántas de cada talla, si el producto tiene tallas y el dueño las dijo. Lo que no se reparte entra sin talla.', 'items' => [
                    'type' => 'object',
                    'properties' => ['talla' => ['type' => 'string'], 'cantidad' => ['type' => 'integer']],
                    'required' => ['talla', 'cantidad'],
                    'additionalProperties' => false,
                ]],
                ['type' => 'null'],
            ]],
            'almacen' => ['type' => ['string', 'null'], 'description' => 'A qué almacén entra; null = el principal.'],
            'cantidad' => ['type' => 'integer', 'description' => 'Unidades en total.'],
            'proveedor' => ['type' => 'string', 'description' => 'A quién se le compró (un proveedor de Finanzas › Proveedores).'],
            'total' => ['type' => 'number', 'description' => 'Lo que costó la compra en total (no por unidad).'],
            'moneda' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'VES']],
            'tasa' => ['type' => ['number', 'null'], 'description' => 'Solo en bolívares: bolívares por dólar. Si no la dijo, null (se le pregunta).'],
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la compra…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $base = self::base($args);
        $payment = InventoryArgs::payment($args, $base['data']['currency'], $context);
        $data = $base['data'] + array_diff_key($payment, ['label' => true]) + [
            'notes' => is_string($args['notas'] ?? null) && trim($args['notas']) !== '' ? trim($args['notas']) : null,
        ];
        $validator = Validator::make($data, [
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'variants' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ] + InventoryPurchases::PAYMENT_RULES, InventoryPurchases::MESSAGES + ['total_amount.required' => 'Escribe cuánto costó la compra en total.']);
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }

        return new Proposal(
            'Registrar una compra',
            "Compra de {$data['quantity']} × {$base['productName']} a {$base['supplier']->name}: {$base['money']}",
            Proposal::lines([
                Proposal::line('Producto', $base['productName']),
                Proposal::line('Cantidad', "{$data['quantity']} unidades"),
                Proposal::line('Tallas', InventoryArgs::sizesLabel($data['variants'] ?? [], $data['quantity'])),
                Proposal::line('Entra a', $base['warehouse']->name),
                Proposal::line('Proveedor', $base['supplier']->name),
                Proposal::line('Costo total', $base['money']),
                Proposal::line('Costo por unidad', $base['unit']),
                Proposal::line('Pago', $payment['label']),
                Proposal::line('Notas', $data['notes']),
            ]),
            ['data' => $data],
            self::costWarnings($base['product']->id, $base['unitUsd']),
            array_values(array_filter([InventoryArgs::sinStockNote($base['product']->id)])),
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $purchase = self::guard(fn () => $this->purchases->create($payload['data']));

        return new Outcome(
            "Compra registrada: {$purchase->quantity} unidades a $" . number_format((float) $purchase->unit_cost_usd, 2, ',', '.') . ' cada una'
                . ($purchase->payment_type === 'credit' ? '. La deuda queda en Finanzas › Proveedores.' : ', pagada al contado.'),
            ['label' => 'Ver el inventario', 'to' => '/inventory'],
        );
    }

    /**
     * Producto, almacén, proveedor, cantidad, tallas y costo: lo que comparten la compra y la compra por recibir.
     *
     * @return array{data: array, product: \App\Models\Product, productName: string, warehouse: \App\Models\Warehouse, supplier: \App\Models\Supplier, money: string, unit: string, unitUsd: float}
     */
    public static function base(array $args): array
    {
        $product = InventoryArgs::product($args['producto'] ?? null);
        $quantity = (int) ($args['cantidad'] ?? 0);
        if ($quantity < 1) {
            throw new ToolProblem('¿Cuántas unidades son?');
        }
        $supplier = InventoryArgs::supplier($args['proveedor'] ?? null);
        $warehouse = InventoryArgs::warehouse($args['almacen'] ?? null);
        $currency = (string) ($args['moneda'] ?? '');
        $rate = FinanceArgs::rateFor($currency, $args['tasa'] ?? null);
        $total = round((float) ($args['total'] ?? 0), 2);
        if ($total <= 0) {
            throw new ToolProblem('¿Cuánto costó la compra en total?');
        }
        $split = InventoryArgs::sizes($product, $args['tallas'] ?? null, $quantity);
        $usd = Expense::toUsd($total, $currency, $rate);
        $unitUsd = round($usd['amount_usd'] / $quantity, 4);

        return [
            'data' => ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => $quantity,
                'variants' => $split ?: null, 'total_amount' => $total, 'currency' => $currency, 'rate' => $rate],
            'product' => $product,
            'productName' => InventoryArgs::productName($product),
            'warehouse' => $warehouse,
            'supplier' => $supplier,
            'money' => FinanceArgs::money($total, $currency, $rate),
            'unit' => '$' . number_format($unitUsd, 2, ',', '.'),
            'unitUsd' => $unitUsd,
        ];
    }

    /** Si el costo por unidad es muy distinto al de la última compra de ese producto, lo avisa (un total mal escrito). */
    public static function costWarnings(int $productId, float $unitUsd): array
    {
        $last = InventoryPurchase::where('product_id', $productId)->where('status', InventoryPurchase::RECEIVED)->orderByDesc('received_at')->orderByDesc('id')->first();
        if (!$last || (float) $last->unit_cost_usd <= 0) {
            return [];
        }
        $before = (float) $last->unit_cost_usd;
        if (abs($unitUsd - $before) / $before <= 0.3) {
            return [];
        }

        return ['El costo por unidad ($' . number_format($unitUsd, 2, ',', '.') . ') es muy distinto al de la última compra ($'
            . number_format($before, 2, ',', '.') . ($last->received_at ? ', el ' . $last->received_at->format('d/m') : '') . '): revisa el total.'];
    }

    /** Lo que rechaza el inventario (por ejemplo, una talla que ya no existe) vuelve como mensaje, igual que en la pantalla. */
    public static function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Exception $e) {
            if (get_class($e) === \Exception::class) {
                throw new ToolProblem($e->getMessage());
            }
            throw $e;
        }
    }
}
