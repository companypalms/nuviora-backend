<?php

namespace App\Services\Assistant\Tools;

use App\Models\CreativeBatch;
use App\Services\Meta\Queue\QueueRules;

/**
 * La cola de producción y la banca de creativos (Etapa 5, paso 4), como en Meta Ads › Cola y banca: los encargos con su
 * número, su estado, sus piezas y, de los testeados, el resultado y el aprendizaje. Solo lee: aprobar o mover un encargo
 * se hace en la pantalla.
 */
class MetaQueueTool extends MetaTool
{
    private const FILTERS = [
        'para_aprobar' => ['proposed'], 'en_cola' => QueueRules::QUEUE, 'en_banca' => ['bank'], 'en_test' => ['testing'],
        'testeados' => ['tested'], 'descartados' => ['discarded'],
    ];

    private const MAX = 25;

    public function name(): string
    {
        return 'cola_produccion';
    }

    public function description(): string
    {
        return 'La cola de producción y la banca de creativos de Meta Ads: los encargos (batches con número B#### y sus piezas C####) para aprobar, en la cola de los editores (aprobado, en producción, en revisión), en banca, en test y los testeados con su resultado y su aprendizaje. Se filtra por producto, por estado o por un número (B0123). Úsala para "qué hay en la cola", "qué hay en banca de X", "qué encargos hay para aprobar", "cómo le fue al B0123".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => ['string', 'null'], 'description' => 'Solo los de ese producto. null = todos.'],
                'estado' => ['type' => ['string', 'null'], 'enum' => [...array_keys(self::FILTERS), null],
                    'description' => 'Solo los de ese estado. null = todo menos los descartados.'],
                'numero' => ['type' => ['string', 'null'], 'description' => 'Un batch por su número, por ejemplo "B0123". null = todos.'],
            ],
            'required' => ['producto', 'estado', 'numero'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando la cola de creativos…';
    }

    public function run(array $args): ToolResult
    {
        $q = CreativeBatch::with(['pieces', 'product:id,title,showable_name', 'reference:id,label', 'learningCity:id,name']);
        if (($args['numero'] ?? null) !== null) {
            if (!preg_match('/(\d+)/', $args['numero'], $m)) {
                throw new ToolProblem('El número de un batch es como "B0123".');
            }
            $q->where('number', (int) $m[1]);
        }
        $productId = $this->lookup->productId($args['producto'] ?? null);
        if ($productId !== null) {
            $q->where('product_id', $productId);
        }
        $filter = $args['estado'] ?? null;
        $filter !== null ? $q->whereIn('status', self::FILTERS[$filter] ?? []) : (($args['numero'] ?? null) === null ? $q->where('status', '!=', 'discarded') : null);
        $order = array_flip(array_keys(QueueRules::STATUSES));
        $batches = $q->get()->sortBy(fn ($b) => [$order[$b->status] ?? 9, $b->position ?? PHP_INT_MAX, -$b->id])->values();
        if ($batches->isEmpty()) {
            return new ToolResult(['problema' => ($args['numero'] ?? null) !== null ? "No hay ningún encargo con el número {$args['numero']}." : 'No hay encargos así en la cola.'],
                [['type' => 'link', 'label' => 'Abrir la cola y banca', 'to' => '/meta-ads/cola']]);
        }

        $counts = [];
        foreach ($batches->countBy('status') as $status => $n) {
            $counts[QueueRules::STATUSES[$status]] = $n;
        }
        $data = [
            'cuantos' => $counts,
            'encargos' => $batches->take(self::MAX)->map(fn (CreativeBatch $b) => array_filter([
                'numero' => $b->code() ?? 'sin número (sin aprobar)',
                'producto' => $b->product?->showable_name ?: $b->product?->title,
                'estado' => QueueRules::STATUSES[$b->status] . ($b->status_at ? ' desde el ' . $b->status_at->format('d/m') : ''),
                'propuesto_por' => $b->source === 'rules' ? 'las reglas (redactado por la IA)' : null,
                'tipo' => QueueRules::KINDS[$b->kind] ?? $b->kind,
                'angulo' => $b->angle_code,
                'hipotesis' => $b->hypothesis,
                'referencia' => $b->reference?->label,
                'ciudad' => $b->learningCity?->name,
                'piezas' => $b->pieces->map(fn ($p) => implode(': ', array_filter([$p->code(), trim((string) $p->change)])))->filter()->values()->all() ?: null,
                'plazo' => $b->due_date?->format('d/m'),
                'resultado' => $b->result ? implode('; ', array_map(fn ($v) => ($v['city'] ?? 'Meta') . ": {$v['label']}", $b->result['verdicts'] ?? []))
                    . ' · gasto ' . self::money((float) ($b->result['spend'] ?? 0)) . ', ' . self::purchases((float) ($b->result['purchases'] ?? 0))
                    . (isset($b->result['cpa']) ? ', CPA ' . self::money((float) $b->result['cpa']) : '') : null,
                'aprendizaje' => $b->learning,
                'motivo_del_descarte' => $b->discard_reason,
            ], fn ($v) => $v !== null && $v !== ''))->values()->all(),
        ];
        if ($batches->count() > self::MAX) {
            $data['nota'] = 'Van los primeros ' . self::MAX . ' de ' . $batches->count() . '.';
        }
        $items = $batches->take(self::MAX)->map(fn (CreativeBatch $b) => [
            'text' => ($b->code() ?? 'Sin número') . ' · ' . ($b->product?->showable_name ?: $b->product?->title) . ' · ' . QueueRules::STATUSES[$b->status]
                . ($b->hypothesis ? ': ' . mb_strimwidth($b->hypothesis, 0, 120, '…') : ''),
            'level' => match (true) {
                $b->status === 'tested' => in_array($b->result['verdicts'][0]['key'] ?? null, ['proven', 'promising'], true) ? 'green' : 'red',
                $b->status === 'proposed' => 'yellow',
                default => 'pending',
            },
        ])->all();

        return new ToolResult($data, [['type' => 'list', 'title' => 'Cola y banca de creativos', 'items' => $items],
            ['type' => 'link', 'label' => 'Abrir la cola y banca', 'to' => '/meta-ads/cola']]);
    }
}
