<?php

namespace App\Services\Assistant\Voice;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La voz con OpenAI (/v1/audio/transcriptions, modelo gpt-transcribe). Se le dice que es español y el vocabulario del
 * negocio (keywords), para que escriba bien "Binance" o "Cashea". Si OpenAI no acepta esas pistas (400), se intenta una vez
 * más sin ellas.
 */
class OpenAiTranscriber implements Transcriber
{
    public const URL = 'https://api.openai.com/v1/audio/transcriptions';

    /** Una nota de 5 minutos tarda unos segundos; el servidor corta las peticiones al minuto. */
    private const TIMEOUT_SECONDS = 50;

    public const PROMPT = 'Mensaje de voz en español de Venezuela del dueño de Nuviora, una tienda online, para su asistente: preguntas y pedidos sobre ventas, vendedoras, agencias, repartidores, órdenes, inventario, gastos y tasas del dólar.';

    public const KEYWORDS = ['Nuviora', 'Binance', 'BCV', 'Cashea', 'Zelle', 'Pago Móvil', 'Shopify', 'vendedoras', 'agencias', 'repartidores', 'upsell', 'efectividad'];

    public function transcribe(string $path, string $format): string
    {
        $response = $this->send($path, $format, true);
        if ($response->status() === 400) {
            $response = $this->send($path, $format, false);
        }
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }

        return trim((string) $response->json('text'));
    }

    /** Las partes del formulario que se manda (sin el audio). Público para probarlo. */
    public static function fields(bool $hints): array
    {
        $fields = [['name' => 'model', 'contents' => (string) config('services.openai.transcribe_model', 'gpt-transcribe')]];
        if ($hints) {
            $fields[] = ['name' => 'prompt', 'contents' => self::PROMPT];
            $fields[] = ['name' => 'languages[]', 'contents' => 'es'];
            foreach (self::KEYWORDS as $word) {
                $fields[] = ['name' => 'keywords[]', 'contents' => $word];
            }
        }

        return $fields;
    }

    private function send(string $path, string $format, bool $hints): \Illuminate\Http\Client\Response
    {
        $audio = fopen($path, 'rb');
        if ($audio === false) {
            throw new RuntimeException('No se pudo leer el audio.');
        }

        try {
            return Http::withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->attach('file', $audio, "voz.{$format}")
                ->post(self::URL, self::fields($hints));
        } finally {
            if (is_resource($audio)) {
                fclose($audio);
            }
        }
    }
}
