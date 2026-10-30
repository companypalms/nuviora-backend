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

    /** @param array{is_working: bool, works_from: ?string, works_until: ?string} $hours ya revisado con hours() */
    public function setAgency(User $agency, array $hours, ?User $by): void
    {
        $agency->forceFill(['agency_is_working' => $hours['is_working'], 'agency_works_from' => $hours['works_from'], 'agency_works_until' => $hours['works_until']])->save();
        Log::info('Agencia ' . $agency->names . ': ' . ($hours['is_working'] ? 'encendida' : 'apagada') . ', horario ' . WorkingHours::hoursLabel($hours['works_from'], $hours['works_until']) . ' (por ' . $by?->names . ').');
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
