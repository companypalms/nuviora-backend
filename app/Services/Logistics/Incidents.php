<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsIncident;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;
use App\Models\LogisticsRoute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Las incidencias graves del rider (§14). "Una ruta cerrada NO se modifica por conveniencia. Sí puede reabrirse/
 * interrumpirse cuando existe una incidencia real que impide completarla":
 * - el rider la reporta con motivo, comentario, GPS y hora (§14.1); al operador le llega una alerta urgente (§14.2, §25);
 * - el operador decide: sigue con la ruta, o "Interrumpir ruta" y buscar reemplazo con rondas nuevas (o asignar a mano),
 *   definiendo de dónde se recoge el paquete: donde está el rider (la custodia sigue con él) o el centro, si ya volvió
 *   (§14.2 puntos 3 a 5). El SLA sigue corriendo (§5);
 * - el reemplazo recibe el paquete y se registra el traspaso rider → rider con hora y ubicación (§14.3); "no se debe
 *   teletransportar el stock". Las entregas ya hechas no se tocan (§14.2 punto 7): en una ruta de varias paradas solo las
 *   que no se entregaron entran a recuperación (§28), y el reemplazo se las lleva todas (Dispatcher);
 * - el tramo cortado no se paga (§14): el rider no entregó, así que no tiene ganancia de esa orden; la tiene el reemplazo.
 */
class Incidents
{
    public function __construct(private LogisticsOrders $orders, private Alerts $alerts, private Departures $departures)
    {
    }

