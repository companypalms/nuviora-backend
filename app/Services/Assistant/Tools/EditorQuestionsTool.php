<?php

namespace App\Services\Assistant\Tools;

use App\Models\HelpQuestion;

/**
 * Lo que los editores le preguntaron a su ayuda "¿Dudas?" (.96): quién, cuándo, qué preguntó y qué le respondió. Sirve
 * para ver qué no se entiende en sus pantallas y si alguien pregunta lo que no debe.
 */
class EditorQuestionsTool implements Tool
{
    private const MAX = 60;

    public function name(): string
    {
        return 'preguntas_de_editores';
    }

    public function description(): string
    {
        return 'Lo que los editores (el líder y los de video) le preguntaron a su ayuda «¿Dudas?» (el chat que les explica cómo usar sus pantallas): quién, cuándo, la pregunta y la respuesta, de las más nuevas a las más viejas. Úsala para "qué preguntaron los editores", "qué no entienden", "alguien preguntó algo raro".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dias' => ['type' => ['integer', 'null'], 'description' => 'Los últimos N días (1 a 90). null = los últimos 7.'],
            ],
            'required' => ['dias'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando lo que preguntaron los editores…';
    }

    public function run(array $args): ToolResult
    {
        $days = max(1, min(90, (int) ($args['dias'] ?? 7)));
        $rows = HelpQuestion::with('user:id,names,surnames')->where('created_at', '>=', today()->subDays($days - 1))
            ->orderByDesc('id')->limit(self::MAX + 1)->get();
        if ($rows->isEmpty()) {
            return new ToolResult(['resumen' => "Ningún editor usó «¿Dudas?» en los últimos {$days} días."]);
        }
        $who = fn (HelpQuestion $q) => trim(($q->user?->names ?? 'Alguien') . ' ' . ($q->user?->surnames ?? ''));
        $shown = $rows->take(self::MAX);

        return new ToolResult(array_filter([
            'dias' => $days,
            'cuantas' => $shown->count() . ($rows->count() > self::MAX ? ' (las más nuevas; hay más)' : ''),
            'preguntas' => $shown->map(fn (HelpQuestion $q) => array_filter([
                'cuando' => $q->created_at?->format('Y-m-d H:i'),
                'quien' => $who($q),
                'rol' => $q->book === 'lider' ? 'editor líder' : 'editor de video',
                'pregunta' => $q->question,
                'respuesta' => $q->status === HelpQuestion::DONE ? mb_strimwidth((string) $q->answer, 0, 400, '…') : null,
                'fallo' => $q->status === HelpQuestion::FAILED ? 'la IA no pudo responder' : null,
            ]))->values()->all(),
        ]), [
            ['type' => 'list', 'title' => 'Lo que preguntaron los editores', 'items' => $shown->take(30)->map(fn (HelpQuestion $q) => [
                'text' => $who($q) . ' (' . $q->created_at?->format('d/m H:i') . '): ' . mb_strimwidth($q->question, 0, 160, '…'),
            ])->values()->all()],
        ]);
    }
}
