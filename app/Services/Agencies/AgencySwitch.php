<?php

namespace App\Services\Agencies;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Encender y apagar ciudades y agencias, y el horario de cada agencia (Fran, 2026-10-17). Lo usan Tiendas, ciudades y
 * agencias y el asistente (paso 7). Al apagar, las órdenes nuevas de esa ciudad esperan sin vendedora; al encender, las
 * que esperaban se reparten en el próximo minuto (WorkingHours::release, cada minuto).
 */
class AgencySwitch
{
    public function setCity(City $city, bool $working, ?User $by): void
    {
        $city->forceFill(['is_working' => $working])->save();
        Log::info('Ciudad ' . $city->name . ': ' . ($working ? 'encendida' : 'apagada') . ' (por ' . $by?->names . ').');
    }

    /**
     * @param array{is_working: bool, works_from: ?string, works_until: ?string, schedule?: ?array} $hours ya revisado con
     *        hours() o schedule(). Sin la clave "schedule", el horario por día que tenga queda como está.
     */
    public function setAgency(User $agency, array $hours, ?User $by): void
    {
        $fill = ['agency_is_working' => $hours['is_working'], 'agency_works_from' => $hours['works_from'], 'agency_works_until' => $hours['works_until']];
        if (array_key_exists('schedule', $hours)) {
            $fill['agency_schedule'] = $hours['schedule'];
        }
        $agency->forceFill($fill)->save();
        Log::info('Agencia ' . $agency->names . ': ' . ($hours['is_working'] ? 'encendida' : 'apagada') . ', horario ' . (WorkingHours::agencyHoursLabel($agency) ?? 'todo el día') . ' (por ' . $by?->names . ').');
    }

    /** El horario como se lee: por día si lo tiene, si no "desde/hasta"; null = todo el día. */
    public static function label(array $hours): ?string
    {
        if (!empty($hours['schedule'])) {
            return WorkingHours::scheduleLabel($hours['schedule']);
        }

        return $hours['works_from'] || $hours['works_until'] ? WorkingHours::hoursLabel($hours['works_from'], $hours['works_until']) : null;
    }

    /**
     * El horario por día limpio (dueño, 2026-11-01): 1 = lunes … 7 = domingo; cada día null (no trabaja) o [desde,
     * hasta] en HH:MM (vacío = sin límite). Con horario por día, el "desde/hasta" de todos los días queda vacío.
     *
     * @return array{is_working: bool, works_from: null, works_until: null, schedule: array<int, ?array>}
     */
    public static function schedule(bool $working, array $days): array
    {
        $clean = [];
        foreach (array_keys(WorkingHours::DAYS) as $day) {
            $hours = $days[$day] ?? null;
            if (!is_array($hours)) {
                $clean[$day] = null;
                continue;
            }
            $from = ($hours[0] ?? null) ?: null;
            $until = ($hours[1] ?? null) ?: null;
            if ($from && $until && $from === $until) {
                throw ValidationException::withMessages(["schedule.{$day}" => 'El ' . WorkingHours::DAYS[$day] . ' la hora de cierre no puede ser igual a la de inicio.']);
            }
            $clean[$day] = [$from, $until];
        }
        if (array_filter($clean) === []) {
            throw ValidationException::withMessages(['schedule' => 'Marca al menos un día de trabajo (o apágala).']);
        }

        return ['is_working' => $working, 'works_from' => null, 'works_until' => null, 'schedule' => $clean];
    }

    /**
     * El horario limpio: vacío = sin límite. La hora de cierre no puede ser la de inicio.
     *
     * @return array{is_working: bool, works_from: ?string, works_until: ?string}
     */
    public static function hours(bool $working, ?string $from, ?string $until): array
    {
        $from = $from ?: null;
        $until = $until ?: null;
        if ($from && $until && $from === $until) {
            throw ValidationException::withMessages(['works_until' => 'La hora de cierre no puede ser igual a la de inicio.']);
        }

        return ['is_working' => $working, 'works_from' => $from, 'works_until' => $until];
    }
}
