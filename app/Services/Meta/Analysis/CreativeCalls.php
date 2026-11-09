<?php

namespace App\Services\Meta\Analysis;

use App\Services\Meta\Decisions\Rules\AdRules;

/**
 * `.70` (dueño, 2026-11-01): "solo quiero que en base a los datos de las campañas la IA pueda decir si escalar, parar o
 * mantener un creativo… y que le diga a mis editores qué creativos deben iterar… basado en datos (hook rate, hold rate,
 * CTR, frecuencia, porcentajes de vistas, tiempo promedio de visualización)". PHP puro: la decisión de cada creativo en una
 * palabra, sus números y qué hacer el editor (`.80`: en palabras y con los números del video, nunca plata).
 *
 * La decisión sale del veredicto de SUS reglas (AdRules), no de la IA:
 * - ESCALAR: prometedor o PROVEN (compra con el CPA debajo de C) y la regla de su ciudad dice "Subir". El anuncio no se
 *   toca: se sube el presupuesto de esa ciudad y se piden iteraciones de él. Si va ganando pero su ciudad no está para
 *   subir (por ejemplo, "Pausar la ciudad"), es MANTENER: no se toca y se avisa lo que dice la ciudad.
 * - PARAR: perdedor. `.80`: "PARAR EN ARAGUA" si va ganando pero en una ciudad pierde ("debería decir claramente qué
 *   creativos se deben parar por ciudad").
 * - ITERAR: ganador que empezó a fallar, o intermedio con un tramo del video flojo (no la landing).
 * - MANTENER: lo demás (en evaluación, sin datos todavía, intermedio sin nada claro, sin objetivo).
 *
 * `.80` (2026-11-02): el editor recibe qué está bien (no tocar), qué está flojo (solo el primer tramo flojo del video:
 * AdRules::STAGES), qué hacer y los números del video (porcentajes, nunca plata).
 */
final class CreativeCalls
{
    public const CALLS = [
        'scale' => ['label' => 'ESCALAR', 'tone' => 'success'],
        'keep' => ['label' => 'MANTENER', 'tone' => 'info'],
        'stop' => ['label' => 'PARAR', 'tone' => 'error'],
        'iterate' => ['label' => 'ITERAR', 'tone' => 'warning'],
        // .80: va ganando, pero en alguna ciudad pierde
        'stop_city' => ['label' => 'PARAR EN UNA CIUDAD', 'tone' => 'error'],
    ];

    /** `.80`: qué hacer con el primer tramo flojo, en palabras para el editor (sin plata). */
    private const FIX = [
        'hook' => 'Haz hooks nuevos (los primeros 3 segundos), uno distinto en cada pieza, y deja igual lo que viene después: primero hay que lograr que la gente se quede.',
        'after_hook' => 'El hook funciona: no lo toques. La gente se va justo después (entre el segundo 3 y el 15): haz más rápido lo que viene después del hook, ve directo al problema y a la promesa, y corta lo que sobra. Una versión distinta en cada pieza.',
        'middle' => 'El inicio funciona: no lo toques. El medio pierde gente: hazlo más corto y con más ritmo (cortes más rápidos, menos explicación) y adelanta lo más fuerte (la demostración o el beneficio).',
        'close' => 'Casi todo el video funciona: no lo toques. La gente se va antes del final: prueba cierres distintos, con el llamado a comprar más claro y más temprano.',
        'click' => 'Poca gente hace clic: deja igual lo que ya engancha y haz más clara la oferta y el llamado a la acción (dilo antes y con texto en pantalla).',
        'fatigue' => 'La gente ya lo vio mucho: haz hooks nuevos con otras primeras imágenes, uno distinto en cada pieza, conservando el mismo mensaje.',
        'format' => 'Prueba la misma idea en imagen: en video está saliendo caro mostrarlo.',
    ];

    /** `.81`: un ganador con todo bien: 1 hook nuevo y el mismo mensaje en otros formatos (cada pieza dice cuál). */
    private const HEALTHY = 'Este creativo funciona en todo: no lo toques. Haz lo que dice cada pieza: un hook nuevo con el mismo cuerpo y el mismo mensaje en otros formatos, para que dure más y aprendamos qué formato vende.';

    /** Lo que no es del video: con estas causas, que un ganador falle no es trabajo del editor. */
    private const NOT_VIDEO = ['auction', 'landing_offer', 'landing_speed', 'tracking'];

