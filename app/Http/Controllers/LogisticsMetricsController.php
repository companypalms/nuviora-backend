<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCenter;
use App\Services\Logistics\Metrics;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Las métricas del §27 de un centro, por rango de días. Para administración. */
class LogisticsMetricsController extends Controller
{
    public function show(Request $request, LogisticsCenter $center, Metrics $metrics)
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        $from = Carbon::parse($data['from'], config('app.timezone'));
        $to = Carbon::parse($data['to'], config('app.timezone'));
        if ($from->diffInDays($to) > 92) {
            return response()->json(['status' => false, 'message' => 'Elige un rango de hasta 3 meses.'], 422);
        }

        return response()->json(['status' => true, 'metrics' => $metrics->for($center, $from, $to)]);
    }

    /**
     * La hoja de la revisión semanal (idea 2 de Fran): las piezas defectuosas o en revisión del almacén del centro, por
     * producto y variante, y los retornos que esperan la decisión de administración, con de qué orden vinieron, desde
     * cuándo y quién los recibió. Para imprimir. Quién la revisa lo decide Fran; mientras tanto, administración.
     */
    public function reviewSheet(LogisticsCenter $center)
    {
        $warehouseId = $center->warehouse_id;
        $variants = \Illuminate\Support\Facades\DB::table('inventory_variants as iv')
            ->join('products as p', 'p.id', '=', 'iv.product_id')->join('product_variants as v', 'v.id', '=', 'iv.variant_id')
            ->where('iv.warehouse_id', $warehouseId)->where('iv.defective_stock', '>', 0)
            ->get(['p.id as product_id', 'p.title as product', 'v.title as variant', 'iv.defective_stock as quantity']);
        $withVariants = $variants->pluck('product_id')->unique();
        $plain = \Illuminate\Support\Facades\DB::table('inventories as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.warehouse_id', $warehouseId)->where('i.defective_stock', '>', 0)->whereNotIn('i.product_id', $withVariants)
            ->get(['p.id as product_id', 'p.title as product', 'i.defective_stock as quantity'])->map(fn ($r) => (array) $r + ['variant' => null]);
        $returns = \App\Models\LogisticsOrder::with(['order:id,name', 'order.products:id,order_id,product_id,title,name,size,quantity', 'rider:id,names',
            'events' => fn ($q) => $q->where('action', 'retorno_recibido')->with('actor:id,names')])
            ->where('center_id', $center->id)->where('status', \App\Constants\LogisticsStatus::IN_REVIEW)->orderBy('returned_at')->get();

        return response()->json(['status' => true, 'warehouse_id' => $warehouseId, 'generated_at' => now(),
            'pieces' => $variants->map(fn ($r) => (array) $r)->merge($plain)->sortBy('product')->values(),
            'returns' => $returns->map(fn ($r) => [
                'logistics_order_id' => $r->id,
                'order' => $r->order?->name,
                'rider' => $r->rider?->names,
                'items' => $r->order?->products->map(fn ($p) => ['name' => $p->title ?: $p->name, 'size' => $p->size, 'quantity' => (int) $p->quantity])->values(),
                'returned_at' => $r->returned_at,
                'received_by' => $r->events->first()?->actor?->names,
            ])->values(),
        ]);
    }
}
