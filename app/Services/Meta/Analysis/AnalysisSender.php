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
 * - `.78` (dueño, 2026-11-02: "en Meta Ads solo subimos un batch a la vez por producto… debió haber mandado lo más
 *   importante del FlexiStep y luego lo más importante de otro producto"): por turnos, del producto más urgente al menos,
 *   como mucho uno por producto: el de su creativo más importante (primero los ganadores, después los ganadores que
 *   empezaron a fallar y después los mediocres con algo que arreglar; a igual nivel, el que más gastó; los perdedores no
 *   se iteran). Un producto que ya tiene un encargo en camino o listo en la banca (la "banca por producto: desde", 1) no
 *   recibe otro: "que cada producto activo siempre tenga un batch en la banca listo para lanzar".
 * - Solo lo que cabe en el día según los editores (como la cola: editores × encargos por editor, menos las correcciones);
 *   lo que no cabe sale al día siguiente si sigue igual.
 * - Solo productos en modo normal (en pausa, abandonados o en liquidación no reciben creativos nuevos) y con trabajo (U5,
 *   "sin trabajo", por ejemplo sin stock, no).
 */
class AnalysisSender
{
    /** Un encargo que ya está en manos del equipo (o esperando lanzarse). */
    public const OPEN = ['approved', 'production', 'review', 'bank'];

    /** `.78`: qué creativo de un producto va primero (menos = antes). Los perdedores no se iteran. */
    public const PRIORITY = ['proven' => 0, 'promising' => 0, 'failing' => 1, 'intermediate' => 2];

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
        foreach (self::turns($analysis->facts ?? [], $s) as $c) {
            $id = (int) $c['creative']['id'];
            if ($c['full']) {
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
     * `.78`: el turno de cada producto, del más urgente al menos: su creativo más importante (picks) y sus encargos en
     * camino o en la banca. "full" = ya tiene los que pide la banca (1): hoy no recibe otro.
     *
     * @return list<array{product_id: int, urgency: ?int, creative: array, tier: int, open: list<string>, full: bool}>
     */
    public static function turns(array $facts, array $s): array
    {
        $picks = self::picks($facts);
        $open = [];
        if ($picks !== []) {
            foreach (CreativeBatch::whereIn('product_id', array_column($picks, 'product_id'))->whereIn('status', self::OPEN)->orderBy('id')->get() as $b) {
                $open[(int) $b->product_id][] = (string) $b->code();
            }
        }
        $target = max(1, (int) ($s['bank_min'] ?? 1));

        return array_map(fn ($c) => $c + ['open' => $open[$c['product_id']] ?? [], 'full' => count($open[$c['product_id']] ?? []) >= $target], $picks);
    }

    /**
     * `.78` (PHP puro): de cada producto, en el orden del análisis (el más urgente primero), su creativo más importante:
     * primero los ganadores, después los que empezaron a fallar, después los mediocres con algo que arreglar; a igual
     * nivel, el que más gastó (los creativos ya vienen de más a menos gasto).
     *
     * @return list<array{product_id: int, urgency: ?int, creative: array, tier: int}>
     */
    public static function picks(array $facts): array
    {
        $out = [];
        foreach (self::candidates($facts) as $c) {
            $tier = self::PRIORITY[$c['creative']['verdict_key'] ?? ''] ?? 3;
            if (!isset($out[$c['product_id']]) || $tier < $out[$c['product_id']]['tier']) {
                $out[$c['product_id']] = $c + ['tier' => $tier];
            }
        }

        return array_values($out);
    }

    /**
     * Los creativos del análisis que traen algo para el editor (ITERAR o ganadores), en el orden del análisis (el producto
     * más urgente primero y, dentro de cada uno, el que más gastó).
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
        // .80: lo que arregla el encargo es el primer tramo flojo del video (o el cansancio); null = un ganador con todo bien
        $fix = array_key_exists('fix', $call) ? $call['fix'] : (CreativeCalls::fix($iterate) !== null ? $iterate : null);
        $versions = $pieces === 1 ? 'Una versión nueva' : "{$pieces} versiones";
        $hooks = fn (string $rest) => $pieces === 1 ? "Una versión nueva con otro hook, {$rest}." : "{$pieces} versiones: un hook distinto en cada una, {$rest}.";
        $stage = \App\Services\Meta\Decisions\Rules\AdRules::STAGES[$fix] ?? null;
        $keep = match ($fix) {
            'hook', 'fatigue' => 'Todo lo que viene después de los primeros 3 segundos: déjalo igual (en este encargo solo cambia el hook).',
            'after_hook' => 'El hook (los primeros 3 segundos): funciona.',
            'middle' => 'El inicio (el hook y lo que sigue): funciona.',
            'close' => 'Todo el video hasta el cierre: funciona.',
            'click' => 'Lo que ya engancha: funciona.',
            'format' => 'La idea y la promesa.',
            default => 'Todo lo que viene después del hook: funciona.',
        };
        if ($call['key'] !== 'iterate') {
            return [
                'test_idea' => $stage ? "Probamos si mejorando {$stage}, este creativo que ya vende vende todavía más."
                    : 'Probamos si hooks nuevos le alargan la vida a un creativo que ya está vendiendo.',
                'goal' => $stage ? "{$versions} que mejoren {$stage}, conservando todo lo demás." : $hooks('con el mismo cuerpo'),
                'keep' => $keep,
                'improve' => $call['editor'],
            ];
        }

        return [
            'test_idea' => $fix === 'fatigue' || $fix === null ? 'Probamos si con hooks nuevos este creativo vuelve a enganchar a la gente que ya lo vio.'
                : 'Probamos si arreglando ' . ($stage ?? 'el formato') . ', este creativo vuelve a vender.',
            'goal' => $fix === 'fatigue' || $fix === null ? $hooks('con otras primeras imágenes')
                : "{$versions} que arreglen " . ($stage ?? 'el formato') . '.',
            'keep' => $keep,
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
