<?php

namespace App\Services\StoreChat;

use App\Models\Product;
use App\Models\ProductProfile;
use App\Models\Setting;
use App\Models\StoreChatConversation;
use App\Models\StoreChatMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * El chat con IA de la tienda (`.158`): responde una pregunta de un cliente sobre un producto con su ficha, la revisa
 * antes de enviarla y la guarda. La IA no tiene herramientas ni ningún otro dato del sistema, y nada se guarda en OpenAI.
 * En `.158` solo lo usa el dueño para probar (source "test"); el botón en la tienda llega con la `.159`.
 */
class StoreChat
{
    private const TIMEOUT_SECONDS = 30;

    private const MAX_OUTPUT_TOKENS = 1200; // incluye lo que razona; la respuesta pide 60 palabras como máximo

    public function __construct(private StoreChatFacts $facts)
    {
    }

    public static function settings(): array
    {
        return StoreChatRules::settings(Setting::get(StoreChatRules::SETTING));
    }

    /** Guarda lo que llega; lo que no llega queda como estaba (el asistente cambia solo el teléfono). */
    public static function saveSettings(array $settings): array
    {
        $clean = StoreChatRules::settings($settings + self::settings());
        Setting::set(StoreChatRules::SETTING, $clean);

        return $clean;
    }

    /** `.159`: guarda los "Más detalles" de un producto (solo si ya tiene su ficha guardada). */
    public static function saveDetails(Product $product, ?string $details): string
    {
        $profile = ProductProfile::where('product_id', $product->id)->first();
        abort_if($profile === null, 422, 'Guarda primero la ficha del producto (Qué es, Para qué sirve y Cómo funciona).');
        $details = trim((string) $details);
        abort_if(mb_strlen($details) > StoreChatRules::DETAILS_MAX, 422, 'Los detalles son muy largos (hasta ' . number_format(StoreChatRules::DETAILS_MAX, 0, ',', '.') . ' letras).');
        $profile->forceFill(['chat_details' => $details !== '' ? $details : null])->save();

        return $details;
    }

    /** openai, example (la PC de pruebas, sin llave) o unavailable (sin llave en el servidor). */
    public function mode(): string
    {
        if (config('services.openai.key')) {
            return 'openai';
        }

        return app()->environment('local') ? 'example' : 'unavailable';
    }

    /** Las respuestas de hoy que llamaron a la IA (las de la tienda y las de prueba; los exámenes no): el tope del día. */
    public function answeredToday(): int
    {
        return StoreChatMessage::whereIn('status', StoreChatMessage::BILLED)->where('created_at', '>=', today())
            ->whereHas('conversation', fn ($q) => $q->where('source', '<>', StoreChatConversation::EXAM))->count();
    }

    /**
     * Responde una pregunta. La tienda solo pregunta si el chat está prendido en ese producto; el dueño prueba siempre.
     * Con un tope alcanzado sale el mensaje fijo (y el botón sigue); si la IA falla, "intenta de nuevo". En un examen cada
     * pregunta va sola (como un cliente nuevo) y no hay topes.
     * `.161`: lo que no sabe (o la revisión paró) pide el WhatsApp; si en un mensaje después viene el número, se guarda en
     * "Por contactar" sin llamar a la IA (que nunca ve el número) y el cliente recibe la confirmación con el horario.
     *
     * @return array{id: int, question: string, answer: string, status: string, reason: ?string, raw: ?string, ready: bool, note: string, button: string}
     */
    public function ask(Product $product, string $visitor, string $question, string $source, ?string $ip, CarbonImmutable $now): array
    {
        $settings = self::settings();
        if ($source === StoreChatConversation::STORE) {
            abort_unless($settings['enabled'] && in_array($product->id, $settings['product_ids'], true), 404, 'El chat no está prendido en este producto.');
        }
        $facts = $this->facts->for($product, $now);
        abort_unless(StoreChatRules::hasFicha($facts['ficha']), 422, 'Este producto no tiene ficha: el chat no sabría qué responder. Llénala en Integraciones › Meta Ads › Ficha del producto.');
        $mode = $this->mode();
        abort_unless(in_array($mode, ['openai', 'example'], true), 503, 'El chat no está disponible ahora.');
        $raw = trim(mb_substr($question, 0, StoreChatRules::MAX_QUESTION));
        $question = StoreChatRules::mask($raw);
        abort_if($question === '', 422, 'Escribe tu pregunta.');

        $conversation = StoreChatConversation::firstOrCreate(
            ['visitor' => $visitor, 'product_id' => $product->id, 'source' => $source],
            ['ip_hash' => $ip !== null ? hash('sha256', $ip . '|' . config('app.key')) : null],
        );
        $exam = $source === StoreChatConversation::EXAM;
        // .161: le había pedido el WhatsApp y aquí viene
        $phone = !$exam && $conversation->awaiting_phone ? StoreChatRules::phoneIn($raw) : null;
        if ($phone !== null) {
            StoreChatCallbacks::create($conversation, (string) $conversation->awaiting_question, $phone);
            $conversation->forceFill(['awaiting_phone' => false, 'awaiting_question' => null])->save();

            return $this->save($conversation, $question, StoreChatRules::phoneSaved($settings['hours']), StoreChatMessage::CALLBACK, false);
        }
        $limited = !$exam && ($this->answeredToday() >= $settings['daily_answers']
            || ($source === StoreChatConversation::STORE && $this->visitorToday($visitor) >= $settings['per_visitor']));
        if ($limited) {
            return $this->save($conversation, $question, StoreChatRules::LIMIT, StoreChatMessage::LIMIT, false);
        }

        // .161: también lo que la revisión paró y la confirmación del WhatsApp (lo que el cliente ya leyó)
        $history = $exam ? collect() : $conversation->messages()
            ->whereIn('status', [StoreChatMessage::ANSWERED, StoreChatMessage::UNKNOWN, StoreChatMessage::BLOCKED, StoreChatMessage::CALLBACK])
            ->orderByDesc('id')->take(StoreChatRules::HISTORY)->get()->reverse()->values();
        try {
            $reply = $mode === 'openai'
                ? $this->openAi(StoreChatRules::instructions($facts), self::input($history, $question))
                : StoreChatRules::example($facts, $question) + ['model' => 'ejemplo', 'in' => null, 'out' => null];
        } catch (Throwable $e) {
            report($e);

            return $this->save($conversation, $question, StoreChatRules::FAILED, StoreChatMessage::FAILED, false);
        }

        $answer = StoreChatRules::plain((string) $reply['respuesta']);
        $context = [$question, ...$history->flatMap(fn ($m) => [$m->question, (string) $m->answer])->all()];
        $reason = $answer === '' ? 'La IA respondió vacío' : StoreChatRules::check($answer, $facts, $context);
        $status = $reason !== null ? StoreChatMessage::BLOCKED : ($reply['sabe'] ? StoreChatMessage::ANSWERED : StoreChatMessage::UNKNOWN);

        // .161: lo que no supo (o la revisión paró) queda esperando el WhatsApp
        if (!$exam && $status !== StoreChatMessage::ANSWERED) {
            $conversation->forceFill(['awaiting_phone' => true, 'awaiting_question' => $question])->save();
        }

        return $this->save($conversation, $question, $reason !== null ? StoreChatRules::askPhone($settings['hours']) : $answer, $status, $reason === null && $reply['listo'], [
            'blocked_reason' => $reason, 'raw_answer' => $reason !== null ? $answer : null,
            'model' => $reply['model'], 'input_tokens' => $reply['in'], 'output_tokens' => $reply['out'],
        ]);
    }

