<?php

namespace App\Services\Assistant\Tools;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\RecurringExpensePayment;
use App\Services\Assistant\Present;
use App\Services\Calendar\Rules\ExpenseRules;
use App\Services\Finance\OtherPayments;
use Carbon\CarbonImmutable;

/**
 * Los gastos (Finanzas › Gastos y Gastos recurrentes): los anotados en un período por categoría, los pagos de los gastos
 * fijos de esos días y los gastos fijos con su próximo vencimiento.
 */
class ExpensesTool implements Tool
{
    /** Los próximos vencimientos que se miran de cada gasto fijo. */
    private const AHEAD_DAYS = 45;

    public function __construct(private OtherPayments $payments)
    {
    }

    public function name(): string
    {
        return 'gastos';
    }

    public function description(): string
    {
        return 'Los gastos de un período: los gastos anotados en Finanzas (por categoría, con los más grandes) y los pagos de gastos fijos hechos esos días; y la lista de gastos fijos (Shopify, Hostinger, editores…) con cuánto son, cada cuánto y su próximo vencimiento (y si hay alguno atrasado). Ojo: la ganancia del Panel del dueño reparte los gastos fijos por día; esto es lo anotado y lo pagado.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => PeriodArgs::schema() + ['categoria' => ['type' => ['string', 'null'], 'description' => 'Solo esa categoría de gasto. null = todas.']],
            'required' => ['periodo', 'desde', 'hasta', 'categoria'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando los gastos…';
    }

    public function run(array $args): ToolResult
    {
        $period = PeriodArgs::period($args, CarbonImmutable::now());
        $category = trim((string) ($args['categoria'] ?? ''));
        $expenses = Expense::with('category:id,name')->whereNull('voided_at')
            ->whereBetween('expense_date', [$period->fromDate(), $period->toDate()])
            ->when($category !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('name', 'like', "%{$category}%")))
            ->orderByDesc('amount_usd')->get();
        $paid = RecurringExpensePayment::with('recurring:id,name')->whereNull('voided_at')
            ->whereBetween('paid_at', [$period->start->format('Y-m-d H:i:s'), $period->end->format('Y-m-d H:i:s')])->get();

        $byCategory = $expenses->groupBy(fn ($e) => $e->category?->name ?? 'Sin categoría')
            ->map(fn ($items, $name) => ['categoria' => $name, 'total' => round((float) $items->sum('amount_usd'), 2), 'cantidad' => $items->count()])
            ->sortByDesc('total')->values();

        $until = CarbonImmutable::now()->addDays(self::AHEAD_DAYS)->toDateString();
        $fixed = RecurringExpense::with('category:id,name')->where('is_active', true)->orderBy('name')->get()->map(function ($r) use ($until) {
            $dues = $this->payments->dueDates($r, $until);
            $unpaid = array_values(array_filter($dues, fn ($d) => !$d['paid']));
            $next = $unpaid[0] ?? null;

            return array_filter([
                'gasto' => $r->name,
                'categoria' => $r->category?->name,
                'cada' => RecurringExpense::FREQUENCIES[$r->frequency] ?? $r->frequency,
                'proximo_vencimiento' => $next ? CarbonImmutable::parse($next['due_date'])->format('d/m/Y') : null,
                'monto' => $next ? ExpenseRules::amount((float) $next['amount'], (string) $next['currency']) : null,
                'atrasados_sin_pagar' => count(array_filter($unpaid, fn ($d) => $d['overdue'])) ?: null,
            ], fn ($v) => $v !== null);
        })->all();

        $data = array_filter([
            'periodo' => Present::period($period),
            'categoria' => $category !== '' ? $category : null,
            'gastos_anotados' => [
                'total' => Present::value((float) $expenses->sum('amount_usd'), 'money'),
                'por_categoria' => $byCategory->map(fn ($c) => ['categoria' => $c['categoria'], 'total' => Present::value($c['total'], 'money'), 'cantidad' => $c['cantidad']])->all(),
                'los_mas_grandes' => $expenses->take(15)->map(fn ($e) => [
                    'fecha' => $e->expense_date?->format('d/m/Y'),
                    'concepto' => Present::mask((string) $e->concept),
                    'categoria' => $e->category?->name,
                    'monto' => ExpenseRules::amount((float) $e->amount, (string) $e->currency),
                ])->all(),
            ],
            'pagos_de_gastos_fijos' => $paid->isNotEmpty() ? [
                'total' => Present::value((float) $paid->sum('amount_usd'), 'money'),
                'pagos' => $paid->map(fn ($p) => ['gasto' => $p->recurring?->name, 'pagado' => $p->paid_at ? substr((string) $p->paid_at, 0, 10) : null,
                    'monto' => ExpenseRules::amount((float) $p->amount, (string) $p->currency)])->all(),
            ] : null,
            'gastos_fijos' => $fixed ?: 'No hay gastos fijos cargados en Finanzas › Gastos recurrentes.',
        ], fn ($v) => $v !== null);

        $display = [];
        if ($byCategory->isNotEmpty()) {
            $display[] = Present::chart('hbar', "Gastos por categoría · {$period->label}", 'money', [['key' => 'total', 'label' => 'Total']],
                $byCategory->take(12)->map(fn ($c) => ['label' => $c['categoria'], 'total' => $c['total']])->all());
        }
        $display[] = ['type' => 'link', 'label' => 'Abrir Finanzas › Gastos', 'to' => '/finanzas?tab=expenses'];

        return new ToolResult($data, $display);
    }
}
