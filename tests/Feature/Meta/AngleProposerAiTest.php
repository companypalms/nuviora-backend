<?php

use App\Services\Meta\AngleProposer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Meta Ads › Ángulos (Etapa 5, paso 1b): "Proponer con IA", con una respuesta falsa (nunca se llama a OpenAI de verdad).

const LLAVE_FALSA_ANGULOS = 'sk-llave-falsa-de-prueba-angulos';

$respuestaAngulos = fn (array $data) => Http::response(['status' => 'completed', 'output' => [
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]],
]]);

describe('Proponer ángulos con IA', function () use ($respuestaAngulos) {
    it('pregunta sin guardar nada en OpenAI, con la llave solo en la cabecera, y devuelve la propuesta', function () use ($respuestaAngulos) {
        config(['services.openai.key' => LLAVE_FALSA_ANGULOS, 'services.openai.insights_model' => 'modelo-de-prueba']);
        $data = ['angulos' => [['codigo' => 'FASP', 'nombre' => 'Dolor de tobillo', 'hipotesis' => 'H', 'igual_a' => '']]];
        Http::fake(['api.openai.com/*' => $respuestaAngulos($data)]);

        expect((new AngleProposer())->ask('textos'))->toBe($data);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses'
            && $r['store'] === false
            && $r['model'] === 'modelo-de-prueba'
            && $r['text']['format']['strict'] === true
            && $r['text']['format']['name'] === 'angulos'
            && $r->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA_ANGULOS)
            && !str_contains(json_encode($r->data()), LLAVE_FALSA_ANGULOS));
    });

    it('avisa si la IA responde con error', function () {
        config(['services.openai.key' => LLAVE_FALSA_ANGULOS]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'cuota agotada']], 429)]);
        expect(fn () => (new AngleProposer())->ask('textos'))->toThrow(RuntimeException::class, 'OpenAI respondió 429: cuota agotada');
    });

    it('avisa si la IA responde sin el formato esperado', function () use ($respuestaAngulos) {
        config(['services.openai.key' => LLAVE_FALSA_ANGULOS]);
        Http::fake(['api.openai.com/*' => $respuestaAngulos(['otra' => 'cosa'])]);
        expect(fn () => (new AngleProposer())->ask('textos'))->toThrow(RuntimeException::class, 'sin el formato esperado');
    });
});
