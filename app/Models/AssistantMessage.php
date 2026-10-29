<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /** Lo que ve el chat. Los tokens y las herramientas usadas quedan solo en la base. */
    public function toChat(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'text' => $this->text,
            'status' => $this->status,
            'progress' => $this->progress,
            'display' => $this->display ?? [],
            'error' => $this->error,
            'client_id' => $this->client_id,
            'time' => $this->created_at?->format('H:i'),
        ];
    }
}
