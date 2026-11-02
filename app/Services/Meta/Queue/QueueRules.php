<?php

namespace App\Services\Meta\Queue;

use Carbon\CarbonImmutable;

/**
 * Las reglas de la cola de producción y la banca (Etapa 5, paso 4a), en PHP puro:
 * - los estados de un encargo, como el manual del dueño: Propuesto → Aprobado → En producción → En revisión → En banca →
 *   En test → Testeado, o Descartado. "En test" y "Testeado" se ponen solos (BatchTracker);
 * - la numeración única: B#### para el batch y C#### para cada pieza, después del número más alto que ya existe y nunca
 *   menos de 100 (para no confundirse con los viejos BATCH 004 o C005, que no se renumeran);
 * - cuánto cuenta la banca y el texto del encargo para el editor.
 */
final class QueueRules
{
    public const STATUSES = [
        'proposed' => 'Propuesto', 'approved' => 'Aprobado', 'production' => 'En producción', 'review' => 'En revisión',
        'bank' => 'En banca', 'testing' => 'En test', 'tested' => 'Testeado', 'discarded' => 'Descartado',
    ];

    /** Aprobados sin testear: la banca del producto (el manual pide 1–2 por producto). */
    public const BANK = ['approved', 'production', 'review', 'bank'];

    /** La cola de los editores, en orden. */
    public const QUEUE = ['approved', 'production', 'review'];

    public const KINDS = ['iteration' => 'Iteración', 'new_concept' => 'Concepto nuevo', 'new_angle' => 'Ángulo nuevo', 'top_funnel' => 'Arriba del embudo'];

    public const FORMATS = ['video' => 'Video', 'image' => 'Imagen', 'mixed' => 'Video e imagen'];

    /** .66: el estilo del encargo (para aprender qué formato funciona en cada producto). */
    public const STYLES = [
        'testimonio' => 'Testimonio', 'animado' => 'Video animado', 'venta_directa' => 'Venta directa', 'demostracion' => 'Demostración',
        'ugc' => 'UGC', 'antes_despues' => 'Antes y después', 'problema_solucion' => 'Problema–solución',
        'entrevista' => 'Entrevista', // .81 (dueño, 2026-11-02)
        'otro' => 'Otro',
    ];

    /** Los veredictos de las reglas que cierran el test de un batch; sin datos o en evaluación todavía no dicen nada. */
    public const FINAL_VERDICTS = ['promising', 'proven', 'intermediate', 'loser', 'failing'];

    public const MIN_NUMBER = 100;

