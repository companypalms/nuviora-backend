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

    /**
     * El encargo en texto, para mandárselo al editor (botón "Copiar").
     *
     * @param array $b el encargo como lo arma la pantalla (MetaQueueController::present)
     */
    public static function brief(array $b): string
    {
        $lines = ['ENCARGO ' . ($b['code'] ?? '(sin aprobar)') . " · {$b['product']}" . ($b['urgency'] ? " · U{$b['urgency']}" : '')];
        $kind = self::KINDS[$b['kind']] ?? $b['kind'];
        $lines[] = "Tipo: {$kind}" . ($b['reference'] ? " (referencia: {$b['reference']})" : '');
        if ($b['angle_code']) {
            $lines[] = "Ángulo: {$b['angle_code']}" . ($b['angle_name'] ? " ({$b['angle_name']})" : '');
        }
        foreach (['concept' => 'Concepto', 'hypothesis' => 'Hipótesis', 'audience' => 'Dolor o público', 'city' => 'Ciudad de aprendizaje',
            'offer' => 'Oferta', 'keep' => 'Conservar'] as $key => $label) {
            if (($b[$key] ?? null) !== null && trim((string) $b[$key]) !== '') {
                $lines[] = "{$label}: " . trim((string) $b[$key]);
            }
        }
        if ($b['format']) {
            $lines[] = 'Formato: ' . (self::FORMATS[$b['format']] ?? $b['format']);
        }
        if ($b['pieces']) {
            $lines[] = 'Piezas:';
            foreach ($b['pieces'] as $i => $p) {
                $lines[] = ($i + 1) . '. ' . ($p['code'] ? "{$p['code']} — " : '') . (trim((string) $p['change']) !== '' ? trim((string) $p['change']) : 'sin detalle')
                    . ($p['format'] && $p['format'] !== $b['format'] ? ' (' . mb_strtolower(self::FORMATS[$p['format']] ?? $p['format']) . ')' : '');
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
        if (trim((string) ($b['deliverables'] ?? '')) !== '') {
            $lines[] = 'Entregables: ' . trim((string) $b['deliverables']);
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
}
