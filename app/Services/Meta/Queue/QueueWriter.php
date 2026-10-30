<?php

namespace App\Services\Meta\Queue;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA que redacta los encargos que proponen las reglas (Etapa 5, paso 4b): la hipótesis, el público, el concepto, qué se
 * conserva, qué cambia en cada pieza y los entregables; y el aprendizaje de los batches que ya se testearon. El tipo, la
 * referencia, la ciudad y cuántos son de las reglas: la IA no los cambia.
 *
 * El tono lo pidió el dueño (2026-10-30): fuerte y agresivo, con promesas claras de resultado. Lo único que no escribe es
 * que el producto cura una enfermedad, ni avales, médicos o estudios que no estén en los datos: los avales reales los
 * pone el dueño en cada producto y se usan tal cual.
 *
 * Como el análisis de la mañana: la Responses API de OpenAI con un JSON estricto y sin guardar nada (store: false). Sin
 * OPENAI_API_KEY se redacta con una plantilla (example).
 */
class QueueWriter
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el director creativo de una tienda online de Venezuela que vende con anuncios de Facebook e Instagram y cobra contra entrega. Escribes los encargos de creativos para los editores de video, en español sencillo y directo.
Recibes los encargos que ya decidieron las reglas del dueño: el producto, el tipo (iteración, concepto nuevo, ángulo nuevo o arriba del embudo), el ángulo, el creativo de referencia con sus textos y lo que dice su video, la ciudad, qué iterar y cuántas piezas lleva. No cambies nada de eso: redáctalo.
El tono es el que pide el dueño: fuerte y agresivo. Hooks que pegan en el primer segundo, promesas claras de resultado y de alivio, urgencia. Nada tibio.
Reglas que no puedes romper:
- Nunca escribas que el producto cura una enfermedad, ni que reemplaza al médico o a las medicinas.
- No inventes médicos, estudios, certificaciones, avales ni testimonios. Usa solo los avales y pruebas reales que vienen en los datos del producto, tal cual.
- No inventes números: precios, descuentos y cantidades solo los que vienen en los datos.
- No cambies hook, concepto, estilo y promesa a la vez: en una iteración se conserva el concepto y la promesa, y cambia lo que dicen los datos (por ejemplo, el hook).
- Sin guion completo: lo arma el editor líder.
Para cada encargo escribe, con su clave:
- hipotesis: qué crees que va a pasar y por qué, en 1 o 2 frases.
- publico: el dolor o el público al que le habla.
- concepto: el concepto en pocas palabras.
- conservar: qué se conserva del creativo de referencia (si no hay referencia, qué no hay que tocar entre las piezas).
- piezas: exactamente la cantidad de piezas pedida; en cada una, qué cambia, en una frase concreta (por ejemplo, el texto del hook).
- entregables: qué entrega el editor (cantidad de videos o imágenes, formato 9:16, duración).
- angulo_codigo y angulo_nombre: solo en un ángulo nuevo, un código de 3 a 6 letras que no esté en la lista de ángulos del producto y su nombre; en los demás, null.
Y para cada batch testeado que venga en los datos, su aprendizaje en 1 o 2 frases (qué funcionó o no, y qué hacer distinto), con su clave.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['encargos', 'aprendizajes'],
        'properties' => [
            'encargos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['clave', 'hipotesis', 'publico', 'concepto', 'conservar', 'piezas', 'entregables', 'angulo_codigo', 'angulo_nombre'],
                'properties' => [
                    'clave' => ['type' => 'string'],
                    'hipotesis' => ['type' => 'string'],
                    'publico' => ['type' => 'string'],
                    'concepto' => ['type' => 'string'],
                    'conservar' => ['type' => 'string'],
                    'piezas' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'entregables' => ['type' => 'string'],
                    'angulo_codigo' => ['type' => ['string', 'null']],
                    'angulo_nombre' => ['type' => ['string', 'null']],
                ],
            ]],
            'aprendizajes' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['clave', 'texto'],
                'properties' => ['clave' => ['type' => 'string'], 'texto' => ['type' => 'string']],
            ]],
        ],
    ];

    private const KINDS = ['iteration' => 'iteración', 'new_concept' => 'concepto nuevo', 'new_angle' => 'ángulo nuevo', 'top_funnel' => 'arriba del embudo'];

    /**
     * Lo que lee la IA: cada encargo con su clave y lo de su producto (avales reales, precio, ángulos), y los testeados.
     *
     * @param list<array> $plan ProposalRules::plan, cada uno con "key", "pieces" y "context" (proof, price, angles,
     *                          reference_texts, reference_video)
     * @param list<array{key: string, product: string, code: string, kind: string, angle: ?string, hypothesis: ?string, verdicts: array, spend: float, purchases: float, cpa: ?float}> $tested
     */
    public static function input(array $plan, array $tested): string
    {
        $lines = [];
        foreach ($plan as $p) {
            $c = $p['context'];
            $lines[] = "== ENCARGO {$p['key']} · {$p['product']} · U{$p['urgency']} · " . (self::KINDS[$p['kind']] ?? $p['kind']) . " · {$p['pieces']} piezas";
            $lines[] = "Por qué: {$p['reason']}";
            $lines[] = 'Qué iterar: ' . $p['iterate'];
            $lines[] = 'Ángulo: ' . ($p['angle_code'] ?? 'nuevo (proponlo)') . (isset($c['angles'][$p['angle_code'] ?? '']) ? ' — ' . $c['angles'][$p['angle_code']] : '');
            if ($p['reference']) {
                $lines[] = "Referencia: {$p['reference']}" . ($p['reference_type'] ? " ({$p['reference_type']})" : '');
                foreach ($c['reference_texts'] ?? [] as $t) {
                    $lines[] = "  · texto del anuncio: {$t}";
                }
                foreach ($c['reference_video'] ?? [] as $t) {
                    $lines[] = "  · video: {$t}";
                }
            }
            $lines[] = 'Ciudad de aprendizaje: ' . ($p['city'] ?? 'sin elegir');
            $lines[] = 'Precio: ' . ($c['price'] ?? 'sin dato');
            $lines[] = 'Avales y pruebas reales: ' . (trim((string) ($c['proof'] ?? '')) !== '' ? trim($c['proof']) : 'ninguno (no inventes)');
            if ($c['angles'] ?? []) {
                $lines[] = 'Ángulos del producto: ' . implode('; ', array_map(fn ($code, $name) => $code . ($name ? " ({$name})" : ''), array_keys($c['angles']), $c['angles']));
            }
            $lines[] = '';
        }
        foreach ($tested as $t) {
            $lines[] = "== TESTEADO {$t['key']} · {$t['product']} · {$t['code']} · " . (self::KINDS[$t['kind']] ?? $t['kind']) . ($t['angle'] ? " · {$t['angle']}" : '');
            if ($t['hypothesis']) {
                $lines[] = "Hipótesis: {$t['hypothesis']}";
            }
            $lines[] = 'Resultado: ' . implode('; ', array_map(fn ($v) => ($v['city'] ?? 'Meta') . ": {$v['label']}", $t['verdicts']))
                . ' · gasto $' . number_format($t['spend'], 2, ',', '.') . ', ' . rtrim(rtrim(number_format($t['purchases'], 1, ',', ''), '0'), ',') . ' compras'
                . ($t['cpa'] !== null ? ', CPA $' . number_format($t['cpa'], 2, ',', '.') : '');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

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
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'encargos_creativos', 'strict' => true, 'schema' => self::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso redactar los encargos.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['encargos'], $data['aprendizajes']) || !is_array($data['encargos']) || !is_array($data['aprendizajes'])) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return ['text' => $data, 'model' => $model,
            'tokens_in' => $response->json('usage.input_tokens'), 'tokens_out' => $response->json('usage.output_tokens')];
    }

    /** Lo mismo con una plantilla (sin IA): en la PC de pruebas o en un servidor sin clave. */
    public static function example(array $plan, array $tested): array
    {
        return [
            'encargos' => array_map(fn ($p) => [
                'clave' => $p['key'],
                'hipotesis' => ucfirst(self::KINDS[$p['kind']] ?? $p['kind']) . ($p['reference'] ? " de {$p['reference']}" : '') . ": {$p['iterate']}.",
                'publico' => '',
                'concepto' => $p['kind'] === 'iteration' ? 'El mismo de la referencia' : '',
                'conservar' => $p['kind'] === 'iteration' ? 'El concepto, la promesa y el cierre de la referencia.' : '',
                'piezas' => array_map(fn ($i) => $p['kind'] === 'iteration' ? "Cambia {$p['iterate']} (versión " . ($i + 1) . ')' : 'Variación ' . ($i + 1), range(0, $p['pieces'] - 1)),
                'entregables' => "{$p['pieces']} " . ($p['format'] === 'image' ? 'imágenes' : 'videos 9:16'),
                'angulo_codigo' => null, 'angulo_nombre' => null,
            ], $plan),
            'aprendizajes' => [],
        ];
    }
}
