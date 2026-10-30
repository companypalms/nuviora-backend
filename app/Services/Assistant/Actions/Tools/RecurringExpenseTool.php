<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Calendar\Rules\Schedule;
use App\Services\Finance\Expenses;
use Illuminate\Support\Facades\Validator;

/**
 * Un gasto fijo nuevo (lo mismo que Finanzas › Gastos recurrentes › Nuevo: Finance\Expenses::createRecurring). Sale solo
 * en el calendario el día que toca pagarlo.
 */
class RecurringExpenseTool extends ChangeTool
{
    private const FREQUENCIES = ['semanal' => 'weekly', 'mensual' => 'monthly', 'anual' => 'annual', 'cada_n_dias' => 'custom'];

    public function __construct(private Expenses $expenses)
    {
    }

    public function name(): string
    {
        return 'proponer_gasto_fijo';
    }

    public function description(): string
    {
        return 'Propone un gasto fijo nuevo (Shopify, Hostinger, el alquiler, los editores…), como en Finanzas › Gastos recurrentes: nombre, categoría, cada cuánto se paga y qué día, monto y moneda, y desde cuándo. Después sale solo en el calendario el día de pago. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nombre' => ['type' => 'string'],
                'categoria' => ['type' => 'string', 'description' => 'El nombre de una categoría de gastos.'],
                'frecuencia' => ['type' => 'string', 'enum' => array_keys(self::FREQUENCIES)],
                'dia_de_pago' => ['type' => ['integer', 'null'], 'description' => 'Semanal: 1 (lunes) a 7 (domingo). Mensual: 1 a 31 (31 = el último día del mes). Si no, null.'],
                'fecha_de_pago' => ['type' => ['string', 'null'], 'description' => 'Anual o cada_n_dias: AAAA-MM-DD del próximo pago. Si no, null.'],
                'cada_dias' => ['type' => ['integer', 'null'], 'description' => 'Solo cada_n_dias: cada cuántos días.'],
                'monto' => ['type' => 'number'],
                'moneda' => ['type' => 'string', 'enum' => Expense::CURRENCIES],
                'tasa' => ['type' => ['number', 'null'], 'description' => 'Solo en bolívares: bolívares por dólar.'],
                'desde' => ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD desde cuándo vale el monto; null = hoy.'],
            ],
            'required' => ['nombre', 'categoria', 'frecuencia', 'dia_de_pago', 'fecha_de_pago', 'cada_dias', 'monto', 'moneda', 'tasa', 'desde'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando el gasto fijo…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $frequency = self::FREQUENCIES[$args['frecuencia'] ?? ''] ?? null;
        $category = FinanceArgs::category($args['categoria'] ?? null);
        $currency = (string) ($args['moneda'] ?? '');
        $data = [
            'name' => trim((string) ($args['nombre'] ?? '')),
            'expense_category_id' => $category->id,
            'frequency' => $frequency,
            'interval_days' => $frequency === 'custom' ? ($args['cada_dias'] ?? null) : null,
            'payment_day' => in_array($frequency, ['weekly', 'monthly'], true) ? ($args['dia_de_pago'] ?? null) : null,
            'payment_date' => in_array($frequency, ['annual', 'custom'], true) ? ($args['fecha_de_pago'] ?? null) : null,
            'amount' => $args['monto'] ?? null,
            'currency' => $currency,
            'rate' => FinanceArgs::rateFor($currency, $args['tasa'] ?? null),
            'valid_from' => FinanceArgs::date($args['desde'] ?? null, $context, '"Desde"'),
        ];
        $validator = Validator::make($data, $this->expenses->recurringRules($frequency) + $this->expenses->amountRules(), Expenses::MESSAGES);
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();
        $warnings = [];
        if (RecurringExpense::where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists()) {
            $warnings[] = "Ya hay un gasto fijo activo que se llama \"{$data['name']}\": quedarían dos.";
        }

        return new Proposal(
            'Nuevo gasto fijo',
            "Nuevo gasto fijo \"{$data['name']}\": " . FinanceArgs::money(round((float) $data['amount'], 2), $currency, $data['rate'] ?? null) . ', ' . mb_strtolower(self::when($data)),
            Proposal::lines([
                Proposal::line('Nombre', $data['name']),
                Proposal::line('Categoría', $category->name),
                Proposal::line('Se paga', self::when($data)),
                Proposal::line('Monto', FinanceArgs::money(round((float) $data['amount'], 2), $currency, $data['rate'] ?? null)),
                Proposal::line('Desde', Schedule::dayName($data['valid_from'])),
            ]),
            $data,
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $recurring = $this->expenses->createRecurring($payload, $context->user->id);

        return new Outcome("Gasto fijo \"{$recurring->name}\" creado: ya sale en tu calendario el día de pago.", ['label' => 'Ver los gastos fijos', 'to' => '/finanzas']);
    }

    /** "Cada mes, el día 5", "Cada semana, el viernes", "Cada año, el 15/3", "Cada 10 días, desde el 2/11". */
    private static function when(array $d): string
    {
        return match ($d['frequency']) {
            'weekly' => 'Cada semana, el ' . RecurringExpense::WEEKDAYS[(int) $d['payment_day']],
            'monthly' => 'Cada mes, ' . ((int) $d['payment_day'] >= 31 ? 'el último día' : 'el día ' . (int) $d['payment_day']),
            'annual' => 'Cada año, el ' . date('j/n', strtotime($d['payment_date'])),
            default => 'Cada ' . (int) $d['interval_days'] . ' días, desde el ' . Schedule::dayName($d['payment_date']),
        };
    }
}
