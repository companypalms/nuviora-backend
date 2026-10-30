<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un creativo propio, identificado por el creative_tracking_id (Módulo 2, §32). Puede estar en varios anuncios, ad sets
 * y cuentas (§33). Su estado (Testing, Winner, Fatigued, Loser, Archived…) se asigna a mano (§41).
 * Etapa 5, paso 1b: la identidad es "{producto}:{código}" (el C004 de un producto no se junta con el de otro), con el
 * nombre que se muestra (label) y el ángulo, el batch y el padre (iteración) que dicen sus anuncios.
 */
class MetaCreative extends Model
{
    public const STATUSES = ['testing', 'winner', 'fatigued', 'loser', 'archived'];

    protected $fillable = ['tracking_id', 'product_id', 'label', 'creative_code', 'angle_code', 'batch_code', 'iteration_of', 'type',
        'status', 'status_changed_by', 'status_changed_at'];

    protected $casts = ['status_changed_at' => 'datetime'];

    public function ads()
    {
        return $this->hasMany(MetaAd::class, 'meta_creative_ref_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Lo que se muestra: el nombre del anuncio sin la copia ("CRE001-FLEX-FAS-BATCH004"), o el código si no hay. */
    public function displayName(): string
    {
        return $this->label ?: (str_contains((string) $this->tracking_id, ':') ? substr((string) $this->tracking_id, strpos($this->tracking_id, ':') + 1) : (string) $this->tracking_id);
    }
}
