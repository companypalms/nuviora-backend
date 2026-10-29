<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Metrics\Filters;
use App\Services\Metrics\OwnerPanel;
use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Panel del dueño (Etapa 2, diccionario de métricas). Solo el Admin, por la ruta. Solo lee: no cambia nada.
 */
class OwnerPanelController extends Controller
{
    /** Las listas de los filtros: tiendas, ciudades y productos. */
    public function options(): JsonResponse
    {
        return response()->json([
            'shops' => Shop::orderBy('name')->get(['id', 'name']),
            'cities' => City::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('title')->get(['id', 'title', 'showable_name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title]),
        ]);
    }

    public function sales(Request $request, OwnerPanel $panel): JsonResponse
    {
        [$period, $filters] = $this->scope($request);

        return response()->json($panel->sales($period, $filters));
    }

    public function money(Request $request, OwnerPanel $panel): JsonResponse
    {
        [$period, $filters] = $this->scope($request);

        return response()->json($panel->money($period, $filters));
    }

    public function funnel(Request $request, OwnerPanel $panel): JsonResponse
    {
        [$period, $filters] = $this->scope($request);

        return response()->json($panel->funnel($period, $filters));
    }

    /** @return array{0: Period, 1: Filters} */
    private function scope(Request $request): array
    {
        $data = $request->validate([
            'preset' => ['required', Rule::in(Period::PRESETS)],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'shop_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
        ]);
        try {
            $period = Period::fromPreset($data['preset'], $data['from'] ?? null, $data['to'] ?? null, CarbonImmutable::now());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['from' => $e->getMessage()]);
        }

        return [$period, new Filters(
            isset($data['shop_id']) ? (int) $data['shop_id'] : null,
            isset($data['city_id']) ? (int) $data['city_id'] : null,
            isset($data['product_id']) ? (int) $data['product_id'] : null,
        )];
    }
}