    /**
     * La decisión de un creativo: ESCALAR, MANTENER, PARAR, ITERAR o PARAR EN UNA CIUDAD, qué hacer (para el dueño) y qué
     * hacer el editor.
     *
     * @param array $cr el creativo de MetaDecisions (verdict, video, cities, type, metrics)
     * @param array<string, array{city: ?string, key: string, label: string, proposal: ?string}> $cityActions por campaña: lo
     *        que dice la regla de esa ciudad (ESCALAR solo si alguna dice "Subir")
     * @return array{key: string, label: string, tone: string, todo: string, editor: ?string, fix: ?string, stop_in: list<string>}
     */
    public static function call(array $cr, array $cityActions = []): array
    {
        $verdict = $cr['verdict']['key'] ?? 'no_data';
        $stage = $cr['video']['iterate'] ?? null;
        $causes = array_column($cr['verdict']['causes'] ?? [], 'key');
        $winner = in_array($verdict, ['promising', 'proven'], true);
        $cities = self::cities($cr, $cityActions);
        $scaleCities = array_filter($cities, fn ($a) => $a['key'] === 'scale');
        $stopIn = self::stopIn($cr);
        // .116: su ángulo o sus iteraciones descansan: sigue prendido, pero no se piden más versiones
        $rest = $cr['rest'] ?? null;
        $ask = $rest === null ? ' y pide iteraciones de él' : '';
        // Qué tiene que arreglar el editor: el cansancio, el primer tramo flojo del video o, si un ganador falla sin una causa
        // que no sea del video, hooks nuevos
        $notVideo = array_intersect($causes, self::NOT_VIDEO) !== [] && !in_array('fatigue', $causes, true);
        $fix = match (true) {
            $verdict === 'failing' && in_array('fatigue', $causes, true) => 'fatigue',
            isset(self::FIX[$stage]) => $stage,
            $verdict === 'failing' && !$notVideo => 'fatigue',
            default => null,
        };
        $key = match (true) {
            $winner && $stopIn !== [] => 'stop_city',
            $winner && $scaleCities !== [] => 'scale',
            $winner => 'keep',
            $verdict === 'loser' => 'stop',
            in_array($verdict, ['failing', 'intermediate'], true) && $fix !== null && $rest === null => 'iterate',
            default => 'keep',
        };
        $proposal = trim((string) ($cr['verdict']['proposal'] ?? ''));
        $stopText = $stopIn ? 'Apágalo en ' . self::join($stopIn) . ' (ahí pierde). ' : '';
        $stageName = AdRules::STAGES[$fix] ?? ($fix === 'fatigue' ? 'el cansancio' : 'el formato');

        $todo = match (true) {
            $key === 'stop_city' => $stopText . 'Donde gana, no lo toques' . $ask . '.' . ($scaleCities ? self::cityText($scaleCities) : ''),
            $key === 'scale' => 'Va ganando: no toques el anuncio, sube el presupuesto' . $ask . '.' . self::cityText($scaleCities),
            $winner => 'Va ganando: no lo toques' . $ask . '.' . ($cities ? ' Ojo, lo que dice su ciudad:' . self::cityText($cities) : ''),
            $key === 'stop' => 'Apágalo.' . ($stage && isset(AdRules::STAGES[$stage]) ? ' Para aprender: lo más flojo fue ' . AdRules::STAGES[$stage] . '.' : ''),
            $key === 'iterate' => $stopText . ($fix === 'fatigue' ? 'Se está cansando: pide hooks nuevos.' : 'Lo flojo es ' . $stageName . ': pide versiones que lo arreglen.'),
            // .113: si pierde en una ciudad y en otra todavía se prueba, "Apágalo en Aragua" ya lo dice: solo falta dónde esperar
            default => $stopText . ($stopText !== '' && isset($cr['verdict']['wait']) ? $cr['verdict']['wait'] : ($proposal !== '' ? $proposal : 'Déjalo correr.')),
        };
        $todo .= $rest !== null ? ' ' . $rest : '';
        $editor = ($winner || $key === 'iterate') && $rest === null ? self::editorText($cr, $winner && $fix === null ? null : $fix) : null;
        $label = match (true) {
            $key === 'stop_city' => 'PARAR EN ' . mb_strtoupper(self::join($stopIn)),
            $stopIn !== [] && $key !== 'stop' => self::CALLS[$key]['label'] . ' · PARAR EN ' . mb_strtoupper(self::join($stopIn)),
            default => self::CALLS[$key]['label'],
        };

        return ['key' => $key, 'label' => $label, 'tone' => self::CALLS[$key]['tone'], 'todo' => $todo, 'editor' => $editor, 'fix' => $fix, 'stop_in' => $stopIn];
    }

    /** `.71`: qué cambiar, en palabras (null si no hay nada claro que arreglar en el video). */
    public static function fix(?string $iterate): ?string
    {
        return self::FIX[$iterate] ?? null;
    }

