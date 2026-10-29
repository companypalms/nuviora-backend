<?php

use App\Services\Assistant\Ai\OpenAiModel;
use App\Services\Assistant\Conversation;
use App\Services\Assistant\Tools\Tool;
use App\Services\Assistant\Tools\ToolCatalog;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Assistant\Tools\ToolResult;
use App\Services\Assistant\Turn;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// El asistente del dueño (Etapa 4, paso 2) con OpenAI, con respuestas falsas (nunca se llama a OpenAI de verdad) y una
// herramienta de mentira. No toca la base de datos.

const LLAVE_FALSA_ASISTENTE = 'sk-llave-falsa-del-asistente';

/** Una herramienta de mentira: devuelve lo que se le diga (o falla como se le diga). */
function herramientaFalsa(string $name = 'ventas', ?Closure $run = null): Tool
{
    return new class ($name, $run) implements Tool {
        public function __construct(private string $n, private ?Closure $r)
        {
        }

        public function name(): string
        {
            return $this->n;
        }

        public function description(): string
        {
            return 'De prueba';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => ['periodo' => ['type' => 'string']], 'required' => ['periodo'], 'additionalProperties' => false];
        }

        public function working(array $args): string
        {
            return 'Revisando las ventas…';
        }

        public function run(array $args): ToolResult
        {
            return $this->r ? ($this->r)($args) : new ToolResult(['vendido' => '$100,00', 'periodo' => $args['periodo'] ?? null], [['type' => 'cards', 'cards' => []]]);
        }
    };
}

$llamada = fn (string $callId = 'call_1', string $name = 'ventas', string $args = '{"periodo":"hoy"}') => Http::response(['model' => 'gpt-6.1-sol', 'output' => [
    ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'razonamiento-cifrado', 'summary' => []],
    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => $callId, 'name' => $name, 'arguments' => $args],
], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'input_tokens_details' => ['cached_tokens' => 800]]]);

$texto = fn (string $text) => Http::response(['model' => 'gpt-6.1-sol', 'output' => [
    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]],
], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 80, 'input_tokens_details' => ['cached_tokens' => 1000]]]);

$turno = fn () => new Turn('instrucciones', [], '¿Cuánto vendimos hoy?', ToolCatalog::of(herramientaFalsa())->definitions());

