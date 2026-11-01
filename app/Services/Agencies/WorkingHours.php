<?php

namespace App\Services\Agencies;

use App\Constants\OrderStatus;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assignment\AssignOrderService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ciudades y agencias que se apagan, y el horario de cada agencia (Fran, 2026-10-17: "Valencia no puede trabajar y
 * entonces necesito que sus órdenes no pasen a asignado a vendedora"; "Maracay todos los días cierra a las 5 de la
 * tarde"; 10:54: "los horarios deberían ser por agencia, porque cada agencia puede tener un horario distinto").
 *  - Una agencia trabaja si está encendida y, si tiene horario, está dentro de él. Una que no trabaja no recibe órdenes
 *    en el reparto de su ciudad (AgencyRouter::cityAgencies).
 *  - Una ciudad no tiene horario: trabaja si está encendida y, si tiene agencias activas en el reparto, al menos una de
 *    ellas trabaja.
 *  - La orden de una ciudad que no trabaja no pasa a vendedora: se queda donde está, sin vendedora, y queda anotada
 *    (Setting SETTING_WAITING). Cuando la ciudad vuelve a trabajar (al encenderla o al llegar su hora) se reparte como
 *    siempre (release, cada minuto). Una en "Sin Stock" que recupera stock tampoco sale mientras tanto.
 *  - Las que ya tenían vendedora no se tocan.
 *  - El horario puede ser por día de la semana (dueño, 2026-11-01: "Caracas de 9 a 7 de lunes a viernes, los sábados
 *    hasta las 5"; ninguna trabaja los domingos): users.agency_schedule. Sin él, el "desde/hasta" vale todos los días.
 */
class WorkingHours
{
    public const SETTING_WAITING = 'agencies.orders_waiting_city';

    public const DAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    private const DAYS_PLURAL = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábados', 7 => 'domingos'];

    private const DAYS_SHORT = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    public function __construct(private AgencyRouter $router) {}

    public function agencyWorks(User $agency, ?Carbon $at = null): bool
    {
        return $this->agencyStop($agency, $at) === null;
    }

    public function cityWorks(City $city, ?Carbon $at = null): bool
    {
        return $this->cityStop($city, $at) === null;
    }

    /**
     * Por qué la agencia no trabaja ahora ("está apagada", "trabaja de 08:00 a 17:00", "no trabaja los domingos", "los
     * sábados trabaja de 09:00 a 17:00"), o null si trabaja.
     */
    public function agencyStop(User $agency, ?Carbon $at = null): ?string
    {
        if ($agency->isDeactivated()) {
            return 'está desactivada'; // paso 6: la cuenta ya no trabaja
        }
        if ($agency->agency_is_working === false) {
            return 'está apagada';
        }
        $at ??= now();
        if (is_array($agency->agency_schedule)) {
            $day = $at->dayOfWeekIso;
            $hours = self::dayHours($agency->agency_schedule, $day);
            if ($hours === null) {
                return 'no trabaja los ' . self::DAYS_PLURAL[$day];
            }

            return self::inHours($at, $hours[0], $hours[1]) ? null : 'los ' . self::DAYS_PLURAL[$day] . ' trabaja ' . self::hoursLabel($hours[0], $hours[1]);
        }

        return self::inHours($at, $agency->agency_works_from, $agency->agency_works_until)
            ? null : 'trabaja ' . self::hoursLabel($agency->agency_works_from, $agency->agency_works_until);
    }

    /**
     * El horario de la semana de la agencia: [1 => [desde, hasta] … 7 => null], 1 = lunes, null = ese día no trabaja,
     * desde/hasta "HH:MM" o null (sin límite). Sin horario por día, el "desde/hasta" vale los 7 días. null = trabaja
     * todos los días a toda hora. Lo lee n8n (check-window) para mandar los mensajes en el horario de la agencia.
     *
     * @return array<int, array{0: ?string, 1: ?string}|null>|null
     */
    public static function weekSchedule(User $agency): ?array
    {
        if (is_array($agency->agency_schedule)) {
            $week = [];
            foreach (array_keys(self::DAYS) as $day) {
                $week[$day] = self::dayHours($agency->agency_schedule, $day);
            }

            return $week;
        }
        $from = $agency->agency_works_from ? substr(self::time($agency->agency_works_from), 0, 5) : null;
        $until = $agency->agency_works_until ? substr(self::time($agency->agency_works_until), 0, 5) : null;

        return $from || $until ? array_fill_keys(array_keys(self::DAYS), [$from, $until]) : null;
    }

    /** El horario de la agencia en palabras ("lun a vie de 09:00 a 19:00 · sáb de 09:00 a 17:00 · dom no trabaja"); null = todo el día. */
    public static function agencyHoursLabel(User $agency): ?string
    {
        if (is_array($agency->agency_schedule)) {
            return self::scheduleLabel($agency->agency_schedule);
        }

        return $agency->agency_works_from || $agency->agency_works_until ? self::hoursLabel($agency->agency_works_from, $agency->agency_works_until) : null;
    }

    /** Un horario por día en palabras, juntando los días seguidos que tienen el mismo horario. */
    public static function scheduleLabel(array $schedule): string
    {
        $parts = [];
        for ($day = 1; $day <= 7; $day = $end + 1) {
            $hours = self::dayHours($schedule, $day);
            for ($end = $day; $end < 7 && self::dayHours($schedule, $end + 1) === $hours; $end++);
            $days = self::DAYS_SHORT[$day] . ($end === $day ? '' : ($end === $day + 1 ? ' y ' : ' a ') . self::DAYS_SHORT[$end]);
            $parts[] = $days . ' ' . ($hours === null ? 'no trabaja' : self::hoursLabel($hours[0], $hours[1]));
        }

        return implode(' · ', $parts);
    }

    /** Las horas de un día del horario semanal: [desde, hasta] ("HH:MM" o null) o null si ese día no trabaja. */
    public static function dayHours(array $schedule, int $day): ?array
    {
        $hours = $schedule[$day] ?? null;
        if (!is_array($hours)) {
            return null;
        }
        $clean = fn ($v) => $v ? substr(self::time((string) $v), 0, 5) : null;

        return [$clean($hours[0] ?? null), $clean($hours[1] ?? null)];
    }

    /**
     * Por qué la ciudad no trabaja ahora, en una frase ("Carabobo está apagada", "Dalgo, la agencia de Aragua, trabaja
     * hasta las 17:00"), o null si trabaja.
     */
    public function cityStop(City $city, ?Carbon $at = null): ?string
    {
        $at ??= now();
        if ($city->is_working === false) {
            return "{$city->name} está apagada";
        }
        $agencies = $city->agencies()->wherePivot('is_active', true)->get();
        if ($agencies->isNotEmpty() && !$agencies->contains(fn (User $a) => $this->agencyWorks($a, $at))) {
            return $agencies->count() === 1
                ? "{$agencies->first()->names}, la agencia de {$city->name}, " . $this->agencyStop($agencies->first(), $at)
                : "ninguna agencia de {$city->name} está trabajando";
        }

        return null;
    }

    /** Si la orden no puede pasar a vendedora porque su ciudad no trabaja, el motivo; si puede, null. */
    public function orderStop(Order $order): ?string
    {
        $city = $this->router->cityFor($order);

        return $city ? $this->cityStop($city) : null;
    }

    /**
     * Si la ciudad de la orden no trabaja, la anota para repartirla cuando vuelva (con una línea en su historial, una
     * sola vez) y devuelve true. Si trabaja, false.
     */
    public function holdIfStopped(Order $order): bool
    {
        $why = $this->orderStop($order);
        if ($why === null) {
            return false;
        }
        DB::transaction(function () use ($order, $why) {
            $ids = $this->waitingIds(true);
            if (in_array($order->id, $ids, true)) {
                return;
            }
            $ids[] = $order->id;
            Setting::set(self::SETTING_WAITING, $ids);
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => 'city_stopped',
                'description' => "No pasa a vendedora: {$why}. Se reparte cuando vuelva a trabajar.",
            ]);
        });

        return true;
    }

    /** @return array<int, int> */
    public function waitingIds(bool $lock = false): array
    {
        $query = Setting::where('key', self::SETTING_WAITING);
        $row = $lock ? $query->lockForUpdate()->first() : $query->first();
        $ids = $row ? json_decode($row->value, true) : [];

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @return array<int, int>  city_id => órdenes que esperan que la ciudad trabaje. */
    public function waitingByCity(): array
    {
        $ids = $this->waitingIds();

        return $ids ? Order::whereIn('id', $ids)->whereNotNull('city_id')
            ->groupBy('city_id')->selectRaw('city_id, COUNT(*) as c')->pluck('c', 'city_id')->map(fn ($c) => (int) $c)->all() : [];
    }

    /**
     * Reparte las órdenes anotadas cuya ciudad ya trabaja y cuya tienda está abierta, como se hubieran repartido: la de
     * "Sin Stock" que ya tiene stock sale como cuando llega mercancía; las demás, como en el reparto de la mañana. Salen
     * de la lista las que ya tienen vendedora o terminaron. Devuelve cuántas se procesaron.
     */
    public function release(): int
    {
        $ids = $this->waitingIds();
        if ($ids === []) {
            return 0;
        }
        $assigner = app(AssignOrderService::class);
        $done = [];
        $closed = [OrderStatus::ENTREGADO, OrderStatus::CANCELADO, OrderStatus::RECHAZADO];
        foreach ($ids as $id) {
            $order = Order::with('status')->find($id);
            // La de "Sin Stock" puede tener vendedora y esperar igual (recuperó stock con la ciudad apagada)
            $sinStock = $order?->status?->description === OrderStatus::SIN_STOCK;
            if (!$order || (!$sinStock && $order->agent_id) || in_array($order->status?->description, $closed, true)) {
                $done[] = $id;
                continue;
            }
            if ($this->orderStop($order) !== null || !$order->shop_id || !$assigner->isBusinessOpen($order->shop_id)) {
                continue;
            }
            try {
                if ($sinStock) {
                    app(InventoryService::class)->recoverFromSinStock($order);
                    $order->refresh();
                    if (!$order->agent_id && $order->status?->description === OrderStatus::SIN_STOCK) {
                        $assigner->assignOne($order);
                    }
                } else {
                    $assigner->assignWaiting($order);
                }
            } catch (\Throwable $e) {
                Log::error("WorkingHours: la orden #{$id} no se pudo repartir al volver a trabajar su ciudad: " . $e->getMessage());
                continue;
            }
            $done[] = $id;
        }
        if ($done !== []) {
            DB::transaction(function () use ($done) {
                Setting::set(self::SETTING_WAITING, array_values(array_diff($this->waitingIds(true), $done)));
            });
        }

        return count($done);
    }

    /** Dentro del horario. Sin horario, todo el día; solo "desde" o solo "hasta", desde o hasta esa hora. */
    public static function inHours(Carbon $at, ?string $from, ?string $until): bool
    {
        if (!$from && !$until) {
            return true;
        }
        $time = $at->format('H:i:s');
        $from = $from ? self::time($from) : '00:00:00';
        $until = $until ? self::time($until) : '24:00:00';
        if ($until <= $from) {
            // Pasa la medianoche (20:00 a 06:00)
            return $time >= $from || $time < $until;
        }

        return $time >= $from && $time < $until;
    }

    /** "de 08:00 a 17:00", "hasta las 17:00", "desde las 08:00". */
    public static function hoursLabel(?string $from, ?string $until): string
    {
        $from = $from ? substr(self::time($from), 0, 5) : null;
        $until = $until ? substr(self::time($until), 0, 5) : null;

        return match (true) {
            $from && $until => "de {$from} a {$until}",
            (bool) $until => "hasta las {$until}",
            (bool) $from => "desde las {$from}",
            default => 'todo el día',
        };
    }

    private static function time(string $value): string
    {
        return strlen($value) === 5 ? "{$value}:00" : $value;
    }
}
