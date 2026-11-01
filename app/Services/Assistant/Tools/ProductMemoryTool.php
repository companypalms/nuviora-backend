<?php

namespace App\Services\Assistant\Tools;

use App\Models\Product;
use App\Services\Assistant\Lookup;
use App\Services\Meta\Products\ProductMemories;
use App\Services\Meta\Products\ProductMemoryRules;
use App\Services\Meta\Queue\QueueRules;

/**
 * "Lo que sabemos" de un producto (`.66`): el resumen (lo que funciona, lo que no, lo que falta probar), los resultados
 * por ángulo, buyer persona y estilo, y las notas del dueño. Solo lee.
 */
class ProductMemoryTool implements Tool
{
    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'lo_que_sabemos';
    }

    public function description(): string
    {
        return 'Lo que sabemos de un producto después de testear sus batches de creativos: lo que funciona, lo que no y lo que falta probar (por ángulo, buyer persona, estilo y concepto, con cuántos batches ganaron o perdieron), y las notas del dueño. Úsala para "qué sabemos de X", "qué funciona en X", "qué ángulo de X funciona mejor", "qué falta probar en X".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['producto' => ['type' => 'string', 'description' => 'El nombre del producto.']],
            'required' => ['producto'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando lo que sabemos del producto…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->lookup->productId($args['producto'] ?? null) ?? throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál.');
        $product = Product::find($productId, ['id', 'title', 'showable_name']);
        $p = ProductMemories::page($productId);
        $data = ['producto' => $product->showable_name ?: $product->title, 'batches_testeados' => $p['tested']];
        if ($p['summary']) {
            $data['resumen_de_la_ia'] = array_filter(['funciona' => $p['summary']['works'] ?? [], 'no_funciona' => $p['summary']['fails'] ?? [],
                'falta_probar' => $p['summary']['untested'] ?? []]);
            $data['resumen_del'] = $p['generated_at'];
            if ($p['stale']) {
                $data['ojo'] = 'El resumen es de antes de los últimos batches o notas; los números de abajo están al día.';
            }
        }
        foreach (['angle' => 'por_angulo', 'persona' => 'por_buyer_persona', 'style' => 'por_estilo'] as $dim => $key) {
            $rows = array_map(fn ($g) => "{$g['label']}: " . ProductMemoryRules::score($g), $p['groups'][$dim]);
            if ($rows) {
                $data[$key] = $rows;
            }
        }
        $data['falta_probar'] = array_filter(['angulos' => $p['untested']['angle'], 'buyer_personas' => $p['untested']['persona'],
            'estilos' => array_map(fn ($s) => QueueRules::STYLES[$s] ?? $s, $p['untested']['style'])]) ?: null;
        $data['notas_del_dueno'] = array_map(fn ($n) => "{$n['kind_label']}: {$n['text']}", $p['notes']) ?: null;

        return new ToolResult(array_filter($data, fn ($v) => $v !== null),
            [['type' => 'link', 'label' => 'Abrir Lo que sabemos', 'to' => '/meta-ads/lo-que-sabemos?producto=' . $productId]]);
    }
}
