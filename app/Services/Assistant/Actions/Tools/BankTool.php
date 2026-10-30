<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\CompanyAccount;
use App\Models\FinancialAccount;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Finance\CashAccounts;
use App\Services\Finance\CashLedger;
use Illuminate\Support\Facades\Validator;

/**
 * "Añade un banco" (decisión del dueño, paso 5): la cuenta en Finanzas › Caja, donde está el dinero, y, si da los datos,
 * la cuenta para cobrar que ven los clientes (Tasa y pagos › Cuentas de la empresa), enlazada a esa caja. Con las mismas
 * reglas de esas pantallas (Finance\CashAccounts).
 */
class BankTool extends ChangeTool
{
    private const KINDS = ['banco' => 'bank', 'billetera' => 'wallet', 'efectivo' => 'cash', 'otro' => 'other'];

    private const CURRENCY_LABEL = ['VES' => 'Bolívares (VES)', 'USD' => 'Dólares (USD)', 'USDT' => 'USDT', 'EUR' => 'Euros (EUR)'];

    public function __construct(private CashAccounts $accounts)
    {
    }

    public function name(): string
    {
        return 'proponer_banco';
    }

    public function description(): string
    {
        return 'Propone añadir un banco o una cuenta nueva: siempre la cuenta en Finanzas › Caja (donde está el dinero) y, si el dueño da los datos para que le paguen (pago móvil, transferencia, Zelle, Binance…), también la cuenta para cobrar que ven los clientes, enlazada a esa caja. Si falta la moneda, pregúntala. El dueño confirma en una tarjeta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nombre' => ['type' => 'string', 'description' => 'Nombre de la cuenta en la caja: "Banesco", "Binance", "Efectivo oficina".'],
                'tipo' => ['type' => 'string', 'enum' => array_keys(self::KINDS)],
                'moneda' => ['type' => 'string', 'enum' => FinancialAccount::CURRENCIES],
                'saldo_inicial' => ['type' => ['number', 'null'], 'description' => 'Cuánto tiene hoy, si lo dice; o null.'],
                'notas' => ['type' => ['string', 'null']],
                'cobro' => [
                    'anyOf' => [
                        [
                            'type' => 'object',
                            'description' => 'La cuenta para cobrar que ven los clientes, solo si el dueño da sus datos.',
                            'properties' => [
                                'nombre' => ['type' => 'string', 'description' => 'Como la ven los clientes: "Pago móvil Banesco".'],
                                'metodo' => ['type' => 'string', 'enum' => array_keys(CompanyAccount::methods())],
                                'extracto' => ['type' => ['string', 'null'], 'description' => 'En qué extracto bancario se verifican los pagos (para la conciliación), o null.'],
                                'datos' => [
                                    'type' => 'array',
                                    'description' => 'Lo que ve el cliente para pagar: titular, teléfono, cédula o RIF, número de cuenta, correo…',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => ['dato' => ['type' => 'string'], 'valor' => ['type' => 'string']],
                                        'required' => ['dato', 'valor'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => ['nombre', 'metodo', 'extracto', 'datos'],
                            'additionalProperties' => false,
                        ],
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => ['nombre', 'tipo', 'moneda', 'saldo_inicial', 'notas', 'cobro'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la cuenta nueva…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $account = [
            'name' => trim((string) ($args['nombre'] ?? '')),
            'kind' => self::KINDS[$args['tipo'] ?? ''] ?? null,
            'currency' => $args['moneda'] ?? null,
            'opening_balance' => $args['saldo_inicial'] ?? null,
            'notes' => is_string($args['notas'] ?? null) && trim($args['notas']) !== '' ? trim($args['notas']) : null,
        ];
        $validator = Validator::make($account, $this->accounts->accountRules(), $this->accounts->accountMessages());
        if ($validator->fails()) {
            throw new ToolProblem(implode(' ', $validator->errors()->all()));
        }
        $account = $validator->validated();

        $company = null;
        $lines = [
            Proposal::line('Cuenta en la caja', $account['name']),
            Proposal::line('Tipo', FinancialAccount::KINDS[$account['kind']] ?? $account['kind']),
            Proposal::line('Moneda', self::CURRENCY_LABEL[$account['currency']] ?? $account['currency']),
            Proposal::line('Saldo inicial', $account['opening_balance'] !== null
                ? number_format((float) $account['opening_balance'], 2, ',', '.') . " {$account['currency']} al " . date('d/m/Y', strtotime(CashLedger::startDate()))
                : 'Sin saldo inicial'),
            Proposal::line('Notas', $account['notes'] ?? null),
        ];
        $warnings = [];
        if (is_array($args['cobro'] ?? null)) {
            $c = $args['cobro'];
            $details = array_values(array_filter(array_map(fn ($d) => ['label' => trim((string) ($d['dato'] ?? '')), 'value' => trim((string) ($d['valor'] ?? ''))], $c['datos'] ?? []),
                fn ($d) => $d['label'] !== '' && $d['value'] !== ''));
            $company = [
                'name' => trim((string) ($c['nombre'] ?? '')),
                'method' => $c['metodo'] ?? null,
                'currency' => $account['currency'],
                'statement_source_name' => is_string($c['extracto'] ?? null) && trim($c['extracto']) !== '' ? trim($c['extracto']) : null,
                'details' => $details,
                'is_active' => true,
            ];
            $validator = Validator::make($company, $this->accounts->companyRules(true));
            if ($validator->fails()) {
                throw new ToolProblem(implode(' ', $validator->errors()->all()));
            }
            if (CompanyAccount::whereRaw('LOWER(name) = ?', [mb_strtolower($company['name'])])->exists()) {
                $warnings[] = "Ya hay una cuenta para cobrar que se llama \"{$company['name']}\": quedarían dos con el mismo nombre.";
            }
            $methods = CompanyAccount::methods();
            $lines[] = Proposal::line('Para cobrar', "{$company['name']} (" . ($methods[$company['method']] ?? $company['method']) . ')');
            $lines[] = Proposal::line('Lo que ven los clientes', implode(' · ', array_map(fn ($d) => "{$d['label']}: {$d['value']}", $details)) ?: 'Sin datos');
            $lines[] = Proposal::line('Extracto', $company['statement_source_name']);
            $warnings[] = 'Los clientes y las vendedoras van a ver esta cuenta para pagar, y lo que entre por ahí se suma a la caja de "' . $account['name'] . '".';
        }

        return new Proposal(
            $company ? 'Añadir un banco (caja y cuenta para cobrar)' : 'Añadir una cuenta a la caja',
            'Añadir la cuenta "' . $account['name'] . '" (' . $account['currency'] . ')' . ($company ? ' y la cuenta para cobrar "' . $company['name'] . '"' : ''),
            Proposal::lines($lines),
            ['account' => $account, 'company' => $company],
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $account = $this->accounts->createAccount($payload['account'], $context->user->id);
        $text = "Cuenta \"{$account->name}\" creada en la caja.";
        if ($payload['company'] !== null) {
            $company = $this->accounts->createCompanyAccount($payload['company']);
            $this->accounts->link($company, $account, $context->user->id);
            $text .= " Y \"{$company->name}\" ya aparece para cobrar, enlazada a esa caja.";
        }

        return new Outcome($text, ['label' => 'Ver la caja', 'to' => '/finanzas']);
    }
}
