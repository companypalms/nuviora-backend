<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La ficha de un producto (`.64`): qué es, para qué sirve, cómo funciona, lo que NO es, la información clave y las
 * fotos. Sus buyer personas están en ProductPersona. Las reglas están en Services\Meta\Products\ProductProfileRules.
 */
class ProductProfile extends Model
{
    protected $fillable = ['product_id', 'what_it_is', 'benefits', 'mechanism', 'not_this', 'key_info', 'photos_url', 'source', 'updated_by',
        'phase', 'testing_round', 'phase_at', 'testing_results'];

    /** .65: la fase (testing / iterate) y el resultado de cada ronda de testeo terminada. */
    protected $casts = ['phase_at' => 'datetime', 'testing_results' => 'array'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
