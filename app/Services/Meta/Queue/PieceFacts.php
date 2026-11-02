<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\MetaCreative;
use App\Services\Meta\Analysis\AnalysisSender;

/**
 * `.81`: lo que mira el sistema para elegir los formatos de un ganador sano (PieceRules::pickFormats). Solo lee.
 * - Cómo le fue a cada formato en cada producto: los batches testeados (su estilo, o "imagen" si eran de imagen) y las
 *   piezas de otro formato con su resultado.
 * - Lo que no se repite: el formato del creativo original y los de los encargos en camino de ese producto.
 */
final class PieceFacts
{
    /** @return array<int, array<string, array{won: int, lost: int, tie: int, total: int}>> por producto y formato */
    public static function formatStats(): array
    {
        $out = [];
        $add = function (int $pid, ?string $style, string $outcome) use (&$out) {
            if ($style === null || $style === '' || $style === 'otro') {
                return;
            }
            $out[$pid][$style] ??= ['won' => 0, 'lost' => 0, 'tie' => 0, 'total' => 0];
            $out[$pid][$style][match ($outcome) { 'Ganó' => 'won', 'Perdió' => 'lost', default => 'tie' }]++;
            $out[$pid][$style]['total']++;
        };
        foreach (CreativeBatch::where('status', 'tested')->get(['id', 'product_id', 'style', 'format', 'result']) as $b) {
            $outcome = EditorRules::result('tested', $b->result['verdicts'] ?? []);
            $add((int) $b->product_id, $b->style, $outcome);
            if ($b->format === 'image') {
                $add((int) $b->product_id, 'imagen', $outcome);
            }
        }
        foreach (CreativeBatchPiece::with('batch:id,product_id')->whereNotNull('result')->whereNotNull('style')->get(['id', 'creative_batch_id', 'style', 'result']) as $p) {
            if ($p->batch) {
                $add((int) $p->batch->product_id, $p->style, (string) $p->result);
            }
        }

        return $out;
    }

    /** Lo mismo de todos los demás productos, sumado. */
    public static function others(array $stats, int $productId): array
    {
        $out = [];
        foreach ($stats as $pid => $styles) {
            if ((int) $pid === $productId) {
                continue;
            }
            foreach ($styles as $s => $c) {
                $out[$s] ??= ['won' => 0, 'lost' => 0, 'tie' => 0, 'total' => 0];
                foreach ($c as $k => $v) {
                    $out[$s][$k] += $v;
                }
            }
        }

        return $out;
    }

    /**
     * Los formatos que no se repiten en este producto: el del creativo original (si es imagen o si salió de un encargo con
     * estilo) y los de los encargos que ya están en camino o en la banca.
     *
     * @return list<string>
     */
    public static function exclude(int $productId, ?int $referenceCreativeId): array
    {
        $out = [];
        if ($referenceCreativeId !== null && ($cr = MetaCreative::find($referenceCreativeId, ['id', 'type', 'batch_code', 'creative_code']))) {
            if ($cr->type === 'image') {
                $out[] = 'imagen';
            }
            if (preg_match('/^B(\d+)$/i', (string) $cr->batch_code, $m)) {
                $out[] = CreativeBatch::where('number', (int) $m[1])->value('style');
            }
            if (preg_match('/^C(\d+)$/i', (string) $cr->creative_code, $m)) {
                $out[] = CreativeBatchPiece::where('number', (int) $m[1])->value('style');
            }
        }
        $open = CreativeBatch::with('pieces:id,creative_batch_id,style,format')->where('product_id', $productId)
            ->whereIn('status', [...AnalysisSender::OPEN, 'proposed'])->get(['id', 'style', 'format']);
        foreach ($open as $b) {
            $out[] = $b->style;
            if ($b->format === 'image') {
                $out[] = 'imagen';
            }
            foreach ($b->pieces as $p) {
                $out[] = $p->style;
            }
        }

        return array_values(array_unique(array_filter($out)));
    }
}
