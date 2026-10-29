<?php

namespace App\Services\Metrics;

use App\Models\Setting;
use App\Models\User;
use App\Services\Metrics\Rules\GoalRules;
use Illuminate\Validation\ValidationException;

/**
 * Las metas y los límites de las alertas del dueño (GoalRules), guardados en la tabla settings como un solo JSON. No
 * guardan historia: si el dueño cambia una meta, los períodos viejos se miran con la nueva.
 */
class GoalSettings
{
    public function get(): array
    {
        return GoalRules::normalize(Setting::get(GoalRules::SETTING));
    }

    /** Guarda lo que mandó la pantalla, si tiene sentido; si no, avisa qué casilla está mal. */
    public function save(array $input, User $by): array
    {
        $config = GoalRules::normalize($input);
        if ($problems = GoalRules::problems($config)) {
            throw ValidationException::withMessages($problems);
        }
        $config['updated_by'] = trim($by->names . ' ' . $by->surnames);
        $config['updated_at'] = now()->format('Y-m-d H:i');
        Setting::set(GoalRules::SETTING, $config);

        return $config;
    }
}
