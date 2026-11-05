<?php

namespace App\Console\Commands;

use App\Services\Meta\Videos\WinnerVideos;
use Illuminate\Console\Command;

/**
 * `.103`: el video del ganador de cada encargo de iterar (WinnerVideos), bajado de Meta una vez y guardado en el
 * almacén. Corre cada 10 minutos, hasta 5 videos por vuelta. Con --report solo muestra cuántos faltan.
 */
class MetaWinnerVideos extends Command
{
    protected $signature = 'meta:winner-videos {--limit=5 : cuántos videos como mucho en esta vuelta} {--report : solo mostrar cuántos faltan (solo lectura)}';

    protected $description = 'Guarda en el almacén el video del ganador de cada encargo de iterar (bajado de Meta, solo lectura)';

    public function handle(WinnerVideos $videos): int
    {
        if ($this->option('report')) {
            $this->info('Videos de ganadores por guardar: ' . count($videos->pending()));

            return self::SUCCESS;
        }
        $r = $videos->run(max(0, (int) $this->option('limit')));
        if ($r['skipped']) {
            $this->warn($r['skipped']);
        }
        $this->info("Videos de ganadores por guardar: {$r['pending']}. En esta vuelta: {$r['saved']} guardados, {$r['failed']} que Meta no dio (se reintentan en "
            . WinnerVideos::RETRY_HOURS . ' horas).');

        return self::SUCCESS;
    }
}
