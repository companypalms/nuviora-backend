<?php

namespace App\Services\Metrics\Reports;

use App\Services\Metrics\Rules\GoalRules;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA que explica el reporte del negocio (`.48b`): recibe solo los números del panel del dueño y de la revisión (el
 * input de ReportFacts) y los cuenta en palabras simples. No calcula nada. Como el análisis de Meta: la Responses API de
 * OpenAI con un JSON estricto y sin guardar nada (store: false). Sin OPENAI_API_KEY arma el mismo formato con una
 * plantilla (example).
 */
class ReportWriter
{
    private const INSTRUCTIONS = <<<'TXT'
Eres el asistente del dueño de una tienda online de Venezuela que vende con anuncios de Facebook e Instagram y cobra contra entrega. Le escribes su reporte del negocio en español sencillo, como si se lo contaras en persona: claro, corto y sin palabras técnicas.
Recibes los números de su panel (ventas, dinero, pérdidas, productos, equipo, metas y alertas) con su comparación contra el período anterior, y una revisión que dice si los números se pueden creer.
Reglas que no puedes romper:
- No inventes números: usa solo los montos, porcentajes y cantidades que aparecen en los datos, escritos igual. No hagas cuentas nuevas (ni restas, ni promedios, ni porcentajes). Si no está en los datos, no lo escribas.
- No inventes causas. Si algo subió o bajó y los datos no dicen por qué, dilo así o di lo que muestran los datos (por ejemplo, qué producto o qué motivo de cancelación pesó más).
- Si dice que los pedidos todavía se están entregando, recuerda que esos números pueden subir y no los trates como malos.
- Si la calidad de los números tiene algo GRAVE, dilo en el resumen: qué número está afectado y qué hay que hacer.
- Nada de órdenes ni promesas: el dueño decide. Puedes decir qué conviene mirar primero.
Escribe:
- resumen: 4 a 6 frases con lo más importante del período: cómo fue, qué cambió contra el período anterior y lo que más pesó.
- ventas: 1 o 2 frases sobre pedidos, entregas, efectividad y lo vendido.
- dinero: 1 a 3 frases sobre la ganancia, la publicidad (costo por venta) y los productos que más dejaron o perdieron.
- perdidas: 1 o 2 frases sobre cancelaciones, rechazos y sus motivos.
- equipo: 1 o 2 frases sobre las vendedoras y agencias que destacaron o bajaron (vacío si no hay datos).
- que_mirar: hasta 3 cosas cortas que conviene revisar primero, de la más importante a la menos.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['resumen', 'ventas', 'dinero', 'perdidas', 'equipo', 'que_mirar'],
        'properties' => [
            'resumen' => ['type' => 'string'],
            'ventas' => ['type' => 'string'],
            'dinero' => ['type' => 'string'],
            'perdidas' => ['type' => 'string'],
            'equipo' => ['type' => 'string'],
            'que_mirar' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];

    /**
     * Le pregunta a la IA. Público para probarlo con una respuesta falsa.
     *
     * @return array{text: array, model: string, tokens_in: ?int, tokens_out: ?int}
     */
    public function ask(string $input): array
    {
        $model = (string) config('services.openai.business_report_model', config('services.openai.insights_model', 'gpt-6-luna'));
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(120)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false,
                'reasoning' => ['effort' => 'low'],
                'instructions' => self::INSTRUCTIONS,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'reporte_negocio', 'strict' => true, 'schema' => self::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso escribir el reporte.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['resumen'], $data['que_mirar']) || !is_array($data['que_mirar'])) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return ['text' => $data, 'model' => $model,
            'tokens_in' => $response->json('usage.input_tokens'), 'tokens_out' => $response->json('usage.output_tokens')];
    }

    /** El mismo formato, armado con los números (sin IA): para la PC de pruebas o si la IA falla. */
    public static function template(array $f, bool $example = false): array
    {
        $fmt = [GoalRules::class, 'fmt'];
        $card = fn (string $section, string $key) => collect($f['cards'][$section] ?? [])->firstWhere('key', $key);
        $line = fn (?array $c) => $c ? rtrim(ReportFacts::cardLine($c), '.') : null;
        $join = fn (array $parts) => ($parts = array_filter($parts)) ? implode('. ', $parts) . '.' : '';

        $resumen = array_filter([
            $example ? 'Ejemplo (sin IA en esta PC): este texto lo arma el sistema con los números.' : null,
            "{$f['period']['label']}, comparado con {$f['compare_label']}.",
            $line($card('money', 'profit')) ? $line($card('money', 'profit')) . '.' : null,
            $line($card('sales', 'delivered')) ? $line($card('sales', 'delivered')) . '.' : null,
            $f['maturing'] ? 'Los pedidos de los últimos días todavía se están entregando: estos números pueden subir.' : null,
            $f['check']['ok'] ? 'Los números están comprobados.' : 'Ojo con la calidad de los números: ' . $f['check']['text'],
        ]);
        $best = $f['products']['best'][0] ?? null;
        $losing = $f['products']['losing'][0] ?? null;
        $reason = $f['reasons'][0] ?? null;
        $sellers = $f['team']['sellers']['best'][0] ?? null;
        $mirar = [];
        foreach ($f['check']['problems'] as $p) {
            if ($p['severity'] === 'grave') {
                $mirar[] = $p['text'];
            }
        }
        foreach ($f['alerts'] as $a) {
            if ($a['level'] === 'red') {
                $mirar[] = $a['title'] . ': ' . ($a['items'][0] ?? '');
            }
        }
        if ($losing) {
            $mirar[] = "{$losing['name']} perdió " . $fmt(abs($losing['profit']), 'money') . ' después de su publicidad.';
        }

        return [
            'resumen' => implode(' ', $resumen),
            'ventas' => $join([$line($card('sales', 'created')), $line($card('sales', 'effectiveness')), $line($card('sales', 'revenue'))]),
            'dinero' => $join([
                $line($card('money', 'cost_per_sale')),
                $best ? "El que más dejó: {$best['name']} (" . $fmt($best['profit'], 'money') . ')' : null,
                $losing ? "El que perdió: {$losing['name']} (" . $fmt($losing['profit'], 'money') . ')' : null,
            ]),
            'perdidas' => $join([
                $line($card('losses', 'cancelled')),
                $reason ? "El motivo más común: {$reason['label']} ({$reason['count']})" : null,
            ]),
            'equipo' => $sellers ? "La vendedora con mejor {$f['team']['sellers']['metric']}: {$sellers['name']} (" . $fmt($sellers['value'], $f['team']['sellers']['format']) . ').' : '',
            'que_mirar' => array_slice($mirar, 0, 3),
        ];
    }
}
