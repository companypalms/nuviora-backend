<?php

namespace App\Services\Agencies;

use App\Constants\OrderStatus;
use App\Models\City;
use App\Models\Order;
use App\Models\Status;
use App\Models\User;

/**
 * Las agencias y las ciudades como las muestra "Tiendas, ciudades y agencias" (AgencyRoutingController): cada agencia
 * con su tarifa, su máximo, sus pedidos activos, sus ciudades y si está trabajando (y su horario); cada ciudad con sus
 * agencias y su %, sus pedidos pendientes de agencia, si está trabajando y los que esperan. La usan esa pantalla y el
 * asistente del dueño. Solo lee.
 */
class RoutingOverview
{
    public function __construct(private AgencyRouter $router, private WorkingHours $hours)
    {
    }

    public function payload(): array
    {
        $agencies = User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))
            ->orderBy('names')->get(['id', 'names', 'color', 'delivery_cost', 'max_active_orders', 'agency_is_working', 'agency_works_from', 'agency_works_until', 'deactivated_at']);
        $waiting = $this->hours->waitingByCity();
        $active = $this->router->activeCounts($agencies->pluck('id')->all());
        $pendingId = Status::where('description', OrderStatus::PENDIENTE_AGENCIA)->value('id');
        $pending = $pendingId
            ? Order::where('status_id', $pendingId)->groupBy('city_id')->selectRaw('city_id, COUNT(*) as c')->pluck('c', 'city_id')
            : collect();
        $cities = City::with('agencies:id,names,color,max_active_orders')->orderBy('name')->get();
        $citiesByAgency = [];
        foreach ($cities as $city) {
            foreach ($city->agencies as $a) {
                $citiesByAgency[$a->id][] = $city->name;
            }
        }

        return [
            'agencies' => $agencies->map(fn (User $a) => [
                'id' => $a->id,
                'names' => $a->names,
                'color' => $a->color,
                'delivery_cost' => (float) $a->delivery_cost,
                'max_active_orders' => $a->max_active_orders,
                'active' => $active[$a->id] ?? 0,
                'full' => $a->max_active_orders !== null && ($active[$a->id] ?? 0) >= $a->max_active_orders,
                'cities' => $citiesByAgency[$a->id] ?? [],
                'is_working' => $a->agency_is_working !== false,
                'works_from' => $a->agency_works_from ? substr($a->agency_works_from, 0, 5) : null,
                'works_until' => $a->agency_works_until ? substr($a->agency_works_until, 0, 5) : null,
                'stop_reason' => $this->hours->agencyStop($a),
            ])->values(),
            'cities' => $cities->map(function (City $city) use ($active, $pending, $waiting) {
                $activeAgencies = $city->agencies->filter(fn ($a) => $a->pivot->is_active);
                $weights = $this->router->weights($activeAgencies);
                $total = array_sum($weights);

                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'delivery_cost_usd' => (float) $city->delivery_cost_usd,
                    'pending' => (int) ($pending[$city->id] ?? 0),
                    'is_working' => $city->is_working !== false,
                    'stop_reason' => $this->hours->cityStop($city),
                    'waiting' => (int) ($waiting[$city->id] ?? 0),
                    'agencies' => $city->agencies->map(fn ($a) => [
                        'id' => $a->id,
                        'names' => $a->names,
                        'color' => $a->color,
                        'weight' => $a->pivot->weight !== null ? (float) $a->pivot->weight : null,
                        'is_active' => (bool) $a->pivot->is_active,
                        'share' => $total > 0 && isset($weights[$a->id]) ? round($weights[$a->id] / $total, 4) : 0,
                        'active' => $active[$a->id] ?? 0,
                        'max_active_orders' => $a->max_active_orders,
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
