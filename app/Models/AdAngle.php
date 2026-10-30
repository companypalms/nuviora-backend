<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un ángulo de un producto (Etapa 5, paso 1b): el código que aparece en los nombres (FASP, NERV, GINE), con su nombre y
 * su hipótesis. El sistema lo crea al encontrar el código; la IA propone el nombre y la hipótesis leyendo el texto de los
 * anuncios; el dueño corrige y confirma. Si es lo mismo que otro código (FAS dentro de FASP), apunta a él con alias_of.
 */
class AdAngle extends Model
{
    public const STATUSES = ['detected', 'proposed', 'confirmed'];

    protected $fillable = ['product_id', 'code', 'alias_of', 'name', 'hypothesis', 'status', 'proposed_by', 'suggested_alias',
        'proposed_at', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['proposed_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function canonical()
    {
        return $this->belongsTo(self::class, 'alias_of');
    }

    public function aliases()
    {
        return $this->hasMany(self::class, 'alias_of');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
