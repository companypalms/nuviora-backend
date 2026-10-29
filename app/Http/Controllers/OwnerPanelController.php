<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Metrics\Filters;
use App\Services\Metrics\GoalSettings;
use App\Services\Metrics\Insights\CancellationInsights;
use App\Services\Metrics\OwnerPanel;
use App\Services\Metrics\Period;
use App\Services\Metrics\Team\TeamCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Panel del dueño (Etapa 2, diccionario de métricas). Solo el Admin, por la ruta. Solo lee, salvo las metas y los límites
 * de las alertas que pone el dueño (§6), que son configuración: no tocan pedidos ni dinero.
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

    public function losses(Request $request, OwnerPanel $panel): JsonResponse
    {
        [$period, $filters] = $this->scope($request);

        return response()->json($panel->losses($period, $filters));
    }

    /** Equipo: los KPIs de las personas de un rol del catálogo (por defecto, las vendedoras). */
    public function team(Request $request, OwnerPanel $panel): JsonResponse
    {
        [$period, $filters] = $this->scope($request);
        // Un rol que ya no está en el catálogo (lo recordaba el navegador) muestra las vendedoras
        $data = $request->validate(['role' => 'nullable|string|max:40']);

        return response()->json($panel->team($period, $filters, $data['role'] ?? 'sellers'));
    }

    /** El detalle de una persona (o de un grupo, en las líderes) de Equipo. */
    public function teamPerson(Request $request, OwnerPanel $panel): JsonResponse
    {
        [$period, $filters] = $this->scope($request);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(TeamCatalog::roles()))], 'id' => 'required|integer']);
        $person = $panel->teamPerson($period, $filters, $data['role'], (int) $data['id']);

        return $person
            ? response()->json($person)
            : response()->json(['message' => 'No tiene actividad en este período.'], 404);
    }

    /** El análisis con IA de los motivos de cancelación ("force" lo rehace aunque ya esté guardado). */
    public function lossesInsights(Request $request, CancellationInsights $insights): JsonResponse
    {
        [$period, $filters] = $this->scope($request);
        @set_time_limit(130);

        return response()->json($insights->analyze($period, $filters, $request->boolean('force')));
    }

    /** Las alertas de ahora, arriba del panel. No dependen de los filtros. */
    public function alerts(OwnerPanel $panel): JsonResponse
    {
        return response()->json($panel->alerts());
    }

    /** Las metas del negocio de este mes, para arriba del Dashboard. */
    public function goalsSummary(OwnerPanel $panel): JsonResponse
    {
        return response()->json($panel->goalsSummary());
    }

    /** Las metas y los límites guardados, con su explicación y tus números de referencia. */
    public function goals(OwnerPanel $panel): JsonResponse
    {
        return response()->json($panel->goals());
    }

    /**
     * Guarda las metas y los límites (todo junto; una casilla vacía es "sin meta"). Las del negocio, del mes que corre y
     * del siguiente ("months": "2026-11" => metas).
     */
    public function saveGoals(Request $request, GoalSettings $settings, OwnerPanel $panel): JsonResponse
    {
        $data = $request->validate(['months' => 'present|array', 'team' => 'present|array', 'alerts' => 'present|array']);
        $settings->save($data, $request->user(), CarbonImmutable::now());

        return response()->json($panel->goals());
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
