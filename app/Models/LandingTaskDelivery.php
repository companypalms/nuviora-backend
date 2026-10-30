<?php

namespace App\Models;

use App\Services\Meta\Queue\EditorRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Una entrega de un encargo de landing (Etapa 5, paso 6b): la versión (V01, V02…), el enlace de vista previa de Shopify,
 * qué cambió y la revisión del dueño: pendiente, aprobada (pasa a "Para publicar") o devuelta con un comentario.
 */
class LandingTaskDelivery extends Model
{
    protected $fillable = ['landing_task_id', 'version', 'editor_id', 'preview_url', 'notes', 'review', 'reviewed_by', 'reviewed_at', 'comment'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function task()
    {
        return $this->belongsTo(LandingTask::class, 'landing_task_id');
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
