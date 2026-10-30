<?php

namespace App\Console\Commands;

use App\Models\AdAngle;
use App\Models\MetaCreative;
use App\Services\Meta\MetaCreativeLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a leer los nombres y a enlazar todos los anuncios con su creativo (Etapa 5, paso 1b), desde la base y sin
 * llamar a Meta. Con --dry-run muestra lo que pasaría y no guarda nada. Los creativos viejos que se quedan sin anuncios
 * no se borran: dejan de verse.
 */
class RelinkMetaCreatives extends Command
{
    protected $signature = 'meta:relink-creatives {--dry-run : Solo mostrar lo que cambiaría}';

    protected $description = 'Enlaza cada anuncio de Meta con su creativo (producto + código) y arma el diccionario de ángulos';

    public function handle(MetaCreativeLinker $linker): int
    {
        $dry = (bool) $this->option('dry-run');
        DB::beginTransaction();
        try {
            $stats = $linker->run();
            $this->line(($dry ? 'PRUEBA (no se guarda nada). ' : '') . "Anuncios cambiados: {$stats['ads']} | ad sets cambiados: {$stats['adsets']}"
                . " | anuncios con creativo: {$stats['linked']} | creativos nuevos: {$stats['creatives_new']}"
                . " | ángulos nuevos: {$stats['angles_new']} (alias: {$stats['aliases_new']})");
            $mixed = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
                ->whereNotNull('a.meta_creative_ref_id')->whereNotNull('c.product_id')
                ->groupBy('a.meta_creative_ref_id')->havingRaw('COUNT(DISTINCT c.product_id) > 1')->select('a.meta_creative_ref_id')->get()->count();
            $this->line('Creativos con anuncios de más de un producto: ' . $mixed . ' | creativos sin anuncios (no se ven): '
                . MetaCreative::doesntHave('ads')->count());
            foreach (AdAngle::with('product:id,title,showable_name', 'canonical:id,code')->orderBy('product_id')->orderBy('code')->get() as $a) {
                $this->line('  ' . ($a->product?->showable_name ?: $a->product?->title) . ' · ' . $a->code . ($a->canonical ? " = {$a->canonical->code}" : ''));
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return self::SUCCESS;
    }
}
