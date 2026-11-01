<?php

namespace App\Services\Meta\Products;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA escribe el resumen de "Lo que sabemos" de un producto (`.66`): lo que funciona, lo que no y lo que falta probar,
 * con los números que contó el sistema (ProductMemoryRules). Como los demás: la Responses API de OpenAI con un JSON
 * estricto y sin guardar nada (store: false). Sin clave (esta PC) o si falla, el resumen sale de los números (example).
 */
class ProductMemoryWriter
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el director creativo de una tienda online de Venezuela que vende con anuncios de Facebook e Instagram. Escribes, para el dueño, el resumen de lo que ya sabemos de un producto después de probar batches de creativos: qué funciona, qué no y qué falta probar, por ángulo, buyer persona, estilo y concepto.
Recibes los números que contó el sistema (cuántos batches ganaron, perdieron o empataron en cada ángulo, buyer persona y estilo), el aprendizaje de cada batch y las notas del dueño.
Reglas:
- En español sencillo, frases cortas. Como mucho 6 puntos en cada lista.
- Cada punto dice qué (un ángulo, un buyer persona, un estilo, un concepto, una forma de abrir el video…) y por qué, con su evidencia tal cual viene en los datos ("ganó 2 de 3 batches"). No inventes números, montos ni porcentajes.
- "funciona": lo que ganó y conviene repetir o iterar. "no_funciona": lo que perdió y conviene no repetir. "falta_probar": lo que todavía no se probó y vale la pena probar, empezando por lo más prometedor.
- Las notas del dueño valen tanto como los datos; si un dato y una nota se contradicen, dilo.
- Con un solo batch no es una regla: dilo con cuidado ("en el único batch que se probó…").
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['funciona', 'no_funciona', 'falta_probar'],
        'properties' => [
            'funciona' => ['type' => 'array', 'items' => ['type' => 'string']],
            'no_funciona' => ['type' => 'array', 'items' => ['type' => 'string']],
            'falta_probar' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];

    /** @return array{summary: array{works: list<string>, fails: list<string>, untested: list<string>}, model: string} */
    public function ask(string $input): array
    {
        $model = (string) config('services.openai.meta_analysis_model', config('services.openai.insights_model', 'gpt-6-luna'));
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(120)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false,
                'reasoning' => ['effort' => 'low'],
                'instructions' => self::INSTRUCTIONS,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'lo_que_sabemos', 'strict' => true, 'schema' => self::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso escribir el resumen.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['funciona'], $data['no_funciona'], $data['falta_probar'])) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }
        $clean = fn ($list) => array_values(array_slice(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : '', (array) $list)), 0, 6));

        return ['summary' => ['works' => $clean($data['funciona']), 'fails' => $clean($data['no_funciona']), 'untested' => $clean($data['falta_probar'])], 'model' => $model];
    }
}
