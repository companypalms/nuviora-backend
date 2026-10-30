<?php

namespace App\Console\Commands;

use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Queue\BatchTracker;
use Illuminate\Console\Command;

/**
 * Los estados automáticos de los creativos con las reglas del dueño (Etapa 5, paso 2a) y los encargos de la cola que ya
 * salieron en Meta o tienen veredicto (paso 4a): cada día a las 06:45, después del CPA de las 06:30 y antes del análisis
 * de las 07:00, solo con días cerrados. El que puso el dueño a mano no se toca.
 */
class MetaCreativeStatuses extends Command
{
    protected $signature = 'meta:creative-statuses';

    protected $description = 'Pone el estado automático de los creativos (Winner, Fatigued, Loser, Archived, Testing) con las reglas del dueño';

    public function handle(MetaDecisions $decisions): int
    {
        $r = $decisions->applyStatuses();
        $this->info("Estados cambiados: {$r['updated']} | sin cambios: {$r['unchanged']} | puestos a mano (no se tocan): {$r['kept_manual']}");
        // Paso 4a: los encargos de la cola que ya salieron en Meta (En test) o ya tienen veredicto (Testeado)
        $q = (new BatchTracker($decisions))->run();
        $this->info("Cola: {$q['testing']} pasaron a En test y {$q['tested']} a Testeado");

        return self::SUCCESS;
    }
}
