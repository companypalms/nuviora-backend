<?php

namespace App\Models;

use App\Services\Meta\Landings\LandingUrl;
use Illuminate\Database\Eloquent\Model;

/**
 * Una landing (Etapa 5, paso 6a): la página de destino de los anuncios, sin UTM ni variante. La crea la sincronización al
 * verla en un anuncio; el dueño le pone nombre y ángulo.
 */
class Landing extends Model
{
    protected $fillable = ['product_id', 'url_hash', 'url', 'name', 'angle_code', 'notes', 'first_seen_at', 'updated_by'];

    protected $casts = ['first_seen_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function changes()
    {
        return $this->hasMany(MetaAdLandingChange::class);
    }

    /** El nombre que le puso el dueño o, si no tiene, el de su URL ("flexistep", "flexistep · basu"). */
    public function label(): string
    {
        return $this->name ?: LandingUrl::label($this->url);
    }
}
