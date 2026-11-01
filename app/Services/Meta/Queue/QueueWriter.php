<?php

namespace App\Services\Meta\Queue;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA que redacta los encargos que proponen las reglas (Etapa 5, paso 4b) y el aprendizaje de los batches que ya se
 * testearon. El tipo, la referencia, la ciudad y cuántos son de las reglas: la IA no los cambia.
 *
 * `.63` (pedido del dueño del 2026-11-01): la IA no le dicta al editor el creativo exacto, le explica qué se busca: lo
 * que buscamos, lo que funcionó (no se toca), lo que hay que mejorar e ideas para cada versión (el editor decide cómo).
 * Y escribe para el editor: nada de números del negocio, reglas ni prioridades (el proceso del dueño no se le cuenta). La
 * hipótesis sigue, solo para el dueño.
 *
 * El tono de los anuncios lo pidió el dueño (2026-10-30): fuerte y agresivo, con promesas claras de resultado. Lo único
 * que no se escribe es que el producto cura una enfermedad, ni avales, médicos o estudios que no estén en los datos.
 *
 * Como el análisis de la mañana: la Responses API de OpenAI con un JSON estricto y sin guardar nada (store: false). Sin
 * OPENAI_API_KEY se redacta con una plantilla (example).
 */
class QueueWriter
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el director creativo de una tienda online de Venezuela que vende con anuncios de Facebook e Instagram y cobra contra entrega. Escribes los encargos de creativos para los editores de video, en español sencillo, claro y directo, como si le hablaras al editor.
Recibes los encargos que ya decidieron las reglas del dueño: el producto, el tipo (iteración, concepto nuevo, ángulo nuevo o arriba del embudo), el ángulo, el creativo de referencia con sus textos, lo que dice su video y cómo le fue a cada parte del video, y cuántas versiones lleva. No cambies nada de eso: redáctalo.
Cada encargo trae la FICHA DEL PRODUCTO (qué es, para qué sirve, cómo funciona, lo que NO es, la información clave y sus buyer personas). Léela primero: todo lo que escribas tiene que ser fiel a la ficha. Nunca contradigas "lo que NO es" y no le agregues usos ni beneficios que no estén en la ficha.
Los encargos que dicen "Testeo" son la primera prueba de un producto: se prueban sus ángulos principales, sin creativo de referencia. Explícale al editor la idea del ángulo para su buyer persona. Si un ángulo lleva más de un batch en el testeo, cada uno prueba una hipótesis distinta: otro concepto u otro formato (por ejemplo: video animado, testimonio, venta directa, demostración). Dilo claro en "probando" y en "concepto", y nunca hagas dos iguales.
Tu trabajo NO es decirle al editor qué video hacer: es explicarle qué estamos buscando, qué funcionó y qué hay que mejorar, y darle ideas. El editor es el creativo y decide cómo hacerlo.
Así se escribe una iteración (ejemplo): si el hook funcionó y la gente se va en la parte del medio, el objetivo es "3 versiones bien distintas entre sí, con el mismo hook, que hagan que la gente se quede hasta el final"; lo que funcionó es "el hook: engancha muy bien en los primeros segundos, no lo cambies"; lo que hay que mejorar es "mucha gente se va en la parte del medio del video"; y las ideas son "una versión más corta", "otra que cambie por completo la parte del medio", "otra con un medio más dinámico".
Cómo le fue al video, en palabras para el editor: "hook" es si engancha en los primeros segundos; "retención" es si la gente se queda viendo; "se van al principio / en el medio / al final" dice dónde abandonan; "clic" es si la gente toca el anuncio; "compra" es si los que llegan compran (si engancha y hacen clic pero no compran, puede ser la landing, no el video).
Lo que el editor NUNCA debe leer (es información interna del dueño): números de ventas, compras, gasto, CPA, costos, precios de costo o ganancias; prioridades (U1, U2…), urgencias o reglas; ciudades de prueba; porcentajes de rendimiento. Describe cómo le fue al video solo con palabras (engancha bien, la gente se va en el medio…).
Reglas del contenido de los anuncios que no puedes romper:
- El tono que pide el dueño para los anuncios: fuerte y agresivo. Hooks que pegan en el primer segundo, promesas claras de resultado y de alivio, urgencia. Nada tibio.
- Nunca escribas que el producto cura una enfermedad, ni que reemplaza al médico o a las medicinas.
- No inventes médicos, estudios, certificaciones, avales ni testimonios. Usa solo los avales y pruebas reales que vienen en los datos del producto, tal cual.
- No inventes números: precios, descuentos y cantidades solo los que vienen en los datos.
- No supongas para qué sirve un producto por su nombre (un "chaleco térmico" no es para el frío, por ejemplo). Qué es, para qué sirve y a quién le habla sale solo de la ficha del producto, de sus ángulos y de los textos de la referencia.
- En una iteración se conserva lo que funcionó y se cambia lo que hay que mejorar; las versiones tienen que ser bien distintas entre sí (no tres variaciones de lo mismo).
- Si la referencia trae "lo que dice su video", apóyate en eso para explicar lo que funcionó (su promesa, su frase fuerte). No copies el guion entero ni escribas guiones.
Para cada encargo escribe, con su clave:
- hipotesis: SOLO PARA EL DUEÑO (el editor no la ve): qué crees que va a pasar y por qué, en 1 o 2 frases. Aquí sí puedes nombrar lo que dicen los datos.
- probando: la misma hipótesis contada PARA EL EDITOR, en 1 o 2 frases: qué idea estamos probando y por qué creemos que puede funcionar, en términos del cliente (qué siente, qué le pasa, qué lo detiene) y del video. Le da contexto para que haga bien su trabajo. Sin números, sin métricas, sin ciudades, sin reglas ni cómo decide el dueño. Ejemplo: "Probamos si abrir mostrando el problema que vive el cliente en su día a día, antes de mostrar el producto, engancha a gente que todavía no está buscando una solución."
- objetivo: lo que buscamos con este encargo, en 1 o 2 frases para el editor.
- conservar: lo que funcionó y no hay que cambiar (en una iteración); si no hay referencia, qué tienen que tener en común todas las versiones. Vacío si no aplica.
- mejorar: lo que hay que mejorar respecto a la referencia, en palabras (en una iteración); vacío en un concepto o ángulo nuevo.
- publico: el dolor o el público al que le habla.
- buyer: el nombre exacto de UNO de los buyer personas de la ficha al que le habla este encargo (el que mejor encaje con el ángulo y la referencia). El editor solo conoce ese. Si el encargo trae "Buyer persona del ángulo", usa ese. null si la ficha no trae buyer personas.
- concepto: el concepto en pocas palabras.
- ideas: exactamente la cantidad de versiones pedida; una idea por versión, como sugerencia ("una versión más corta", "otra que…"), no como orden ni guion.
- entregables: qué entrega el editor (cantidad de videos o imágenes, formato 9:16, duración aproximada).
- angulo_codigo y angulo_nombre: solo en un ángulo nuevo cuyo ángulo dice "nuevo (proponlo)", un código de 3 a 6 letras que no esté en la lista de ángulos del producto y su nombre; en los demás, null.
Y para cada batch testeado que venga en los datos, su aprendizaje en 1 o 2 frases (qué funcionó o no, y qué hacer distinto), con su clave. El aprendizaje es para el dueño.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['encargos', 'aprendizajes'],
        'properties' => [
            'encargos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['clave', 'hipotesis', 'probando', 'objetivo', 'conservar', 'mejorar', 'publico', 'buyer', 'concepto', 'ideas', 'entregables', 'angulo_codigo', 'angulo_nombre'],
                'properties' => [
                    'clave' => ['type' => 'string'],
                    'hipotesis' => ['type' => 'string'],
                    'probando' => ['type' => 'string'],
                    'objetivo' => ['type' => 'string'],
                    'conservar' => ['type' => 'string'],
                    'mejorar' => ['type' => 'string'],
                    'publico' => ['type' => 'string'],
                    'buyer' => ['type' => ['string', 'null']],
                    'concepto' => ['type' => 'string'],
                    'ideas' => ['type' => 'array', 'items' => ['type' => 'string']],
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
            $lines[] = "== ENCARGO {$p['key']} · {$p['product']} · " . ($p['urgency'] !== null ? "U{$p['urgency']}" : 'Testeo') . ' · '
                . (self::KINDS[$p['kind']] ?? $p['kind']) . " · {$p['pieces']} versiones";
            $lines[] = "Por qué: {$p['reason']}";
            $lines[] = 'Qué iterar: ' . $p['iterate'];
            // .65: el testeo: cuántos batches lleva este ángulo y qué conceptos ya tienen los otros
            if ($t = $p['testing'] ?? null) {
                $lines[] = "Testeo (ronda {$t['round']}): batch {$t['n']} de {$t['of']} de este ángulo."
                    . ($t['of'] > 1 ? ' Cada batch de este ángulo prueba una hipótesis distinta: otro concepto u otro formato.' : '');
                foreach ($t['siblings'] ?? [] as $s) {
                    $lines[] = "  · ya hay otro batch de este ángulo con el concepto «{$s}»: este tiene que ser distinto";
                }
                if (!empty($t['persona'])) {
                    $lines[] = "Buyer persona del ángulo: {$t['persona']}";
                }
            }
            $lines[] = 'Ángulo: ' . ($p['angle_code'] ?? 'nuevo (proponlo)') . (isset($c['angles'][$p['angle_code'] ?? '']) ? ' — ' . $c['angles'][$p['angle_code']] : '');
            if ($p['reference']) {
                $lines[] = "Referencia: {$p['reference']}" . ($p['reference_type'] ? " ({$p['reference_type']})" : '');
                foreach ($c['reference_texts'] ?? [] as $t) {
                    $lines[] = "  · texto del anuncio: {$t}";
                }
                foreach ($c['reference_video'] ?? [] as $t) {
                    $lines[] = "  · video: {$t}";
                }
                // .63: cómo le fue a cada parte, en palabras (para explicarle al editor qué funcionó y qué mejorar)
                if ($words = self::videoWords($c['reference_grades'] ?? [], $c['reference_leaves'] ?? null)) {
                    $lines[] = "  · cómo le fue, en palabras: {$words}";
                }
                if (!empty($c['reference_script'])) {
                    $lines[] = '  · lo que dice su video: ' . \App\Services\Meta\Videos\Scripts::line($c['reference_script']);
                }
            }
            $lines[] = 'Ciudad de aprendizaje: ' . ($p['city'] ?? 'sin elegir');
            $lines[] = 'Precio: ' . ($c['price'] ?? 'sin dato');
            $lines[] = 'Avales y pruebas reales: ' . (trim((string) ($c['proof'] ?? '')) !== '' ? trim($c['proof']) : 'ninguno (no inventes)');
            // .64: la ficha del producto (qué es, para qué sirve, cómo funciona, lo que NO es, sus buyer personas)
            $lines[] = 'Ficha del producto:';
            $lines[] = trim((string) ($c['profile'] ?? '')) !== '' ? $c['profile'] : '  · (sin ficha)';
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

    private const GRADE_WORDS = [
        'hook' => ['good' => 'el hook engancha muy bien', 'ok' => 'el hook engancha', 'weak' => 'el hook no engancha'],
        'hold' => ['good' => 'la gente se queda viendo', 'ok' => 'la retención es normal', 'weak' => 'la gente no se queda viendo'],
        'ctr' => ['good' => 'mucha gente toca el anuncio', 'ok' => 'la gente toca el anuncio', 'weak' => 'poca gente toca el anuncio'],
        'conversion' => ['good' => 'los que llegan compran', 'weak' => 'los que llegan compran poco'],
    ];

    private const LEAVES_WORDS = ['opening' => 'se van al principio', 'body' => 'se van en la parte del medio', 'end' => 'se van al final'];

    /** `.63`: cómo le fue al video, en palabras, sin números: "el hook engancha muy bien; la gente no se queda viendo; se van en la parte del medio". */
    public static function videoWords(array $grades, ?string $leaves): string
    {
        $out = [];
        foreach (self::GRADE_WORDS as $key => $words) {
            if (isset($grades[$key], $words[$grades[$key]])) {
                $out[] = $words[$grades[$key]];
            }
        }
        if ($leaves !== null && isset(self::LEAVES_WORDS[$leaves])) {
            $out[] = self::LEAVES_WORDS[$leaves];
        }

        return implode('; ', $out);
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
                'probando' => $p['kind'] === 'iteration'
                    ? 'Probamos si mejorar ' . $p['iterate'] . ', sin tocar lo que ya funciona, hace que el video rinda todavía mejor.'
                    : 'Probamos una idea nueva para este producto, para ver si conecta con el cliente.',
                'objetivo' => $p['kind'] === 'iteration'
                    ? "{$p['pieces']} versiones bien distintas entre sí que mejoren {$p['iterate']}, conservando lo que ya funciona de la referencia."
                    : "{$p['pieces']} versiones bien distintas entre sí para probar este " . (self::KINDS[$p['kind']] ?? $p['kind']) . '.',
                'conservar' => $p['kind'] === 'iteration' ? 'El concepto, la promesa y el cierre de la referencia.' : '',
                'mejorar' => $p['kind'] === 'iteration' ? ucfirst($p['iterate']) . '.' : '',
                'publico' => '',
                'buyer' => $p['context']['personas'][0]['name'] ?? null,
                'concepto' => $p['kind'] === 'iteration' ? 'El mismo de la referencia' : '',
                'ideas' => array_map(fn ($i) => 'Versión ' . ($i + 1) . ': tú decides cómo.', range(0, $p['pieces'] - 1)),
                'entregables' => "{$p['pieces']} " . ($p['format'] === 'image' ? 'imágenes' : 'videos 9:16'),
                'angulo_codigo' => null, 'angulo_nombre' => null,
            ], $plan),
            'aprendizajes' => [],
        ];
    }
}
