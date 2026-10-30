<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Finance\CashLedger;
use App\Services\Finance\OtherPayments;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Pagar un vencimiento de un gasto fijo (lo mismo que "Registrar pago" en Finanzas o en el calendario:
 * OtherPayments::payRecurring). Sale de una cuenta de la caja ese día, y la tarea del calendario se tilda sola. Si no dice
 * cuál, es el más viejo sin pagar (o el próximo).
 */
class RecurringPaymentTool extends ChangeTool
{
    /** Hasta cuántos días adelante se puede pagar un vencimiento. */
    private const AHEAD_DAYS = 31;

    public function __construct(private OtherPayments $payments)
    {
    }

    public function name(): string
    {
        return 'proponer_pago_gasto_fijo';
    }

    public function description(): string
    {
        return 'Propone registrar el pago de un gasto fijo (por ejemplo "ya pagué Shopify con Binance"): sale de una cuenta de la caja y la tarea del calendario se tilda sola. Sin vencimiento, es el más viejo sin pagar (o el próximo); sin monto ni moneda, los del gasto fijo. Hace falta la cuenta de donde salió. Si mandó el recibo, ponlo de comprobante. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gasto_fijo' => ['type' => 'string', 'description' => 'El nombre del gasto fijo.'],
                'vencimiento' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD del vencimiento que se paga, o null (el más viejo sin pagar).'],
                'pagado_el' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD en que se pagó (no futuro); null = hoy.'],
                'cuenta' => ['type' => ['string', 'null'], 'description' => 'De qué cuenta de la caja salió.'],
                'monto' => ['type' => ['number', 'null'], 'description' => 'Lo que se pagó, o null (el monto del gasto fijo).'],
                'moneda' => ['anyOf' => [['type' => 'string', 'enum' => Expense::CURRENCIES], ['type' => 'null']]],
                'tasa' => ['type' => ['number', 'null'], 'description' => 'Solo en bolívares: bolívares por dólar.'],
                'notas' => ['type' => ['string', 'null']],
                'comprobante' => ['type' => ['integer', 'null'], 'description' => 'Para guardarlo de comprobante: el número del archivo (1 = el primero) en el mensaje del dueño, o en el último que trajo archivos. O null.'],
            ],
            'required' => ['gasto_fijo', 'vencimiento', 'pagado_el', 'cuenta', 'monto', 'moneda', 'tasa', 'notas', 'comprobante'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando el pago…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $recurring = FinanceArgs::recurring($args['gasto_fijo'] ?? null);
        $due = $this->due($recurring, $args['vencimiento'] ?? null, $context);
        $account = FinanceArgs::account($args['cuenta'] ?? null);
        $currency = (string) ($args['moneda'] ?? $due['currency']);
        $amount = $args['monto'] ?? ($currency === $due['currency'] ? $due['amount'] : null);
        if ($amount === null) {
            throw new ToolProblem("¿Cuánto se pagó en {$currency}? El gasto fijo es de " . FinanceArgs::money($due['amount'], $due['currency']) . '.');
        }
        // En bolívares, si paga lo mismo del gasto fijo, la tasa del gasto fijo
        $versionRate = $due['currency'] === 'VES' && $due['amount_usd'] > 0 ? round($due['amount'] / $due['amount_usd'], 4) : null;
        $rate = $currency === 'VES' ? FinanceArgs::rateFor($currency, $args['tasa'] ?? ($due['currency'] === 'VES' ? $versionRate : null)) : null;
        $data = [
            'due_date' => $due['due_date'],
            'paid_at' => FinanceArgs::date($args['pagado_el'] ?? null, $context, 'La fecha del pago'),
            'financial_account_id' => $account->id,
            'amount' => $amount,
            'currency' => $currency,
            'rate' => $rate,
            'notes' => is_string($args['notas'] ?? null) && trim($args['notas']) !== '' ? trim($args['notas']) : null,
        ];
        // Las reglas de "Registrar pago" (FinanceExpenseController::recurringPay), sin el archivo
        $validator = Validator::make($data, [
            'due_date' => 'required|date',
            'paid_at' => 'required|date|before_or_equal:today',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => ['required', Rule::in(Expense::CURRENCIES)],
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'notes' => 'nullable|string|max:500',
        ], ['paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.']);
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();
        CashLedger::assertAccount($account->id, $currency, $data['paid_at']);
        $receipt = FinanceArgs::receipt($args['comprobante'] ?? null, $context);
        $amount = round((float) $data['amount'], 2);
        $warnings = [];
        if ($currency !== $due['currency'] || abs($amount - $due['amount']) > 0.005) {
            $warnings[] = 'El gasto fijo es de ' . FinanceArgs::money($due['amount'], $due['currency']) . ': se registra lo que dices que pagaste.';
        }

        return new Proposal(
            'Pagar un gasto fijo',
            "Pagar \"{$recurring->name}\" del " . Schedule::dayName($due['due_date']) . ': ' . FinanceArgs::money($amount, $currency, $data['rate'] ?? null) . " desde {$account->name}",
            Proposal::lines([
                Proposal::line('Gasto fijo', $recurring->name),
                Proposal::line('Vencimiento', Schedule::dayName($due['due_date']) . ($due['overdue'] ? ' (atrasado)' : '')),
                Proposal::line('Monto', FinanceArgs::money($amount, $currency, $data['rate'] ?? null)),
                Proposal::line('Sale de', FinanceArgs::accountLabel($account)),
                Proposal::line('Pagado el', Schedule::dayName($data['paid_at'])),
                Proposal::line('Notas', $data['notes'] ?? null),
                Proposal::line('Comprobante', $receipt['name'] ?? null),
            ]),
            ['recurring_id' => $recurring->id, 'data' => $data, 'receipt' => $receipt],
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $recurring = RecurringExpense::findOrFail($payload['recurring_id']);
        $path = FinanceArgs::storeReceipt($payload['receipt'], 'recurring-payments');
        try {
            $this->payments->payRecurring($recurring, $payload['data'], $path, $context->user->id);
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
        Calendar::forgetToday(); // la tarea del calendario se tilda sola

        return new Outcome("Pago registrado: {$recurring->name} del " . Schedule::dayName($payload['data']['due_date']) . '. Ya se tildó en tu calendario.',
            ['label' => 'Ver los gastos fijos', 'to' => '/finanzas']);
    }

    /** El vencimiento que se paga: el que dijo (si existe y no está pagado), o el más viejo sin pagar, o el próximo. */
    private function due(RecurringExpense $recurring, ?string $date, ActionContext $context): array
    {
        $until = $context->now->addDays(self::AHEAD_DAYS)->toDateString();
        $dues = $this->payments->dueDates($recurring, $date && $date > $until ? $date : $until);
        if ($date !== null && $date !== '') {
            $date = FinanceArgs::date($date, $context, 'El vencimiento');
            foreach ($dues as $d) {
                if ($d['due_date'] === $date) {
                    if ($d['paid']) {
                        throw new ToolProblem('Ese vencimiento ya está pagado.');
                    }

                    return $d;
                }
            }
            $open = array_column(array_filter($dues, fn ($d) => !$d['paid']), 'due_date');
            throw new ToolProblem("\"{$recurring->name}\" no vence ese día." . ($open ? ' Vencimientos sin pagar: ' . implode(', ', array_slice($open, 0, 6)) . '.' : ''));
        }
        foreach ($dues as $d) {
            if (!$d['paid']) {
                return $d;
            }
        }
        throw new ToolProblem("\"{$recurring->name}\" no tiene vencimientos sin pagar en los próximos " . self::AHEAD_DAYS . ' días.');
    }
}
