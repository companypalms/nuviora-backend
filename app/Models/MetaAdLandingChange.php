<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Desde cuándo un anuncio lleva a una landing (Etapa 5, paso 6a). La primera de cada anuncio vale también para antes. */
class MetaAdLandingChange extends Model
{
    protected $fillable = ['meta_ad_id', 'landing_id', 'url', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function ad()
    {
        return $this->belongsTo(MetaAd::class, 'meta_ad_id');
    }

    public function landing()
    {
        return $this->belongsTo(Landing::class);
    }
}
