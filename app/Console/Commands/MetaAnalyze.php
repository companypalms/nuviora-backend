<?php

namespace App\Console\Commands;

use App\Services\Meta\Analysis\MetaAnalyzer;
use Illuminate\Console\Command;

/**
 * El análisis de campañas de cada mañana (Etapa 5, paso 3a): a las 07:00, después del CPA (06:30) y de los estados de los
 * creativos (06:45), con los 3 días cerrados. Lo ve el dueño en Meta Ads › Análisis del día. No cambia nada en Meta.
 */
class MetaAnalyze extends Command
{
    protected $signature = 'meta:analyze';

    protected $description = 'Arma el análisis de campañas del día con las reglas del dueño y lo redacta con IA';

    public function handle(MetaAnalyzer $analyzer): int
    {
        $a = $analyzer->run();
        $this->info("Análisis del {$a->date->toDateString()} (versión {$a->version}): {$a->status} · " . count($a->urgent ?? []) . ' puntos en la lista urgente'
            . ($a->warnings ? ' · montos que no estaban en los datos: ' . implode(', ', $a->warnings) : '') . ($a->error ? " · {$a->error}" : ''));

        return self::SUCCESS;
    }
}
