<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\FinancialAuditLog;
use App\Models\RecurringExpense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Los gastos manuales y los recurrentes (Módulo 4 de Fran, §24 a §33): las reglas y el alta. Lo usan la pantalla Finanzas
 * › Gastos (FinanceExpenseController, de donde salió sin cambiar nada) y el asistente del dueño.
 */
class Expenses
{
    public const MESSAGES = [
        'concept.required' => 'Escribe el concepto del gasto.',
        'name.required' => 'Escribe el nombre del gasto.',
        'expense_category_id.required' => 'Elige la categoría.',
        'amount.required' => 'Escribe el monto.',
        'amount.min' => 'El monto tiene que ser mayor que 0.',
        'rate.required_if' => 'En bolívares, escribe la tasa (bolívares por dólar) de este gasto.',
        'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura.',
        'reason.required' => 'Escribe el motivo del cambio: queda en el historial.',
        'receipt.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
        'receipt.max' => 'El comprobante no puede pasar de 10 MB.',
        'interval_days.required_if' => 'Escribe cada cuántos días se paga.',
        'payment_day.required_if' => 'Elige el día de pago.',
        'payment_date.required_if' => 'Elige la fecha de pago.',
        'valid_from.required' => 'Elige desde cuándo vale el monto.',
    ];

    public function __construct(private OtherPayments $payments)
    {
    }

    /**
     * Un gasto manual, con cómo se pagó (OtherPayments::applyExpensePayment) y su registro en el historial (§82).
     * $data ya validado con expenseRules(). $receiptPath: el comprobante ya guardado en el disco local, o null.
     */
    public function create(array $data, ?string $receiptPath, ?int $userId): Expense
    {
        return DB::transaction(function () use ($data, $receiptPath, $userId) {
            $expense = Expense::create($this->expenseAttributes($data) + ['receipt_path' => $receiptPath, 'created_by' => $userId]);
            $this->payments->applyExpensePayment($expense, $data);
            FinancialAuditLog::record('expense_created', 'expense', $expense->id, null, $expense->fresh()->snapshot(), null, $userId);

            return $expense;
        });
    }

    /** Un gasto recurrente con su primer monto (§27). $data ya validado con recurringRules() + amountRules(). */
    public function createRecurring(array $data, ?int $userId): RecurringExpense
    {
        return DB::transaction(function () use ($data, $userId) {
            $recurring = RecurringExpense::create($this->recurringAttributes($data) + ['is_active' => true, 'created_by' => $userId]);
            $version = $recurring->versions()->create($this->versionAttributes($data, true, $userId));
            FinancialAuditLog::record('recurring_expense_created', 'recurring_expense', $recurring->id, null,
                $recurring->fresh()->snapshot() + array_intersect_key($version->toPayload(), array_flip(['amount', 'currency', 'rate', 'amount_usd', 'valid_from'])), null, $userId);

            return $recurring;
        });
    }

    public function expenseRules(): array
    {
        return [
            'concept' => 'required|string|max:150',
            'expense_category_id' => 'required|integer|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => ['required', Rule::in(Expense::CURRENCIES)],
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'expense_date' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:2000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'product_id' => 'nullable|integer|exists:products,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'agency_id' => 'nullable|integer|exists:users,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            // Entrega 3d (§28, §44): cómo se pagó
            'payment' => ['nullable', Rule::in(['paid', 'payable', 'none'])],
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'payee_type' => ['nullable', Rule::in(array_keys(OtherPayments::PAYEE_TYPES))],
            'payee_name' => 'nullable|string|max:150',
            'due_date' => 'nullable|date',
        ];
    }

    public function expenseAttributes(array $data): array
    {
        $usd = Expense::toUsd((float) $data['amount'], $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);

        return [
            'concept' => trim($data['concept']),
            'expense_category_id' => $data['expense_category_id'],
            'amount' => round((float) $data['amount'], 2),
            'currency' => $data['currency'],
            'expense_date' => Carbon::parse($data['expense_date'])->toDateString(),
            'description' => $data['description'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'agency_id' => $data['agency_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
        ] + $usd;
    }

    /**
     * $frequency: la frecuencia de lo que se valida (antes se leía de la petición con request(), y el asistente valida
     * fuera de ella). El día de un gasto semanal va de 1 (lunes) a 7 (domingo).
     */
    public function recurringRules(?string $frequency): array
    {
        return [
            'name' => 'required|string|max:150',
            'expense_category_id' => 'required|integer|exists:expense_categories,id',
            'frequency' => ['required', Rule::in(array_keys(RecurringExpense::FREQUENCIES))],
            'interval_days' => 'nullable|required_if:frequency,custom|integer|min:1|max:3650',
            'payment_day' => ['nullable', 'required_if:frequency,weekly,monthly', 'integer', 'min:1',
                fn ($attr, $value, $fail) => $frequency === 'weekly' && $value > 7 ? $fail('En un gasto semanal, el día de pago va del lunes al domingo.') : null,
                'max:31'],
            'payment_date' => 'nullable|required_if:frequency,annual,custom|date',
            'product_id' => 'nullable|integer|exists:products,id',
            'city_id' => 'nullable|integer|exists:cities,id',
        ];
    }

    public function recurringAttributes(array $data): array
    {
        $frequency = $data['frequency'];

        return [
            'name' => trim($data['name']),
            'expense_category_id' => $data['expense_category_id'],
            'frequency' => $frequency,
            'interval_days' => $frequency === 'custom' ? (int) $data['interval_days'] : null,
            'payment_day' => in_array($frequency, ['weekly', 'monthly'], true) ? (int) $data['payment_day'] : null,
            'payment_date' => in_array($frequency, ['annual', 'custom'], true) ? Carbon::parse($data['payment_date'])->toDateString() : null,
            'product_id' => $data['product_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
        ];
    }

    public function amountRules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => ['required', Rule::in(Expense::CURRENCIES)],
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'valid_from' => 'required|date',
        ];
    }

    public function versionAttributes(array $data, bool $active, ?int $userId): array
    {
        $usd = Expense::toUsd((float) $data['amount'], $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);

        return [
            'valid_from' => Carbon::parse($data['valid_from'])->toDateString(),
            'is_active' => $active,
            'amount' => round((float) $data['amount'], 2),
            'currency' => $data['currency'],
            'created_by' => $userId,
        ] + $usd;
    }
}