describe('El asistente con OpenAI', function () use ($llamada, $texto, $turno) {
    beforeEach(fn () => config(['services.openai.key' => LLAVE_FALSA_ASISTENTE, 'services.openai.assistant_model' => 'modelo-de-prueba']));

    it('pregunta sin guardar nada en OpenAI, con herramientas estrictas y la llave solo en la cabecera', function () use ($texto, $turno) {
        Http::fake(['api.openai.com/*' => $texto('Hola')]);
        $reply = (new OpenAiModel())->next($turno());

        expect($reply->text)->toBe('Hola');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses'
            && $r['store'] === false
            && $r['model'] === 'modelo-de-prueba'
            && $r['tool_choice'] === 'auto'
            && $r['include'] === ['reasoning.encrypted_content']
            && $r['tools'][0]['strict'] === true && $r['tools'][0]['name'] === 'ventas'
            && $r->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA_ASISTENTE)
            && !str_contains(json_encode($r->data()), LLAVE_FALSA_ASISTENTE));
    });

    it('en la última vuelta no deja pedir más herramientas', function () use ($texto, $turno) {
        Http::fake(['api.openai.com/*' => $texto('Listo')]);
        (new OpenAiModel())->next($turno()->withMustAnswer(true));
        Http::assertSent(fn (Request $r) => $r['tool_choice'] === 'none');
    });

    it('avisa si OpenAI responde con error', function () use ($turno) {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'cuota agotada']], 429)]);
        expect(fn () => (new OpenAiModel())->next($turno()))->toThrow(RuntimeException::class, 'OpenAI respondió 429: cuota agotada');
    });

    it('una respuesta con herramienta: consulta, le devuelve el resultado con su razonamiento y contesta', function () use ($llamada, $texto, $turno) {
        Http::fakeSequence('api.openai.com/*')->pushResponse($llamada())->pushResponse($texto('Hoy vendimos **$100,00**.'));
        $progress = [];
        $out = (new Conversation(new OpenAiModel(), ToolCatalog::of(herramientaFalsa())))->run($turno(), function ($p) use (&$progress) {
            $progress[] = $p;
        });

        expect($out['text'])->toBe('Hoy vendimos **$100,00**.');
        expect($out['display'])->toBe([['type' => 'cards', 'cards' => []]]);
        expect($out['calls'])->toBe([['name' => 'ventas', 'arguments' => ['periodo' => 'hoy'], 'ok' => true]]);
        expect($out['usage'])->toBe(['input' => 2200, 'cached' => 1800, 'output' => 130]);
        expect($out['rounds'])->toBe(2);
        expect($progress)->toBe(['Revisando las ventas…']);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        expect($sent)->toHaveCount(2);
        $input = $sent[1]['input'];
        expect($input[1])->toBe(['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'razonamiento-cifrado', 'summary' => []]);
        expect($input[2])->toBe(['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ventas', 'arguments' => '{"periodo":"hoy"}']);
        expect($input[3]['type'])->toBe('function_call_output');
        expect($input[3]['call_id'])->toBe('call_1');
        expect(json_decode($input[3]['output'], true))->toBe(['vendido' => '$100,00', 'periodo' => 'hoy']);
    });

    it('una herramienta que no existe: se lo dice a la IA y sigue', function () use ($llamada, $texto, $turno) {
        Http::fakeSequence('api.openai.com/*')->pushResponse($llamada('call_9', 'inventada'))->pushResponse($texto('No pude.'));
        $out = (new Conversation(new OpenAiModel(), ToolCatalog::of(herramientaFalsa())))->run($turno(), fn () => null);
        expect($out['calls'][0]['ok'])->toBeFalse();
        expect(Http::recorded()[1][0]['input'][3]['output'])->toContain('No existe la herramienta');
    });

    it('una herramienta con un problema (un nombre que no existe): se lo dice a la IA y sigue', function () use ($llamada, $texto, $turno) {
        Http::fakeSequence('api.openai.com/*')->pushResponse($llamada())->pushResponse($texto('Esa ciudad no existe.'));
        $falla = herramientaFalsa('ventas', fn () => throw new ToolProblem('No encontré ninguna ciudad que se llame "X".'));
        $out = (new Conversation(new OpenAiModel(), ToolCatalog::of($falla)))->run($turno(), fn () => null);
        expect($out['text'])->toBe('Esa ciudad no existe.');
        expect($out['calls'][0]['ok'])->toBeFalse();
    });

    it('como mucho 6 vueltas: en la última tiene que contestar', function () use ($llamada, $texto, $turno) {
        $sequence = Http::fakeSequence('api.openai.com/*');
        for ($i = 0; $i < 5; $i++) {
            $sequence->pushResponse($llamada("call_{$i}"));
        }
        $sequence->pushResponse($texto('Con lo que tengo: $100,00.'));
        $out = (new Conversation(new OpenAiModel(), ToolCatalog::of(herramientaFalsa())))->run($turno(), fn () => null);

        expect($out['rounds'])->toBe(Conversation::MAX_ROUNDS);
        expect($out['text'])->toBe('Con lo que tengo: $100,00.');
        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        expect($sent->last()['tool_choice'])->toBe('none');
        expect($sent->first()['tool_choice'])->toBe('auto');
    });

    it('si se acaba el tiempo, también contesta con lo que tiene (y si no dice nada, un aviso)', function () use ($llamada, $turno) {
        Http::fakeSequence('api.openai.com/*')->pushResponse($llamada())->pushResponse(Http::response(['output' => []]));
        $now = 0.0;
        $clock = function () use (&$now) {
            $now += 60;

            return $now;
        };
        $out = (new Conversation(new OpenAiModel(), ToolCatalog::of(herramientaFalsa()), $clock))->run($turno(), fn () => null);
        expect($out['rounds'])->toBe(2);
        expect($out['text'])->toBe(Conversation::FALLBACK);
    });

    it('lo que lee la IA de una herramienta se recorta si es muy largo', function () {
        $long = Conversation::output(['x' => str_repeat('a', Conversation::MAX_TOOL_CHARS + 100)]);
        expect(mb_strlen($long))->toBeLessThan(Conversation::MAX_TOOL_CHARS + 200);
        expect($long)->toContain('recortado');
    });
});
