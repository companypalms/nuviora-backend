<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un análisis de campañas de la mañana (Etapa 5, paso 3a): los hechos de las reglas del dueño, la lista urgente, el texto
 * de la IA y lo que el dueño marcó. Uno o más por día ("Volver a analizar" crea otra versión).
 */
class MetaAnalysis extends Model
{
    public const STATUSES = ['ok', 'example', 'no_ai', 'error'];

    protected $fillable = ['date', 'version', 'status', 'facts', 'urgent', 'text', 'warnings', 'marks', 'model', 'tokens_in', 'tokens_out', 'error',
        'requested_by', 'viewed_at', 'viewed_by'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'facts' => 'array',
        'urgent' => 'array',
        'text' => 'array',
        'warnings' => 'array',
        'marks' => 'array',
        'viewed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
