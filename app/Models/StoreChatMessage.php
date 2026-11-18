<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pregunta del chat de la tienda (`.158`) con lo que vio el cliente.
 * - answered: respondió con la ficha; unknown: no lo sabía (dijo que lo confirma la asesora);
 * - blocked: la revisión la paró (un monto en dólares, un número que no está en los datos) y salió la respuesta segura;
 * - failed: la IA falló; limit: se llegó a un tope y salió el mensaje fijo.
 */
class StoreChatMessage extends Model
{
    public const ANSWERED = 'answered';
    public const UNKNOWN = 'unknown';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';
    public const LIMIT = 'limit';

    /** Las que cuentan para el tope del día (las que llamaron a la IA). */
    public const BILLED = [self::ANSWERED, self::UNKNOWN, self::BLOCKED];

    protected $fillable = ['conversation_id', 'question', 'answer', 'status', 'blocked_reason', 'raw_answer', 'model', 'input_tokens', 'output_tokens'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(StoreChatConversation::class, 'conversation_id');
    }
}
