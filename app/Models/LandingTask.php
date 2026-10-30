<?php

namespace App\Models;

use App\Services\Meta\Landings\LandingTaskRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Un encargo de landing (Etapa 5, paso 6b): qué landing cambiar y por qué, quién lo hace, sus entregas y, cuando se
 * publica, el test antes/después con los mismos anuncios. Al aprobarlo recibe su número L####.
 */
class LandingTask extends Model
{
    protected $fillable = [
        'product_id', 'number', 'status', 'kind', 'landing_id', 'angle_code', 'observed', 'hypothesis', 'change', 'keep', 'notes', 'due_date',
        'source', 'proposed_on', 'editor_id', 'approved_at', 'approved_by', 'started_at', 'delivered_at', 'status_at', 'status_by', 'discard_reason',
        'published_at', 'published_by', 'published_url', 'test_landing_id', 'tested_at', 'result', 'learning', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'proposed_on' => 'date',
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'delivered_at' => 'datetime',
        'status_at' => 'datetime',
        'published_at' => 'datetime',
        'tested_at' => 'datetime',
        'result' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function landing()
    {
        return $this->belongsTo(Landing::class);
    }

    public function testLanding()
    {
        return $this->belongsTo(Landing::class, 'test_landing_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function deliveries()
    {
        return $this->hasMany(LandingTaskDelivery::class)->orderByDesc('version');
    }

    /** "L0123", o null si todavía no se aprobó. */
    public function code(): ?string
    {
        return $this->number !== null ? LandingTaskRules::code($this->number) : null;
    }
}
