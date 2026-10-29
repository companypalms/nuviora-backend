<?php

namespace App\Http\Controllers;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Status;
use App\Services\CommissionService;
use App\Services\Orders\Cancellations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderCancellationController extends Controller
{
    public function index(Request $request)
    {
        $query = OrderCancellation::with(['order', 'user'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => true,
            'data' => $query->get()
        ]);
    }
    /**
     * "Cancelar orden" (dueño, 2026-10-29): cancela directo, sin aprobación. Pasa por el mismo cambio de estado del
     * Kanban (OrderController::updateStatus): las mismas reglas, el stock, el historial y los avisos, y el motivo
     * queda guardado (Orders\Cancellations). Antes dejaba la orden en "Pendiente Cancelación", un estado que
     * puede no existir.
     */
    public function store(Request $request, Order $order, CommissionService $commissionService)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }
        if ($order->status?->description === OrderStatus::CANCELADO) {
            return response()->json(['status' => false, 'message' => 'La orden ya está cancelada.'], 422);
        }

        $reason = Cancellations::reason($request->input('reason'));
        if (!$reason) {
            return response()->json(['status' => false, 'message' => Cancellations::missingReasonMessage()], 422);
        }

        $change = Request::create($request->url(), 'PUT', ['status' => OrderStatus::CANCELADO, 'cancel_reason' => $reason]);
        $response = app(OrderController::class)->updateStatus($change, $order, $commissionService);
        $data = $response->getData(true);
        if (empty($data['status'])) {
            return $response;
        }

        return response()->json([
            'status' => true,
            'message' => 'Orden cancelada',
            'order' => $data['order'] ?? null,
        ]);
    }


    // aprobar/rechazar
    public function review(Request $request, OrderCancellation $cancellation)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected'
        ]);

        $cancellation->update([
            'status' => $request->status,
            'reviewed_by' => Auth::id(),
        ]);

        // si se aprueba, actualizamos estado de la orden a "Cancelado"
        if ($request->status === 'approved') {
            $canceladoStatus = Status::where('description', 'Cancelado')->first();
            if ($canceladoStatus) {
                $cancellation->order->update(['status_id' => $canceladoStatus->id]);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Cancelación revisada',
            'cancellation' => $cancellation
        ]);
    }
}
