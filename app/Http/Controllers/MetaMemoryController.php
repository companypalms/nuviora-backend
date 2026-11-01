<?php

namespace App\Http\Controllers;

use App\Models\CreativeBatch;
use App\Models\Product;
use App\Models\ProductNote;
use App\Services\Meta\AngleStats;
use App\Services\Meta\Products\ProductMemories;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Meta Ads › Lo que sabemos (`.66`): por producto, lo que funciona, lo que no y lo que falta probar (el resumen de la
 * IA con los números del sistema), los resultados por ángulo, buyer persona y estilo, los batches testeados con su
 * aprendizaje y las notas del dueño. Solo el Admin: los editores nunca lo ven.
 */
class MetaMemoryController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['product_id' => 'sometimes|nullable|integer|exists:products,id']);
        $products = self::products();
        $pid = isset($data['product_id']) ? (int) $data['product_id'] : ($products[0]['id'] ?? null);

        return response()->json(['products' => $products, 'product_id' => $pid, 'kinds' => ProductNote::KINDS, 'styles' => QueueRules::STYLES]
            + ($pid ? ProductMemories::page($pid) : []));
    }

    /** "Actualizar el resumen": lo vuelve a escribir con los datos de hoy. */
    public function summarize(Product $product, ProductMemories $memories)
    {
        $memories->refresh($product->id, true);

        return response()->json(['product_id' => $product->id] + ProductMemories::page($product->id));
    }

    public function storeNote(Request $request, Product $product)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(ProductNote::KINDS))], 'text' => 'required|string|max:1000']);
        ProductNote::create(['product_id' => $product->id, 'kind' => $data['kind'], 'text' => trim($data['text']), 'source' => 'owner', 'created_by' => Auth::id()]);

        return response()->json(['product_id' => $product->id] + ProductMemories::page($product->id), 201);
    }

    public function updateNote(Request $request, ProductNote $note)
    {
        $data = $request->validate(['kind' => ['sometimes', Rule::in(array_keys(ProductNote::KINDS))], 'text' => 'sometimes|string|max:1000']);
        $note->fill(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data))->save();

        return response()->json(['product_id' => $note->product_id] + ProductMemories::page($note->product_id));
    }

    /** Quitar una nota: se archiva (no se borra). */
    public function deleteNote(ProductNote $note)
    {
        $note->forceFill(['archived_at' => now()])->save();

        return response()->json(['product_id' => $note->product_id] + ProductMemories::page($note->product_id));
    }

    /** Los productos con campañas (como en Ángulos) y los que ya tienen batches testeados o notas, con cuántos se testearon. */
    private static function products(): array
    {
        $list = AngleStats::products();
        $tested = CreativeBatch::where('status', 'tested')->groupBy('product_id')->selectRaw('product_id, COUNT(*) n')->pluck('n', 'product_id');
        $more = $tested->keys()->merge(ProductNote::whereNull('archived_at')->distinct()->pluck('product_id'))->unique()
            ->diff(array_column($list, 'id'))->values()->all();
        foreach (Product::whereIn('id', $more ?: [0])->get(['id', 'title', 'showable_name']) as $p) {
            $list[] = ['id' => $p->id, 'name' => $p->showable_name ?: $p->title, 'campaigns' => 0, 'active_campaigns' => 0];
        }

        return array_map(fn ($p) => $p + ['tested' => (int) ($tested[$p['id']] ?? 0)], $list);
    }
}
