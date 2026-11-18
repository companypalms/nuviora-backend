<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `.161`: un cliente del chat de la tienda por contactar: lo que el chat no supo responder y el WhatsApp que dejó. Sale en
 * "Chat de la tienda › Por contactar", en la campanita y en el calendario del dueño hasta que alguien toca "Ya lo contacté".
 * "assigned_to" queda para cuando se lo pase a una vendedora o a la líder de ventas.
 */
class StoreChatCallback extends Model
{
    public const PENDING = 'pending';
    public const DONE = 'done';

    protected $fillable = ['conversation_id', 'product_id', 'question', 'phone', 'source', 'status', 'assigned_to', 'contacted_by', 'contacted_at'];

    protected $casts = ['contacted_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(StoreChatConversation::class, 'conversation_id');
    }
}
