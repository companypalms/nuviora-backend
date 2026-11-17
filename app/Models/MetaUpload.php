<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** `.155`: una subida de un batch a una campaña de Meta (MetaUploader). */
class MetaUpload extends Model
{
    protected $fillable = [
        'creative_batch_id', 'campaign_meta_id', 'meta_ad_account_id', 'meta_analysis_id', 'mission_key', 'status', 'step', 'paused',
        'plan', 'created', 'error', 'attempts', 'requested_by', 'started_at', 'waiting_since', 'finished_at',
    ];

    protected $casts = [
        'paused' => 'boolean',
        'plan' => 'array',
        'created' => 'array',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'waiting_since' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Las que todavía están trabajando. */
    public const OPEN = ['queued', 'running', 'waiting'];

    public function batch()
    {
        return $this->belongsTo(CreativeBatch::class, 'creative_batch_id');
    }

    public function account()
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    public function analysis()
    {
        return $this->belongsTo(MetaAnalysis::class, 'meta_analysis_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
