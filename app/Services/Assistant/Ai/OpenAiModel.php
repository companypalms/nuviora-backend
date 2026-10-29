<?php

namespace App\Services\Assistant\Ai;

use App\Services\Assistant\Turn;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El asistente con OpenAI (Responses API), como los comprobantes, los cierres y el análisis de cancelaciones: con Http,
 * sin guardar nada en OpenAI (store: false) y con herramientas estrictas.
 *
 * Como no se guarda nada allá, cada vuelta manda todo de nuevo: lo que se habló, la pregunta y, de las vueltas
 * anteriores de esta respuesta, el razonamiento de la IA (cifrado: include reasoning.encrypted_content), sus llamadas y lo
 * que devolvió cada herramienta. En la última vuelta (mustAnswer) no puede pedir más herramientas: contesta con lo que
 * tiene.
 */
class OpenAiModel implements AssistantModel
{
    public const URL = 'https://api.openai.com/v1/responses';

    private const TIMEOUT_SECONDS = 60;

    private const MAX_OUTPUT_TOKENS = 4000;

    public function next(Turn $turn): ModelReply
    {
        $model = (string) config('services.openai.assistant_model', 'gpt-6.1-sol');
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post(self::URL, [
                'model' => $model,
                'store' => false, // los datos del negocio no se guardan en OpenAI
                'instructions' => $turn->instructions,
                'input' => self::input($turn),
                'tools' => $turn->tools,
                'tool_choice' => $turn->mustAnswer ? 'none' : 'auto',
                'parallel_tool_calls' => true,
                'reasoning' => ['effort' => (string) config('services.openai.assistant_effort', 'low')],
                'include' => ['reasoning.encrypted_content'],
                'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }

        return self::reply((array) $response->json(), $model);
    }

    /** Lo que se le manda a la IA en esta vuelta. Público para probarlo. */
    public static function input(Turn $turn): array
    {
        $input = [];
        foreach ($turn->history as $m) {
            $input[] = ['role' => $m['role'], 'content' => $m['text']];
        }
        $input[] = ['role' => 'user', 'content' => $turn->files === [] ? $turn->message : self::withFiles($turn)];
        foreach ($turn->steps as $step) {
            foreach ($step['reply']->carry as $item) {
                $input[] = $item;
            }
            foreach ($step['outputs'] as $callId => $output) {
                $input[] = ['type' => 'function_call_output', 'call_id' => (string) $callId, 'output' => $output];
            }
        }

        return $input;
    }

    /**
     * La pregunta con sus archivos: primero el texto y la lista de los archivos; después cada foto (input_image), cada PDF
     * escaneado (input_file) y el texto de cada PDF o Excel, entre <archivo> y </archivo>.
     */
    private static function withFiles(Turn $turn): array
    {
        $list = [];
        foreach ($turn->files as $i => $file) {
            $list[] = ($i + 1) . ". {$file['name']} ({$file['label']})";
        }
        $question = trim($turn->message) !== '' ? $turn->message : '(El dueño mandó solo archivos, sin texto.)';
        $content = [['type' => 'input_text', 'text' => $question . "\n\nArchivos que mandó, en este orden:\n" . implode("\n", $list)]];
        foreach ($turn->files as $file) {
            $content[] = match ($file['type']) {
                // high: en una factura o un comprobante hay que leer los dígitos chicos
                'image' => ['type' => 'input_image', 'image_url' => "data:{$file['mime']};base64,{$file['data']}", 'detail' => 'high'],
                'file' => ['type' => 'input_file', 'filename' => $file['name'], 'file_data' => "data:{$file['mime']};base64,{$file['data']}"],
                default => ['type' => 'input_text', 'text' => (string) $file['text']],
            };
        }

        return $content;
    }

    /** Lee la respuesta de OpenAI. Público para probarlo. */
    public static function reply(array $json, string $model): ModelReply
    {
        $text = [];
        $calls = [];
        $carry = [];
        foreach ($json['output'] ?? [] as $item) {
            switch ($item['type'] ?? null) {
                case 'reasoning':
                    // Se devuelve tal cual (con su id y lo cifrado): sin eso, la vuelta siguiente falla
                    $carry[] = $item;
                    break;
                case 'function_call':
                    $arguments = json_decode((string) ($item['arguments'] ?? ''), true);
                    $calls[] = ['call_id' => (string) $item['call_id'], 'name' => (string) $item['name'], 'arguments' => is_array($arguments) ? $arguments : []];
                    $carry[] = ['type' => 'function_call', 'call_id' => (string) $item['call_id'], 'name' => (string) $item['name'], 'arguments' => (string) ($item['arguments'] ?? '{}')];
                    break;
                case 'message':
                    $said = [];
                    foreach ($item['content'] ?? [] as $content) {
                        if (($content['type'] ?? null) === 'output_text') {
                            $said[] = $content['text'];
                        } elseif (($content['type'] ?? null) === 'refusal') {
                            $said[] = 'No puedo ayudarte con eso.';
                        }
                    }
                    if ($said !== []) {
                        $text[] = implode("\n", $said);
                        $carry[] = ['role' => 'assistant', 'content' => implode("\n", $said)];
                    }
                    break;
            }
        }
        $usage = $json['usage'] ?? [];

        return new ModelReply(
            $text !== [] ? trim(implode("\n\n", $text)) : null,
            $calls,
            $carry,
            [
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'cached' => (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
            ],
            (string) ($json['model'] ?? $model),
        );
    }
}
