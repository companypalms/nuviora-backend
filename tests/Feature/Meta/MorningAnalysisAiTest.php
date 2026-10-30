<?php

use App\Services\Meta\Analysis\AnalysisWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Etapa 5, paso 3a: la IA que redacta el análisis de la mañana, con una respuesta falsa (nunca se llama a OpenAI de verdad).

const LLAVE_FALSA_ANALISIS = 'sk-llave-falsa-de-prueba-analisis';

$respuestaAnalisis = fn (array $data) => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 300], 'output' => [
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]],
]]);

describe('El análisis de la mañana con IA', function () use ($respuestaAnalisis) {
    it('pregunta sin guardar nada en OpenAI, con la llave solo en la cabecera, y devuelve el texto y los tokens', function () use ($respuestaAnalisis) {
        config(['services.openai.key' => LLAVE_FALSA_ANALISIS, 'services.openai.meta_analysis_model' => 'modelo-de-prueba']);
        $data = ['resumen' => 'Hoy: bajar Loli en Valencia.', 'productos' => []];
        Http::fake(['api.openai.com/*' => $respuestaAnalisis($data)]);

        $r = (new AnalysisWriter())->ask('hechos');
        expect($r['text'])->toBe($data)->and($r['model'])->toBe('modelo-de-prueba')->and($r['tokens_in'])->toBe(1200);

        Http::assertSent(fn (Request $q) => $q->url() === 'https://api.openai.com/v1/responses'
            && $q['store'] === false && $q['model'] === 'modelo-de-prueba'
            && $q['text']['format']['strict'] === true && $q['text']['format']['name'] === 'analisis_campanas'
            && str_contains($q['instructions'], 'No inventes números')
            && $q->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA_ANALISIS)
            && !str_contains(json_encode($q->data()), LLAVE_FALSA_ANALISIS));
    });

    it('avisa si la IA responde con error', function () {
        config(['services.openai.key' => LLAVE_FALSA_ANALISIS]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'cuota agotada']], 429)]);
        expect(fn () => (new AnalysisWriter())->ask('hechos'))->toThrow(RuntimeException::class, 'OpenAI respondió 429: cuota agotada');
    });

    it('avisa si la IA responde sin el formato esperado', function () use ($respuestaAnalisis) {
        config(['services.openai.key' => LLAVE_FALSA_ANALISIS]);
        Http::fake(['api.openai.com/*' => $respuestaAnalisis(['otra' => 'cosa'])]);
        expect(fn () => (new AnalysisWriter())->ask('hechos'))->toThrow(RuntimeException::class, 'sin el formato esperado');
    });
});
