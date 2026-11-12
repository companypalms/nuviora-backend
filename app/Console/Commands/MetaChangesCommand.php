<?php

namespace App\Console\Commands;

use App\Services\Meta\Changes\MetaChanges;
use Illuminate\Console\Command;

/**
 * `.131`: arma el historial de cambios de Meta con su hipótesis y mide si se cumplió. A las 00:25, antes del análisis de las
 * 00:30; también al abrir Meta Ads › Historial de cambios. Solo lee lo que ya trajo la sincronización: no toca Meta.
 */
class MetaChangesCommand extends Command
{
    protected $signature = 'meta:changes';

    protected $description = 'Arma el historial de cambios de Meta Ads con su hipótesis y si se cumplió';

    public function handle(MetaChanges $changes): int
    {
        $r = $changes->refresh();
        // .140: y los cambios de precio de cada producto
        $p = $changes->refreshPrices();
        $this->info("Precios: {$p['found']} cambios, {$p['saved']} guardados.");
        $this->info("Cambios vistos: {$r['found']} · guardados o medidos de nuevo: {$r['saved']}");

        return self::SUCCESS;
    }
}
