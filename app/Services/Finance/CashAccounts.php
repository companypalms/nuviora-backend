<?php

namespace App\Services\Finance;

use App\Models\CompanyAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAuditLog;
use App\Models\StatementSource;
use Illuminate\Validation\Rule;

/**
 * Las cuentas donde está el dinero (Finanzas › Caja, §35 y §36) y las cuentas por las que pagan los clientes (Tasa y pagos
 * › Cuentas de la empresa), con su enlace (R14). Lo usan esas pantallas (FinanceCashController y CompanyAccountController,
 * de donde salió sin cambiar nada) y el asistente del dueño ("añade un banco").
 */
class CashAccounts
{
    /** Las reglas de una cuenta nueva de la caja. */
    public function accountRules(): array
    {
        $start = CashLedger::startDate();

        return [
            'name' => 'required|string|max:100|unique:financial_accounts,name',
            'kind' => ['required', Rule::in(array_keys(array_diff_key(FinancialAccount::KINDS, [FinancialAccount::AGENCY_CASH => 1])))],
            'currency' => ['required', Rule::in(FinancialAccount::CURRENCIES)],
            'opening_balance' => 'nullable|numeric|min:-9999999999|max:9999999999',
            'opening_date' => "nullable|date|after_or_equal:{$start}|before_or_equal:today",
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function accountMessages(): array
    {
        return [
            'name.unique' => 'Ya hay una cuenta con ese nombre.',
            'opening_date.after_or_equal' => 'La caja arranca el ' . date('d/m/Y', strtotime(CashLedger::startDate())) . ': el saldo inicial no puede ser de antes.',
        ];
    }

    /** Una cuenta nueva de la caja (§35). $data ya validado con accountRules(). Sin fecha, el saldo es del arranque de la caja. */
    public function createAccount(array $data, ?int $userId): FinancialAccount
    {
        $data['opening_date'] = $data['opening_date'] ?? CashLedger::startDate();
        $account = FinancialAccount::create($data + ['is_active' => true, 'created_by' => $userId]);
        FinancialAuditLog::record('cash_account_created', 'financial_account', $account->id, null, $account->snapshot(), null, $userId);

        return $account;
    }

    /** Las reglas de una cuenta para cobrar. */
    public function companyRules(bool $creating): array
    {
        return [
            'name' => ($creating ? '' : 'sometimes|') . 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'method' => ['nullable', Rule::in(array_keys(CompanyAccount::methods()))],
            'currency' => ['nullable', Rule::in(CompanyAccount::CURRENCIES)],
            'statement_source_name' => 'nullable|string|max:60',
            'details' => 'nullable|array',
            'is_active' => 'boolean',
        ];
    }

    /** Una cuenta para cobrar nueva. $data ya validado con companyRules(true). */
    public function createCompanyAccount(array $data): CompanyAccount
    {
        return CompanyAccount::create($this->withSource($data));
    }

    /** El extracto se elige por su nombre: si no existe, se crea con la moneda de la cuenta. */
    public function withSource(array $validated): array
    {
        if (array_key_exists('statement_source_name', $validated)) {
            $name = trim((string) $validated['statement_source_name']);
            $validated['statement_source_id'] = $name === '' ? null : (StatementSource::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id')
                ?? StatementSource::create(['name' => $name, 'currency' => $validated['currency'] ?? 'VES'])->id);
            unset($validated['statement_source_name']);
        }

        return $validated;
    }

    /**
     * A qué cuenta de la caja entra lo que llega por una cuenta para cobrar (R14). Sus pagos se mueven con ella. Quien
     * llama revisa antes que no sea el efectivo de una agencia.
     */
    public function link(CompanyAccount $companyAccount, ?FinancialAccount $target, ?int $userId): void
    {
        $before = ['financial_account_id' => $companyAccount->financial_account_id];
        $companyAccount->update(['financial_account_id' => $target?->id]);
        FinancialAuditLog::record('cash_account_link', 'financial_account', $target?->id ?? $before['financial_account_id'], $before,
            ['company_account' => $companyAccount->name, 'financial_account_id' => $target?->id], null, $userId);
    }
}
