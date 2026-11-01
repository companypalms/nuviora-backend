<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Product;
use App\Models\ProductNote;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;

/** `.66`: anotar algo que sabe el dueño en "Lo que sabemos" de un producto (lo que funciona, lo que no o una idea para probar). */
class ProductNoteTool extends ChangeTool
{
    private const KINDS = ['funciona' => 'works', 'no_funciona' => 'fails', 'idea' => 'idea'];

    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'proponer_nota_producto';
    }

    public function description(): string
    {
        return 'Propone anotar en "Lo que sabemos" de un producto algo que sabe el dueño y el sistema no ve (por ejemplo "con modelo masculino no funciona" o "probar un video con la abuela"). La IA de los encargos lo lee. El dueño ve una tarjeta y la confirma.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'El nombre del producto.'],
                'tipo' => ['type' => 'string', 'enum' => array_keys(self::KINDS), 'description' => 'funciona, no_funciona o idea (para probar).'],
                'texto' => ['type' => 'string', 'description' => 'La nota, corta y clara, con las palabras del dueño.'],
            ],
            'required' => ['producto', 'tipo', 'texto'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la nota…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $productId = $this->lookup->productId($args['producto'] ?? null) ?? throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál.');
        $product = Product::find($productId, ['id', 'title', 'showable_name']);

        return self::proposal($productId, $product->showable_name ?: $product->title, $args);
    }

    /** La tarjeta, sin base de datos (para las pruebas). */
    public static function proposal(int $productId, string $name, array $args): Proposal
    {
        $kind = self::KINDS[$args['tipo'] ?? ''] ?? null;
        $text = trim((string) ($args['texto'] ?? ''));
        if ($kind === null || $text === '') {
            throw new ToolProblem('Falta qué anotar o si es algo que funciona, que no funciona o una idea para probar.');
        }
        if (mb_strlen($text) > 1000) {
            throw new ToolProblem('La nota es muy larga (hasta 1000 letras).');
        }

        return new Proposal(
            "Anotar en lo que sabemos de {$name}",
            "Anotar en lo que sabemos de {$name}: " . mb_strimwidth($text, 0, 120, '…'),
            Proposal::lines([Proposal::line('Producto', $name), Proposal::line('Tipo', ProductNote::KINDS[$kind]), Proposal::line('Nota', $text)]),
            ['product_id' => $productId, 'kind' => $kind, 'text' => $text],
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        ProductNote::create(['product_id' => (int) $payload['product_id'], 'kind' => $payload['kind'], 'text' => $payload['text'],
            'source' => 'assistant', 'created_by' => $context->user->id]);

        return new Outcome('Anotado. La IA lo lee en los encargos de este producto.',
            ['label' => 'Ver lo que sabemos', 'to' => '/meta-ads/lo-que-sabemos?producto=' . (int) $payload['product_id']]);
    }
}
