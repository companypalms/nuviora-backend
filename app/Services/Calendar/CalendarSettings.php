<?php

namespace App\Services\Calendar;

use App\Models\Setting;
use App\Models\User;
use App\Services\Calendar\Rules\ReminderRules;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * La configuración del calendario del dueño (Etapa 3), en la tabla settings como un solo JSON (como las metas):
 * - started_on: desde qué día existe. Las atrasadas se cuentan desde ese día, para no arrancar con meses de atrasos. Se
 *   guarda la primera vez que se abre el calendario.
 * - rules: los recordatorios del sistema encendidos o apagados, y el día de la revisión semanal (ReminderRules).
 * - timezone (.108, dueño 2026-11-06: "yo estoy en Portugal… mi horario del calendario debe ser en horario portugués…
 *   pero todo el sistema debe seguir en horario de Venezuela"): la hora en que el dueño ve y anota las horas de su
 *   calendario. Los días siguen siendo los del negocio (cambian a medianoche de Venezuela, lo eligió el dueño).
 */
class CalendarSettings
{
    public const SETTING = 'calendar';

    /** Las zonas que se eligen ("Tu hora"). */
    public const ZONES = [
        'Europe/Lisbon' => 'Portugal',
        'Europe/Madrid' => 'España',
        'America/Caracas' => 'Venezuela',
        'America/Bogota' => 'Colombia',
        'America/New_York' => 'Miami',
    ];

    /** La hora del negocio: lo de siempre, mientras el dueño no elija otra. */
    public const BUSINESS_ZONE = 'America/Caracas';

    public function get(): array
    {
        $config = Setting::get(self::SETTING);

        return is_array($config) ? $config : [];
    }

    /** El primer día del calendario ("Y-m-d"); si todavía no tiene, es hoy. */
    public function startedOn(CarbonImmutable $now): string
    {
        $config = $this->get();
        if (empty($config['started_on'])) {
            $config['started_on'] = $now->toDateString();
            Setting::set(self::SETTING, $config);
        }

        return $config['started_on'];
    }

    /** La zona de las horas del calendario ("Europe/Lisbon"); sin elegir, la del negocio. */
    public function timezone(): string
    {
        $zone = $this->get()['timezone'] ?? null;

        return is_string($zone) && isset(self::ZONES[$zone]) ? $zone : self::BUSINESS_ZONE;
    }

    /** @return array{key: string, label: string} */
    public function zone(): array
    {
        $zone = $this->timezone();

        return ['key' => $zone, 'label' => self::ZONES[$zone]];
    }

    public function saveTimezone(string $zone, User $by, CarbonImmutable $now): void
    {
        if (!isset(self::ZONES[$zone])) {
            throw ValidationException::withMessages(['timezone' => 'Elige una de las zonas de la lista.']);
        }
        $config = $this->get();
        $config['started_on'] ??= $now->toDateString();
        $config['timezone'] = $zone;
        $config['updated_by'] = trim($by->names . ' ' . $by->surnames);
        $config['updated_at'] = $now->format('Y-m-d H:i');
        Setting::set(self::SETTING, $config);
    }

    /** @return array<string, array{on: bool, weekday?: int}> */
    public function rules(): array
    {
        return ReminderRules::normalizeSettings($this->get()['rules'] ?? null);
    }

    /** Guarda qué recordatorios están encendidos y el día de la revisión semanal; si algo está mal, avisa qué. */
    public function saveRules(array $input, User $by, CarbonImmutable $now): array
    {
        if ($problems = ReminderRules::settingsProblems($input)) {
            throw ValidationException::withMessages($problems);
        }
        $config = $this->get();
        $config['started_on'] ??= $now->toDateString();
        $config['rules'] = ReminderRules::normalizeSettings(array_replace_recursive($this->rules(), $input));
        $config['updated_by'] = trim($by->names . ' ' . $by->surnames);
        $config['updated_at'] = $now->format('Y-m-d H:i');
        Setting::set(self::SETTING, $config);

        return $config['rules'];
    }
}
