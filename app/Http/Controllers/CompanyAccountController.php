<?php

namespace App\Http\Controllers;

use App\Models\CompanyAccount;
use App\Models\StatementSource;
use App\Services\Finance\CashAccounts;
use App\Services\Payments\PaymentAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Tasa y pagos › Cuentas de la empresa. Las reglas y el alta están en Services\Finance\CashAccounts (las comparte con el asistente). */
class CompanyAccountController extends Controller
{
    public function index(PaymentAccounts $payments)
    {
        $accounts = CompanyAccount::with('statementSource')->get();
        // .60: lo que lleva hoy cada cuenta de pago móvil (órdenes y Bs), para ver cómo se reparte. Solo administración
        if (in_array(Auth::user()?->role?->description, ['Admin', 'Gerente', 'Master'], true)) {
            $today = $payments->today();
            foreach ($accounts->where('method', PaymentAccounts::KIND) as $a) {
                $a->setAttribute('today', $today[$a->id] ?? ['orders' => 0, 'bs' => 0.0]);
            }
        }

        return response()->json($accounts);
    }

    /**
     * Lo que se puede elegir al configurar una cuenta (documento de Fran del 2026-10-06, §2): el método, la moneda y el
     * extracto donde se verifica, uno de los que ya existen o uno nuevo.
     */
    public function options()
    {
        return response()->json([
            'methods' => CompanyAccount::methods(),
            'currencies' => CompanyAccount::CURRENCIES,
            'sources' => StatementSource::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request, CashAccounts $accounts)
    {
        $validated = $request->validate($accounts->companyRules(true));

        $account = $accounts->createCompanyAccount($validated);

        return response()->json($account->load('statementSource'), 201);
    }

    public function show(CompanyAccount $companyAccount)
    {
        return response()->json($companyAccount->load('statementSource'));
    }

    public function update(Request $request, CompanyAccount $companyAccount, CashAccounts $accounts)
    {
        $validated = $request->validate($accounts->companyRules(false));

        $companyAccount->update($accounts->withSource($validated));

        return response()->json($companyAccount->load('statementSource'));
    }

    public function destroy(CompanyAccount $companyAccount)
    {
        $companyAccount->delete();

        return response()->json(null, 204);
    }
}
