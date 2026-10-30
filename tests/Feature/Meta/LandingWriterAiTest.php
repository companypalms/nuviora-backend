<?php

use App\Services\Meta\Landings\LandingWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Etapa 5, paso 6c: la IA que redacta los encargos de landing, con una respuesta falsa (nunca se llama a OpenAI de verdad).

const LLAVE_FALSA_LANDING = 'sk-llave-falsa-de-prueba-landing';

$respuestaLanding = fn (array $data) => Http::response(['status' => 'completed', 'output' => [
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]],
]]);

/** Un encargo como lo arma LandingProposer. */
function encargoLanding(string $key, string $kind, string $finding, ?string $angle = null): array
{
    return ['key' => $key, 'product' => 'FlexiStep', 'url' => 'https://tienda.com/products/flexistep', 'kind' => $kind, 'finding' => $finding,
        'angle' => $angle, 'angle_name' => $angle ? 'Dolor de rodilla' : null, 'angle_hypothesis' => null, 'best' => $finding === 'beaten' ? 'BASU' : null,
        'proof' => 'Registro sanitario 123', 'observed' => 'Lenta: de cada 100 clics llegan 45.'];
}

describe('Los encargos de landing redactados por la IA', function () use ($respuestaLanding) {
    it('pregunta sin guardar nada, con el tono del dueño, un cambio a la vez y sin curas ni avales inventados', function () use ($respuestaLanding) {
        config(['services.openai.key' => LLAVE_FALSA_LANDING, 'services.openai.meta_analysis_model' => 'modelo-de-prueba']);
        $data = ['encargos' => [['clave' => 'L1', 'hipotesis' => 'h', 'cambiar' => 'c', 'conservar' => 'k']]];
        Http::fake(['api.openai.com/*' => $respuestaLanding($data)]);

        $r = (new LandingWriter())->ask(LandingWriter::input([encargoLanding('L1', 'speed', 'slow')]));
        expect($r['text'])->toBe($data)->and($r['model'])->toBe('modelo-de-prueba');

        Http::assertSent(fn (Request $q) => $q->url() === 'https://api.openai.com/v1/responses'
            && $q['store'] === false && $q['text']['format']['strict'] === true && $q['text']['format']['name'] === 'encargos_landing'
            && str_contains($q['instructions'], 'fuerte y agresivo')
            && str_contains($q['instructions'], 'Nunca escribas que el producto cura una enfermedad')
            && str_contains($q['instructions'], 'No inventes médicos, estudios, certificaciones, avales ni testimonios')
            && str_contains($q['instructions'], 'Un solo cambio por encargo')
            && str_contains($q['input'][0]['content'][0]['text'], 'avales y pruebas reales del producto: Registro sanitario 123')
            && $q->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA_LANDING)
            && !str_contains(json_encode($q->data()), LLAVE_FALSA_LANDING));
    });

    it('avisa si la IA responde con error o sin el formato esperado', function () use ($respuestaLanding) {
        config(['services.openai.key' => LLAVE_FALSA_LANDING]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'cuota agotada']], 429)]);
        expect(fn () => (new LandingWriter())->ask('x'))->toThrow(RuntimeException::class, 'OpenAI respondió 429: cuota agotada');
        Http::fake(['api.openai.com/*' => $respuestaLanding(['otra' => 'cosa'])]);
        expect(fn () => (new LandingWriter())->ask('x'))->toThrow(RuntimeException::class);
    });

    it('sin IA, la plantilla según lo que se vio: píxel, velocidad, ángulo, otra que compra mejor y oferta', function () {
        $t = LandingWriter::example([
            encargoLanding('L1', 'speed', 'no_pixel'), encargoLanding('L2', 'speed', 'slow'), encargoLanding('L3', 'angle', 'angle_mismatch', 'NERV'),
            encargoLanding('L4', 'angle', 'beaten', 'FASP'), encargoLanding('L5', 'offer', 'angle_weak', 'GINE'),
        ])['encargos'];
        expect(array_column($t, 'clave'))->toBe(['L1', 'L2', 'L3', 'L4', 'L5'])
            ->and($t[0]['cambiar'])->toContain('píxel')
            ->and($t[1]['cambiar'])->toContain('fotos comprimidas')->and($t[1]['conservar'])->toContain('Los textos')
            ->and($t[2]['cambiar'])->toContain('ángulo NERV (Dolor de rodilla)')
            ->and($t[3]['cambiar'])->toContain('«BASU»')
            ->and($t[4]['cambiar'])->toContain('la oferta');
    });
});
