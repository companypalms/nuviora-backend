<?php

namespace App\Services\Meta\Analysis;

/**
 * `.70` (dueño, 2026-11-01): "solo quiero que en base a los datos de las campañas la IA pueda decir si escalar, parar o
 * mantener un creativo… y que le diga a mis editores qué creativos deben iterar… basado en datos (hook rate, hold rate,
 * CTR, frecuencia, porcentajes de vistas, tiempo promedio de visualización)". PHP puro: la decisión de cada creativo en una
 * palabra, sus números (solo para el dueño) y qué hacer el editor, en palabras y sin números (decisión del dueño).
 *
 * La decisión sale del veredicto de SUS reglas (AdRules), no de la IA:
 * - ESCALAR: prometedor o PROVEN (compra con el CPA debajo de C) y la regla de su ciudad dice "Subir". El anuncio no se
 *   toca: se sube el presupuesto de esa ciudad y se piden iteraciones de él. Si va ganando pero su ciudad no está para
 *   subir (por ejemplo, "Pausar la ciudad"), es MANTENER: no se toca y se avisa lo que dice la ciudad.
 * - PARAR: perdedor.
 * - ITERAR: ganador que empezó a fallar, o intermedio con algo claro que arreglar en el video (no la landing).
 * - MANTENER: lo demás (en evaluación, sin datos todavía, intermedio sin nada claro, sin objetivo).
 */
final class CreativeCalls
{
    public const CALLS = [
        'scale' => ['label' => 'ESCALAR', 'tone' => 'success'],
        'keep' => ['label' => 'MANTENER', 'tone' => 'info'],
        'stop' => ['label' => 'PARAR', 'tone' => 'error'],
        'iterate' => ['label' => 'ITERAR', 'tone' => 'warning'],
    ];

    /** Qué cambiar, en palabras para el editor (sin números). */
    private const FIX = [
        'hook' => 'Cambia los primeros 3 segundos: el gancho no engancha. El resto del video funciona, no lo toques.',
        'duration' => 'Acorta el video o hazlo más dinámico en el medio: la gente se va antes de terminar. Conserva el inicio.',
        'cta' => 'Mejora el cierre: la gente se va justo antes del final. Haz el llamado a comprar más claro y más temprano.',
        'format' => 'Prueba la misma idea en imagen: en video está saliendo caro mostrarlo.',
    ];

    /**
     * La decisión de un creativo: ESCALAR, MANTENER, PARAR o ITERAR, qué hacer (para el dueño) y qué hacer el editor.
     *
     * @param array $cr el creativo de MetaDecisions (verdict, video, cities, type)
     * @param array<string, array{city: ?string, key: string, label: string, proposal: ?string}> $cityActions por campaña: lo
     *        que dice la regla de esa ciudad (ESCALAR solo si alguna dice "Subir")
     * @return array{key: string, label: string, tone: string, todo: string, editor: ?string}
     */
    public static function call(array $cr, array $cityActions = []): array
    {
        $verdict = $cr['verdict']['key'] ?? 'no_data';
        $iterate = $cr['video']['iterate'] ?? null;
        $winner = in_array($verdict, ['promising', 'proven'], true);
        $cities = self::cities($cr, $cityActions);
        $scaleCities = array_filter($cities, fn ($a) => $a['key'] === 'scale');
        $key = match (true) {
            $winner && $scaleCities !== [] => 'scale',
            $winner => 'keep',
            $verdict === 'loser' => 'stop',
            $verdict === 'failing' => 'iterate',
            $verdict === 'intermediate' && isset(self::FIX[$iterate]) => 'iterate',
            default => 'keep',
        };
        $proposal = trim((string) ($cr['verdict']['proposal'] ?? ''));

        $todo = match (true) {
            $key === 'scale' => 'Va ganando: no toques el anuncio, sube el presupuesto y pide iteraciones de él.' . self::cityText($scaleCities),
            $winner => 'Va ganando: no lo toques y pide iteraciones de él.' . ($cities ? ' Ojo, lo que dice su ciudad:' . self::cityText($cities) : ''),
            $key === 'stop' => 'Apágalo.',
            $key === 'iterate' => $verdict === 'failing' ? 'Se está cansando: pide versiones nuevas.' : 'Tiene algo claro que arreglar en el video: pide versiones que lo arreglen.',
            default => $proposal !== '' ? $proposal : 'Déjalo correr.',
        };
        $editor = match (true) {
            $winner => 'Este creativo está funcionando: haz versiones nuevas que conserven el gancho y la promesa, y prueben otras aperturas.'
                . (isset(self::FIX[$iterate]) ? ' Y además: ' . lcfirst(self::FIX[$iterate]) : ''),
            $key === 'iterate' => self::FIX[$iterate]
                ?? 'La gente ya lo vio mucho y se cansó: haz versiones nuevas con otra apertura y otras imágenes, conservando la promesa.',
            default => null,
        };

        return ['key' => $key] + self::CALLS[$key] + ['todo' => $todo, 'editor' => $editor];
    }

