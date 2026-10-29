<?php

namespace App\Services\Assistant;

use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\Ai\AssistantModel;
use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Ai\OpenAiModel;
use App\Services\Assistant\Tools\ToolCatalog;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * El asistente del dueño (Etapa 4): un chat por persona. Guarda la pregunta y una respuesta "pensando"; la respuesta se
 * arma después de contestarle al navegador (answer), que va preguntando hasta que llega. Así una respuesta larga (la IA
 * consulta varias herramientas) no la corta el límite de tiempo del servidor, y se ve qué está revisando.
 *
 * Qué IA usa (mode):
 * - openai: hay OPENAI_API_KEY;
 * - example: sin clave y en la PC de pruebas (APP_ENV=local): el modo ejemplo, que no gasta nada;
 * - unavailable: sin clave en un servidor; off: apagado con ASSISTANT_ENABLED=false.
 */
class Assistant
{
    /** Los mensajes anteriores que se le mandan a la IA (solo el texto). */
    public const HISTORY = 12;

    /** Los mensajes que se ven al abrir el chat. */
    public const VISIBLE = 60;

    /** Una respuesta que lleva más que esto "pensando" se cortó (el servidor se reinició, por ejemplo). */
    public const STALE_MINUTES = 3;

    public const MAX_TEXT = 4000;

    public function __construct(private ToolCatalog $tools)
    {
    }

    public function mode(): string
    {
        if (!config('services.assistant.enabled', true)) {
            return 'off';
        }
        if (config('services.openai.key')) {
            return 'openai';
        }

        return app()->environment('local') ? 'example' : 'unavailable';
    }

    public function dailyLimit(): int
    {
        return max(1, (int) config('services.assistant.daily_limit', 150));
    }

    /** Las preguntas de hoy (las del chat limpio también cuentan). */
    public function todayCount(User $user): int
    {
        return AssistantMessage::where('user_id', $user->id)->where('role', 'user')
            ->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
    }

    /** El chat que se ve: los últimos mensajes, sin los limpiados. */
    public function messages(User $user): array
    {
        $this->expireStale($user);

        return AssistantMessage::where('user_id', $user->id)->visible()->orderByDesc('id')->limit(self::VISIBLE)->get()
            ->reverse()->map(fn (AssistantMessage $m) => $m->toChat())->values()->all();
    }

    public function message(User $user, int $id): ?AssistantMessage
    {
        $this->expireStale($user);

        return AssistantMessage::where('user_id', $user->id)->find($id);
    }

    /**
     * Guarda la pregunta y la respuesta "pensando". Si el navegador reintenta con el mismo client_id, devuelve las que ya
     * existen (no pregunta dos veces).
     *
     * @return array{0: AssistantMessage, 1: AssistantMessage} la pregunta y la respuesta
     */
    public function send(User $user, string $text, ?string $clientId): array
    {
        if ($clientId !== null && ($question = AssistantMessage::where('user_id', $user->id)->where('client_id', $clientId)->where('role', 'user')->first())) {
            $reply = AssistantMessage::where('reply_to_id', $question->id)->first();
            if ($reply) {
                return [$question, $reply];
            }
        }

        $mode = $this->mode();
        abort_if($mode === 'off', 503, 'El asistente está apagado.');
        abort_if($mode === 'unavailable', 503, 'La IA no está configurada en este servidor (falta OPENAI_API_KEY).');
        abort_if($this->todayCount($user) >= $this->dailyLimit(), 429, "Llegaste al límite de {$this->dailyLimit()} mensajes de hoy. Mañana se reinicia.");
        $this->expireStale($user);
        abort_if(AssistantMessage::where('user_id', $user->id)->where('status', AssistantMessage::THINKING)->exists(), 409, 'Espera a que termine la respuesta anterior.');

        $question = AssistantMessage::create([
            'user_id' => $user->id, 'role' => 'user', 'text' => trim($text), 'status' => AssistantMessage::DONE, 'client_id' => $clientId,
        ]);
        $reply = AssistantMessage::create([
            'user_id' => $user->id, 'role' => 'assistant', 'status' => AssistantMessage::THINKING, 'progress' => 'Pensando…', 'reply_to_id' => $question->id,
        ]);

        return [$question, $reply];
    }

    /** Arma la respuesta (después de contestarle al navegador). */
    public function answer(int $replyId): void
    {
        @set_time_limit(150);
        ignore_user_abort(true);
        $reply = AssistantMessage::find($replyId);
        $question = $reply?->reply_to_id ? AssistantMessage::find($reply->reply_to_id) : null;
        if (!$reply || !$question || $reply->status !== AssistantMessage::THINKING) {
            return;
        }
        $user = User::find($reply->user_id);

        try {
            $outcome = (new Conversation($this->model(), $this->tools))->run(
                new Turn(Instructions::build((string) $user?->names, CarbonImmutable::now()), $this->history($question), (string) $question->text, $this->tools->definitions()),
                fn (string $progress) => $reply->forceFill(['progress' => mb_strimwidth($progress, 0, 150)])->save(),
            );
            $reply->forceFill([
                'text' => $outcome['text'],
                'display' => $outcome['display'],
                'tool_calls' => $outcome['calls'],
                'model' => $outcome['model'],
                'input_tokens' => $outcome['usage']['input'],
                'cached_tokens' => $outcome['usage']['cached'],
                'output_tokens' => $outcome['usage']['output'],
                'status' => AssistantMessage::DONE,
                'progress' => null,
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $reply->forceFill([
                'status' => AssistantMessage::FAILED,
                'progress' => null,
                'error' => 'La IA no pudo responder ahora. Intenta de nuevo en un momento.',
            ])->save();
        }
    }

    /** "Limpiar chat": se dejan de ver (no se borran, y siguen contando para el límite del día). */
    public function clear(User $user): int
    {
        return AssistantMessage::where('user_id', $user->id)->visible()->update(['cleared_at' => now()]);
    }

    private function model(): AssistantModel
    {
        return $this->mode() === 'openai' ? new OpenAiModel() : new ExampleModel();
    }

    /** Lo que se habló antes de la pregunta (solo el texto, de los mensajes terminados que se ven). */
    private function history(AssistantMessage $question): array
    {
        return AssistantMessage::where('user_id', $question->user_id)->visible()->where('id', '<', $question->id)
            ->where('status', AssistantMessage::DONE)->whereNotNull('text')
            ->orderByDesc('id')->limit(self::HISTORY)->get(['role', 'text'])
            ->reverse()->map(fn ($m) => ['role' => $m->role === 'assistant' ? 'assistant' : 'user', 'text' => (string) $m->text])->values()->all();
    }

    private function expireStale(User $user): void
    {
        AssistantMessage::where('user_id', $user->id)->where('status', AssistantMessage::THINKING)
            ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => AssistantMessage::FAILED, 'progress' => null, 'error' => 'Se cortó la respuesta. Vuelve a preguntar.']);
    }
}
