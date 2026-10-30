<?php

namespace App\Models;

use App\Services\Meta\Queue\QueueRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Un encargo de la cola de producción (Etapa 5, paso 4a): un batch de creativos de un producto, con sus piezas. Al
 * aprobarlo recibe su número B#### y cada pieza su C####. Ver QueueRules para los estados y EditorRules para los editores.
 */
class CreativeBatch extends Model
{
    protected $fillable = ['product_id', 'number', 'position', 'status', 'kind', 'angle_code', 'concept', 'hypothesis', 'audience',
        'reference_creative_id', 'learning_city_id', 'format', 'keep', 'offer', 'deliverables', 'urgency', 'due_date', 'source', 'proposed_on',
        'approved_at', 'approved_by', 'status_at', 'status_by', 'discard_reason', 'testing_at', 'tested_at', 'result', 'learning', 'created_by',
        'editor_id', 'assigned_at', 'assigned_by', 'started_at', 'delivered_at'];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'proposed_on' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'status_at' => 'datetime',
        'testing_at' => 'datetime',
        'tested_at' => 'datetime',
        'result' => 'array',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function pieces()
    {
        return $this->hasMany(CreativeBatchPiece::class)->orderBy('position');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function reference()
    {
        return $this->belongsTo(MetaCreative::class, 'reference_creative_id');
    }

    public function learningCity()
    {
        return $this->belongsTo(City::class, 'learning_city_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusUser()
    {
        return $this->belongsTo(User::class, 'status_by');
    }

    /** El editor que lo hace (paso 5a). */
    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /** Sus entregas, la más nueva primero. */
    public function deliveries()
    {
        return $this->hasMany(CreativeBatchDelivery::class)->orderByDesc('version');
    }

    /** "B0123", o null si todavía no se aprobó. */
    public function code(): ?string
    {
        return $this->number !== null ? QueueRules::batchCode($this->number) : null;
    }
}
