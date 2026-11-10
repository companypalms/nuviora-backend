<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.131`: un cambio que el sistema vio en Meta (presupuesto, pausar o prender, apagar anuncios, batch nuevo, quitar un mínimo),
 * con su hipótesis y si se cumplió. Lo arma MetaChanges; el dueño solo le agrega su nota. Las reglas, en ChangeRules.
 */
class MetaChange extends Model
{
    protected $fillable = ['fingerprint', 'kind', 'product_id', 'campaign_meta_id', 'adset_meta_id', 'city', 'date', 'changed_at', 'detail', 'mission',
        'target_c', 'target_be', 'hypothesis', 'criterion', 'before', 'after', 'result', 'result_text', 'measured_at', 'note'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'changed_at' => 'datetime',
        'measured_at' => 'datetime',
        'detail' => 'array',
        'mission' => 'array',
        'before' => 'array',
        'after' => 'array',
        'target_c' => 'float',
        'target_be' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
