<?php

namespace App\Services\Orders;

use App\Constants\OrderStatus;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderUpdate;
use App\Models\Status;
use App\Models\User;
use App\Notifications\OrderAssignedNotification;
use App\Services\Agencies\AgencyRouter;
use App\Services\Agencies\WorkingHours;
use App\Services\Inventory\StockCheck;
use App\Services\Logistics\Centers;
use App\Services\Logistics\LogisticsOrders;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * .149 "Cambiar ciudad o agencia" (dueño, 2026-11-16: "lo correcto sería que solo se reasignara a una ciudad, si quiero
 * reasignarlo a una ciudad, y si quiero reasignarlo de agencia dentro de esa ciudad, también lo pueda reasignar. Y es
 * importante que la líder de vendedoras pueda reasignar de ciudad al igual que yo"). Reemplaza "Gestión Logística", que
 * dejaba elegir cualquier agencia de cualquier ciudad y, al cambiar solo la ciudad, dejaba la agencia vieja; y también
 * "Asignar Agencia" / "Reasignar agencia".
 *  - La agencia siempre es de la ciudad elegida (city_agency activa), o la elige el sistema (stock, cupo y %, AgencyRouter).
 *  - Se puede hasta que la agencia se lo da a un motorizado. Entregado, solo el Admin (como antes).
 *  - Quién: Admin, Gerente y Master; la líder de vendedoras en los pedidos que ve (los de su grupo); las demás vendedoras,
 *    en Sin Stock (como con "Reasignar agencia") o si el pedido todavía no tiene agencia (al mandarlo a la agencia sin una
 *    ciudad conocida: require_manual_agency).
 */
final class OrderRelocation
{
    /** Hasta que la agencia se lo da a un motorizado. */
    public const OPEN = [
        OrderStatus::NUEVO, OrderStatus::ASIGNADO_VENDEDOR, OrderStatus::LLAMADO_1, OrderStatus::LLAMADO_2, OrderStatus::LLAMADO_3,
        OrderStatus::ESPERANDO_UBICACION, OrderStatus::PROGRAMADO_MAS_TARDE, OrderStatus::PROGRAMADO_OTRO_DIA,
        OrderStatus::REPROGRAMADO_HOY, OrderStatus::CAMBIO_UBICACION, OrderStatus::POR_APROBAR_UBICACION, OrderStatus::NOVEDADES,
        OrderStatus::NOVEDAD_SOLUCIONADA, OrderStatus::SIN_STOCK, OrderStatus::PENDIENTE_AGENCIA, OrderStatus::ASIGNAR_A_AGENCIA,
        OrderStatus::ASIGNAR_REPARTIDOR,
    ];

    /** Ya es de la agencia (o la espera): la nueva lo recibe en "Asignar a agencia", sin motorizado. */
    public const AT_AGENCY = [OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::PENDIENTE_AGENCIA];

    public function __construct(private AgencyRouter $router)
    {
    }

    /** owner (Admin), boss (Gerente, Master), leader (la líder, en los pedidos que ve), seller (la vendedora del pedido) o none. */
    public static function who(User $user, Order $order): string
    {
        $role = strtolower(trim((string) $user->role?->description));
        if ($role === 'admin') {
            return 'owner';
        }
        if (in_array($role, ['gerente', 'master', 'manager'], true)) {
            return 'boss';
        }
        if (str_contains($role, 'vende')) {
            if ($user->ledGroup() && OrderAccess::can($user, $order)) {
                return 'leader';
            }

            return (int) $order->agent_id === (int) $user->id ? 'seller' : 'none';
        }

        return 'none';
    }

    /** Pasar a una agencia que está en su máximo: tú y la líder sí; la vendedora, no. */
    public static function canForce(string $who): bool
    {
        return in_array($who, ['owner', 'boss', 'leader'], true);
    }

    /** Por qué no se puede (null = se puede). $unassigned: el pedido todavía no tiene agencia (ponérsela no es reasignarlo). */
    public static function stop(string $who, ?string $status, bool $unassigned = false): ?string
    {
        if ($who === 'none') {
            return 'No tienes permiso para cambiar la ciudad o la agencia de este pedido.';
        }
        if ($status === OrderStatus::ENTREGADO) {
            return $who === 'owner' ? null : 'Ya está entregado: solo el dueño puede cambiarle la ciudad o la agencia.';
        }
        if (in_array($status, [OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::EN_RUTA], true)) {
            return 'Ya lo tiene el motorizado de la agencia. Primero tiene que volver (Novedad o Reprogramado).';
        }
        if (!in_array($status, self::OPEN, true)) {
            return "En \"{$status}\" ya no se cambia la ciudad ni la agencia.";
        }
        if ($who === 'seller' && $status !== OrderStatus::SIN_STOCK && !$unassigned) {
            return 'Solo se puede en Sin Stock. Para los demás pedidos, pídeselo a tu líder.';
        }

        return null;
    }

    /** Logística propia: si el centro ya lo está moviendo, se cambia desde la Torre de control. */
    private function centerStop(Order $order): ?string
    {
        return app(LogisticsOrders::class)->active($order)
            ? 'Lo está manejando el centro logístico: cámbialo desde la Torre de control.' : null;
    }

    /**
     * Lo que muestra la ventana: la ciudad y la agencia de hoy, y por cada ciudad que reparte (con agencias o con centro)
     * sus agencias con stock, cupo y horario.
     */
    public function options(Order $order, User $user): array
    {
        $who = self::who($user, $order);
        $status = $order->status?->description;
        $current = $this->router->cityFor($order);
        // Lo que escribió el cliente, solo si no es la ciudad del pedido (ni como provincia ni como ciudad)
        $client = $order->client;
        $wrote = collect([$client?->province, $client?->city])->map(fn ($n) => trim((string) $n))->filter();
        $clientCity = $wrote->contains(fn ($n) => $current && mb_strtolower($n) === mb_strtolower($current->name)) ? null : $wrote->first();
        $base = [
            'stop' => self::stop($who, $status, !$order->agency_id) ?? $this->centerStop($order),
            'status' => $status,
            'city_id' => $current?->id,
            'agency_id' => $order->agency_id ? (int) $order->agency_id : null,
            'client_city' => $clientCity,
            'can_force' => self::canForce($who),
            'cities' => [],
        ];
        if ($base['stop']) {
            return $base;
        }

        $hours = app(WorkingHours::class);
        $centers = app(Centers::class);
        $base['cities'] = City::operational()->orderBy('name')->get()->map(function (City $city) use ($order, $hours, $centers, $current) {
            $agencies = $this->agenciesOf($city);
            $center = $centers->forCity($city->id);
            if ($agencies->isEmpty() && !$center && $city->id !== $current?->id) {
                return null;
            }
            $counts = $this->router->activeCounts($agencies->pluck('id')->all());
            $rows = $agencies->map(fn (User $a) => [
                'id' => $a->id,
                'name' => $a->names,
                'stock' => $this->router->hasStockAt($order, $a->id),
                'active' => $counts[$a->id] ?? 0,
                'max' => $a->max_active_orders !== null ? (int) $a->max_active_orders : null,
                'full' => !$this->router->hasRoom($a, $order),
                'off' => $hours->agencyStop($a),
            ])->values()->all();
            $centerStock = $center?->warehouse_id && app(StockCheck::class)->at($order, (int) $center->warehouse_id)['ok'];

            return [
                'id' => $city->id,
                'name' => $city->name,
                'center' => (bool) $center,
                'stock' => $centerStock || collect($rows)->contains('stock', true),
                'agencies' => $rows,
            ];
        })->filter()->values()->all();

        return $base;
    }

    /** Las agencias que reparten en la ciudad (activas ahí y con la cuenta activa), estén o no en horario. */
    private function agenciesOf(City $city): Collection
    {
        return $city->agencies()->wherePivot('is_active', true)->orderBy('users.names')->get()
            ->reject(fn (User $a) => $a->isDeactivated())->values();
    }

    /**
     * Pasa el pedido a la ciudad y a la agencia (null = la elige el sistema). Lo que no cuadra se rechaza con un
     * ValidationException: 'order' (el motivo) o 'full' (la agencia está en su máximo y no se forzó).
     *
     * @return array{0: Order, 1: string}  el pedido guardado y lo que pasó, en palabras.
     */
    public function move(Order $order, User $actor, int $cityId, ?int $agencyId, bool $force = false): array
    {
        $who = self::who($actor, $order);
        $status = $order->status?->description;
        if ($stop = self::stop($who, $status, !$order->agency_id) ?? $this->centerStop($order)) {
            throw ValidationException::withMessages(['order' => $stop]);
        }
        $city = City::find($cityId);
        if (!$city) {
            throw ValidationException::withMessages(['order' => 'Esa ciudad no existe.']);
        }

        $order->city_id = $city->id;
        $center = app(Centers::class)->forCity($city->id);
        $notes = [];
        $forced = false;
        if ($agencyId) {
            $agency = $this->agenciesOf($city)->firstWhere('id', $agencyId);
            if (!$agency) {
                throw ValidationException::withMessages(['order' => "Esa agencia no reparte en {$city->name}. Elige una de la lista."]);
            }
            if (!$this->router->hasRoom($agency, $order)) {
                $max = (int) $agency->max_active_orders;
                $full = "{$agency->names} está en su máximo ({$max} " . ($max === 1 ? 'pedido activo' : 'pedidos activos') . ').';
                if (!self::canForce($who)) {
                    throw ValidationException::withMessages(['order' => "{$full} Elige otra o pídeselo a tu líder."]);
                }
                if (!$force) {
                    throw ValidationException::withMessages(['full' => "{$full} ¿Se lo pasas igual?"]);
                }
                $forced = true;
                $notes[] = "Se pasó a {$agency->names} aunque estaba en su máximo ({$max} " . ($max === 1 ? 'activo' : 'activos') . ').';
            }
            $this->router->place($order, $agency);
            $order->agency_locked = true; // elegida a mano: el reparto automático no la cambia
        } elseif ($center) {
            // Logística propia: el centro lo reparte (a un rider o a la agencia de respaldo)
            $agency = null;
            $order->agency_id = null;
            $order->warehouse_id = $center->warehouse_id;
            $order->delivery_cost = $city->delivery_cost_usd;
            $order->agency_locked = false;
        } else {
            [$agency, , $info] = $this->router->pick($order);
            if ($agency && !empty($info['todas_llenas'])) {
                $notes[] = "Todas las agencias de {$city->name} con el producto estaban en su máximo: se repartió en partes iguales.";
            }
            if (!$agency) {
                // Sin stock en ninguna o todas fuera de horario: la de más % (con stock primero); queda en Sin Stock si nadie tiene
                $agency = $this->agenciesOf($city)
                    ->sortByDesc(fn (User $a) => ($this->router->hasStockAt($order, $a->id) ? 1000 : 0) + (float) ($a->pivot->weight ?? 0))->first();
                if (!$agency) {
                    throw ValidationException::withMessages(['order' => "{$city->name} no tiene agencias activas: configúralas en Reparto de agencias."]);
                }
                if (!$this->router->hasStockAt($order, $agency->id)) {
                    $notes[] = "Ninguna agencia de {$city->name} tiene stock de este pedido.";
                }
            }
            $this->router->place($order, $agency);
            $order->agency_locked = false; // la eligió el sistema: el reparto la puede mover a otra con stock
        }

        $changedAgency = $order->isDirty('agency_id') || $order->isDirty('warehouse_id');
        if (!$changedAgency && !$order->isDirty('city_id')) {
            throw ValidationException::withMessages(['order' => 'Ya está en ' . $city->name . ($agency ? " con {$agency->names}" : '') . ': no cambió nada.']);
        }
        $toAgency = in_array($status, self::AT_AGENCY, true);
        if ($changedAgency && $status !== OrderStatus::ENTREGADO) {
            $order->deliverer_id = null; // el motorizado era de la agencia vieja
            if ($toAgency && ($assign = Status::where('description', OrderStatus::ASIGNAR_A_AGENCIA)->value('id'))) {
                $order->status_id = $assign;
                $order->received_at ??= now();
            }
        }
        $order->save();

        if ($center && !$agency && $toAgency) {
            app(LogisticsOrders::class)->enter($order, $center, $actor->id); // ya estaba en "Asignar a agencia": entra al centro
        }
        $where = $agency ? "{$city->name} con {$agency->names}" . ($agencyId ? '' : ' (la eligió el sistema)') : "{$city->name} (lo reparte el centro logístico)";
        $message = "Listo: el pedido pasó a {$where}.";
        if ($status === OrderStatus::SIN_STOCK && ($released = $this->releaseSinStock($order->refresh(), $agency?->names ?? 'el centro logístico'))) {
            $message .= " {$released}";
        }
        if ($changedAgency && $toAgency && $agency) {
            $message .= ' La agencia lo recibe en "Asignar a agencia".';
            try {
                $agency->notify(new OrderAssignedNotification($order, "Nueva orden asignada a tu agencia: {$order->number_label}"));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $label = ['owner' => 'el dueño', 'boss' => 'supervisión', 'leader' => 'la líder', 'seller' => 'la vendedora'][$who];
        OrderActivityLog::create([
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'action' => 'relocated',
            'description' => trim("Ciudad o agencia cambiada por {$label}: {$where}. " . implode(' ', $notes)),
            'properties' => ['city_id' => $city->id, 'agency_id' => $agency?->id, 'chosen' => (bool) $agencyId, 'forced' => $forced],
        ]);
        if ($notes !== []) {
            $message .= ' ' . implode(' ', $notes);
        }
        event(new \App\Events\OrderUpdated($order));

        return [$order, $message];
    }

    /**
     * Sin Stock: si el almacén nuevo tiene stock, el pedido vuelve a su estado de antes (o a "Asignado a vendedor") con la
     * misma vendedora. El mismo chequeo que corre el Kanban (hasStock), para que no rebote. Devuelve qué pasó, o null si
     * tampoco hay stock ahí. (Antes estaba solo en OrderController::assignAgency.)
     */
    public function releaseSinStock(Order $order, string $destination): ?string
    {
        $sinStock = Status::where('description', OrderStatus::SIN_STOCK)->first();
        if (!$sinStock || (int) $order->status_id !== (int) $sinStock->id || !$order->hasStock()) {
            return null;
        }
        $terminal = ['Entregado', 'Cancelado', 'Rechazado', 'En ruta', 'Asignar a agencia', 'Novedades', 'Novedad Solucionada'];
        $restored = $order->previous_status_id ? Status::find($order->previous_status_id) : null;
        $target = $restored && !in_array($restored->description, $terminal, true)
            ? $restored : Status::where('description', OrderStatus::ASIGNADO_VENDEDOR)->first();
        if (!$target) {
            return null;
        }

        $order->status_id = $target->id;
        $order->previous_status_id = null;
        $order->save(); // la vendedora no cambia

        OrderActivityLog::create([
            'order_id' => $order->id,
            'user_id' => auth()->id() ?? 1,
            'action' => 'status_changed',
            'description' => "Agencia reasignada a '{$destination}'. Stock disponible en el nuevo almacén: orden movida a '{$target->description}'.",
            'properties' => [
                'old_status' => $sinStock->id,
                'new_status' => $target->id,
                'agency_id' => $order->agency_id,
                'warehouse_id' => $order->warehouse_id,
                'restored' => $restored && $target->id === $restored->id,
            ],
        ]);
        OrderUpdate::create([
            'order_id' => $order->id,
            'user_id' => auth()->id() ?? 1,
            'message' => "✅ Agencia reasignada. Había stock en el nuevo almacén: la orden pasó a '{$target->description}'.",
        ]);

        return "Orden reasignada a {$destination}. Pasó a '{$target->description}'.";
    }
}
