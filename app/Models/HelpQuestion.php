<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pregunta de un editor a su ayuda "¿Dudas?" (.96) con su respuesta. Los tokens y el modelo quedan solo en el
 * servidor.
 */
class HelpQuestion extends Model
{
    public const DONE = 'done';
    public const FAILED = 'failed';

    protected $fillable = ['user_id', 'book', 'question', 'answer', 'status', 'model', 'input_tokens', 'output_tokens'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lo que ve el chat. */
    public function toChat(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->status === self::DONE ? $this->answer : null,
            'failed' => $this->status === self::FAILED,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
