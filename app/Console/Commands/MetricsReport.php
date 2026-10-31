<?php

namespace App\Console\Commands;

use App\Services\Metrics\Reports\BusinessReporter;
use Illuminate\Console\Command;

/**
 * Los reportes del negocio (`.48b`): cada mañana a las 07:15, los que tocan según las revisiones del calendario (el diario
 * de lunes a sábado, el semanal el día de la revisión semanal y el mensual el día 8). Con --type, solo ese tipo, aunque
 * no le toque hoy. No manda nada a nadie: quedan en Reportes del negocio.
 */
class MetricsReport extends Command
{
    protected $signature = 'metrics:report {--type=* : daily, weekly o monthly (sin esto, los que tocan hoy)}';

    protected $description = 'Genera los reportes del negocio que tocan hoy (números del Panel del dueño, revisados, y el texto de la IA)';

    public function handle(BusinessReporter $reporter): int
    {
        $types = $this->option('type') ?: BusinessReporter::dueToday();
        foreach ($types as $type) {
            if (!in_array($type, ['daily', 'weekly', 'monthly'], true)) {
                $this->error("Tipo desconocido: {$type}");
                return self::FAILURE;
            }
            try {
                $r = $reporter->run($type);
                $this->info("Reporte {$type} del {$r->period_from->toDateString()} al {$r->period_to->toDateString()}: versión {$r->version} ({$r->status})");
            } catch (\Throwable $e) {
                report($e);
                $this->error("No se pudo generar el reporte {$type}: {$e->getMessage()}");
            }
        }
        if ($types === []) {
            $this->line('Hoy no toca ningún reporte.');
        }

        return self::SUCCESS;
    }
}
