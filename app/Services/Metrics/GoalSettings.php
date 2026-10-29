<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use App\Models\User;
use App\Services\Metrics\Rules\GoalRules;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Las metas y los límites de las alertas del dueño (GoalRules), guardados en la tabla settings como un solo JSON. Las
 * metas del negocio son de cada mes y quedan guardadas (los meses pasados se miran con las suyas); las de personas y los
 * límites de las alertas son unos solos: si el dueño los cambia, los períodos viejos se miran con los nuevos.
 */
class GoalSettings
{
    public function get(): array
    {
        return GoalRules::normalize(Setting::get(GoalRules::SETTING));
    }

    /**
     * Guarda lo que mandó la pantalla, si tiene sentido; si no, avisa qué casilla está mal. Del negocio solo se cambian el
     * mes que corre y el siguiente: los meses pasados no se tocan.
     */
    public function save(array $input, User $by, CarbonImmutable $now): array
    {
        $months = $this->get()['months'];
        $editable = GoalRules::editableMonths($now);
        foreach (is_array($input['months'] ?? null) ? $input['months'] : [] as $month => $business) {
            if (in_array((string) $month, $editable, true)) {
                $months[(string) $month] = $business;
            }
        }
        $config = GoalRules::normalize(['months' => $months, 'team' => $input['team'] ?? [], 'alerts' => $input['alerts'] ?? []]);
        if ($problems = GoalRules::problems($config)) {
            throw ValidationException::withMessages($problems);
        }
        $config['updated_by'] = trim($by->names . ' ' . $by->surnames);
        $config['updated_at'] = $now->format('Y-m-d H:i');
        Setting::set(GoalRules::SETTING, $config);

        return $config;
    }
}