    /** `.80`: las ciudades donde pierde y sigue activo. @return list<string> */
    public static function stopIn(array $cr): array
    {
        $out = [];
        foreach ($cr['cities'] ?? [] as $c) {
            if (($c['key'] ?? $c['verdict'] ?? null) === 'loser' && ($c['active'] ?? true) && ($c['city'] ?? null) !== null) {
                $out[] = (string) $c['city'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * `.80`: lo que lee el editor: qué hacer, qué está bien (no tocar), qué está flojo y los números del video (sin plata).
     * $fix null = un ganador con todo bien.
     */
    public static function editorText(array $cr, ?string $fix): string
    {
        $stages = $cr['video']['stages'] ?? [];
        $good = [];
        foreach ($stages as $k => $st) {
            if ($st['grade'] !== 'weak' && $k !== $fix) {
                $good[] = $st['label'] . ' (' . self::num((float) $st['value']) . ' %)';
            }
        }
        $parts = ['Qué hacer: ' . ($fix === null ? self::HEALTHY : (($cr['verdict']['key'] ?? null) === 'proven' || ($cr['verdict']['key'] ?? null) === 'promising'
            ? 'Este creativo está vendiendo: conserva todo lo que funciona. ' : '') . self::FIX[$fix])];
        if ($good) {
            $parts[] = 'Está bien (no lo toques): ' . implode(', ', $good) . '.';
        }
        if ($fix !== null && isset($stages[$fix])) {
            $st = $stages[$fix];
            $parts[] = 'Está flojo: ' . $st['label'] . ' (' . self::num((float) $st['value']) . ' %' . ($st['median'] !== null ? '; en tus ganadores, ' . self::num((float) $st['median']) . ' %' : '') . ').';
        } elseif ($fix === 'fatigue' && ($f = $cr['metrics']['frequency'] ?? null) !== null) {
            $parts[] = 'Está flojo: la gente ya lo vio mucho (frecuencia ' . self::num((float) $f) . ').';
        }
        if ($n = self::videoNumbers($cr)) {
            $parts[] = $n;
        }

        return implode(' ', $parts);
    }

    /** `.80`: "Números del video (3 días): hook 51 % · justo después del hook (hold) 41 % · …" (sin plata). */
    public static function videoNumbers(array $cr): ?string
    {
        $stages = $cr['video']['stages'] ?? [];
        $names = ['hook' => 'hook', 'after_hook' => 'justo después del hook (hold)', 'middle' => 'medio', 'close' => 'cierre', 'click' => 'CTR único'];
        $parts = [];
        foreach ($names as $k => $name) {
            if (isset($stages[$k])) {
                $parts[] = $name . ' ' . self::num((float) $stages[$k]['value']) . ' %';
            }
        }
        if (($f = $cr['metrics']['frequency'] ?? null) !== null) {
            $parts[] = 'frecuencia ' . self::num((float) $f);
        }
        if (($w = $cr['video']['watch_pct'] ?? null) !== null) {
            $parts[] = 'visto en promedio ' . self::num((float) $w) . ' %';
        }

        return $parts ? 'Números del video (' . ($cr['video']['window'] ?? $cr['metrics']['window'] ?? '') . '): ' . implode(' · ', $parts) . '.' : null;
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
                $out[] = 'De cada 100 que empiezan el video llegan: al 25 % ' . $pct($r['p25']) . ' · al 50 % ' . $pct($r['p50'] ?? null)
                    . ' · al 75 % ' . $pct($r['p75'] ?? null) . ' · al final ' . $pct($r['p100'] ?? null);
            }
            // .80: cada tramo sobre los que llegaron a él, con la mediana de tus ganadores
            $stages = $cr['video']['stages'] ?? [];
            if ($isVideo && $stages) {
                $out[] = 'Por tramos: ' . implode(' · ', array_map(fn ($st) => $st['label'] . ' ' . self::num((float) $st['value']) . ' %'
                    . ($st['median'] !== null ? ' (ganadores ' . self::num((float) $st['median']) . ' %)' : '') . ($st['grade'] === 'weak' ? ' FLOJO' : ''), $stages));
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
        // .80: si la ciudad solo trae el aviso de stock ("Ojo: …"), primero lo que dice su regla
        $parts = array_map(fn ($a) => 'En ' . ($a['city'] ?? 'su ciudad') . ': ' . rtrim(trim((string) (str_starts_with((string) $a['proposal'], 'Ojo:')
            ? $a['label'] . '. ' . $a['proposal'] : ($a['proposal'] ?: $a['label']))), '.') . '.', $cities);

        return $parts ? ' ' . implode(' ', array_unique($parts)) : '';
    }

    /** "Aragua", "Aragua y Carabobo", "Aragua, Carabobo y Caracas". */
    public static function join(array $items): string
    {
        $last = array_pop($items);

        return $items ? implode(', ', $items) . ' y ' . $last : (string) $last;
    }

    /** "18,5" (como el resto del panel, sin ceros de más). */
    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }
}