    /**
     * Los números del creativo, para el dueño: los del video (hook rate, hold rate, CTR, frecuencia, cuánta gente llega al
     * 25 %, al 75 % y al final, el tiempo promedio y el CPM) y la plata de los últimos 7 días.
     *
     * @return list<string>
     */
    public static function numbers(array $cr): array
    {
        $m = $cr['metrics'] ?? null;
        $out = [];
        if ($m) {
            $isVideo = ($cr['type'] ?? null) !== 'image';
            $pct = fn ($v) => $v === null ? null : self::num((float) $v) . ' %';
            $parts = array_filter([
                $isVideo && $m['hook_rate'] !== null ? 'Hook rate ' . $pct($m['hook_rate']) : null,
                $isVideo && $m['hold_rate'] !== null ? 'Hold rate ' . $pct($m['hold_rate']) : null,
                $m['unique_ctr'] !== null ? 'CTR ' . $pct($m['unique_ctr']) : null,
                $m['frequency'] !== null ? 'Frecuencia ' . self::num((float) $m['frequency']) : null,
                $m['cpm'] !== null ? 'CPM ' . AnalysisFacts::money((float) $m['cpm']) : null,
            ]);
            if ($parts) {
                $out[] = implode(' · ', $parts) . ' (' . $m['window'] . ')';
            }
            $r = $m['retention'] ?? null;
            if ($isVideo && $r && ($r['p25'] ?? null) !== null) {
                $out[] = 'Llegan al 25 %: ' . $pct($r['p25']) . ' · al 75 %: ' . $pct($r['p75'] ?? null) . ' · al final: ' . $pct($r['p100'] ?? null);
            }
            if ($isVideo && ($m['avg_watch_time'] ?? null) !== null) {
                $out[] = 'Tiempo promedio de visualización: ' . self::num((float) $m['avg_watch_time']) . ' s'
                    . (($m['length'] ?? null) ? ' de ' . self::num((float) $m['length']) . ' s (' . self::num($m['avg_watch_time'] / $m['length'] * 100) . ' %)' : '');
            }
        }
        if ($w = $cr['week'] ?? null) {
            $out[] = '7 días: gasto ' . AnalysisFacts::money((float) $w['spend']) . ' · ' . self::num((float) $w['purchases']) . ' compras'
                . ($w['cpa'] !== null ? ' · CPA ' . AnalysisFacts::money((float) $w['cpa']) : '');
        }

        return $out;
    }

    /** Lo que dice la regla de cada ciudad donde sale el creativo. @return list<array{city: ?string, key: string, label: string, proposal: ?string}> */
    private static function cities(array $cr, array $cityActions): array
    {
        $out = [];
        foreach ($cr['cities'] ?? [] as $c) {
            $a = $cityActions[(string) ($c['campaign_meta_id'] ?? '')] ?? null;
            if ($a) {
                $out[] = $a + ['city' => $c['city'] ?? null];
            }
        }

        return $out;
    }

    /** " En Caracas: Subir $30,00 → $36,00." (la propuesta de la regla; si no tiene, su nombre) */
    private static function cityText(array $cities): string
    {
        $parts = array_map(fn ($a) => 'En ' . ($a['city'] ?? 'su ciudad') . ': ' . rtrim(trim((string) ($a['proposal'] ?: $a['label'])), '.') . '.', $cities);

        return $parts ? ' ' . implode(' ', array_unique($parts)) : '';
    }

    /** "18,5" (como el resto del panel, sin ceros de más). */
    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }
}
