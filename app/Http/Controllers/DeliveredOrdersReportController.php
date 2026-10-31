<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Reportes › Reporte Horas Entregas: los pedidos creados en unas fechas y cuánto tardó cada uno en entregarse. Solo el
 * Admin.
 * - `.58` (2026-10-31): por fechas (sin elegir, los últimos 30 días; antes cargaba toda la historia de una vez) y con la
 *   fecha real de entrega (orders.delivered_at, la que mantiene el sistema venga de donde venga el "Entregado"; antes,
 *   processed_at, que solo se ponía al entregar desde una pantalla).
 */
class DeliveredOrdersReportController extends Controller
{
    /** Lo más largo que se puede pedir de una vez. */
    private const MAX_DAYS = 400;

    public function index(Request $request)
    {
        // Admin only
        $user = Auth::user();
        if ($user->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No autorizado.'], 403);
        }
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            return response()->json(['status' => false, 'message' => 'La fecha "desde" no puede ser después de "hasta".'], 422);
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            return response()->json(['status' => false, 'message' => 'El período no puede pasar de ' . self::MAX_DAYS . ' días.'], 422);
        }

        $orders = Order::with(['client:id,first_name,last_name,phone', 'agent:id,names', 'agency:id,names', 'status:id,description'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'order_number', 'status_id', 'client_id', 'agent_id', 'agency_id', 'current_total_price', 'currency', 'created_at', 'delivered_at'])
            ->map(function ($order) {
                $clientName = $order->client
                    ? trim(($order->client->first_name ?? '') . ' ' . ($order->client->last_name ?? ''))
                    : 'Sin cliente';

                $createdAt = $order->created_at ? Carbon::parse($order->created_at) : null;
                $deliveredAt = $order->status?->description === 'Entregado' && $order->delivered_at ? Carbon::parse($order->delivered_at) : null;

                $durationHours = ($createdAt && $deliveredAt)
                    ? round($createdAt->diffInMinutes($deliveredAt) / 60, 2)
                    : null;

                return [
                    'id'             => $order->id,
                    'order_number'   => $order->name ?? $order->order_number,
                    'status'         => $order->status?->description ?? 'Sin estatus',
                    'client_name'    => $clientName,
                    'client_phone'   => $order->client?->phone ?? '',
                    'agent_name'     => $order->agent?->names ?? 'Sin vendedora',
                    'agency_name'    => $order->agency?->names ?? 'Sin agencia',
                    'total'          => $order->current_total_price,
                    'currency'       => $order->currency ?? 'USD',
                    'created_at'     => $createdAt?->format('Y-m-d H:i:s'),
                    'delivered_at'   => $deliveredAt?->format('Y-m-d H:i:s'),
                    'duration_hours' => $durationHours,
                ];
            });

        return response()->json([
            'status' => true,
            'data'   => $orders,
            'total'  => $orders->count(),
            'from'   => $from->toDateString(),
            'to'     => $to->toDateString(),
        ]);
    }
}
