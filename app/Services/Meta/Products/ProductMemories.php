<?php

namespace App\Services\Meta\Products;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\Product;
use App\Models\ProductMemory;
use App\Models\ProductNote;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Metrics\Reports\TextCheck;
use RuntimeException;

/**
 * "Lo que sabemos" de cada producto (`.66`), con la base de datos. Las reglas y los números, en ProductMemoryRules.
 * - Los hechos se arman en el momento con los batches testeados, la ficha y las notas del dueño.
 * - El resumen de la IA se guarda con la huella de esos hechos: cada mañana (con el análisis) se vuelve a escribir solo
 *   el de los productos cuyos hechos cambiaron; el dueño también lo puede pedir con "Actualizar el resumen".
 */
class ProductMemories
{
    public function __construct(private ?ProductMemoryWriter $writer = null)
    {
    }

    /** Los hechos de un producto (ProductMemoryRules::facts). */
    public static function facts(int $productId): array
    {
        $names = AdAngle::where('product_id', $productId)->pluck('name', 'code');
        $batches = CreativeBatch::with('persona:id,name')->where('product_id', $productId)->where('status', 'tested')
            ->orderByDesc('tested_at')->orderByDesc('id')->get();
        $rows = $batches->map(fn (CreativeBatch $b) => [
            'code' => $b->code(), 'angle' => $b->angle_code, 'angle_name' => $b->angle_code ? ($names[$b->angle_code] ?? null) : null,
            'persona' => $b->persona?->name, 'style' => $b->style, 'concept' => $b->concept,
            'outcome' => EditorRules::result('tested', $b->result['verdicts'] ?? []), 'learning' => $b->learning,
            'tested_at' => $b->tested_at?->toDateString(), 'id' => $b->id,
        ])->values()->all();
        $notes = ProductNote::where('product_id', $productId)->whereNull('archived_at')->orderBy('id')->get(['id', 'kind', 'text'])
            ->map(fn (ProductNote $n) => ['id' => $n->id, 'kind' => $n->kind, 'text' => $n->text])->all();
        // .81: cada pieza con resultado: qué probó (o el tipo de su encargo) y su formato
        $pieces = \App\Models\CreativeBatchPiece::with('batch:id,product_id,kind')->whereNotNull('result')
            ->whereHas('batch', fn ($q) => $q->where('product_id', $productId))->orderByDesc('result_at')->orderByDesc('id')->get()
            ->map(fn ($p) => ['code' => $p->code(), 'test' => $p->test ?? $p->batch?->kind, 'style' => $p->style, 'outcome' => (string) $p->result])->values()->all();

        return ProductMemoryRules::facts($rows, array_column(TestingPhase::angles($productId), 'code'),
            array_column(ProductProfiles::get($productId)['personas'], 'name'), $notes, $pieces);
    }

    /** Lo que sabemos para la IA que escribe los encargos (vacío si todavía no hay nada). */
    public static function forWriter(int $productId): string
    {
        return ProductMemoryRules::forWriter(self::facts($productId), ProductMemory::where('product_id', $productId)->first()?->summary);
    }

    /**
     * Escribe (o vuelve a escribir) el resumen de un producto si sus hechos cambiaron, o siempre con $force.
     */
    public function refresh(int $productId, bool $force = false): ProductMemory
    {
        $f = self::facts($productId);
        $hash = ProductMemoryRules::hash($f);
        $row = ProductMemory::firstOrNew(['product_id' => $productId]);
        if (!$force && $row->exists && $row->facts_hash === $hash) {
            return $row;
        }
        $summary = ProductMemoryRules::example($f);
        $data = ['status' => app()->environment('local') ? 'example' : 'no_ai', 'model' => null, 'error' => null, 'unknown_numbers' => null];
        if ($f['tested'] === 0 && $f['notes'] === []) {
            $data['status'] = 'empty';
        } elseif (config('services.openai.key')) {
            $product = Product::find($productId, ['title', 'showable_name']);
            $input = ProductMemoryRules::input((string) ($product?->showable_name ?: $product?->title), $f);
            try {
                $ai = ($this->writer ?? new ProductMemoryWriter())->ask($input);
                $summary = $ai['summary'];
                $data = ['status' => 'ok', 'model' => $ai['model'], 'error' => null,
                    'unknown_numbers' => TextCheck::unknown(implode("\n", array_merge($summary['works'], $summary['fails'], $summary['untested'])), $input) ?: null];
            } catch (RuntimeException $e) {
                report($e);
                $data = ['status' => 'error', 'model' => null, 'unknown_numbers' => null,
                    'error' => 'La IA no pudo escribir el resumen: sale con los números. (' . mb_strimwidth($e->getMessage(), 0, 200, '…') . ')'];
            }
        }
        $row->fill($data + ['summary' => $summary, 'facts_hash' => $hash, 'generated_at' => now()])->save();

        return $row;
    }

    /** Cada mañana: el resumen de los productos cuyos hechos cambiaron. @return int cuántos se escribieron */
    public function refreshChanged(): int
    {
        $ids = CreativeBatch::where('status', 'tested')->distinct()->pluck('product_id')
            ->merge(ProductNote::whereNull('archived_at')->distinct()->pluck('product_id'))->map(fn ($v) => (int) $v)->unique();
        $n = 0;
        foreach ($ids as $pid) {
            $before = ProductMemory::where('product_id', $pid)->value('facts_hash');
            if ($this->refresh($pid)->facts_hash !== $before) {
                $n++;
            }
        }

        return $n;
    }

    /** Todo lo de un producto, para la pantalla y el asistente. */
    public static function page(int $productId): array
    {
        $f = self::facts($productId);
        $row = ProductMemory::where('product_id', $productId)->first();
        $notes = ProductNote::with('author:id,names')->where('product_id', $productId)->whereNull('archived_at')->orderByDesc('id')->get()
            ->map(fn (ProductNote $n) => ['id' => $n->id, 'kind' => $n->kind, 'kind_label' => ProductNote::KINDS[$n->kind] ?? $n->kind, 'text' => $n->text,
                'source' => $n->source, 'by' => $n->author?->names, 'at' => $n->created_at?->toIso8601String()])->all();

        return [
            'summary' => $row?->summary, 'status' => $row?->status, 'model' => $row?->model, 'error' => $row?->error,
            'unknown_numbers' => $row?->unknown_numbers ?? [], 'generated_at' => $row?->generated_at?->toIso8601String(),
            // El resumen se escribió con otros datos (hay batches testeados o notas nuevas desde entonces)
            'stale' => $row !== null && $row->facts_hash !== ProductMemoryRules::hash($f),
            'tested' => $f['tested'], 'totals' => $f['totals'], 'groups' => $f['groups'], 'untested' => $f['untested'],
            'piece_groups' => $f['piece_groups'],
            'batches' => $f['batches'], 'notes' => $notes,
        ];
    }
}
