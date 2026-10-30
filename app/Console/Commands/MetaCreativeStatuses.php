<?php

namespace App\Console\Commands;

use App\Services\Meta\Decisions\MetaDecisions;
use Illuminate\Console\Command;

/**
 * Los estados automáticos de los creativos con las reglas del dueño (Etapa 5, paso 2a): cada día a las 06:45, después
 * del CPA de las 06:30 y antes del análisis de las 07:00, solo con días cerrados. El que puso el dueño a mano no se toca.
 */
class MetaCreativeStatuses extends Command
{
    protected $signature = 'meta:creative-statuses';

    protected $description = 'Pone el estado automático de los creativos (Winner, Fatigued, Loser, Archived, Testing) con las reglas del dueño';

    public function handle(MetaDecisions $decisions): int
    {
        $r = $decisions->applyStatuses();
        $this->info("Estados cambiados: {$r['updated']} | sin cambios: {$r['unchanged']} | puestos a mano (no se tocan): {$r['kept_manual']}");

        return self::SUCCESS;
    }
}
