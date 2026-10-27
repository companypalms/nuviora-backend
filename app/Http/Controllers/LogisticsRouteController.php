<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\LogisticsRoute;
use App\Services\Logistics\Routes;
use App\Services\Logistics\Shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Rutas de varias paradas en la torre (§9.3, §10, §24.1): lo que propone el sistema, aprobarlo y cambiar el orden de las
 * paradas antes de la salida (queda auditado, §10.2). Solo el operador de ese centro o administración (§33).
 */
class LogisticsRouteController extends Controller
{
    public function __construct(private Routes $routes, private Shifts $shifts)
    {
    }

    public function proposals(LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $out = $this->routes->proposals($center);

        return response()->json(['status' => true] + $out);
    }

    /** §24.1: "IF operator approves: update route; rider does not re-accept". */
    public function approve(Request $request)
    {
        $data = $request->validate([
            'add_logistics_order_id' => 'required|integer|exists:logistics_orders,id',
            'stops' => 'required|array|min:2|max:5',
            'stops.*' => 'integer|distinct|exists:logistics_orders,id',
            'exceptional' => 'boolean',
        ]);
        $add = LogisticsOrder::findOrFail($data['add_logistics_order_id']);
        $this->authorizeCenter($add->center);
        if (!in_array((int) $add->id, array_map('intval', $data['stops']), true)) {
            return response()->json(['status' => false, 'message' => 'La orden que se suma tiene que estar entre las paradas.'], 422);
        }
        if (LogisticsOrder::whereIn('id', $data['stops'])->where('center_id', '!=', $add->center_id)->exists()) {
            return response()->json(['status' => false, 'message' => 'Las paradas tienen que salir del mismo centro (§10.1).'], 422);
        }

        return $this->reply($this->routes->approve($add, $data['stops'], Auth::user(), (bool) ($data['exceptional'] ?? false)));
    }

    /** §10.2: el operador cambia el orden antes de la salida, con motivo; queda en el historial de cada parada. */
    public function reorder(Request $request, LogisticsRoute $logisticsRoute)
    {
        $this->authorizeCenter($logisticsRoute->center);
        $data = $request->validate([
            'stops' => 'required|array|min:2|max:5',
            'stops.*' => 'integer|distinct',
            'reason' => 'nullable|string|max:250',
        ]);

        return $this->reply($this->routes->reorder($logisticsRoute, $data['stops'], Auth::user(), $data['reason'] ?? null));
    }

    private function reply(array $out)
    {
        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }

    private function authorizeCenter(?LogisticsCenter $center): void
    {
        $user = Auth::user();
        if (!$center || (!Shifts::isAdmin($user) && !$this->shifts->isOperatorOf($user, $center))) {
            abort(response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403));
        }
    }
}
