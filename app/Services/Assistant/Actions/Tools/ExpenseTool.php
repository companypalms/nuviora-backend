<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Expense;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Finance\CashLedger;
use App\Services\Finance\Expenses;
use App\Services\Finance\OtherPayments;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Anotar un gasto del día (lo mismo que Finanzas › Gastos › Nuevo gasto: Finance\Expenses::create), pagado desde una
 * cuenta de la caja o que queda por pagar. Puede llevar de comprobante la foto o el PDF que el dueño mandó en el chat.
 */
class ExpenseTool extends ChangeTool
{
    private const PAYEES = ['empleado' => 'employee', 'herramienta' => 'tool', 'otro' => 'other'];

    public function __construct(private Expenses $expenses)
    {
    }

    public function name(): string
    {
        return 'proponer_gasto';
    }

    public function description(): string
    {
        return 'Propone anotar un gasto del día a día (gasolina, una reparación, una factura…), como en Finanzas › Gastos. Hace falta: concepto, categoría, monto y moneda (en bolívares, la tasa), fecha, y si se pagó (desde qué cuenta de la caja) o queda por pagar (a quién). Si falta algo, pregúntalo antes. Si el dueño mandó la foto o el PDF de la factura, ponlo de comprobante (su número en el mensaje). El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concepto' => ['type' => 'string', 'description' => 'Qué se pagó, corto: "Gasolina", "Factura Hostinger octubre".'],
                'categoria' => ['type' => 'string', 'description' => 'El nombre de una categoría de gastos (si no sabes cuáles hay, usa la herramienta gastos o pregunta).'],
                'monto' => ['type' => 'number'],
                'moneda' => ['type' => 'string', 'enum' => Expense::CURRENCIES],
                'tasa' => ['type' => ['number', 'null'], 'description' => 'Solo en bolívares: bolívares por dólar de ese gasto. Si no la dijo, null (se le pregunta).'],
                'fecha' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD (no puede ser futura); null = hoy.'],
                'pago' => ['type' => 'string', 'enum' => ['pagado', 'por_pagar']],
                'cuenta' => ['type' => ['string', 'null'], 'description' => 'Si se pagó: de qué cuenta de la caja salió ("Banesco", "Binance"…).'],
                'a_quien_tipo' => ['anyOf' => [
                    ['type' => 'string', 'enum' => array_keys(self::PAYEES), 'description' => 'Si queda por pagar: empleado, herramienta (o servicio) u otro.'],
                    ['type' => 'null'],
                ]],
                'a_quien' => ['type' => ['string', 'null'], 'description' => 'Si queda por pagar: a quién se le debe.'],
                'vence' => ['type' => ['string', 'null'], 'description' => 'Si queda por pagar: AAAA-MM-DD en que vence, o null.'],
                'descripcion' => ['type' => ['string', 'null']],
                'comprobante' => ['type' => ['integer', 'null'], 'description' => 'Para guardarlo de comprobante: el número del archivo (1 = el primero) en el mensaje del dueño, o en el último que trajo archivos. O null.'],
            ],
            'required' => ['concepto', 'categoria', 'monto', 'moneda', 'tasa', 'fecha', 'pago', 'cuenta', 'a_quien_tipo', 'a_quien', 'vence', 'descripcion', 'comprobante'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando el gasto…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $currency = (string) ($args['moneda'] ?? '');
        $category = FinanceArgs::category($args['categoria'] ?? null);
        $paid = ($args['pago'] ?? 'pagado') !== 'por_pagar';
        $data = [
            'concept' => trim((string) ($args['concepto'] ?? '')),
            'expense_category_id' => $category->id,
            'amount' => $args['monto'] ?? null,
            'currency' => $currency,
            'rate' => FinanceArgs::rateFor($currency, $args['tasa'] ?? null),
            'expense_date' => FinanceArgs::date($args['fecha'] ?? null, $context, 'La fecha del gasto'),
            'description' => is_string($args['descripcion'] ?? null) && trim($args['descripcion']) !== '' ? trim($args['descripcion']) : null,
            'payment' => $paid ? 'paid' : 'payable',
        ];
        $account = null;
        if ($paid) {
            $account = FinanceArgs::account($args['cuenta'] ?? null);
            $data['financial_account_id'] = $account->id;
        } else {
            $data['payee_type'] = self::PAYEES[$args['a_quien_tipo'] ?? ''] ?? null;
            $data['payee_name'] = is_string($args['a_quien'] ?? null) ? trim($args['a_quien']) : null;
            $data['due_date'] = ($args['vence'] ?? null) ? FinanceArgs::date($args['vence'], $context, 'El vencimiento') : null;
            if ($data['payee_type'] === null || ($data['payee_name'] ?? '') === '') {
                throw new ToolProblem('Si queda por pagar, ¿a quién se le debe? (y si es un empleado, una herramienta o servicio, u otra obligación).');
            }
        }
        $rules = Arr::except($this->expenses->expenseRules(), ['receipt']);
        $validator = Validator::make($data, $rules, Expenses::MESSAGES);
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();
        if ($paid) {
            CashLedger::assertAccount($data['financial_account_id'], $currency, $data['expense_date']); // la cuenta y la moneda, como la pantalla
        }
        $receipt = FinanceArgs::receipt($args['comprobante'] ?? null, $context);
        $amount = round((float) $data['amount'], 2);
        $warnings = [];
        if ($data['expense_date'] < $context->now->subDays(7)->toDateString()) {
            $warnings[] = 'La fecha es de hace más de una semana: revisa que sea la correcta.';
        }

        return new Proposal(
            'Anotar un gasto',
            'Anotar el gasto "' . $data['concept'] . '": ' . FinanceArgs::money($amount, $currency, $data['rate'] ?? null)
                . ($paid ? ", pagado desde {$account->name}" : ', por pagar a ' . $data['payee_name']),
            Proposal::lines([
                Proposal::line('Concepto', $data['concept']),
                Proposal::line('Categoría', $category->name),
                Proposal::line('Monto', FinanceArgs::money($amount, $currency, $data['rate'] ?? null)),
                Proposal::line('Fecha', Schedule::dayName($data['expense_date'])),
                Proposal::line('Pago', $paid ? "Pagado desde " . FinanceArgs::accountLabel($account)
                    : 'Por pagar a ' . $data['payee_name'] . ' (' . OtherPayments::PAYEE_TYPES[$data['payee_type']] . ')'
                        . (!empty($data['due_date']) ? ', vence el ' . Schedule::dayName($data['due_date']) : '')),
                Proposal::line('Descripción', $data['description'] ?? null),
                Proposal::line('Comprobante', $receipt['name'] ?? null),
            ]),
            ['data' => $data, 'receipt' => $receipt],
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $path = FinanceArgs::storeReceipt($payload['receipt'], 'expenses');
        try {
            $expense = $this->expenses->create($payload['data'], $path, $context->user->id);
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return new Outcome("Gasto anotado: {$expense->concept}, " . FinanceArgs::money((float) $expense->amount, $expense->currency, $expense->currency === 'VES' ? (float) $expense->rate : null) . '.',
            ['label' => 'Ver los gastos', 'to' => '/finanzas']);
    }
}
