<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué se hizo con una aparición del calendario del dueño (Etapa 3): "done" (hecho) o "dismissed" (descartado), con quién
 * y cuándo. "Deshacer" la borra y la aparición vuelve a quedar pendiente (no es un dato de dinero).
 */
class CalendarMark extends Model
{
    protected $fillable = ['item_key', 'occurs_on', 'status', 'note', 'source', 'marked_by'];

    protected $casts = [
        'occurs_on' => 'date:Y-m-d',
    ];

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
