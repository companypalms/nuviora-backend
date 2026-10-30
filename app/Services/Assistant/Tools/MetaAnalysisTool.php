<?php

namespace App\Services\Assistant\Tools;

use App\Models\MetaAnalysis;
use App\Services\Assistant\Present;

/**
 * El análisis de campañas de la mañana (Etapa 5, paso 3a), como en Meta Ads › Análisis del día: la lista urgente que
 * arman las reglas del dueño, lo que ya marcó y lo que escribió la IA de cada producto. Solo lee (no lo marca como visto).
 */
class MetaAnalysisTool extends MetaTool
{
    private const MAX_ITEMS = 25;

    private const STATUS = [
        'example' => 'Es un ejemplo: en esta PC no hay IA, el texto es una plantilla. La lista sí es de las reglas.',
        'no_ai' => 'La IA no está configurada en el servidor: solo está la lista de las reglas, sin texto de la IA.',
        'error' => 'La IA no pudo escribir el análisis de ese día: solo está la lista de las reglas.',
    ];

    public function name(): string
    {
        return 'analisis_campanas';
    }

    public function description(): string
    {
        return 'El análisis de las campañas de Meta Ads de un día (sale cada mañana a las 7:00, o cuando el dueño toca "Volver a analizar"): la lista de lo más urgente a lo menos urgente que arman las reglas del dueño, con sus montos (bajar, subir o pausar el presupuesto de una ciudad, podar anuncios, el último test, precio, stock, tracking); lo que el dueño ya marcó como hecho o con lo que no estuvo de acuerdo; y lo que escribió la IA de cada producto (diagnóstico, decisiones creativas, mensaje para el editor líder, aprendizaje). Úsala para "cómo van las campañas", "qué hago hoy en Meta", "qué es lo urgente en publicidad".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fecha' => ['type' => ['string', 'null'], 'description' => 'El día, AAAA-MM-DD. null = el análisis más reciente.'],
            ],
            'required' => ['fecha'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando el análisis de campañas…';
    }

    public function run(array $args): ToolResult
    {
        $date = $args['fecha'] ?? null;
        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ToolProblem('La fecha tiene que ser AAAA-MM-DD.');
        }
        $a = MetaAnalysis::with('requester:id,names')->when($date, fn ($q) => $q->where('date', $date))
            ->orderByDesc('date')->orderByDesc('version')->first();
        $link = ['type' => 'link', 'label' => 'Abrir el análisis del día', 'to' => '/meta-ads/analisis'];
        if (!$a) {
            return new ToolResult(['problema' => 'Todavía no hay análisis de campañas' . ($date ? ' de ese día' : '')
                . '. Sale solo cada mañana a las 7:00, o en Meta Ads › Análisis del día con "Volver a analizar".'], [$link]);
        }

        $marks = $a->marks ?? [];
        $items = [];
        $list = [];
        foreach (array_slice($a->urgent ?? [], 0, self::MAX_ITEMS) as $u) {
            $mark = $marks[$u['key']] ?? null;
            $marked = $mark ? ($mark['status'] === 'done' ? 'hecho' : 'no está de acuerdo') . ($mark['note'] ? ': ' . Present::mask($mark['note']) : '') : null;
            $items[] = array_filter([
                'producto' => $u['product'], 'ciudad' => $u['city'], 'que_hacer' => $u['label'], 'detalle' => $u['text'],
                'urgencia_del_producto' => $u['urgency'], 'el_dueno_marco' => $marked,
            ], fn ($v) => $v !== null);
            $list[] = ['text' => ($marked ? '✓ ' : '') . $u['product'] . ($u['city'] ? " · {$u['city']}" : '') . ": {$u['label']}. {$u['text']}",
                'level' => $mark ? 'pending' : self::level($u['kind'])];
        }
        $text = $a->text ?? [];
        $data = array_filter([
            'fecha' => $a->date->format('d/m/Y'),
            'version' => $a->version,
            'hecho' => $a->requested_by ? 'a pedido de ' . ($a->requester->names ?? 'alguien') . ' a las ' . $a->created_at->format('H:i')
                : 'automático, a las ' . $a->created_at->format('H:i'),
            'nota' => self::STATUS[$a->status] ?? null,
            'resumen' => $text['resumen'] ?? null,
            'lista_urgente' => $items ?: 'Nada urgente ese día: todo en mantener o esperar.',
            'puntos_sin_marcar' => count(array_filter($items, fn ($i) => !isset($i['el_dueno_marco']))),
            'productos' => array_map(fn ($p) => array_filter([
                'producto' => $p['producto'] ?? null, 'diagnostico' => $p['diagnostico'] ?? null,
                'decisiones_creativas' => $p['decisiones_creativas'] ?? null, 'mensaje_para_el_editor' => $p['mensaje_editor'] ?? null,
                'aprendizaje' => $p['aprendizaje'] ?? null,
            ]), $text['productos'] ?? []) ?: null,
            'montos_que_la_ia_escribio_y_no_estan_en_los_datos' => $a->warnings ?: null,
        ], fn ($v) => $v !== null);

        $display = $list ? [['type' => 'list', 'title' => 'Lo urgente · ' . $a->date->format('d/m') . ($a->version > 1 ? " (versión {$a->version})" : ''), 'items' => $list]] : [];

        return new ToolResult($data, [...$display, $link]);
    }
}
