<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCenter;
use App\Models\Product;
use App\Models\StockAdjustmentRequest;
use App\Services\InventoryService;
use App\Services\Logistics\Shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Solicitar ajuste" (§16.2.3, §29 punto 22; idea 3 de Fran): el operador del centro no ajusta el stock, lo propone con
 * motivo y, si quiere, una foto. Administración lo aprueba (se registra el movimiento) o lo rechaza con su nota.
 */
class LogisticsAdjustmentController extends Controller
{
    public function __construct(private Shifts $shifts)
    {
    }

    /** Las solicitudes del centro: las pendientes y las últimas decididas. */
    public function index(LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $rows = StockAdjustmentRequest::with(['product:id,title,name', 'variant:id,title', 'requester:id,names', 'decider:id,names'])
            ->where('center_id', $center->id)
            ->orderByRaw("status = 'pendiente' desc")->orderByDesc('id')->limit(100)->get();

        return response()->json(['status' => true, 'requests' => $rows]);
    }

    /** Los productos del almacén del centro, para elegir cuál ajustar (el operador no ve el catálogo: §3, P0). */
    public function products(Request $request, LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $q = trim((string) $request->query('q', ''));
        $products = Product::with('variants:id,product_id,title')
            ->whereIn('id', DB::table('inventories')->where('warehouse_id', $center->warehouse_id)->select('product_id'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('title')->limit(30)->get(['id', 'title', 'name']);

        return response()->json(['status' => true, 'products' => $products]);
    }

    public function store(Request $request, LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'required|integer|not_in:0|between:-1000,1000',
            'reason' => 'required|string|max:250',
            'photo' => 'nullable|image|max:10240',
        ]);
        if (!DB::table('inventories')->where('warehouse_id', $center->warehouse_id)->where('product_id', $data['product_id'])->exists()) {
            return response()->json(['status' => false, 'message' => 'Ese producto no está en el almacén del centro.'], 422);
        }
        $row = StockAdjustmentRequest::create([
            'center_id' => $center->id,
            'warehouse_id' => $center->warehouse_id,
            'product_id' => $data['product_id'],
            'variant_id' => $data['variant_id'] ?? null,
            'quantity' => $data['quantity'],
            'reason' => $data['reason'],
            'photo_path' => $request->file('photo')?->store('stock_adjustments', 'public'),
            'requested_by' => Auth::id(),
            'status' => StockAdjustmentRequest::PENDING,
        ]);
        app(\App\Services\Logistics\Alerts::class)->raise($center, 'ajuste_solicitado', \App\Models\LogisticsAlert::WARNING,
            Auth::user()->names . ' pide un ajuste de stock: ' . ($data['quantity'] > 0 ? '+' : '') . $data['quantity'] . ' pieza(s). Lo aprueba administración.',
            "ajuste:{$row->id}");

        return response()->json(['status' => true, 'message' => 'Solicitud enviada: el stock no cambia hasta que administración la apruebe.', 'request' => $row], 201);
    }

    /** Solo administración (rutas con role:Admin,Master). */
    public function decide(Request $request, StockAdjustmentRequest $adjustment, InventoryService $inventory)
    {
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:250']);
        return DB::transaction(function () use ($adjustment, $data, $inventory) {
            $row = StockAdjustmentRequest::whereKey($adjustment->id)->lockForUpdate()->first();
            if ($row->status !== StockAdjustmentRequest::PENDING) {
                return response()->json(['status' => false, 'message' => 'Esa solicitud ya se decidió.'], 409);
            }
            $movementId = null;
            if ($data['approve']) {
                $qty = abs($row->quantity);
                $variants = $row->variant_id ? [$row->variant_id => $qty] : null;
                $notes = "Ajuste solicitado por el operador (#{$row->id}): {$row->reason}";
                try {
                    $movement = $row->quantity > 0
                        ? $inventory->addStock($row->product_id, $row->warehouse_id, $qty, Auth::id(), $notes, 'StockAdjustmentRequest', $row->id, $variants)
                        : $inventory->removeStock($row->product_id, $row->warehouse_id, $qty, Auth::id(), $notes, 'StockAdjustmentRequest', $row->id, $variants);
                    $movementId = $movement?->id;
                } catch (\Throwable $e) {
                    return response()->json(['status' => false, 'message' => 'No se pudo aplicar: ' . $e->getMessage()], 422);
                }
            }
            $row->update([
                'status' => $data['approve'] ? StockAdjustmentRequest::APPROVED : StockAdjustmentRequest::REJECTED,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
                'decision_note' => $data['note'] ?? null,
                'movement_id' => $movementId,
            ]);
            \App\Models\LogisticsAlert::where('dedupe_key', "ajuste:{$row->id}")->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => Auth::id()]);

            return response()->json(['status' => true, 'message' => $data['approve'] ? 'Ajuste aplicado al stock.' : 'Solicitud rechazada.']);
        });
    }

    private function authorizeCenter(LogisticsCenter $center): void
    {
        $user = Auth::user();
        if (!Shifts::isAdmin($user) && !$this->shifts->isOperatorOf($user, $center)) {
            abort(response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403));
        }
    }
}
