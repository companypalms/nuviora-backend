<?php

namespace App\Services\Metrics\Insights;

use App\Services\Metrics\Filters;
use App\Services\Metrics\Period;
use App\Services\Metrics\Readers\OrderFactsReader;
use App\Services\Metrics\Rules\LossRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El análisis con IA de los motivos de cancelación (dueño, 2026-10-29: "que la IA analice automáticamente todos los
 * motivos de cancelación escritos por las vendedoras y me dé un análisis de lo que está pasando, qué podemos mejorar y
 * cómo lo mejoramos").
 *
 * - Lee los motivos escritos por personas de los pedidos del período (no los del cierre automático, que son todos
 *   iguales), con el motivo de la lista, el momento y quién canceló (el primer nombre de la vendedora: lo autorizó el
 *   dueño). Antes de enviarlos borra teléfonos, correos y números largos.
 * - Como los comprobantes y los cierres: la Responses API de OpenAI con un JSON Schema estricto y sin guardar nada
 *   (store: false).
 * - El resultado se guarda un día: si los motivos no cambian, no se vuelve a pagar. "Volver a analizar" lo rehace.
 * - Sin OPENAI_API_KEY: en la PC de pruebas (APP_ENV=local) muestra un ejemplo, para ver cómo se ve; en un servidor
 *   avisa que la IA no está configurada.
 */
class CancellationInsights
{
    /** Los motivos más recientes que se envían, como mucho (lo demás se resume en los totales). */
    public const MAX_REASONS = 300;

    /** Con menos motivos escritos que estos no hay nada que analizar. */
    public const MIN_REASONS = 5;

    private const CACHE_HOURS = 24;

    private const INSTRUCTIONS = <<<'TXT'
Eres analista de operaciones de una tienda online de Venezuela que vende con pago contra entrega: el cliente paga cuando recibe. Las vendedoras confirman cada pedido por teléfono o WhatsApp; después una agencia de reparto o un repartidor propio lo lleva.
Recibes los pedidos cancelados de un período: por cada uno, la fecha, el motivo que se eligió de una lista, en qué momento del proceso se canceló, quién lo canceló y lo que escribió con sus palabras. También recibes los totales del período.
Analiza y responde:
- resumen: qué está pasando, en 3 a 5 frases simples, con números aproximados.
- hallazgos: los problemas principales (máximo 5), de mayor a menor, cada uno con cuántos pedidos afecta aproximadamente y qué tan grave es.
- mejoras: acciones concretas y prácticas (máximo 6), de la más importante a la menos, cada una con cómo hacerla (una o dos frases), quién la haría (vendedoras, líder de ventas, administración, agencias, marketing o el dueño) y su impacto.
- vendedoras: observaciones sobre una vendedora solo si sus cancelaciones muestran un patrón claro (por ejemplo, cancela por "no contesta" después de una sola llamada). Si no hay patrones claros, una lista vacía.
Escribe en español sencillo, para un dueño de negocio que no es técnico. Usa solo lo que dicen los datos: no inventes cifras ni causas. Lo que aparece entre corchetes, como [teléfono], se borró por privacidad.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['resumen', 'hallazgos', 'mejoras', 'vendedoras'],
        'properties' => [
            'resumen' => ['type' => 'string'],
            'hallazgos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['titulo', 'detalle', 'pedidos_aprox', 'gravedad'],
                'properties' => [
                    'titulo' => ['type' => 'string'],
                    'detalle' => ['type' => 'string'],
                    'pedidos_aprox' => ['type' => 'integer'],
                    'gravedad' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
                ],
            ]],
            'mejoras' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['accion', 'como', 'responsable', 'impacto'],
                'properties' => [
                    'accion' => ['type' => 'string'],
                    'como' => ['type' => 'string'],
                    'responsable' => ['type' => 'string'],
                    'impacto' => ['type' => 'string', 'enum' => ['alto', 'medio', 'bajo']],
                ],
            ]],
            'vendedoras' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['nombre', 'observacion'],
                'properties' => ['nombre' => ['type' => 'string'], 'observacion' => ['type' => 'string']],
            ]],
        ],
    ];

    public function __construct(private OrderFactsReader $orders)
    {
    }

    /**
     * @return array{status: string, message?: string, analysis?: array, analyzed: int, written: int, generated_at?: string, cached?: bool, example?: bool}
     */
    public function analyze(Period $period, Filters $filters, bool $force = false): array
    {
        $facts = iterator_to_array($this->orders->losses($period, $filters), false);
        $totals = LossRules::compute($facts, $period);
        $lines = self::lines($facts, self::MAX_REASONS);
        $written = self::writtenCount($facts);
        $base = ['analyzed' => count($lines), 'written' => $written];

        if (count($lines) < self::MIN_REASONS) {
            return $base + ['status' => 'few', 'message' => 'Hay muy pocos motivos escritos en este período para analizarlos (' . count($lines) . '). Prueba con un período más largo.'];
        }
        if (!config('services.openai.key')) {
            return app()->environment('local')
                ? $base + ['status' => 'ok', 'example' => true, 'analysis' => self::example(), 'generated_at' => now()->format('Y-m-d H:i'), 'cached' => false]
                : $base + ['status' => 'unavailable', 'message' => 'La IA no está configurada en este servidor (falta OPENAI_API_KEY).'];
        }

        $input = self::input($lines, $totals, $period, $written);
        $key = 'owner-panel:cancel-insights:' . sha1($input . '|' . config('services.openai.insights_model'));
        if (!$force && ($hit = Cache::get($key))) {
            return $base + $hit + ['cached' => true];
        }
        try {
            $analysis = $this->ask($input);
        } catch (RuntimeException $e) {
            report($e);

            return $base + ['status' => 'error', 'message' => 'La IA no pudo hacer el análisis ahora. Intenta de nuevo en unos minutos. (' . $e->getMessage() . ')'];
        }
        $result = ['status' => 'ok', 'analysis' => $analysis, 'generated_at' => now()->format('Y-m-d H:i')];
        Cache::put($key, $result, now()->addHours(self::CACHE_HOURS));

        return $base + $result + ['cached' => false];
    }

    /** Le pregunta a la IA. Público para poder probarlo con una respuesta falsa. */
    public function ask(string $input): array
    {
        $model = config('services.openai.insights_model', 'gpt-6-luna');
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(100)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false, // motivos escritos por el equipo, con nombres de vendedoras
                'reasoning' => ['effort' => 'low'],
                'instructions' => self::INSTRUCTIONS,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'analisis_cancelaciones', 'strict' => true, 'schema' => self::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso hacer el análisis.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || array_diff(self::SCHEMA['required'], array_keys($data))) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return $data;
    }

    /** Borra teléfonos, correos y números largos de un motivo escrito antes de enviarlo. */
    public static function mask(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[correo]', $text);
        $text = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '[teléfono]', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Una línea por cancelación escrita por una persona, de la más nueva a la más vieja:
     * "2026-10-20 | No contesta / no se pudo contactar | En las llamadas | María | <motivo>".
     *
     * @param array<int, array|object> $facts
     * @return string[]
     */
    public static function lines(array $facts, int $max): array
    {
        $rows = array_values(array_filter(array_map(fn ($f) => (array) $f, $facts), [self::class, 'isWritten']));
        usort($rows, fn ($a, $b) => strcmp((string) $b['cancelled_at'], (string) $a['cancelled_at']));
        $stageOf = [];
        foreach (LossRules::STAGES as $s) {
            foreach ($s['statuses'] as $status) {
                $stageOf[$status] = $s['label'];
            }
        }

        return array_map(function ($f) use ($stageOf) {
            $who = ($f['user_role'] ?? null) === 'Vendedor' ? LossRules::firstName($f['user_name'] ?? null) : 'Administración';

            return implode(' | ', [
                substr((string) $f['cancelled_at'], 0, 10),
                LossRules::reasonLabel($f['category'] ?? 'sin_lista'),
                $stageOf[$f['previous_status'] ?? ''] ?? 'Sin dato',
                $who,
                mb_strimwidth(self::mask((string) $f['reason']), 0, 300, '…'),
            ]);
        }, array_slice($rows, 0, $max));
    }

    /** Lo que recibe la IA: los totales del período y los motivos. */
    public static function input(array $lines, array $computed, Period $period, int $written): string
    {
        $t = $computed['totals'];
        $list = fn (array $rows) => implode('; ', array_map(fn ($r) => "{$r['label']}: {$r['count']}", $rows)) ?: 'ninguno';

        return implode("\n", [
            "Período: del {$period->fromDate()} al {$period->toDate()}.",
            "Pedidos creados: {$t['created']}. Cancelados: {$t['cancelled']}. Rechazados en puerta: {$t['rejected']}.",
            'Cancelados por motivo de la lista: ' . $list($computed['reasons']) . '.',
            'Cancelados por momento: ' . $list($computed['stages']) . '.',
            'Cancelados por quién: ' . $list($computed['who']) . '.',
            'Rechazos por última novedad: ' . $list($computed['novelties']) . '.',
            "Motivos escritos por personas: {$written}" . (count($lines) < $written ? ' (van los ' . count($lines) . ' más recientes)' : '') . '.',
            '',
            'Fecha | Motivo de la lista | Momento | Quién canceló | Lo que escribió',
            ...$lines,
        ]);
    }

    private static function writtenCount(array $facts): int
    {
        return count(array_filter(array_map(fn ($f) => (array) $f, $facts), [self::class, 'isWritten']));
    }

    /** Un pedido cancelado (hoy) por una persona que escribió el motivo. */
    private static function isWritten(array $f): bool
    {
        return ($f['status'] ?? null) === 'Cancelado' && ($f['kind'] ?? 'normal') === 'normal'
            && ($f['source'] ?? null) === 'user' && trim((string) ($f['reason'] ?? '')) !== '';
    }

    /** Lo que se ve en la PC de pruebas, donde no hay IA conectada: un ejemplo con la misma forma que la respuesta real. */
    private static function example(): array
    {
        return [
            'resumen' => 'EJEMPLO (en esta PC no hay IA conectada): la mayoría de las cancelaciones pasan en las llamadas, porque el cliente no contesta. Le siguen los clientes que se arrepienten o lo consiguen más barato, y los que no tienen el dinero esa semana.',
            'hallazgos' => [
                ['titulo' => 'Clientes que no contestan', 'detalle' => 'Cerca de un tercio de las cancelaciones son por "no contesta", muchas después de pocas llamadas.', 'pedidos_aprox' => 20, 'gravedad' => 'alta'],
                ['titulo' => 'Cancelaciones después de confirmar', 'detalle' => 'Varios pedidos se cancelan cuando ya estaban en la agencia: costaron trabajo y a veces envío.', 'pedidos_aprox' => 10, 'gravedad' => 'media'],
            ],
            'mejoras' => [
                ['accion' => 'Mensaje de WhatsApp antes de la segunda llamada', 'como' => 'Enviar una plantilla con la foto del producto y el total, y llamar 2 horas después.', 'responsable' => 'Vendedoras', 'impacto' => 'alto'],
                ['accion' => 'Confirmar el dinero antes de pasar a agencia', 'como' => 'Preguntar el método de pago y si tiene el monto el día de la entrega.', 'responsable' => 'Líder de ventas', 'impacto' => 'medio'],
            ],
            'vendedoras' => [],
        ];
    }
}
