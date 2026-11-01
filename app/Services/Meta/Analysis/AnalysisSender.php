<?php

namespace App\Services\Meta\Analysis;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\MetaAnalysis;
use App\Models\MetaCreative;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Queue\BatchQueue;
use Illuminate\Support\Facades\DB;

/**
 * `.71` (dueño, 2026-11-01): "no quiero copiar nada para pasárselo al editor líder, quiero que todo se haga a través del
 * sistema, y que él se lo pase a los otros editores a través del sistema también". Eligió que sea automático.
 *
 * Después de cada análisis, los creativos que hay que iterar (ITERAR) y los que van ganando (con algo para el editor) se le
 * mandan solos al editor líder como un encargo aprobado (B####): si la IA ya había propuesto una iteración de ese creativo,
 * se aprueba esa; si no, se crea una con las indicaciones del análisis, en palabras. El líder la ve en "Encargos del equipo"
 * y se la asigna a un editor, que la ve en "Mis encargos".
 *
 * - Nunca dos encargos abiertos del mismo creativo.
 * - Solo lo que cabe en el día según los editores (como la cola: editores × encargos por editor, menos las correcciones);
 *   lo que no cabe sale al día siguiente si sigue igual.
 * - Solo productos en modo normal (en pausa, abandonados o en liquidación no reciben creativos nuevos) y con trabajo (U5,
 *   "sin trabajo", por ejemplo sin stock, no).
 */
class AnalysisSender
{
    /** Un encargo de ese creativo que ya está en manos del equipo (o esperando lanzarse). */
    public const OPEN = ['approved', 'production', 'review', 'bank'];

    public function __construct(private DecisionSettings $settings = new DecisionSettings(), private BatchQueue $queue = new BatchQueue())
    {
    }

    /** @return array{sent: list<string>, already: int, waiting: int} */
    public function run(MetaAnalysis $analysis): array
    {
        $today = now(config('app.timezone'))->toDateString();
        $s = $this->settings->get();
        $left = self::capacity($s) - CreativeBatch::whereNotNull('approved_at')->whereDate('approved_at', $today)->count();
        $pieces = max(1, (int) $s['batch_pieces']);
        $out = ['sent' => [], 'already' => 0, 'waiting' => 0];
        foreach (self::candidates($analysis->facts ?? []) as $c) {
            $id = (int) $c['creative']['id'];
            if (CreativeBatch::where('reference_creative_id', $id)->whereIn('status', self::OPEN)->exists()) {
                $out['already']++;
                continue;
            }
            if ($left <= 0) {
                $out['waiting']++;
                continue;
            }
            $batch = DB::transaction(function () use ($c, $id, $pieces, $today) {
                $batch = CreativeBatch::where('reference_creative_id', $id)->where('status', 'proposed')->orderByDesc('id')->first()
                    ?? $this->create($c, $pieces, $today);

                return $this->queue->approve($batch, null);
            });
            $out['sent'][] = (string) $batch->code();
            $left--;
        }

        return $out;
    }

    /** Cuántos encargos por día pueden hacer los editores (como la cola de propuestas). */
    public static function capacity(array $s): int
    {
        return max(0, (int) round($s['video_editors'] * $s['batches_per_editor'] / (1 + $s['corrections_pct'] / 100)));
    }

    /**
     * Los creativos del análisis que se le mandan al editor líder, en el orden del análisis (el producto más urgente
     * primero y, dentro de cada uno, el que más gastó): los que traen algo para el editor (ITERAR o ganadores).
     *
     * @return list<array{product_id: int, urgency: ?int, creative: array}>
     */
    public static function candidates(array $facts): array
    {
        $out = [];
        foreach ($facts as $p) {
            if (($p['mode'] ?? 'normal') !== 'normal' || ($p['urgency']['level'] ?? null) === 5) {
                continue;
            }
            foreach ($p['creatives'] ?? [] as $cr) {
                if (!empty($cr['call']['editor'])) {
                    $out[] = ['product_id' => (int) $p['product_id'], 'urgency' => $p['urgency']['level'] ?? null, 'creative' => $cr];
                }
            }
        }

        return $out;
    }

    /**
     * Lo que dice el encargo (en palabras, para el editor): qué probamos, qué buscamos, qué conservar y qué cambiar.
     *
     * @param array{key: string, editor: ?string} $call
     * @return array{test_idea: string, goal: string, keep: string, improve: ?string}
     */
    public static function brief(array $call, ?string $iterate, int $pieces): array
    {
        $versions = $pieces === 1 ? 'Una versión nueva' : "{$pieces} versiones bien distintas entre sí";
        if ($call['key'] !== 'iterate') {
            return [
                'test_idea' => 'Probamos si versiones nuevas de un creativo que ya está vendiendo, con otras aperturas, venden igual o más.',
                'goal' => "{$versions}, con aperturas nuevas.",
                'keep' => 'El gancho y la promesa: funcionan, no los cambies.',
                'improve' => CreativeCalls::fix($iterate),
            ];
        }
        $fix = CreativeCalls::fix($iterate);

        return [
            'test_idea' => $fix ? 'Probamos si arreglando lo que falla, este creativo vuelve a vender.'
                : 'Probamos si con otra apertura este creativo vuelve a enganchar a la gente que ya lo vio.',
            'goal' => $fix ? "{$versions} que arreglen lo que falla." : "{$versions}, con otra apertura y otras imágenes.",
            'keep' => match ($iterate) {
                'hook' => 'Todo lo que viene después de los primeros segundos: funciona.',
                'duration', 'cta' => 'El inicio: el gancho funciona.',
                'format' => 'La idea y la promesa.',
                default => 'La promesa.',
            },
            'improve' => $call['editor'],
        ];
    }

    /** Una iteración nueva de ese creativo, con las indicaciones del análisis (queda propuesta; enseguida se aprueba). */
    private function create(array $c, int $pieces, string $today): CreativeBatch
    {
        $cr = $c['creative'];
        $creative = MetaCreative::find((int) $cr['id'], ['id', 'product_id', 'angle_code', 'type']);
        $batch = CreativeBatch::create(self::brief($cr['call'], $cr['video']['iterate'] ?? null, $pieces) + [
            'product_id' => $creative?->product_id ?? $c['product_id'], 'kind' => 'iteration', 'reference_creative_id' => (int) $cr['id'],
            'angle_code' => $creative?->angle_code, 'format' => ($creative?->type ?? $cr['type'] ?? null) === 'image' ? 'image' : 'video',
            'urgency' => $c['urgency'], 'source' => 'rules', 'proposed_on' => $today, 'status' => 'proposed', 'status_at' => now(),
        ]);
        for ($i = 1; $i <= $pieces; $i++) {
            CreativeBatchPiece::create(['creative_batch_id' => $batch->id, 'position' => $i]);
        }

        return $batch;
    }
}
