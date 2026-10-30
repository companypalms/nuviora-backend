<?php

namespace App\Services\Assistant;

use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\Ai\AssistantModel;
use App\Services\Assistant\Ai\ExampleModel;
use App\Services\Assistant\Ai\OpenAiModel;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ActionDesk;
use App\Services\Assistant\Files\Attachments;
use App\Services\Assistant\Files\FileRules;
use App\Services\Assistant\Tools\ToolCatalog;
use App\Services\Assistant\Voice\ExampleTranscriber;
use App\Services\Assistant\Voice\OpenAiTranscriber;
use App\Services\Assistant\Voice\Transcriber;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
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

    /** El audio del micrófono que entiende la IA, según su contenido (Chrome graba webm; Safari, mp4). */
    public const AUDIO_FORMATS = [
        'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/mp4' => 'mp4', 'video/mp4' => 'mp4', 'audio/x-m4a' => 'm4a',
        'audio/m4a' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
    ];

    public function __construct(private ToolCatalog $tools, private Attachments $files, private ActionDesk $desk)
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
     * Guarda la pregunta (con sus archivos) y la respuesta "pensando". Si el navegador reintenta con el mismo client_id,
     * devuelve las que ya existen (no pregunta dos veces).
     *
     * @param array<int, UploadedFile> $files
     * @return array{0: AssistantMessage, 1: AssistantMessage} la pregunta y la respuesta
     */
    public function send(User $user, string $text, ?string $clientId, array $files = []): array
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

        $this->files->validate($files);
        $stored = $this->files->store($user, $files);
        try {
            $question = AssistantMessage::create([
                'user_id' => $user->id, 'role' => 'user', 'text' => trim($text), 'status' => AssistantMessage::DONE, 'client_id' => $clientId,
                'attachments' => $stored ?: null,
            ]);
            $reply = AssistantMessage::create([
                'user_id' => $user->id, 'role' => 'assistant', 'status' => AssistantMessage::THINKING, 'progress' => $stored ? 'Mirando tus archivos…' : 'Pensando…',
                'reply_to_id' => $question->id,
            ]);
        } catch (Throwable $e) {
            $this->files->delete($stored);
            throw $e;
        }

        return [$question, $reply];
    }

    /**
     * Lo que dijo el dueño al micrófono, en texto, para la caja del chat. El audio no se guarda: es el archivo temporal de
     * la petición, que se borra al terminar.
     *
     * @return array{text: string, example: bool} example: en modo ejemplo es una pregunta de muestra, no lo que dijo
     */
    public function transcribe(UploadedFile $audio): array
    {
        $mode = $this->mode();
        abort_if($mode === 'off', 503, 'El asistente está apagado.');
        abort_if($mode === 'unavailable', 503, 'La IA no está configurada en este servidor (falta OPENAI_API_KEY).');
        $format = self::AUDIO_FORMATS[(string) $audio->getMimeType()] ?? null;
        if ($format === null) {
            throw ValidationException::withMessages(['audio' => 'Ese audio no se puede convertir en texto. Prueba de nuevo o escribe tu pregunta.']);
        }

        $transcriber = $this->transcriber();
        try {
            $text = $transcriber->transcribe($audio->getRealPath(), $format);
        } catch (Throwable $e) {
            report($e);
            abort(502, 'No se pudo convertir la voz en texto ahora. Intenta de nuevo en un momento o escribe tu pregunta.');
        }
        if (trim($text) === '') {
            throw ValidationException::withMessages(['audio' => 'No se escuchó nada. Prueba de nuevo, más cerca del micrófono.']);
        }

        return ['text' => mb_substr(trim($text), 0, self::MAX_TEXT), 'example' => $transcriber instanceof ExampleTranscriber];
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
        $progress = fn (string $progress) => $reply->forceFill(['progress' => mb_strimwidth($progress, 0, 150)])->save();

        try {
            $files = [];
            if ($question->attachments) {
                // Un PDF o un Excel grande necesita memoria para leerlo, y las fotos van enteras a la IA
                if (self::bytes((string) ini_get('memory_limit')) < 512 * 1024 * 1024) {
                    @ini_set('memory_limit', '512M');
                }
                $read = $this->files->read($question->attachments, $progress);
                $question->forceFill(['attachments' => $read])->save();
                $files = $this->files->parts($read);
            }
            // Las herramientas que cambian algo proponen a nombre del dueño, en esta respuesta (paso 5: las tarjetas)
            $tools = $user ? $this->tools->forAnswer(new ActionContext($user, $reply->id, AssistantMessage::filesFor($question), CarbonImmutable::now())) : $this->tools;
            $outcome = (new Conversation($this->model(), $tools))->run(
                new Turn(Instructions::build((string) $user?->names, CarbonImmutable::now()), $this->history($question), (string) $question->text, $tools->definitions(), files: $files),
                $progress,
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

    private function transcriber(): Transcriber
    {
        return $this->mode() === 'openai' ? new OpenAiTranscriber() : new ExampleTranscriber();
    }

    /**
     * Lo que se habló antes de la pregunta (el texto de los mensajes terminados que se ven). De los archivos, el texto de
     * los PDF y Excel más recientes mientras quepa; de las fotos, solo que se mandaron.
     */
    private function history(AssistantMessage $question): array
    {
        $budget = FileRules::HISTORY_TOTAL_CHARS;
        $items = [];
        // Del más nuevo al más viejo, así el espacio para los archivos es para los últimos
        $messages = AssistantMessage::where('user_id', $question->user_id)->visible()->where('id', '<', $question->id)
            ->where('status', AssistantMessage::DONE)->whereNotNull('text')
            ->orderByDesc('id')->limit(self::HISTORY)->get(['id', 'role', 'text', 'attachments']);
        // Cómo terminaron las tarjetas de cambios que propuso (confirmada, cancelada, vencida…)
        $notes = $this->desk->notes($messages->where('role', 'assistant')->pluck('id')->all());
        foreach ($messages as $m) {
            $text = (string) $m->text;
            if ($m->role === 'user' && $m->attachments) {
                $text = trim(($text !== '' ? $text : '(Mandó archivos, sin texto.)') . "\n\n" . $this->files->remembered($m->attachments, $budget));
            }
            if (isset($notes[$m->id])) {
                $text .= $notes[$m->id];
            }
            $items[] = ['role' => $m->role === 'assistant' ? 'assistant' : 'user', 'text' => $text];
        }

        return array_reverse($items);
    }

    /** "512M" => bytes. */
    private static function bytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private function expireStale(User $user): void
    {
        AssistantMessage::where('user_id', $user->id)->where('status', AssistantMessage::THINKING)
            ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => AssistantMessage::FAILED, 'progress' => null, 'error' => 'Se cortó la respuesta. Vuelve a preguntar.']);
    }
}
