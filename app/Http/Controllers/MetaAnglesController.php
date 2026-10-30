<?php

namespace App\Http\Controllers;

use App\Models\AdAngle;
use App\Models\Product;
use App\Models\ProductAdSetting;
use App\Services\Meta\AngleProposer;
use App\Services\Meta\AngleStats;
use App\Services\Meta\MetaCreativeLinker;
use App\Services\Meta\MetaVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Meta Ads › Ángulos y el modo de cada producto (Etapa 5, paso 1b). Solo el Administrador (como todo Meta Ads):
 * - el diccionario de ángulos de cada producto: el sistema junta los códigos de los nombres, la IA propone el nombre y la
 *   hipótesis, el dueño corrige, confirma, une dos códigos que son lo mismo o deshace una unión;
 * - el modo del producto (normal, liquidación, pausado o abandonado), que usan las reglas del paso 2.
 * Nada de esto modifica Meta.
 */
class MetaAnglesController extends Controller
{
    /** Los productos con campañas o ángulos, y los ángulos del elegido con lo que se gastó y vendió en 30 días. */
    public function index(Request $request)
    {
        $products = $this->products();
        $productId = $request->filled('product_id') ? (int) $request->get('product_id') : ($products[0]['id'] ?? null);
        $angles = $productId ? AngleStats::forProduct($productId) : [];

        return response()->json(MetaVisibility::forUser(['products' => $products, 'product_id' => $productId, 'angles' => $angles], Auth::user()));
    }

    /**
     * Cambiar el nombre o la hipótesis, confirmar, unir con otro código (alias_of = el otro) o separar (alias_of vacío).
     * Al unir o separar se vuelven a enlazar los anuncios del producto.
     */
    public function update(Request $request, AdAngle $angle)
    {
        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:120',
            'hypothesis' => 'sometimes|nullable|string|max:1000',
            'confirm' => 'sometimes|boolean',
            'alias_of' => ['sometimes', 'nullable', 'integer', Rule::exists('ad_angles', 'id')->where('product_id', $angle->product_id)],
        ]);
        $relink = false;
        DB::transaction(function () use ($data, $angle, &$relink) {
            if (array_key_exists('name', $data) || array_key_exists('hypothesis', $data)) {
                $angle->fill(array_intersect_key($data, array_flip(['name', 'hypothesis'])));
                if ($angle->isDirty() && $angle->status !== 'confirmed') {
                    $angle->proposed_by = 'owner';
                }
            }
            if (array_key_exists('alias_of', $data) && $data['alias_of'] !== $angle->alias_of) {
                $target = $data['alias_of'] ? AdAngle::findOrFail($data['alias_of']) : null;
                $canonical = $target ? ($target->alias_of ?? $target->id) : null;
                if ($canonical === $angle->id) {
                    throw ValidationException::withMessages(['alias_of' => 'Un ángulo no puede ser lo mismo que sí mismo.']);
                }
                if ($canonical) {
                    // Los que eran lo mismo que este pasan a ser lo mismo que el otro
                    AdAngle::where('alias_of', $angle->id)->update(['alias_of' => $canonical]);
                }
                $angle->alias_of = $canonical;
                $angle->suggested_alias = null;
                $relink = true;
            }
            if (($data['confirm'] ?? false) === true) {
                $angle->fill(['status' => 'confirmed', 'confirmed_by' => Auth::id(), 'confirmed_at' => now()]);
            } elseif (($data['confirm'] ?? null) === false && $angle->status === 'confirmed') {
                $angle->fill(['status' => $angle->proposed_at ? 'proposed' : 'detected', 'confirmed_by' => null, 'confirmed_at' => null]);
            }
            $angle->save();
        });
        if ($relink) {
            (new MetaCreativeLinker())->run(['product' => $angle->product_id]);
        }

        return response()->json(['status' => true, 'angles' => AngleStats::forProduct($angle->product_id)]);
    }

    /** "Proponer con IA": los ángulos sin confirmar del producto, o los elegidos. */
    public function propose(Request $request, AngleProposer $proposer)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'angle_ids' => 'sometimes|array|max:40',
            'angle_ids.*' => 'integer',
        ]);
        $result = $proposer->propose(Product::findOrFail($data['product_id']), $data['angle_ids'] ?? null);

        return response()->json($result + ['angles' => AngleStats::forProduct((int) $data['product_id'])], $result['status'] === 'error' ? 502 : 200);
    }

    /** El modo de cada producto con campañas de Meta (o con modo puesto). */
    public function modes()
    {
        $settings = ProductAdSetting::with('updater:id,names')->get()->keyBy('product_id');

        return response()->json([
            'modes' => ProductAdSetting::MODES,
            'products' => collect($this->products())->map(function ($p) use ($settings) {
                $s = $settings->get($p['id']); // sin fila, el producto está en modo normal

                return $p + ['mode' => $s->mode ?? 'normal', 'note' => $s?->note, 'updated_by' => $s?->updater?->names,
                    'updated_at' => $s?->updated_at?->toIso8601String()];
            })->values(),
        ]);
    }

    public function setMode(Request $request, Product $product)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(ProductAdSetting::MODES)],
            'note' => 'nullable|string|max:255',
        ]);
        ProductAdSetting::updateOrCreate(['product_id' => $product->id], $data + ['updated_by' => Auth::id()]);

        return response()->json(['status' => true]);
    }

    /** @return array<int, array{id: int, name: string, campaigns: int, active_campaigns: int}> */
    private function products(): array
    {
        $campaigns = DB::table('meta_campaigns')->whereNotNull('product_id')->groupBy('product_id')
            ->selectRaw("product_id, COUNT(*) n, SUM(effective_status = 'ACTIVE' AND missing_since IS NULL) active")->get()->keyBy('product_id');
        $ids = $campaigns->keys()->merge(AdAngle::distinct()->pluck('product_id'))->merge(ProductAdSetting::pluck('product_id'))->unique();

        return Product::whereIn('id', $ids)->get(['id', 'title', 'showable_name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title,
                'campaigns' => (int) ($campaigns[$p->id]->n ?? 0), 'active_campaigns' => (int) ($campaigns[$p->id]->active ?? 0)])
            ->sortBy([['active_campaigns', 'desc'], ['name', 'asc']])->values()->all();
    }
}
