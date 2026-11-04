<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Product;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Meta\Products\ProductModes;

/**
 * `.99` (dueño, 2026-11-04: "necesito que se pueda pausar un producto yo diciéndole a mi asistente de IA, y que sea mucho
 * más fácil pausarlo y reactivarlo, o colocarlo en liquidación"): cambiar el estado de un producto (activo, en pausa, en
 * liquidación o abandonado). La tarjeta dice qué encargos quedan en espera o vuelven (ProductModes).
 */
class ProductModeTool extends ChangeTool
{
    private const MODES = ['activo' => 'normal', 'pausado' => 'paused', 'liquidacion' => 'liquidation', 'abandonado' => 'abandoned'];

    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'proponer_estado_producto';
    }

    public function description(): string
    {
        return 'Propone pausar, poner en liquidación, abandonar o reactivar un producto en Meta Ads y para los editores. Pausado (sin unidades), en liquidación (le quedan unidades pero no se repone) o abandonado: no se escala, no se piden encargos y sus encargos sin terminar salen de las listas de los editores; activo: vuelve a recibir encargos y esos encargos vuelven. El dueño ve una tarjeta y la confirma.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'El nombre del producto.'],
                'estado' => ['type' => 'string', 'enum' => array_keys(self::MODES), 'description' => 'activo (reactivar), pausado, liquidacion o abandonado.'],
                'motivo' => ['type' => ['string', 'null'], 'description' => 'Por qué, en pocas palabras y con las palabras del dueño (por ejemplo "sin stock del proveedor"). Si no lo dijo, null.'],
            ],
            'required' => ['producto', 'estado', 'motivo'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando el cambio del producto…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $productId = $this->lookup->productId($args['producto'] ?? null) ?? throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál.');
        $product = Product::find($productId, ['id', 'title', 'showable_name']);

        return self::proposal($productId, $product->showable_name ?: $product->title, ProductModes::modeOf($productId), ProductModes::tasks($productId), $args);
    }

    /**
     * La tarjeta, sin base de datos (para las pruebas).
     *
     * @param list<array{code: ?string, status: string, editor: ?string}> $tasks los encargos que son tarea de alguien
     */
    public static function proposal(int $productId, string $name, string $from, array $tasks, array $args): Proposal
    {
        $to = self::MODES[$args['estado'] ?? ''] ?? throw new ToolProblem('Falta el estado: activo, pausado, en liquidación o abandonado.');
        if ($to === $from) {
            throw new ToolProblem("{$name} ya está así (" . ProductModes::LABELS[$to] . '): no hay nada que cambiar.');
        }
        $why = mb_substr(trim((string) ($args['motivo'] ?? '')), 0, 255);
        $label = ProductModes::LABELS[$to];
        $verb = match ($to) {
            'normal' => 'Reactivar',
            'paused' => 'Pausar',
            'liquidation' => 'Poner en liquidación',
            default => 'Abandonar',
        };

        return new Proposal(
            "{$verb} {$name}",
            "{$verb} {$name}: queda «{$label}»" . ($why !== '' ? " ({$why})" : ''),
            Proposal::lines([
                Proposal::line('Producto', $name),
                Proposal::line('Ahora', ProductModes::LABELS[$from] ?? $from),
                Proposal::line('Queda', "{$label}: " . lcfirst(ProductModes::HELP[$to])),
                Proposal::line('Qué pasa', ProductModes::effect($from, $to, $tasks)),
                $why !== '' ? Proposal::line('Motivo', $why) : null,
            ]),
            ['product_id' => $productId, 'mode' => $to] + ($why !== '' ? ['note' => $why] : []),
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $r = ProductModes::set((int) $payload['product_id'], (string) $payload['mode'], $context->user->id, array_intersect_key($payload, ['note' => true]));

        return new Outcome(ProductModes::LABELS[$r['to']] . '. ' . ProductModes::effect($r['from'], $r['to'], $r['tasks']),
            ['label' => 'Ver los productos', 'to' => '/meta-ads/productos']);
    }
}
