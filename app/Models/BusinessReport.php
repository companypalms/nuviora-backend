<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un reporte del negocio (`.48b`): diario, semanal o mensual, con los números del panel del dueño, la revisión de los
 * números y el texto de la IA. "Volver a generar" crea otra versión del mismo período.
 */
class BusinessReport extends Model
{
    public const TYPES = ['daily', 'weekly', 'monthly'];

    public const STATUSES = ['ok', 'example', 'no_ai', 'error'];

    protected $fillable = ['type', 'period_from', 'period_to', 'version', 'status', 'facts', 'text', 'warnings', 'model', 'tokens_in',
        'tokens_out', 'error', 'requested_by', 'viewed_at', 'viewed_by'];

    protected $casts = [
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'facts' => 'array',
        'text' => 'array',
        'warnings' => 'array',
        'viewed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
