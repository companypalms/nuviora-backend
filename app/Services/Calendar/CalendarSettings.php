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
 */
class CalendarSettings
{
    public const SETTING = 'calendar';

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
