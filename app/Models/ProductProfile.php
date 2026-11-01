<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La ficha de un producto (`.64`): qué es, para qué sirve, cómo funciona, lo que NO es, la información clave y las
 * fotos. Sus buyer personas están en ProductPersona. Las reglas están en Services\Meta\Products\ProductProfileRules.
 */
class ProductProfile extends Model
{
    protected $fillable = ['product_id', 'what_it_is', 'benefits', 'mechanism', 'not_this', 'key_info', 'photos_url', 'source', 'updated_by'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
