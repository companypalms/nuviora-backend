<?php

namespace App\Services\Meta\Videos;

use Carbon\CarbonInterface;

/**
 * Las reglas de la transcripción de los videos ganadores (`.55`). PHP puro.
 * - El hook es lo que se dice en los primeros 3 segundos: los tramos que empiezan antes del segundo 3. Si OpenAI no da
 *   tramos con tiempos, la primera frase (hasta 15 palabras).
 * - Un video que falló se vuelve a intentar al día siguiente, hasta 3 veces. Uno que Meta no deja bajar se vuelve a
 *   intentar a la semana (puede cambiar el permiso). Uno muy grande no se reintenta.
 */
final class TranscriptRules
{
    public const HOOK_SECONDS = 3.0;

    public const MAX_ATTEMPTS = 3;

    /** OpenAI acepta archivos de hasta 25 MB. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    private const HOOK_WORDS = 15;

    /** @param array<int, array{start: float|int, end?: float|int, text: string}> $segments */
    public static function hook(array $segments, string $text): ?string
    {
        $parts = [];
        foreach ($segments as $s) {
            if ((float) ($s['start'] ?? 99) < self::HOOK_SECONDS && trim((string) ($s['text'] ?? '')) !== '') {
                $parts[] = trim((string) $s['text']);
            }
        }
        if ($parts !== []) {
            return implode(' ', $parts);
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }
        $first = preg_split('/(?<=[.!?¡¿])\s+/u', $text)[0];
        $words = preg_split('/\s+/u', $first);

        return count($words) > self::HOOK_WORDS ? implode(' ', array_slice($words, 0, self::HOOK_WORDS)) . '…' : $first;
    }

    /** ¿Se intenta (otra vez) este video hoy? null = nunca se intentó. */
    public static function due(?string $status, int $attempts, ?CarbonInterface $attemptedAt, CarbonInterface $now): bool
    {
        return match ($status) {
            null => true,
            'failed' => $attempts < self::MAX_ATTEMPTS && ($attemptedAt === null || $attemptedAt->lt($now->copy()->subHours(20))),
            'no_access' => $attemptedAt === null || $attemptedAt->lt($now->copy()->subDays(7)),
            default => false, // done, too_big
        };
    }
}
