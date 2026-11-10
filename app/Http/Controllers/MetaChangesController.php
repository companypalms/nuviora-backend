<?php

namespace App\Http\Controllers;

use App\Models\MetaChange;
use App\Services\Meta\Changes\ChangeRules;
use App\Services\Meta\Changes\MetaChanges;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `.131` Meta Ads › Historial de cambios. Solo el Administrador, como todo Meta Ads: cada cambio que el sistema vio en Meta,
 * con su hipótesis y si se cumplió (lo arma MetaChanges), y la nota del dueño.
 */
class MetaChangesController extends Controller
{
    public function index(Request $request, MetaChanges $changes)
    {
        $f = $request->validate([
            'product_id' => 'nullable|integer', 'city' => 'nullable|string|max:80',
            'kind' => ['nullable', Rule::in(array_keys(ChangeRules::KINDS))], 'result' => ['nullable', Rule::in(array_keys(ChangeRules::RESULTS))],
            'fresh' => 'nullable|boolean',
        ]);
        $error = $changes->refreshIfStale((bool) ($f['fresh'] ?? false));

        return response()->json($changes->page($f) + ['refresh_error' => $error]);
    }

    public function note(Request $request, MetaChange $change)
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        $change->forceFill(['note' => trim((string) ($data['note'] ?? '')) ?: null])->save();

        return response()->json(['note' => $change->note]);
    }
}
