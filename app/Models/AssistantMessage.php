<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Un mensaje del chat del asistente del dueño (Etapa 4): una pregunta (user) o una respuesta (assistant). La respuesta
 * nace "pensando" y se completa aparte (Services\Assistant\Assistant::answer).
 */
class AssistantMessage extends Model
{
    public const THINKING = 'thinking';
    public const DONE = 'done';
    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'role', 'text', 'status', 'progress', 'display', 'attachments', 'tool_calls', 'reply_to_id', 'client_id',
        'model', 'input_tokens', 'cached_tokens', 'output_tokens', 'error', 'cleared_at',
    ];

    protected $casts = [
        'display' => 'array',
        'attachments' => 'array',
        'tool_calls' => 'array',
        'cleared_at' => 'datetime',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /** Lo que ve el chat. Los tokens, las herramientas usadas y el texto leído de los archivos quedan solo en el servidor. */
    public function toChat(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'text' => $this->text,
            'status' => $this->status,
            'progress' => $this->progress,
            'display' => $this->displayForChat(),
            'attachments' => $this->attachmentsForChat(),
            'error' => $this->error,
            'client_id' => $this->client_id,
            'time' => $this->created_at?->format('H:i'),
        ];
    }

    /**
     * Los archivos que puede usar una respuesta (por ejemplo, de comprobante de un gasto): los de la pregunta o, si no
     * trae, los del último mensaje del dueño con archivos (manda la factura y después dice "anótala"). Lo usan la
     * propuesta y el Confirmar, así los dos ven los mismos.
     */
    public static function filesFor(self $question): array
    {
        if ($question->attachments) {
            return $question->attachments;
        }

        return self::where('user_id', $question->user_id)->visible()->where('role', 'user')->where('id', '<', $question->id)
            ->whereNotNull('attachments')->orderByDesc('id')->limit(1)->value('attachments') ?? [];
    }

    /** Lo que se ve debajo de la respuesta. Las tarjetas de cambios (paso 5) van con su estado de ahora. */
    public function displayForChat(): array
    {
        $display = $this->display ?? [];
        $ids = array_column(array_filter($display, fn ($b) => ($b['type'] ?? null) === 'action'), 'id');
        if ($ids === []) {
            return $display;
        }
        $actions = AssistantAction::where('user_id', $this->user_id)->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(
            fn ($b) => ($b['type'] ?? null) !== 'action' ? $b : (($a = $actions->get($b['id'])) ? ['type' => 'action', 'action' => $a->toCard()] : null),
            $display,
        )));
    }

    /** Los archivos de la pregunta, con un enlace firmado que vence en 12 h (como el chat interno). */
    public function attachmentsForChat(): array
    {
        $out = [];
        foreach ($this->attachments ?? [] as $i => $file) {
            $url = URL::temporarySignedRoute('assistant.attachment', now()->addHours(12), ['message' => $this->id, 'file' => $i]);
            $out[] = [
                'url' => $url,
                'download_url' => $url . '&download=1',
                'name' => $file['name'] ?? 'archivo',
                'mime' => $file['mime'] ?? '',
                'size' => (int) ($file['size'] ?? 0),
                // Como el chat interno: la foto y el PDF se abren en el navegador; el Excel se descarga
                'kind' => match ($file['kind'] ?? null) {
                    'image' => 'image',
                    'pdf' => 'pdf',
                    default => 'file',
                },
            ];
        }

        return $out;
    }
}