    /** Lo que se le manda a la IA: lo que ya se habló en esta conversación y la pregunta nueva. */
    public static function input(iterable $history, string $question): array
    {
        $input = [];
        foreach ($history as $m) {
            $input[] = ['role' => 'user', 'content' => $m->question];
            $input[] = ['role' => 'assistant', 'content' => (string) $m->answer];
        }
        $input[] = ['role' => 'user', 'content' => $question];

        return $input;
    }

    /** Lo que ve el chat de un mensaje guardado. */
    public static function toChat(StoreChatMessage $m, bool $ready = false): array
    {
        return [
            'id' => $m->id,
            'question' => $m->question,
            'answer' => (string) $m->answer,
            'status' => $m->status,
            'reason' => $m->blocked_reason,
            'raw' => $m->raw_answer,
            'ready' => $ready,
            'note' => StoreChatRules::FORM_NOTE,
            'button' => StoreChatRules::BUTTON,
        ];
    }

    private function save(StoreChatConversation $conversation, string $question, string $answer, string $status, bool $ready, array $extra = []): array
    {
        $message = $conversation->messages()->create(['question' => $question, 'answer' => $answer, 'status' => $status] + $extra);
        $conversation->forceFill([
            'questions' => $conversation->questions + 1,
            'unanswered' => $conversation->unanswered || in_array($status, [StoreChatMessage::UNKNOWN, StoreChatMessage::BLOCKED], true),
        ])->save();

        return self::toChat($message, $ready);
    }

    /** Las preguntas de hoy de ese visitante en la tienda (en cualquier producto). */
    private function visitorToday(string $visitor): int
    {
        return StoreChatMessage::whereIn('status', StoreChatMessage::BILLED)->where('created_at', '>=', today())
            ->whereHas('conversation', fn ($q) => $q->where('visitor', $visitor)->where('source', StoreChatConversation::STORE))->count();
    }

    /**
     * Pregunta a OpenAI: sin herramientas, sin guardar nada allá y con el formato fijo (respuesta, sabe, listo).
     *
     * @return array{respuesta: string, sabe: bool, listo: bool, model: string, in: ?int, out: ?int}
     */
    private function openAi(string $instructions, array $input): array
    {
        $model = (string) config('services.openai.store_chat_model', 'gpt-6-luna');
        $response = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false,
                'reasoning' => ['effort' => 'low'],
                'instructions' => $instructions,
                'input' => $input,
                'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'respuesta_tienda', 'strict' => true, 'schema' => StoreChatRules::SCHEMA]],
            ]);
        if ($response->failed()) {
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200));
        }
        $text = null;
        foreach ($response->json('output') ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    return ['respuesta' => StoreChatRules::SAFE, 'sabe' => false, 'listo' => false, 'model' => $model,
                        'in' => $response->json('usage.input_tokens'), 'out' => $response->json('usage.output_tokens')];
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = ($text ?? '') . $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || !is_string($data['respuesta'] ?? null) || !is_bool($data['sabe'] ?? null) || !is_bool($data['listo'] ?? null)) {
            throw new RuntimeException('La IA respondió sin el formato esperado.');
        }

        return ['respuesta' => $data['respuesta'], 'sabe' => $data['sabe'], 'listo' => $data['listo'], 'model' => $model,
            'in' => $response->json('usage.input_tokens'), 'out' => $response->json('usage.output_tokens')];
    }
}
