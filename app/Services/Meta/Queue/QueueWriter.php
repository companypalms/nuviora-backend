<?php

namespace App\Services\Meta\Queue;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA que redacta los encargos que proponen las reglas (Etapa 5, paso 4b) y el aprendizaje de los batches que ya se
 * testearon. El tipo, la referencia, la ciudad y cuántos son de las reglas: la IA no los cambia.
 *
 * `.63` (pedido del dueño del 2026-11-01): la IA no le dicta al editor el creativo exacto. `.128` (dueño, 2026-11-09: "nunca
 * deberían ser recomendaciones específicas de textos que debe poner, o de speech que debe realizar"): ni ideas por pieza,
 * ni hooks, ni frases. En una variación el editor lee textos fijos (BatchTask); en las piezas nuevas, una línea general.
 * Y escribe para el editor: nada de números del negocio, reglas ni prioridades (el proceso del dueño no se le cuenta). La
 * hipótesis sigue, solo para el dueño.
 *
 * El tono de los anuncios lo pidió el dueño (2026-10-30): fuerte y agresivo, con promesas claras de resultado. `.68`
 * (dueño, 2026-11-01: "necesito tener total libertad de lo que coloco"): las promesas de la ficha se usan tal cual, también
 * las de salud, sin suavizarlas ni pedir respaldo médico. Lo único que no hace la IA es inventarse avales, médicos,
 * estudios o testimonios que no estén en los datos.
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
Cada encargo puede traer LO QUE SABEMOS del producto: lo que funcionó y lo que falló en batches anteriores (por ángulo, buyer persona y estilo, con sus números) y las notas del dueño. Úsalo para decidir el concepto, el estilo y la hipótesis: repite y mejora lo que funcionó, no repitas lo que falló (salvo que la hipótesis nueva sea claramente distinta, y dilo en la hipótesis) y prefiere probar lo que falta. Es información interna del dueño: en lo que lee el editor (probando, entregables) nunca digas que algo ya se probó, ganó o falló, ni números ni cuántos batches.
Tu trabajo NO es decirle al editor qué video hacer. Nunca escribas hooks, frases, textos para poner, guiones, speech, escenas o tomas concretas, ni ideas pieza por pieza: eso lo confunde. El editor es el creativo y decide cómo hacerlo; lo máximo que le das es una línea general de qué idea se prueba.
En un encargo de iteración, lo que lee el editor (qué variar, la hipótesis y cómo hacerlo) son textos fijos de las reglas del dueño: tú escribe solo hipotesis, concepto, estilo y entregables, y deja vacío probando.
Cómo le fue al video, en palabras para el editor: "hook" es si engancha en los primeros segundos; "retención" es si la gente se queda viendo; "se van al principio / en el medio / al final" dice dónde abandonan; "clic" es si la gente toca el anuncio; "compra" es si los que llegan compran (si engancha y hacen clic pero no compran, puede ser la landing, no el video).
Lo que el editor NUNCA debe leer (es información interna del dueño): el precio del producto o cualquier precio o monto de dinero; números de ventas, compras, gasto, CPA, costos, precios de costo o ganancias; prioridades (U1, U2…), urgencias o reglas; ciudades de prueba; porcentajes de rendimiento. Describe cómo le fue al video solo con palabras (engancha bien, la gente se va en el medio…).
Reglas del contenido de los anuncios que no puedes romper:
- El tono que pide el dueño para los anuncios es fuerte y agresivo, con promesas claras de resultado y de alivio: tenlo en cuenta al elegir el concepto y el estilo, sin escribir frases para el anuncio.
- Las promesas son las del dueño: usa las de la ficha tal cual, aunque sean fuertes o de salud (por ejemplo, que trata un problema del cuerpo o que hace rebajar). No las suavices, no les agregues advertencias ni "consulta a tu médico" y no digas que necesitan respaldo médico: el dueño decide lo que dice su producto.
- No inventes por tu cuenta médicos, estudios, certificaciones, avales ni testimonios. Usa solo los que vienen en los datos del producto (la ficha y los avales y pruebas), tal cual.
- Nunca escribas precios ni montos de dinero en lo que lee el editor. No inventes números: las cantidades solo las que vienen en los datos.
- No supongas para qué sirve un producto por su nombre (un "chaleco térmico" no es para el frío, por ejemplo). Qué es, para qué sirve y a quién le habla sale solo de la ficha del producto, de sus ángulos y de los textos de la referencia.
- Si la referencia trae "lo que dice su video", úsalo solo para entender el creativo. No lo copies ni escribas guiones.
Para cada encargo escribe, con su clave:
- hipotesis: la leen el dueño y el editor líder: qué crees que va a pasar y por qué, en 1 o 2 frases. Aquí sí puedes nombrar lo que dicen los datos, pero nunca escribas hooks, frases, speech ni ideas de cómo hacer el video.
- probando: UNA línea general PARA EL EDITOR: qué idea estamos probando, en términos del cliente (qué siente, qué le pasa, qué lo detiene). Sin decir cómo abrir el video, qué mostrar ni qué decir. Sin números, sin métricas, sin ciudades, sin reglas ni cómo decide el dueño. Vacío en una iteración.
- buyer: el nombre exacto de UNO de los buyer personas de la ficha al que le habla este encargo (el que mejor encaje con el ángulo y la referencia). El editor solo conoce ese. Si el encargo trae "Buyer persona del ángulo", usa ese. null si la ficha no trae buyer personas.
- concepto: el concepto en pocas palabras. Es interno: el editor no lo lee.
- estilo: el estilo del video, uno de: testimonio, animado (video animado), venta_directa, demostracion, ugc, antes_despues, problema_solucion u otro. En una iteración, el de la referencia, salvo que lo que se pruebe sea otro estilo. Si un ángulo lleva más de un batch en el testeo, estilos distintos.
- entregables: qué entrega el editor (cantidad de videos o imágenes, formato 9:16, duración aproximada).
Y para cada batch testeado que venga en los datos, su aprendizaje en 1 o 2 frases, con su clave: qué hipótesis se probó (el ángulo, el buyer persona, el estilo, el concepto) y qué nos enseña, qué repetir o qué evitar. Solo con lo que dicen los datos. El aprendizaje es para el dueño: se guarda en "Lo que sabemos" del producto y lo lees antes de los encargos siguientes.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['encargos', 'aprendizajes'],
        'properties' => [
            'encargos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['clave', 'hipotesis', 'probando', 'buyer', 'concepto', 'estilo', 'entregables'],
                'properties' => [
                    'clave' => ['type' => 'string'],
                    'hipotesis' => ['type' => 'string'],
                    'probando' => ['type' => 'string'],
                    'buyer' => ['type' => ['string', 'null']],
                    'concepto' => ['type' => 'string'],
                    'estilo' => ['type' => 'string', 'enum' => ['testimonio', 'animado', 'venta_directa', 'demostracion', 'ugc', 'antes_despues', 'problema_solucion', 'otro']],
                    'entregables' => ['type' => 'string'],
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
     * Lo que lee la IA: cada encargo con su clave y lo de su producto (avales reales, ángulos; .69: sin el precio), y los testeados.
     *
     * @param list<array> $plan ProposalRules::plan, cada uno con "key", "pieces" y "context" (proof, angles,
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
            $lines[] = 'Ángulo: ' . ($p['angle_code'] ?? 'sin ángulo') . (isset($c['angles'][$p['angle_code'] ?? '']) ? ' — ' . $c['angles'][$p['angle_code']] : '');
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
            $lines[] = 'Avales y pruebas reales: ' . (trim((string) ($c['proof'] ?? '')) !== '' ? trim($c['proof']) : 'ninguno (no inventes)');
            // .64: la ficha del producto (qué es, para qué sirve, cómo funciona, lo que NO es, sus buyer personas)
            $lines[] = 'Ficha del producto:';
            $lines[] = trim((string) ($c['profile'] ?? '')) !== '' ? $c['profile'] : '  · (sin ficha)';
            if ($c['angles'] ?? []) {
                $lines[] = 'Ángulos del producto: ' . implode('; ', array_map(fn ($code, $name) => $code . ($name ? " ({$name})" : ''), array_keys($c['angles']), $c['angles']));
            }
            // .66: lo que sabemos de este producto (interno: nunca se le cuenta al editor)
            if (trim((string) ($c['memory'] ?? '')) !== '') {
                $lines[] = 'Lo que sabemos de este producto (interno: úsalo para decidir, nunca se lo cuentes al editor):';
                $lines[] = $c['memory'];
            }
            $lines[] = '';
        }
        foreach ($tested as $t) {
            $lines[] = "== TESTEADO {$t['key']} · {$t['product']} · {$t['code']} · " . (self::KINDS[$t['kind']] ?? $t['kind']) . ($t['angle'] ? " · {$t['angle']}" : '');
            if ($t['hypothesis']) {
                $lines[] = "Hipótesis: {$t['hypothesis']}";
            }
            // .66: qué se probó, para el aprendizaje
            foreach (['test_idea' => 'Qué se probaba', 'persona' => 'Buyer persona', 'style' => 'Estilo', 'concept' => 'Concepto'] as $k => $label) {
                if (!empty($t[$k])) {
                    $lines[] = "{$label}: " . ($k === 'style' ? (QueueRules::STYLES[$t[$k]] ?? $t[$k]) : $t[$k]);
                }
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

    private const LEAVES_WORDS = ['after_hook' => 'se van justo después del hook', 'opening' => 'se van al principio', 'body' => 'se van en la parte del medio',
        'end' => 'se van al final'];

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
                'probando' => $p['kind'] === 'iteration' ? '' : 'Probamos una idea nueva para este producto, para ver si conecta con el cliente.',
                'buyer' => $p['context']['personas'][0]['name'] ?? null,
                'estilo' => isset($p['testing']) ? (['problema_solucion', 'testimonio'][$p['testing']['n'] - 1] ?? 'demostracion') : 'otro',
                'concepto' => $p['kind'] === 'iteration' ? 'El mismo de la referencia' : '',
                'entregables' => "{$p['pieces']} " . ($p['format'] === 'image' ? 'imágenes' : 'videos 9:16'),
            ], $plan),
            'aprendizajes' => [],
        ];
    }
}
