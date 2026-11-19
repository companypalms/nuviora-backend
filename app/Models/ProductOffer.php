<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `.164`: una oferta por cantidad de un producto en nuestro formulario de la tienda, como las de Releasit: "LLEVA 2 GALONES
 * CON 25% DE DESCUENTO" con su etiqueta ("🏆 LA OPCIÓN FAVORITA"). El precio sale de unidades × precio × (1 − %), así sigue
 * solo al precio del producto.
 */
class ProductOffer extends Model
{
    protected $fillable = ['product_id', 'units', 'discount_pct', 'title', 'badge', 'is_default', 'position', 'is_active'];

    protected $casts = ['units' => 'integer', 'discount_pct' => 'float', 'is_default' => 'boolean', 'position' => 'integer', 'is_active' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
