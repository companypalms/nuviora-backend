<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Finance\CashLedger;

/**
 * Lo que comparten las tarjetas de compras del asistente (paso 7): encontrar el producto, el almacén, el proveedor y las
 * tallas por su nombre (si hay dudas, la IA pregunta), cómo se paga, y cuántas órdenes esperan ese producto en "Sin
 * Stock" (al entrar el stock pueden salir de ahí, y el cliente recibe el WhatsApp de siempre).
 */
final class InventoryArgs
{
    public static function product(?string $name): Product
    {
        if (trim((string) $name) === '') {
            throw new ToolProblem('¿Qué producto?');
        }
        $all = Product::orderBy('title')->get(['id', 'title', 'showable_name'])->mapWithKeys(fn ($p) => [$p->id => $p->showable_name ?: $p->title])->all();

        return Product::findOrFail(self::pick($name, $all, 'productos'));
    }

    public static function productName(Product $product): string
    {
        return $product->showable_name ?: $product->title;
    }

    /** El almacén por su nombre; sin nombre, el principal. Nunca "En tránsito". */
    public static function warehouse(?string $name): Warehouse
    {
        $all = Warehouse::active()->where('id', '!=', Warehouse::transitId())->orderByDesc('is_main')->orderBy('name')->pluck('name', 'id')->all();
        if (trim((string) $name) === '') {
            $main = Warehouse::active()->where('is_main', true)->where('id', '!=', Warehouse::transitId())->first();
            if ($main) {
                return $main;
            }
            throw new ToolProblem('¿A qué almacén entra? Almacenes: ' . implode(', ', $all) . '.');
        }

        return Warehouse::findOrFail(self::pick($name, $all, 'almacenes'));
    }

    public static function supplier(?string $name): Supplier
    {
        $all = Supplier::active()->orderBy('name')->pluck('name', 'id')->all();
        if ($all === []) {
            throw new ToolProblem('No hay proveedores cargados: que lo agregue primero en Finanzas › Proveedores.');
        }
        if (trim((string) $name) === '') {
            throw new ToolProblem('¿A qué proveedor se le compró? Proveedores: ' . implode(', ', array_slice($all, 0, 15)) . '.');
        }

        return Supplier::findOrFail(self::pick($name, $all, 'proveedores'));
    }

    /**
     * Las tallas [{talla, cantidad}] como las pide el inventario ({variant_id: cantidad}). Lo que no se reparte entra "sin
     * talla", como en la pantalla.
     *
     * @return array<int, int>
     */
    public static function sizes(Product $product, mixed $sizes, int $quantity): array
    {
        if (!is_array($sizes) || $sizes === []) {
            return [];
        }
        $variants = ProductVariant::where('product_id', $product->id)->where('is_active', true)->orderBy('id')->pluck('title', 'id')->all();
        if ($variants === []) {
            throw new ToolProblem('Ese producto no tiene tallas cargadas: va sin tallas.');
        }
        $out = [];
        foreach ($sizes as $row) {
            $title = trim((string) ($row['talla'] ?? ''));
            $qty = (int) ($row['cantidad'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $match = array_values(array_filter(array_keys($variants), fn ($id) => Lookup::normalize($variants[$id]) === Lookup::normalize($title)));
            if (count($match) !== 1) {
                throw new ToolProblem("La talla \"{$title}\" no es de este producto. Tallas: " . implode(', ', $variants) . '.');
            }
            $out[$match[0]] = ($out[$match[0]] ?? 0) + $qty;
        }
        if (array_sum($out) > $quantity) {
            throw new ToolProblem('La suma por talla (' . array_sum($out) . ") supera la cantidad ({$quantity}).");
        }
        ksort($out);

        return $out;
    }

    /** "S: 10 · M: 20 · sin talla: 5", o null sin tallas. */
    public static function sizesLabel(array $split, int $quantity): ?string
    {
        if ($split === []) {
            return null;
        }
        $titles = ProductVariant::whereIn('id', array_keys($split))->pluck('title', 'id');
        $parts = array_map(fn ($id, $qty) => ($titles[$id] ?? "#{$id}") . ": {$qty}", array_keys($split), $split);
        $rest = $quantity - array_sum($split);

        return implode(' · ', $parts) . ($rest > 0 ? " · sin talla: {$rest}" : '');
    }

    /**
     * Cómo se paga: al contado desde una cuenta de la caja, o a crédito con su vencimiento.
     *
     * @return array{payment_type: string, financial_account_id: ?int, due_date: ?string, terms: ?string, label: string}
     */
    public static function payment(array $args, string $currency, ActionContext $context): array
    {
        $credit = ($args['pago'] ?? null) === 'credito';
        if (!in_array($args['pago'] ?? null, ['contado', 'credito'], true)) {
            throw new ToolProblem('¿Se pagó al contado (desde qué cuenta) o queda a crédito (hasta cuándo)?');
        }
        if ($credit) {
            if (empty($args['vence'])) {
                throw new ToolProblem('A crédito: ¿hasta cuándo hay para pagar?');
            }
            $due = FinanceArgs::date($args['vence'], $context, 'El vencimiento');
            $terms = is_string($args['condiciones'] ?? null) && trim($args['condiciones']) !== '' ? trim($args['condiciones']) : null;

            return ['payment_type' => 'credit', 'financial_account_id' => null, 'due_date' => $due, 'terms' => $terms,
                'label' => 'A crédito, vence el ' . Schedule::dayName($due) . ($terms ? " ({$terms})" : '')];
        }
        $account = FinanceArgs::account($args['cuenta'] ?? null);
        CashLedger::assertAccount($account->id, $currency, $context->now->toDateString()); // la cuenta y la moneda, como la caja

        return ['payment_type' => 'cash', 'financial_account_id' => $account->id, 'due_date' => null, 'terms' => null,
            'label' => 'Al contado desde ' . FinanceArgs::accountLabel($account)];
    }

    /** Cuántas órdenes esperan ese producto en "Sin Stock". */
    public static function waitingSinStock(int $productId): int
    {
        return Order::whereHas('status', fn ($q) => $q->where('description', OrderStatus::SIN_STOCK))
            ->whereHas('products', fn ($q) => $q->where('product_id', $productId))->count();
    }

    /** La nota de "Sin Stock" para la tarjeta, o null. */
    public static function sinStockNote(int $productId): ?string
    {
        $n = self::waitingSinStock($productId);

        return $n > 0
            ? ($n === 1 ? 'Hay 1 orden' : "Hay {$n} órdenes") . ' en "Sin Stock" con este producto: al entrar el stock pueden salir de ahí, y el cliente recibe el WhatsApp de siempre.'
            : null;
    }

    /** El que corresponde al nombre; si no hay o hay varios parecidos, se lo dice a la IA con la lista. */
    private static function pick(string $name, array $all, string $plural): int
    {
        $found = Lookup::match($name, $all, 6);
        if (count($found) === 1) {
            return $found[0]['id'];
        }
        if ($found !== []) {
            throw new ToolProblem("Hay varios {$plural} parecidos a \"{$name}\": " . implode(', ', array_column($found, 'name')) . '. Pregúntale cuál.');
        }
        $some = array_slice($all, 0, 15);

        throw new ToolProblem("No encontré \"{$name}\" entre los {$plural}" . ($some ? ': ' . implode(', ', $some) . (count($all) > 15 ? '…' : '') : '') . '. Pregúntale cuál.');
    }
}
