<?php

namespace App\Services\Assistant\Tools;

use App\Models\Product;
use App\Services\Assistant\Lookup;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\ProductProfiles;

/**
 * La ficha de un producto como está guardada (`.64`): para contestar "qué dice la ficha de X" y para cambiar una parte
 * sin perder lo demás (proponer_ficha_producto reemplaza el campo completo).
 */
class ProductProfileReadTool implements Tool
{
    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'ficha_producto';
    }

    public function description(): string
    {
        return 'La ficha de un producto como está guardada: qué es, para qué sirve, cómo funciona, lo que NO es, la información clave, el link de las fotos, sus buyer personas y lo que le falta para estar completa. Úsala para "qué dice la ficha de X" y antes de cambiar una parte de una ficha que ya existe.';
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
        return 'Leyendo la ficha del producto…';
    }

    public function run(array $args): ToolResult
    {
        $productId = $this->lookup->productId($args['producto'] ?? null) ?? throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál.');
        $product = Product::find($productId, ['id', 'title', 'showable_name']);
        $f = ProductProfiles::get($productId);
        $missing = ProductProfileRules::missing($f['profile'], $f['personas']);
        $data = ['producto' => $product->showable_name ?: $product->title];
        if ($f['profile'] === null && $f['personas'] === []) {
            $data['ficha'] = 'Todavía no tiene ficha. Se la puedes llenar con lo que te pase el dueño (proponer_ficha_producto).';
        } else {
            foreach (ProductProfileRules::FIELDS as $k => $label) {
                $data[$label] = $f['profile'][$k] ?? 'vacío';
            }
            $data['buyer_personas'] = array_map(fn ($p) => ['nombre' => $p['name']] + array_filter([
                'quien_es' => $p['who'], 'que_le_duele' => $p['pain'], 'que_quiere' => $p['wants'], 'que_lo_frena' => $p['objections'],
            ], fn ($v) => $v !== null), $f['personas']) ?: 'ninguno';
        }
        $data['completa'] = $missing === [] ? 'sí' : 'no, falta: ' . implode(', ', $missing);

        return new ToolResult($data, [['type' => 'link', 'label' => 'Abrir la ficha', 'to' => '/admin/integraciones?seccion=meta&tab=profile&producto=' . $productId]]);
    }
}
