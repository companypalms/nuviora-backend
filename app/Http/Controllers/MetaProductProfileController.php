<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductProfile;
use App\Services\Meta\AngleStats;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\ProductProfiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Configuración › Meta Ads › Ficha del producto (`.64`): qué es, para qué sirve, cómo funciona, lo que NO es, la
 * información clave, las fotos y los buyer personas de cada producto. También la llena el asistente con su tarjeta.
 */
class MetaProductProfileController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['product_id' => 'sometimes|nullable|integer|exists:products,id']);
        $products = self::products();
        $pid = isset($data['product_id']) ? (int) $data['product_id'] : ($products[0]['id'] ?? null);

        return response()->json(['products' => $products, 'product_id' => $pid] + ($pid ? self::present($pid) : []));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'profile' => 'required|array',
            'profile.*' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas' => 'present|array|max:' . ProductProfileRules::MAX_PERSONAS,
            'personas.*.id' => 'sometimes|nullable|integer',
            'personas.*.name' => 'nullable|string|max:' . ProductProfileRules::NAME_MAX,
            'personas.*.who' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas.*.pain' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas.*.wants' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
            'personas.*.objections' => 'nullable|string|max:' . ProductProfileRules::TEXT_MAX,
        ]);
        $profile = ProductProfileRules::normalize($data['profile']);
        // Una fila vacía (todavía sin escribir) no es un buyer persona
        $personas = array_values(array_filter(array_map([ProductProfileRules::class, 'normalizePersona'], $data['personas']),
            fn ($p) => array_filter(array_intersect_key($p, ProductProfileRules::PERSONA_FIELDS)) !== []));
        if ($problems = ProductProfileRules::problems($profile, $personas)) {
            throw ValidationException::withMessages(['profile' => $problems]);
        }
        ProductProfiles::save($product->id, $profile, $personas, Auth::id(), 'owner');

        return response()->json(['product_id' => $product->id] + self::present($product->id) + ['products' => self::products()]);
    }

    /** La ficha de un producto para la pantalla: lo guardado, lo que falta y quién la guardó. */
    public static function present(int $productId): array
    {
        $f = ProductProfiles::get($productId);
        $row = ProductProfile::with('updater:id,names')->where('product_id', $productId)->first();

        return [
            'profile' => $f['profile'] ?? array_fill_keys(array_keys(ProductProfileRules::FIELDS), null),
            'personas' => $f['personas'],
            'missing' => ProductProfileRules::missing($f['profile'], $f['personas']),
            'updated' => $row ? ['at' => $row->updated_at?->toIso8601String(), 'by' => $row->updater?->names, 'source' => $row->source] : null,
            'labels' => ['fields' => ProductProfileRules::FIELDS, 'persona' => ProductProfileRules::PERSONA_FIELDS],
        ];
    }

    /** Primero los productos con campañas (como en Ángulos); después los demás, por si uno nuevo todavía no tiene. */
    private static function products(): array
    {
        $withCampaigns = AngleStats::products();
        $ids = array_column($withCampaigns, 'id');
        $others = Product::whereNotIn('id', $ids ?: [0])->get(['id', 'title', 'showable_name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title, 'campaigns' => 0, 'active_campaigns' => 0])
            ->sortBy('name')->values()->all();
        $all = array_merge($withCampaigns, $others);
        $complete = ProductProfiles::completeIds(array_column($all, 'id'));

        return array_map(fn ($p) => $p + ['complete' => in_array($p['id'], $complete, true)], $all);
    }
}
