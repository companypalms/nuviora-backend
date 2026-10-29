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
            'display' => $this->display ?? [],
            'attachments' => $this->attachmentsForChat(),
            'error' => $this->error,
            'client_id' => $this->client_id,
            'time' => $this->created_at?->format('H:i'),
        ];
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
