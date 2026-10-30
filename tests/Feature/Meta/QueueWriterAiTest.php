<?php

use App\Services\Meta\Queue\QueueWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Etapa 5, paso 4b: la IA que redacta los encargos, con una respuesta falsa (nunca se llama a OpenAI de verdad).

const LLAVE_FALSA_COLA = 'sk-llave-falsa-de-prueba-cola';

$respuestaCola = fn (array $data) => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 800, 'output_tokens' => 250], 'output' => [
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]],
]]);

describe('Los encargos redactados por la IA', function () use ($respuestaCola) {
    it('pregunta sin guardar nada, con el tono del dueño y sin curas ni avales inventados', function () use ($respuestaCola) {
        config(['services.openai.key' => LLAVE_FALSA_COLA, 'services.openai.meta_analysis_model' => 'modelo-de-prueba']);
        $data = ['encargos' => [['clave' => 'E1', 'hipotesis' => 'h', 'publico' => 'p', 'concepto' => 'c', 'conservar' => 'k', 'piezas' => ['a', 'b', 'c'],
            'entregables' => 'e', 'angulo_codigo' => null, 'angulo_nombre' => null]], 'aprendizajes' => []];
        Http::fake(['api.openai.com/*' => $respuestaCola($data)]);

        $r = (new QueueWriter())->ask('encargos');
        expect($r['text'])->toBe($data)->and($r['model'])->toBe('modelo-de-prueba')->and($r['tokens_out'])->toBe(250);

        Http::assertSent(fn (Request $q) => $q->url() === 'https://api.openai.com/v1/responses'
            && $q['store'] === false && $q['text']['format']['strict'] === true && $q['text']['format']['name'] === 'encargos_creativos'
            && str_contains($q['instructions'], 'fuerte y agresivo')
            && str_contains($q['instructions'], 'Nunca escribas que el producto cura una enfermedad')
            && str_contains($q['instructions'], 'No inventes médicos, estudios, certificaciones, avales ni testimonios')
            && $q->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA_COLA)
            && !str_contains(json_encode($q->data()), LLAVE_FALSA_COLA));
    });

    it('avisa si la IA responde con error', function () {
        config(['services.openai.key' => LLAVE_FALSA_COLA]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'cuota agotada']], 429)]);
        expect(fn () => (new QueueWriter())->ask('encargos'))->toThrow(RuntimeException::class, 'OpenAI respondió 429: cuota agotada');
    });

    it('avisa si la IA responde sin el formato esperado', function () use ($respuestaCola) {
        config(['services.openai.key' => LLAVE_FALSA_COLA]);
        Http::fake(['api.openai.com/*' => $respuestaCola(['otra' => 'cosa'])]);
        expect(fn () => (new QueueWriter())->ask('encargos'))->toThrow(RuntimeException::class, 'sin el formato esperado');
    });
});
