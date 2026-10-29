<?php

namespace App\Services\Calendar;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * La configuración del calendario del dueño (Etapa 3), en la tabla settings como un solo JSON (como las metas). Por ahora,
 * desde qué día existe: las atrasadas se cuentan desde ese día, para no arrancar con meses de atrasos. Se guarda la primera
 * vez que se abre el calendario.
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
}
