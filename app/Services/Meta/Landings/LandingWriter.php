<?php

namespace App\Services\Meta\Landings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA redacta los encargos de landing que decidieron las reglas (Etapa 5, paso 6c), como QueueWriter con los batches:
 * la hipótesis, qué cambiar y qué conservar. No cambia la landing, el tipo ni el ángulo: eso ya está decidido. Sin
 * clave de OpenAI (esta PC) o si falla, quedan con una plantilla (example).
 */
class LandingWriter
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el director de ecommerce de una tienda online de Venezuela que vende con anuncios de Facebook e Instagram y cobra contra entrega. Las landings son páginas de producto de Shopify. Escribes los encargos para el editor de landings, en español sencillo y directo.
Recibes los encargos que ya decidieron las reglas del dueño: el producto, la landing (su dirección), el tipo (velocidad, hablarle a un ángulo, oferta o contenido), el ángulo con su nombre y su hipótesis, lo que dicen los números y los avales reales del producto. No cambies nada de eso: redáctalo.
Si propones textos para la página, el tono es el que pide el dueño: fuerte y agresivo, con promesas claras de resultado y de alivio. Nada tibio.
Reglas que no puedes romper:
- Nunca escribas que el producto cura una enfermedad, ni que reemplaza al médico o a las medicinas.
- No inventes médicos, estudios, certificaciones, avales ni testimonios. Usa solo los avales y pruebas reales que vienen en los datos del producto, tal cual.
- No inventes números: precios, descuentos y cantidades solo los que vienen en los datos.
- Un solo cambio por encargo, para poder medirlo: no cambies la página entera. Nunca a la vez que los anuncios: los anuncios quedan iguales.
- Velocidad: cosas concretas de Shopify (fotos más livianas, quitar apps o videos que cargan solos, el tema); no cambies los textos.
Para cada encargo escribe, con su clave:
- hipotesis: qué crees que va a pasar y por qué, en 1 o 2 frases.
- cambiar: qué cambiar en la página, en 2 a 4 frases concretas (qué sección, qué texto o qué foto).
- conservar: qué no se toca (el precio, la oferta, el resto de la página…).
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['encargos'],
        'properties' => [
            'encargos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['clave', 'hipotesis', 'cambiar', 'conservar'],
                'properties' => ['clave' => ['type' => 'string'], 'hipotesis' => ['type' => 'string'], 'cambiar' => ['type' => 'string'], 'conservar' => ['type' => 'string']],
            ]],
        ],
    ];

    /** @param list<array{key: string, product: string, url: string, kind: string, angle: ?string, angle_name: ?string, angle_hypothesis: ?string, observed: string, proof: ?string, best: ?string}> $plan */
    public static function input(array $plan): string
    {
        $lines = ['Encargos de landing para redactar:'];
        foreach ($plan as $p) {
            $lines[] = "- clave {$p['key']}: producto {$p['product']}; landing {$p['url']}; tipo " . (LandingTaskRules::KINDS[$p['kind']] ?? $p['kind'])
                . ($p['angle'] ? "; ángulo {$p['angle']}" . ($p['angle_name'] ? " ({$p['angle_name']})" : '') . ($p['angle_hypothesis'] ? ", hipótesis del ángulo: {$p['angle_hypothesis']}" : '') : '')
                . ($p['best'] ? "; la landing que compra mejor con ese ángulo: {$p['best']}" : '')
                . "; lo que dicen los números: {$p['observed']}"
                . ($p['proof'] ? "; avales y pruebas reales del producto: {$p['proof']}" : '; sin avales cargados');
        }

        return implode("\n", $lines);
    }

    /** @return array{text: array, model: string} */
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
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'encargos_landing', 'strict' => true, 'schema' => self::SCHEMA]],
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
        if (!is_array($data) || !isset($data['encargos']) || !is_array($data['encargos'])) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return ['text' => $data, 'model' => $model];
    }

    /** La plantilla, cuando no hay IA: lo mismo que pediría el dueño según el tipo. */
    public static function example(array $plan): array
    {
        return ['encargos' => array_map(function (array $p) {
            $angle = $p['angle'] ? $p['angle'] . ($p['angle_name'] && $p['angle_name'] !== $p['angle'] ? " ({$p['angle_name']})" : '') : null;
            [$hypothesis, $change, $keep] = match (true) {
                $p['finding'] === 'no_pixel' => ['Si el píxel de Meta está en la página, Meta ve las visitas y las compras, y las campañas se optimizan bien.',
                    'Revisar que el píxel de Meta esté en esta página (Shopify › Configuración › Eventos de clientes) y que la visita aparezca en el Administrador de eventos.',
                    'Todo lo demás de la página.'],
                $p['kind'] === 'speed' => ['Si la página carga más rápido en el celular, llegan más de los que hacen clic y se vende más con el mismo gasto.',
                    'Bajar el peso de la página: fotos comprimidas (menos de 200 KB cada una), quitar apps que no se usan y videos que se cargan solos. Medir con PageSpeed en el celular antes y después.',
                    'Los textos, el precio, la oferta y el orden de la página.'],
                $p['finding'] === 'beaten' => ["Si los anuncios del ángulo {$angle} llevan a la landing que mejor le vende a ese ángulo, compran más con los mismos anuncios.",
                    "Pasar a esta landing lo que tiene arriba «{$p['best']}» para el ángulo {$angle} (el título, la primera foto y el primer párrafo).",
                    'El precio, la oferta y el resto de la página.'],
                $p['kind'] === 'offer' => ["Si la landing del ángulo {$angle} deja más clara la oferta, los que llegan por ese ángulo compran como el resto.",
                    'Revisar la oferta y lo primero que se ve: el precio con el envío y el pago contra entrega bien visibles, y la garantía.',
                    'El título del ángulo y las fotos.'],
                default => ["Si la página habla de lo mismo que los anuncios del ángulo {$angle}, los que llegan por ese ángulo compran como el resto.",
                    "Arriba de todo, un título y una foto que hablen del ángulo {$angle}: el mismo dolor y la misma promesa del anuncio.",
                    'El precio, la oferta y el resto de la página.'],
            };

            return ['clave' => $p['key'], 'hipotesis' => $hypothesis, 'cambiar' => $change, 'conservar' => $keep];
        }, $plan)];
    }
}
