<?php

namespace App\Http\Controllers;

use App\Models\AdAngle;
use App\Models\Landing;
use App\Services\Meta\Landings\LandingReport;
use App\Services\Meta\MetaAdsReport;
use App\Services\Meta\MetaVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Meta Ads › Landings (Etapa 5, paso 6a). Solo el Administrador, como todo Meta Ads:
 * - cada landing de un producto con sus anuncios, clics, visitas, % de visita, % de compra y CPA contra C, por ciudad y
 *   por ángulo, y lo que dicen los números (LandingRules);
 * - el dueño le pone nombre ("Base", "BASU"), el ángulo del que habla y notas.
 * Nada de esto toca Meta ni Shopify: las landings las encuentra la sincronización en los anuncios.
 */
class MetaLandingsController extends Controller
{
    public function index(Request $request, LandingReport $report)
    {
        $data = $request->validate([
            'product_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'range' => ['nullable', Rule::in(array_keys(MetaAdsReport::RANGES))],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);
        $products = $report->products();
        $productId = isset($data['product_id']) ? (int) $data['product_id'] : ($products[0]['id'] ?? null);
        $body = ['products' => $products, 'product_id' => $productId, 'ranges' => MetaAdsReport::RANGES];
        if ($productId !== null) {
            $body += $report->forProduct($productId, $data['range'] ?? 'last_30d', $data['from'] ?? null, $data['to'] ?? null,
                isset($data['city_id']) ? (int) $data['city_id'] : null);
        }

        return response()->json(MetaVisibility::forUser($body, Auth::user()));
    }

    /** El nombre, el ángulo (uno del producto, o vacío = la base) y las notas. */
    public function update(Request $request, Landing $landing)
    {
        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:120',
            'angle_code' => ['sometimes', 'nullable', 'string', 'max:12',
                Rule::exists('ad_angles', 'code')->where(fn ($q) => $q->where('product_id', $landing->product_id)->whereNull('alias_of'))],
            'notes' => 'sometimes|nullable|string|max:2000',
        ], ['angle_code.exists' => 'Ese ángulo no es de este producto.']);
        foreach (['name', 'notes'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = trim((string) $data[$k]) === '' ? null : trim((string) $data[$k]);
            }
        }
        $landing->fill($data + ['updated_by' => Auth::id()])->save();

        return response()->json([
            'id' => $landing->id, 'name' => $landing->name, 'label' => $landing->label(), 'angle_code' => $landing->angle_code, 'notes' => $landing->notes,
            'angle_name' => $landing->angle_code ? AdAngle::where('product_id', $landing->product_id)->where('code', $landing->angle_code)->value('name') : null,
        ]);
    }
}