    private const DAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    public static function batchCode(int $number): string
    {
        return 'B' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public static function pieceCode(int $number): string
    {
        return 'C' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** El número de un batch como lo deja el lector de nombres de Meta ("B0123" → 123, "B004" → 4, "B001.1" → 1). */
    public static function batchNumber(?string $code): ?int
    {
        return $code !== null && preg_match('/^B(\d+)(?:\.\d+)?$/', $code, $m) ? (int) $m[1] : null;
    }

    /** El número de un creativo de Meta ("C0457" → 457, "CRE001" → 1, "ITER2-CRE001" → 1); "CREATIVO6" no es un C###. */
    public static function pieceNumber(?string $code): ?int
    {
        return $code !== null && preg_match('/(?:^|-)C(?:RE|R)?(\d+)$/', $code, $m) ? (int) $m[1] : null;
    }

    /** El siguiente número libre: después del más alto que ya existe, y nunca menos de 100. */
    public static function next(iterable $used, int $min = self::MIN_NUMBER): int
    {
        $max = 0;
        foreach ($used as $n) {
            if ($n !== null && (int) $n > $max) {
                $max = (int) $n;
            }
        }

        return max($min, $max + 1);
    }

    /** ¿Puede el dueño pasar un encargo de un estado a otro? Aprobar es aparte, porque da los números. */
    public static function canMove(string $from, string $to, bool $numbered): bool
    {
        if ($from === $to || !isset(self::STATUSES[$to]) || $to === 'proposed' && $numbered) {
            return false;
        }

        return match ($from) {
            'proposed' => $to === 'discarded',
            // Un descartado vuelve a donde estaba: a aprobar si nunca se aprobó, o aprobado con sus números
            'discarded' => $to === ($numbered ? 'approved' : 'proposed'),
            default => $to !== 'proposed',
        };
    }

    /**
     * El resultado del test de un batch, con el veredicto de las reglas en cada ciudad donde salió: null mientras alguna
     * ciudad no tenga un veredicto que lo cierre.
     *
     * @param list<array{city: ?string, key: string, label: string}> $verdicts
     */
    public static function outcome(array $verdicts): ?array
    {
        if ($verdicts === []) {
            return null;
        }
        foreach ($verdicts as $v) {
            if (!in_array($v['key'], self::FINAL_VERDICTS, true)) {
                return null;
            }
        }

        return array_values($verdicts);
    }

    /** Cuántos batches pedir con lo que ya hay en la banca: "2–3" con 1 en banca → "1–2"; nunca menos de 0. */
    public static function minusBank(string $range, int $bank): string
    {
        if ($bank <= 0) {
            return $range;
        }
        $parts = array_map(fn ($n) => max(0, (int) $n - $bank), preg_split('/[–-]/u', $range));

        return implode('–', array_values(array_unique($parts)));
    }

    /** Los nombres para Meta: el ad set "B0123 FASP" y los anuncios "C0457 FASP", "C0458 FASP"… */
    public static function metaNames(?int $number, ?string $angle, array $pieceNumbers): array
    {
        $suffix = $angle ? ' ' . $angle : '';

        return [
            'adset' => $number !== null ? self::batchCode($number) . $suffix : null,
            'ads' => array_values(array_map(fn ($n) => self::pieceCode((int) $n) . $suffix, array_filter($pieceNumbers, fn ($n) => $n !== null))),
        ];
    }

    /** .64: lo del producto en el encargo, como lo lee el editor. */
    public const PRODUCT_LABELS = [
        'what_it_is' => 'Qué es', 'benefits' => 'Para qué sirve', 'mechanism' => 'Cómo funciona',
        'not_this' => 'Lo que NO es (nunca lo digas)', 'key_info' => 'Información clave', 'photos_url' => 'Fotos',
    ];

    /**
     * El encargo en texto, para mandárselo al editor (botón "Copiar"). `.63`: es para el editor, así que no lleva nada del
     * proceso del dueño (ni la prioridad, ni la hipótesis, ni la ciudad de prueba): lo que buscamos, lo que funcionó, lo
     * que hay que mejorar y las ideas para cada versión.
     *
     * @param array $b el encargo como lo arma la pantalla (MetaQueueController::present o EditorWork::present)
     */
    public static function brief(array $b): string
    {
        $lines = ['ENCARGO ' . ($b['code'] ?? '(sin aprobar)') . " · {$b['product']}"];
        $kind = self::KINDS[$b['kind']] ?? $b['kind'];
        $lines[] = "Tipo: {$kind}" . ($b['reference'] ? " (referencia: {$b['reference']})" : '');
        if ($b['angle_code']) {
            $lines[] = "Ángulo: {$b['angle_code']}" . ($b['angle_name'] ? " ({$b['angle_name']})" : '');
        }
        // .64: lo del producto y el buyer persona de este encargo (solo ese)
        // .69: el editor nunca lee un precio (ni de la ficha, ni de la oferta, ni de lo que escribió la IA)
        $info = array_filter(array_map([self::class, 'withoutPrices'], (array) ($b['product_info'] ?? [])), fn ($v) => $v !== null);
        if ($info) {
            $lines[] = 'Sobre el producto:';
            foreach (self::PRODUCT_LABELS as $key => $label) {
                if (!empty($info[$key])) {
                    $lines[] = "- {$label}: " . trim(preg_replace('/\s*\n+\s*/u', ' / ', (string) $info[$key]));
                }
            }
        }
        if (!empty($b['persona']['name'])) {
            $lines[] = 'A quién le hablas: ' . \App\Services\Meta\Products\ProductProfileRules::personaLine(array_map([self::class, 'withoutPrices'], $b['persona']));
        }
        foreach (['test_idea' => 'Qué estamos probando', 'goal' => 'Lo que buscamos', 'keep' => 'Lo que funcionó (no lo cambies)', 'improve' => 'Lo que hay que mejorar',
            'concept' => 'Concepto', 'audience' => 'Dolor o público', 'offer' => 'Oferta'] as $key => $label) {
            $value = self::withoutPrices(isset($b[$key]) ? (string) $b[$key] : null);
            if ($value !== null) {
                $lines[] = "{$label}: {$value}";
            }
        }
        if ($b['format']) {
            $lines[] = 'Formato: ' . (self::FORMATS[$b['format']] ?? $b['format']);
        }
        if (!empty($b['style'])) {
            $lines[] = 'Estilo: ' . (self::STYLES[$b['style']] ?? $b['style']);
        }
        if ($b['pieces']) {
            // .81: si las piezas traen qué prueba cada una, se dice pieza por pieza con su hipótesis
            $planned = array_filter($b['pieces'], fn ($p) => !empty($p['hypothesis']));
            $lines[] = $planned ? 'Qué hacer en cada pieza:' : 'Ideas para cada versión (tú decides cómo):';
            foreach ($b['pieces'] as $i => $p) {
                $change = self::withoutPrices((string) $p['change']);
                $hypothesis = self::withoutPrices((string) ($p['hypothesis'] ?? ''));
                $lines[] = ($i + 1) . '. ' . ($p['code'] ? "{$p['code']} — " : '') . (!empty($p['test_label']) && $planned ? "{$p['test_label']}: " : '') . ($change ?? 'sin detalle')
                    . ($p['format'] && $p['format'] !== $b['format'] ? ' (' . mb_strtolower(self::FORMATS[$p['format']] ?? $p['format']) . ')' : '')
                    . ($hypothesis !== null ? " Qué probamos: {$hypothesis}" : '');
            }
        }
        // Paso 5a: los enlaces de Drive de la última entrega, para subirlos a Meta
        $files = array_filter($b['pieces'] ?? [], fn ($p) => !empty($p['link']));
        if ($files) {
            $lines[] = 'Archivos (Drive):';
            foreach ($files as $p) {
                $lines[] = '- ' . ($p['code'] ?? 'Pieza') . ': ' . $p['link'] . (!empty($p['hook']) ? " — hook: {$p['hook']}" : '');
            }
        }
        if (($deliverables = self::withoutPrices($b['deliverables'] ?? null)) !== null) {
            $lines[] = 'Entregables: ' . $deliverables;
        }
        if ($b['due_date']) {
            $d = CarbonImmutable::parse($b['due_date']);
            $lines[] = 'Plazo: ' . self::DAYS[$d->dayOfWeek] . ' ' . $d->format('d/m');
        }
        if (!empty($b['editor'])) {
            $lines[] = "Editor: {$b['editor']}";
        }
        $names = $b['meta_names'] ?? ['adset' => null, 'ads' => []];
        if ($names['adset']) {
            $lines[] = "Nombres en Meta: ad set \"{$names['adset']}\"" . ($names['ads'] ? '; anuncios ' . implode(', ', array_map(fn ($n) => "\"{$n}\"", $names['ads'])) : '') . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * `.69` (dueño, 2026-11-01): el editor "no debería ver el precio del producto". Quita de un texto que lee el editor
     * cada frase que habla de un precio o de un monto de dinero ("Precio $25", "cuesta 40 dólares", "Bs 900"). null si no
     * queda nada. El dueño ve sus textos completos; esto es solo para lo que le llega al editor.
     */
    public static function withoutPrices(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        // Por frases (punto seguido de espacio, punto y coma, signos o salto de línea), conservando cómo terminaba cada una
        $parts = preg_split('/(?<=[;!?\n]|\.(?=\s|$))/u', $text) ?: [$text];
        $out = trim(implode('', array_filter($parts, fn ($p) => !self::mentionsPrice($p))));

        return $out === '' ? null : $out;
    }

    /** ¿Habla de un precio o de un monto de dinero? */
    public static function mentionsPrice(string $text): bool
    {
        $money = '(?:\$|US\$|USD|€|Bs\.?|bol[ií]vares|d[oó]lares|euros)';

        return (bool) preg_match('/\bprecios?\b|\bcuestan?\b|\bvalen?\s+\d|' . $money . '\s?\d|\d[\d.,]*\s?' . $money . '(?![\p{L}])/iu', $text);
    }

    /** Lo mismo en una lista o en un texto dentro de otra estructura (los textos y el guion de la referencia). */
    public static function withoutPricesIn(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::withoutPrices($value);
        }
        if (is_array($value)) {
            $out = array_map([self::class, 'withoutPricesIn'], $value);

            return array_is_list($value) ? array_values(array_filter($out, fn ($v) => $v !== null)) : $out;
        }

        return $value;
    }
}
