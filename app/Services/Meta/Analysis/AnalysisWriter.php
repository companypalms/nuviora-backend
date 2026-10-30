<?php

namespace App\Services\Meta\Analysis;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA que escribe el análisis de la mañana (Etapa 5, paso 3a), como los documentos del dueño y con el formato de la
 * sección 11 de su manual: el diagnóstico por producto y ciudad, las decisiones creativas, el borrador del mensaje al editor
 * líder (que no se envía) y el aprendizaje. Recibe solo lo que dijeron sus reglas: no decide montos ni acciones.
 *
 * Como el análisis de cancelaciones: la Responses API de OpenAI con un JSON estricto y sin guardar nada (store: false).
 * Sin OPENAI_API_KEY arma el mismo formato con una plantilla (example).
 */
class AnalysisWriter
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el analista de Meta Ads del dueño de una tienda online de Venezuela que vende con anuncios de Facebook e Instagram y cobra contra entrega. Cada mañana escribes su análisis de campañas, en español sencillo, como lo haría él.
Recibes, por producto, lo que dijeron SUS reglas con los 3 días cerrados: la urgencia (U1 a U5), qué hacer en cada ciudad con el monto, el veredicto de cada creativo (sin datos, en evaluación, prometedor, PROVEN, intermedio, perdedor, ganador que empezó a fallar y qué cambió), el diagnóstico de los videos, el trabajo siguiente, el precio y las alarmas.
Reglas que no puedes romper:
- No inventes números: usa solo los montos, porcentajes y cantidades que aparecen en los datos, escritos igual. Si no está en los datos, no lo escribas.
- No cambies las acciones de las reglas: explícalas. Si una ciudad dice "Subir $30,00 → $36,00", no propongas otro monto.
- El sistema no toca Meta: las acciones las hace el dueño. Nunca digas que algo ya se cambió.
- Nunca presupuesto y creativos el mismo día.
- En el mensaje al editor líder: sin guion completo (el editor líder lo arma). El tono de los creativos es fuerte y agresivo, con promesas claras de resultado; nunca escribas que el producto cura una enfermedad ni inventes avales, médicos o estudios.
Para cada producto escribe:
- diagnostico: qué está pasando y por qué, en 2 a 4 frases (como los documentos del dueño).
- ciudades: por cada ciudad que aparece en los datos, una lectura de 1 o 2 frases con la acción.
- decisiones_creativas: la lista de decisiones sobre los creativos, de la más importante a la menos (apagar, mantener sin tocar, iterar y qué cambiar), con su nombre. Ordena los creativos como P1, P2, P3… según lo que aportan.
- mensaje_editor: el borrador del mensaje al editor líder con lo que hay que producir hoy: cuántos batches, de qué tipo (iteraciones, concepto nuevo o ángulo nuevo), el ángulo, qué se conserva y qué cambia en cada una de las 3 piezas (por ejemplo 3 hooks distintos). Si el producto no necesita creativos, dilo en una frase.
- aprendizaje: lo que deja el día, en 1 o 2 frases.
Y arriba, resumen: 3 a 5 frases con lo más importante del día, de lo más urgente a lo menos.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['resumen', 'productos'],
        'properties' => [
            'resumen' => ['type' => 'string'],
            'productos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['producto', 'diagnostico', 'ciudades', 'decisiones_creativas', 'mensaje_editor', 'aprendizaje'],
                'properties' => [
                    'producto' => ['type' => 'string'],
                    'diagnostico' => ['type' => 'string'],
                    'ciudades' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['ciudad', 'lectura'],
                        'properties' => ['ciudad' => ['type' => 'string'], 'lectura' => ['type' => 'string']],
                    ]],
                    'decisiones_creativas' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mensaje_editor' => ['type' => 'string'],
                    'aprendizaje' => ['type' => 'string'],
                ],
            ]],
        ],
    ];

    /**
     * Le pregunta a la IA. Público para probarlo con una respuesta falsa.
     *
     * @return array{text: array, model: string, tokens_in: ?int, tokens_out: ?int}
     */
    public function ask(string $input): array
    {
        $model = (string) config('services.openai.meta_analysis_model', config('services.openai.insights_model', 'gpt-6-luna'));
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(150)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false,
                'reasoning' => ['effort' => 'low'],
                'instructions' => self::INSTRUCTIONS,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'analisis_campanas', 'strict' => true, 'schema' => self::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso escribir el análisis.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['resumen'], $data['productos']) || !is_array($data['productos'])) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return ['text' => $data, 'model' => $model,
            'tokens_in' => $response->json('usage.input_tokens'), 'tokens_out' => $response->json('usage.output_tokens')];
    }

    /** El mismo formato, armado con las reglas (sin IA): para la PC de pruebas. */
    public static function example(array $facts, array $urgent): array
    {
        $top = array_slice($urgent, 0, 3);
        $resumen = 'Ejemplo (sin IA en esta PC). ' . count($facts) . ' ' . (count($facts) === 1 ? 'producto' : 'productos') . ' con campañas. '
            . ($top ? 'Lo más urgente: ' . implode('; ', array_map(fn ($i) => "{$i['product']}" . ($i['city'] ? " en {$i['city']}" : '') . ": {$i['label']}", $top)) . '.' : 'Nada urgente.');
        $productos = [];
        foreach ($facts as $p) {
            $u = $p['urgency'];
            $productos[] = [
                'producto' => $p['product'],
                'diagnostico' => ($u ? "{$u['label']}." : '') . ($u && $u['reasons'] ? ' ' . implode('. ', $u['reasons']) . '.' : ''),
                'ciudades' => array_map(fn ($c) => ['ciudad' => (string) $c['city'], 'lectura' => trim($c['action']['label'] . ': ' . $c['action']['reading'] . ' ' . ($c['action']['proposal'] ?? ''))], $p['cities']),
                'decisiones_creativas' => array_map(fn ($cr, $i) => 'P' . ($i + 1) . " {$cr['label']}: {$cr['verdict']}" . ($cr['proposal'] ? " → {$cr['proposal']}" : ''),
                    $p['creatives'], array_keys($p['creatives'])),
                'mensaje_editor' => 'Hola. Para ' . $p['product'] . ': ' . ($u ? "batches a pedir: {$u['batches']}. " : '') . implode(' ', $p['next_work']),
                'aprendizaje' => 'Ejemplo: en el sistema real la IA escribe aquí lo que deja el día.',
            ];
        }

        return ['resumen' => $resumen, 'productos' => $productos];
    }
}
