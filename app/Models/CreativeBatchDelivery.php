<?php

namespace App\Models;

use App\Services\Meta\Queue\EditorRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Una entrega de un encargo (Etapa 5, paso 5a): la versión (V01, V02…), el enlace de Drive de cada pieza, la ficha del
 * editor (concepto, ángulo y el hook de cada pieza) y la revisión: pendiente, aprobada (pasa a la banca) o devuelta con
 * un comentario.
 */
class CreativeBatchDelivery extends Model
{
    protected $fillable = ['creative_batch_id', 'version', 'editor_id', 'concept', 'angle', 'notes', 'pieces', 'review', 'reviewed_by', 'reviewed_at', 'comment'];

    protected $casts = [
        'pieces' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(CreativeBatch::class, 'creative_batch_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** "V01". */
    public function label(): string
    {
        return EditorRules::version($this->version);
    }
}