    /** §14.1: "Reportar incidencia de ruta". */
    public function report(LogisticsOrder $row, User $rider, string $type, ?string $comment, ?float $lat = null, ?float $lng = null): array
    {
        return DB::transaction(function () use ($row, $rider, $type, $comment, $lat, $lng) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ((int) $row->rider_id !== (int) $rider->id || !in_array($row->status, [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT], true)) {
                return ['ok' => false, 'message' => 'Solo se reporta una incidencia de una orden tuya que está en camino.', 'code' => 409];
            }
            $routeStops = $row->route_id ? LogisticsOrder::where('route_id', $row->route_id)->pluck('id')->all() : [$row->id];
            if (LogisticsIncident::whereIn('logistics_order_id', $routeStops)->whereIn('status', LogisticsIncident::ACTIVE)->exists()) {
                return ['ok' => false, 'message' => 'Esta ruta ya tiene una incidencia abierta: el operador la está viendo.', 'code' => 409];
            }
            $incident = LogisticsIncident::create([
                'logistics_order_id' => $row->id,
                'order_id' => $row->order_id,
                'center_id' => $row->center_id,
                'rider_id' => $rider->id,
                'type' => $type,
                'comment' => $comment,
                'lat' => $lat,
                'lng' => $lng,
                'reported_at' => now(),
                'status' => LogisticsIncident::OPEN,
            ]);
            $this->orders->event($row, 'incidencia', $row->status, $row->status, $rider->id, $comment,
                ['tipo' => $type, 'incidencia_id' => $incident->id, 'lat' => $lat, 'lng' => $lng]);
            $this->alerts->raise($row->center, 'incidencia', LogisticsAlert::CRITICAL,
                "{$this->label($row)}: {$rider->names} reporta una incidencia de ruta, \"{$incident->typeLabel()}\"" . ($comment ? ": {$comment}" : '')
                . '. Decide si sigue o si se interrumpe la ruta.', "incidencia:{$incident->id}", $row->order_id, $rider->id);

            return ['ok' => true, 'message' => 'Incidencia enviada: el operador te va a decir qué hacer.', 'code' => 200];
        });
    }

    /** El operador decide que el rider puede seguir con la ruta (por ejemplo, el bloqueo se despejó). */
    public function keepGoing(LogisticsIncident $incident, User $by, ?string $note = null): array
    {
        if ($incident->status !== LogisticsIncident::OPEN) {
            return ['ok' => false, 'message' => 'Esta incidencia ya se decidió.', 'code' => 409];
        }
        $incident->update(['status' => LogisticsIncident::CONTINUES, 'resolved_at' => now(), 'resolved_by' => $by->id, 'result' => $note ?: 'Sigue con la ruta.']);
        $row = $incident->logisticsOrder;
        $this->orders->event($row, 'incidencia_sigue', $row->status, $row->status, $by->id, $note, ['incidencia_id' => $incident->id]);
        $this->alerts->resolve("incidencia:{$incident->id}", $by->id);

        return ['ok' => true, 'message' => 'Listo: el rider sigue con la ruta.', 'code' => 200];
    }

    /**
     * §14.2 puntos 3 a 5: "Interrumpir ruta" y buscar reemplazo. pickup = rider: el paquete se recoge donde está el rider
     * (sigue bajo su custodia hasta el traspaso). pickup = almacen: ya volvió al centro; la custodia pasa al almacén ahora
     * y sale como cualquier orden (también por agencia, §19).
     */
    public function interrupt(LogisticsIncident $incident, User $by, string $pickup, ?string $note = null): array
    {
        if (!in_array($pickup, ['rider', 'almacen'], true)) {
            return ['ok' => false, 'message' => 'Elige de dónde se recoge el paquete.', 'code' => 422];
        }
        $out = DB::transaction(function () use ($incident, $by, $pickup, $note) {
            $incident = LogisticsIncident::whereKey($incident->id)->lockForUpdate()->first();
            $row = LogisticsOrder::whereKey($incident->logistics_order_id)->lockForUpdate()->first();
            if ($incident->status !== LogisticsIncident::OPEN) {
                return ['ok' => false, 'message' => 'Esta incidencia ya se decidió.', 'code' => 409];
            }
            // §14.2 punto 7 y §28: en una ruta de varias paradas, solo las que no se entregaron entran a recuperación
            $inTransit = [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT];
            $pending = $row->route_id
                ? LogisticsOrder::where('route_id', $row->route_id)->where('rider_id', $incident->rider_id)
                    ->whereIn('status', $inTransit)->orderBy('route_stop')->lockForUpdate()->get()
                : collect((int) $row->rider_id === (int) $incident->rider_id && in_array($row->status, $inTransit, true) ? [$row] : []);
            if ($pending->isEmpty()) {
                return ['ok' => false, 'message' => 'La orden ya no está en camino con ese rider.', 'code' => 409];
            }
            $now = now();
            foreach ($pending as $stop) {
                $this->recover($stop, $incident, $by, $pickup, $note, $now);
            }
            if ($row->route_id) {
                LogisticsRoute::whereKey($row->route_id)->update(['status' => LogisticsRoute::INTERRUPTED, 'interrupted_at' => $now]);
                // Las paradas en recuperación dejan la ruta (conservan su número de parada para salir en el mismo orden)
                LogisticsOrder::whereIn('id', $pending->pluck('id'))->update(['route_id' => null]);
                if (!LogisticsOrder::where('route_id', $row->route_id)->whereNotIn('status', Routes::WITH_RESULT)->exists()) {
                    LogisticsRoute::whereKey($row->route_id)->update(['finished_at' => $now]);
                }
            }
            $incident->update(['status' => $pickup === 'almacen' ? LogisticsIncident::CLOSED : LogisticsIncident::INTERRUPTED, 'pickup' => $pickup,
                'interrupted_at' => $now, 'interrupted_by' => $by->id] + ($pickup === 'almacen'
                    ? ['resolved_at' => $now, 'resolved_by' => $by->id, 'result' => 'Ruta interrumpida; el paquete volvió al centro.'] : []));
            $count = $pending->count();
            $what = $count > 1 ? "las {$count} paradas sin entregar" : 'el paquete';

            return ['ok' => true, 'code' => 200, 'message' => $pickup === 'rider'
                ? "Ruta interrumpida: se busca reemplazo, que recoge {$what} donde está el rider."
                : "Ruta interrumpida: {$what} en el centro y se busca otro rider."];
        });
        if ($out['ok']) {
            $this->alerts->resolve("incidencia:{$incident->id}", $by->id);
        }

        return $out;
    }

    /** Una orden de la ruta interrumpida vuelve a buscar rider, con el paquete donde está el rider o en el centro (§14.2). */
    private function recover(LogisticsOrder $stop, LogisticsIncident $incident, User $by, string $pickup, ?string $note, $now): void
    {
        $order = Order::whereKey($stop->order_id)->lockForUpdate()->first();
        // La novedad que hubiera queda cerrada: la ruta se interrumpe
        LogisticsNovelty::where('logistics_order_id', $stop->id)->whereNull('resolved_at')
            ->update(['resolved_at' => $now, 'resolved_by' => $by->id, 'result' => 'Ruta interrumpida por una incidencia (§14).']);
        if ($pickup === 'almacen') {
            $this->departures->custody($stop, $order, 'rider', $stop->rider_id, 'almacen', $stop->center?->warehouse_id, $by->id);
        }
        $this->orders->transition($stop, LogisticsStatus::SEARCHING, 'ruta_interrumpida', $by->id, $note,
            ['incidencia_id' => $incident->id, 'recoge' => $pickup, 'rider_anterior' => $stop->rider_id, 'ruta' => $stop->route_id], [
                'rider_id' => null,
                'pickup_rider_id' => $pickup === 'rider' ? $stop->rider_id : null,
                'searching_started_at' => $now,
                'rider_confirmed_at' => null,
                // Con el paquete de vuelta en el centro, ya está listo y vuelve a salir de ahí
                'ready_at' => $pickup === 'almacen' ? $now : null,
                'departed_at' => $pickup === 'almacen' ? null : $stop->departed_at,
                'assigned_via' => null,
                'assigned_by' => null,
            ]);
        // La orden ya no es de ese rider (sin pasar por los observadores: el estado de la orden no cambia)
        Order::whereKey($order->id)->update(['deliverer_id' => null]);
    }

    /**
     * §14.3: el reemplazo recibió el paquete del rider que tuvo la incidencia. Lo confirma el reemplazo o el operador. En
     * una ruta de recuperación (Dispatcher) recibe todos juntos: la ruta sale ahí y queda bloqueada (§11).
     */
    public function transfer(LogisticsOrder $row, User $by, ?float $lat = null, ?float $lng = null): array
    {
        return DB::transaction(function () use ($row, $by, $lat, $lng) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if (!$row->pickup_rider_id || !$row->rider_id
                || !in_array($row->status, [LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING, LogisticsStatus::READY], true)) {
                return ['ok' => false, 'message' => 'No hay un traspaso pendiente en esta orden.', 'code' => 409];
            }
            if ($by->role?->description === 'Repartidor' && (int) $by->id !== (int) $row->rider_id) {
                return ['ok' => false, 'message' => 'El traspaso lo confirma el rider que recibe el paquete.', 'code' => 403];
            }
            $from = (int) $row->pickup_rider_id;
            $stops = $row->route_id
                ? LogisticsOrder::where('route_id', $row->route_id)->where('pickup_rider_id', $from)->where('rider_id', $row->rider_id)
                    ->orderBy('route_stop')->lockForUpdate()->get()
                : collect([$row]);
            $now = now();
            foreach ($stops as $stop) {
                $order = Order::whereKey($stop->order_id)->lockForUpdate()->first();
                $this->departures->custody($stop, $order, 'rider', $from, 'rider', $stop->rider_id, $by->id, $lat, $lng);
                $this->orders->transition($stop, LogisticsStatus::EN_ROUTE, 'traspaso', $by->id, null, ['de' => $from, 'a' => $stop->rider_id, 'lat' => $lat, 'lng' => $lng],
                    ['pickup_rider_id' => null, 'departed_at' => $now]);
            }
            if ($row->route_id) {
                LogisticsRoute::whereKey($row->route_id)->update(['status' => LogisticsRoute::EN_ROUTE, 'locked_at' => $now, 'departed_at' => $now]);
            }
            LogisticsIncident::whereIn('logistics_order_id', $stops->pluck('id'))->where('status', LogisticsIncident::INTERRUPTED)
                ->update(['status' => LogisticsIncident::CLOSED, 'replacement_rider_id' => $row->rider_id, 'transferred_at' => $now,
                    'resolved_at' => $now, 'resolved_by' => $by->id, 'result' => 'Traspaso al reemplazo.']);

            return ['ok' => true, 'message' => $stops->count() > 1 ? "Traspaso confirmado: los {$stops->count()} paquetes siguen con el reemplazo."
                : 'Traspaso confirmado: el paquete sigue con el reemplazo.', 'code' => 200];
        });
    }

    private function label(LogisticsOrder $row): string
    {
        return 'Orden ' . (Order::whereKey($row->order_id)->value('name') ?? "#{$row->order_id}");
    }
}
