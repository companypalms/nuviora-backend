<?php

namespace App\Services\Meta\Videos;

use App\Models\MetaVideoTranscript;
use Illuminate\Support\Facades\DB;

/**
 * Lo que dicen los videos de los creativos ganadores (`.56`), para la IA y las pantallas: el análisis de la mañana, las
 * propuestas de ángulos, los encargos a los editores y el chat. Sale de lo transcrito en la `.55` (solo los ganadores).
 */
final class Scripts
{
    /** Hasta dónde se manda el guion a la IA (un video de 30 a 60 segundos entra entero). */
    public const MAX_CHARS = 700;

    /**
     * El video transcrito más reciente de cada creativo.
     *
     * @param int[] $creativeIds
     * @return array<int, array{hook: ?string, text: ?string}> por id de creativo
     */
    public static function forCreatives(array $creativeIds): array
    {
        $creativeIds = array_values(array_unique(array_filter(array_map('intval', $creativeIds))));
        if ($creativeIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('meta_ads as a')->join('meta_video_transcripts as t', 't.video_id', '=', 'a.video_id')
            ->whereIn('a.meta_creative_ref_id', $creativeIds)->where('t.status', MetaVideoTranscript::DONE)
            ->orderByDesc('t.transcribed_at')->get(['a.meta_creative_ref_id', 't.hook', 't.text']) as $r) {
            $out[(int) $r->meta_creative_ref_id] ??= ['hook' => $r->hook, 'text' => $r->text];
        }

        return $out;
    }

    /**
     * Los ganadores de un producto con su video transcrito, por código de ángulo (los que ganaron hace menos primero).
     *
     * @return array<string, list<array{label: string, hook: ?string, text: ?string}>>
     */
    public static function winnersByAngle(int $productId, int $perAngle = 2): array
    {
        $rows = DB::table('meta_creatives as c')->join('meta_ads as a', 'a.meta_creative_ref_id', '=', 'c.id')
            ->join('meta_video_transcripts as t', 't.video_id', '=', 'a.video_id')
            ->where('c.product_id', $productId)->whereIn('c.status', VideoTranscriber::WINNERS)->whereNotNull('c.angle_code')
            ->where('t.status', MetaVideoTranscript::DONE)
            ->orderByDesc('c.status_changed_at')->orderByDesc('t.transcribed_at')
            ->get(['c.id', 'c.label', 'c.angle_code', 't.hook', 't.text']);
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $code = strtoupper((string) $r->angle_code);
            if (isset($seen[$r->id]) || count($out[$code] ?? []) >= $perAngle) {
                continue;
            }
            $seen[$r->id] = true;
            $out[$code][] = ['label' => (string) ($r->label ?: "Creativo {$r->id}"), 'hook' => $r->hook, 'text' => $r->text];
        }

        return $out;
    }

    /** Para la IA, en una línea: «hook» y el guion (recortado). */
    public static function line(array $s, int $max = self::MAX_CHARS): string
    {
        $parts = [];
        if (!empty($s['hook'])) {
            $parts[] = 'hook «' . trim($s['hook']) . '»';
        }
        if (!empty($s['text'])) {
            $parts[] = 'guion «' . mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $s['text'])), 0, $max, '…') . '»';
        }

        return implode(' · ', $parts);
    }
}
