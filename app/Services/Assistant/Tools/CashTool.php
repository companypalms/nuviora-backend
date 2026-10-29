<?php

namespace App\Services\Assistant\Tools;

use App\Models\FinancialAccount;
use App\Services\Assistant\Present;
use App\Services\Calendar\Rules\ExpenseRules;
use App\Services\Finance\CashLedger;

/** Cuánto hay en cada cuenta de la caja (Finanzas › Caja) y cuánto efectivo tienen las agencias. Es lo de ahora mismo. */
class CashTool implements Tool
{
    public function __construct(private CashLedger $cash)
    {
    }

    public function name(): string
    {
        return 'caja';
    }

    public function description(): string
    {
        return 'Cuánto dinero hay ahora en cada cuenta de la empresa (bancos, billeteras, efectivo; lo mismo de Finanzas › Caja), en su moneda y en dólares, con lo pendiente de confirmar; y cuánto efectivo de entregas tiene todavía cada agencia. Los bolívares se pasan a dólares con la tasa de hoy (aproximado).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false];
    }

    public function working(array $args): string
    {
        return 'Revisando la caja…';
    }

    public function run(array $args): ToolResult
    {
        $accounts = $this->cash->balances()->where('is_active', true);
        $company = $accounts->where('kind', '!=', FinancialAccount::AGENCY_CASH)->values();
        $agencies = $accounts->where('kind', FinancialAccount::AGENCY_CASH)->filter(fn ($a) => abs((float) $a['balance_usd']) > 0.005)->values();

        $data = [
            'cuentas' => $company->map(fn ($a) => array_filter([
                'cuenta' => $a['name'],
                'tipo' => $a['kind_label'],
                'saldo' => ExpenseRules::amount((float) $a['balance'], (string) $a['currency']),
                'en_dolares' => $a['currency'] !== 'USD' ? Present::value((float) $a['balance_usd'], 'money') . ($a['approximate'] ? ' (aprox.)' : '') : null,
                'pendiente_de_confirmar' => abs((float) $a['pending']) > 0.005 ? ExpenseRules::amount((float) $a['pending'], (string) $a['currency']) : null,
            ]))->all(),
            'total_en_cuentas' => Present::value((float) $company->sum('balance_usd'), 'money'),
            'efectivo_en_agencias' => $agencies->map(fn ($a) => ['agencia' => $a['name'], 'monto' => Present::value((float) $a['balance_usd'], 'money')])->all(),
            'total_en_agencias' => Present::value((float) $agencies->sum('balance_usd'), 'money'),
        ];

        return new ToolResult($data, [
            [
                'type' => 'table',
                'title' => 'Caja ahora',
                'columns' => [['key' => 'usd', 'label' => 'En dólares', 'format' => 'money']],
                'rows' => $company->map(fn ($a) => ['name' => $a['name'], 'group' => $a['kind_label'] . ' · ' . ExpenseRules::amount((float) $a['balance'], (string) $a['currency']),
                    'values' => ['usd' => (float) $a['balance_usd']], 'low_data' => false])->all(),
            ],
            ['type' => 'link', 'label' => 'Abrir Finanzas › Caja', 'to' => '/finanzas?tab=cash'],
        ]);
    }
}
