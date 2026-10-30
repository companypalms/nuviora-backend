<?php

namespace App\Services\Meta;

use App\Models\AdAngle;
use App\Models\Product;
use App\Services\Metrics\Insights\CancellationInsights;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * "Proponer con IA" en Meta Ads › Ángulos (Etapa 5, paso 1b; el dueño: "la IA propone, yo confirmo"). Por cada ángulo
 * sin confirmar de un producto le manda a la IA el texto y el título de hasta 8 de sus anuncios, y la IA devuelve un
 * nombre, la hipótesis y, si cree que es lo mismo que otro código del producto, cuál. Nada queda confirmado: el dueño lo
 * corrige y lo confirma.
 *
 * Como el análisis de cancelaciones: la Responses API de OpenAI con un JSON estricto y sin guardar nada (store: false).
 * Sin OPENAI_API_KEY: en la PC de pruebas (APP_ENV=local) propone un ejemplo; en un servidor avisa.
 */
class AngleProposer
{
    public const MAX_ADS = 8;

    private const INSTRUCTIONS = <<<'TXT'
Eres el estratega creativo de una tienda online de Venezuela que vende productos con anuncios de Meta (Facebook e Instagram) y cobra contra entrega.
En los nombres de los anuncios, cada "ángulo" es un código corto (por ejemplo FASP, NERV o GINE) que agrupa anuncios que le hablan al mismo dolor, deseo o público.
Recibes un producto y, por cada código, el texto y el título de algunos de sus anuncios y los nombres de los anuncios.
Por cada código responde:
- codigo: el mismo código que recibiste.
- nombre: un nombre corto en español para el ángulo (2 a 5 palabras), por ejemplo "Dolor de tobillo al caminar".
- hipotesis: una frase con la hipótesis del ángulo: a quién le habla, qué dolor o deseo toca y por qué compraría. Por ejemplo: "Personas con dolor de tobillo que quieren caminar sin molestias compran si ven que la tobillera les da firmeza desde el primer uso".
- igual_a: si por los textos el código es claramente el mismo ángulo que otro código de la lista (por ejemplo FAS y FASP), ese otro código; si no, una cadena vacía.
Usa solo lo que dicen los textos. Si un código no tiene textos, deduce lo que puedas del código y de los nombres, y dilo en la hipótesis ("sin textos: …"). No inventes promesas médicas ni resultados garantizados. Escribe en español sencillo.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['angulos'],
        'properties' => [
            'angulos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['codigo', 'nombre', 'hipotesis', 'igual_a'],
                'properties' => [
                    'codigo' => ['type' => 'string'],
                    'nombre' => ['type' => 'string'],
                    'hipotesis' => ['type' => 'string'],
                    'igual_a' => ['type' => 'string'],
                ],
            ]],
        ],
    ];

    /**
     * @param int[]|null $angleIds los ángulos a proponer; sin lista, los del producto que no están confirmados ni los
     *                       escribió el dueño
     * @return array{status: string, message?: string, updated?: int, example?: bool}
     */
    public function propose(Product $product, ?array $angleIds = null): array
    {
        $angles = AdAngle::where('product_id', $product->id)->whereNull('alias_of')
            // Sin lista: los que no están confirmados ni los escribió el dueño (lo suyo no se pisa)
            ->when($angleIds, fn ($q) => $q->whereIn('id', $angleIds), fn ($q) => $q->where('status', '!=', 'confirmed')
                ->where(fn ($w) => $w->whereNull('proposed_by')->orWhere('proposed_by', '!=', 'owner')))
            // Solo los que tienen anuncios (uno sin anuncios no tiene nada que leer)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
                ->whereColumn('c.product_id', 'ad_angles.product_id')->whereColumn('a.angle_code', 'ad_angles.code'))
            ->orderBy('code')->get();
        if ($angles->isEmpty()) {
            return ['status' => 'few', 'message' => 'No hay ángulos sin confirmar en este producto.'];
        }
        $allCodes = AdAngle::where('product_id', $product->id)->whereNull('alias_of')->pluck('code')->all();
        $example = false;
        if (!config('services.openai.key')) {
            if (!app()->environment('local')) {
                return ['status' => 'unavailable', 'message' => 'La IA no está configurada en este servidor (falta OPENAI_API_KEY).'];
            }
            $answer = self::example($angles->pluck('code')->all());
            $example = true;
        } else {
            try {
                $answer = $this->ask(self::input($product->showable_name ?: $product->title, $this->samples($product->id, $angles->all()), $allCodes));
            } catch (RuntimeException $e) {
                report($e);

                return ['status' => 'error', 'message' => 'La IA no pudo proponer ahora. Intenta de nuevo en unos minutos. (' . $e->getMessage() . ')'];
            }
        }
        $updated = 0;
        foreach (self::clean($answer, $angles->pluck('code')->all(), $allCodes) as $code => $p) {
            $angle = $angles->firstWhere('code', $code);
            if (!$angle) {
                continue;
            }
            // Uno confirmado solo llega aquí si el dueño pidió otra propuesta: vuelve a quedar por confirmar
            $angle->fill(['name' => $p['name'], 'hypothesis' => $p['hypothesis'], 'suggested_alias' => $p['alias'],
                'status' => 'proposed', 'proposed_by' => 'ai', 'proposed_at' => now(), 'confirmed_by' => null, 'confirmed_at' => null])->save();
            $updated++;
        }

        return ['status' => 'ok', 'updated' => $updated] + ($example ? ['example' => true] : []);
    }

    /**
     * Lo que vuelve de la IA, limpio (PHP puro): solo los códigos pedidos, textos cortos y un alias solo si es otro
     * código del producto.
     *
     * @return array<string, array{name: string, hypothesis: string, alias: ?string}>
     */
    public static function clean(array $answer, array $asked, array $allCodes): array
    {
        $out = [];
        foreach ($answer['angulos'] ?? [] as $a) {
            $code = strtoupper(trim((string) ($a['codigo'] ?? '')));
            if (!in_array($code, $asked, true) || isset($out[$code])) {
                continue;
            }
            $alias = strtoupper(trim((string) ($a['igual_a'] ?? '')));
            $out[$code] = [
                'name' => mb_substr(trim((string) ($a['nombre'] ?? '')), 0, 120),
                'hypothesis' => mb_substr(trim((string) ($a['hipotesis'] ?? '')), 0, 1000),
                'alias' => $alias !== '' && $alias !== $code && in_array($alias, $allCodes, true) ? $alias : null,
            ];
        }

        return $out;
    }

    /** Lo que recibe la IA: el producto, los códigos que existen y, por código, sus anuncios. */
    public static function input(string $product, array $samples, array $allCodes): string
    {
        $lines = ["Producto: {$product}", 'Códigos de ángulo de este producto: ' . implode(', ', $allCodes), ''];
        foreach ($samples as $code => $s) {
            $lines[] = "== Código {$code}" . ($s['aliases'] ? ' (también escrito ' . implode(', ', $s['aliases']) . ')' : '');
            $lines[] = 'Nombres de anuncios: ' . (implode(' · ', $s['names']) ?: '(ninguno)');
            foreach ($s['texts'] as $i => $t) {
                $lines[] = '- Anuncio ' . ($i + 1) . ': ' . $t;
            }
            if (!$s['texts']) {
                $lines[] = '- (sin textos guardados todavía)';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** Le pregunta a la IA. Público para poder probarlo con una respuesta falsa. */
    public function ask(string $input): array
    {
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(100)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.insights_model', 'gpt-6-luna'),
                'store' => false,
                'reasoning' => ['effort' => 'low'],
                'instructions' => self::INSTRUCTIONS,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'angulos', 'strict' => true, 'schema' => self::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso proponer.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['angulos']) || !is_array($data['angulos'])) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return $data;
    }

    /** Un ejemplo para ver la pantalla en la PC de pruebas, sin clave. */
    public static function example(array $codes): array
    {
        return ['angulos' => array_map(fn ($c) => [
            'codigo' => $c,
            'nombre' => "Ejemplo: ángulo {$c}",
            'hipotesis' => 'Ejemplo (sin IA en esta PC): personas con este dolor compran si el anuncio muestra que el producto lo alivia desde el primer uso.',
            'igual_a' => '',
        ], $codes)];
    }

    /**
     * Por cada ángulo: sus alias, los nombres de hasta 8 anuncios y sus textos distintos (título + texto, sin teléfonos ni
     * correos), de los más nuevos.
     *
     * @param AdAngle[] $angles
     * @return array<string, array{aliases: string[], names: string[], texts: string[]}>
     */
    private function samples(int $productId, array $angles): array
    {
        $out = [];
        foreach ($angles as $angle) {
            $aliases = AdAngle::where('alias_of', $angle->id)->pluck('code')->all();
            $ads = DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
                ->where('c.product_id', $productId)->where('a.angle_code', $angle->code)
                ->orderByDesc('a.created_time')->limit(60)->get(['a.name', 'a.ad_title', 'a.ad_body']);
            $names = $ads->map(fn ($a) => CreativeTrackingId::label($a->name))->unique()->take(self::MAX_ADS)->values()->all();
            $texts = $ads->map(fn ($a) => trim(($a->ad_title ? $a->ad_title . ' — ' : '') . (string) $a->ad_body))
                ->filter()->unique()->take(self::MAX_ADS)
                ->map(fn ($t) => mb_strimwidth(CancellationInsights::mask($t), 0, 600, '…'))->values()->all();
            $out[$angle->code] = ['aliases' => $aliases, 'names' => $names, 'texts' => $texts];
        }

        return $out;
    }
}
