<?php

namespace App\Services\Calendar\Rules;

use Carbon\CarbonImmutable;

/**
 * Los gastos fijos de Finanzas (Gastos recurrentes: Shopify, Hostinger, editores…) en el calendario del dueño (Etapa 3,
 * paso 3). PHP puro. Cada vencimiento sale en su día de pago (si cae domingo, el sábado antes: regla de los pagos), con
 * "Registrar pago", que abre la misma ventana de Finanzas y saca el pago de la caja. Se tilda solo cuando el vencimiento
 * tiene un pago registrado (no anulado).
 */
final class ExpenseRules
{
    /** "Registrar pago" se ofrece hasta 30 días antes: es lo que muestra la ventana de pago de Finanzas. */
    public const PAY_AHEAD_DAYS = 30;

    public const HELP = 'Un gasto fijo de Finanzas › Gastos recurrentes. "Registrar pago" anota el pago y lo saca de la caja; si se paga con la tarjeta, igual hay que registrarlo para que la caja cuadre.';

    /**
     * Lo que dice un vencimiento.
     *
     * @param array{due_date: string, amount: float, currency: string, amount_usd: float, paid: bool} $due
     * @return array{title: string, details: string, actions: list<array>, auto_done: bool, auto_note: ?string}
     */
    public static function occurrence(int $id, string $name, ?string $category, array $due, string $today): array
    {
        $details = self::amount((float) $due['amount'], $due['currency']);
        if ($due['currency'] !== 'USD' && $due['currency'] !== 'USDT' && $due['amount_usd'] > 0) {
            $details .= ' (≈ ' . ReminderRules::money((float) $due['amount_usd']) . ')';
        }
        if ($category) {
            $details .= ' · ' . $category;
        }
        $actions = [];
        $payable = CarbonImmutable::parse($today)->addDays(self::PAY_AHEAD_DAYS)->toDateString();
        if (!$due['paid'] && $due['due_date'] <= $payable) {
            $actions[] = ['label' => 'Registrar pago', 'pay' => $id];
        }
        $actions[] = ['label' => 'Ver en Finanzas', 'link' => '/finanzas?tab=recurring'];

        return [
            'title' => 'Pagar ' . $name,
            'details' => $details . '.',
            'actions' => $actions,
            'auto_done' => (bool) $due['paid'],
            'auto_note' => $due['paid'] ? 'El pago está registrado en la caja.' : null,
        ];
    }

    /** "$39,00", "39,00 USDT", "Bs 1.234,00". */
    public static function amount(float $amount, string $currency): string
    {
        $n = number_format($amount, 2, ',', '.');

        return match ($currency) {
            'USD' => '$' . $n,
            'VES' => 'Bs ' . $n,
            default => "{$n} {$currency}",
        };
    }
}
