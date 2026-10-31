<?php

namespace App\Console\Commands;

use App\Models\MetaVideoTranscript;
use App\Services\Meta\Videos\VideoTranscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Los videos de los creativos ganadores (`.55`): cada mañana a las 07:30 transcribe los que faltan, hasta 40 por día.
 * Con --report solo muestra cómo va (no baja ni transcribe nada).
 */
class MetaTranscribe extends Command
{
    protected $signature = 'meta:transcribe {--limit=40 : cuántos videos como mucho hoy} {--report : solo mostrar cómo va (solo lectura)}';

    protected $description = 'Transcribe los videos de los creativos ganadores (winner y fatigued); con --report solo muestra cómo va';

    public function handle(VideoTranscriber $transcriber): int
    {
        if ($this->option('report')) {
            return $this->report($transcriber);
        }
        $r = $transcriber->run(max(0, (int) $this->option('limit')));
        if ($r['skipped']) {
            $this->warn($r['skipped']);
        }
        $this->info("Videos de ganadores por transcribir: {$r['pending']}. Hoy: {$r['done']} transcritos, {$r['no_access']} sin permiso de Meta, "
            . "{$r['too_big']} muy grandes, {$r['failed']} con error.");

        return self::SUCCESS;
    }

    private function report(VideoTranscriber $transcriber): int
    {
        $winners = DB::table('meta_ads as a')->join('meta_creatives as c', 'c.id', '=', 'a.meta_creative_ref_id')
            ->whereIn('c.status', VideoTranscriber::WINNERS)->whereNotNull('a.video_id')->where('a.video_id', '<>', '')
            ->distinct()->count('a.video_id');
        $this->info("VIDEOS DE LOS GANADORES: {$winners} (de creativos winner y fatigued)");
        foreach (MetaVideoTranscript::LABELS as $status => $label) {
            $this->line(str_pad($label, 48) . MetaVideoTranscript::where('status', $status)->count());
        }
        $this->line(str_pad('Por transcribir hoy', 48) . count($transcriber->pending()));
        foreach (MetaVideoTranscript::where('status', '<>', MetaVideoTranscript::DONE)->orderByDesc('attempted_at')->limit(5)->get() as $t) {
            $this->line("  ✗ video {$t->video_id}: {$t->error}");
        }
        foreach (MetaVideoTranscript::where('status', MetaVideoTranscript::DONE)->orderByDesc('transcribed_at')->limit(3)->get() as $t) {
            $this->line("  ✔ video {$t->video_id}: hook «" . mb_strimwidth((string) $t->hook, 0, 90, '…') . '»');
        }

        return self::SUCCESS;
    }
}
