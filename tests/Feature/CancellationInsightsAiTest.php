<?php

use App\Services\Metrics\Insights\CancellationInsights;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Panel del dueño › Pérdidas: la pregunta a la IA, con una respuesta falsa (nunca se llama a OpenAI de verdad).

const LLAVE_FALSA = 'sk-llave-falsa-de-prueba';

$respuesta = fn (array $analisis) => Http::response(['status' => 'completed', 'model' => 'gpt-6-luna', 'output' => [
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($analisis)]]],
]]);

describe('Análisis de cancelaciones con IA', function () use ($respuesta) {
    it('pregunta sin guardar nada en OpenAI, con la llave solo en la cabecera, y devuelve el análisis', function () use ($respuesta) {
        config(['services.openai.key' => LLAVE_FALSA, 'services.openai.insights_model' => 'modelo-de-prueba']);
        $analisis = ['resumen' => 'Todo bien', 'hallazgos' => [], 'mejoras' => [], 'vendedoras' => []];
        Http::fake(['api.openai.com/*' => $respuesta($analisis)]);

        expect(app(CancellationInsights::class)->ask('motivos'))->toBe($analisis);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses'
            && $r['store'] === false
            && $r['model'] === 'modelo-de-prueba'
            && $r['text']['format']['strict'] === true
            && $r->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA)
            && !str_contains(json_encode($r->data()), LLAVE_FALSA));
    });

    it('avisa si la IA responde con error', function () {
        config(['services.openai.key' => LLAVE_FALSA]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'cuota agotada']], 429)]);
        expect(fn () => app(CancellationInsights::class)->ask('motivos'))->toThrow(RuntimeException::class, 'OpenAI respondió 429: cuota agotada');
    });

    it('avisa si la IA responde sin el formato esperado', function () use ($respuesta) {
        config(['services.openai.key' => LLAVE_FALSA]);
        Http::fake(['api.openai.com/*' => $respuesta(['resumen' => 'solo esto'])]);
        expect(fn () => app(CancellationInsights::class)->ask('motivos'))->toThrow(RuntimeException::class, 'sin el formato esperado');
    });
});
