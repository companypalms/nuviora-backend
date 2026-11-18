<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una conversación del chat de la tienda (`.158`): un visitante (número al azar de su navegador) en un producto. "test"
 * son las del dueño probando desde su página.
 */
class StoreChatConversation extends Model
{
    public const STORE = 'store';
    public const TEST = 'test';

    protected $fillable = ['visitor', 'product_id', 'source', 'ip_hash', 'questions', 'unanswered'];

    protected $casts = ['unanswered' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StoreChatMessage::class, 'conversation_id');
    }
}
