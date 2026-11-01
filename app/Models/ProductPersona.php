<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un buyer persona de la ficha de un producto (`.64`). Quitarlo de la ficha lo archiva (archived_at): los encargos que
 * ya le hablaban lo siguen mostrando.
 */
class ProductPersona extends Model
{
    protected $fillable = ['product_id', 'name', 'who', 'pain', 'wants', 'objections', 'position', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
