<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El modo de un producto en la publicidad (Etapa 5, paso 1b), lo pone el dueño. Lo usan las reglas del paso 2:
 * - normal: se analiza y se le piden creativos;
 * - liquidation (liquidación, como Audinet en el manual): sin creativos nuevos ni escalar, solo los anuncios cerca o
 *   debajo del break-even, y apagar al agotarse;
 * - paused (pausado) y abandoned (abandonado): no se le piden creativos ni se escala.
 * Sin fila, el producto está en modo normal. En proof van los avales y pruebas reales del producto (paso 4b), que la IA
 * usa tal cual en los encargos.
 */
class ProductAdSetting extends Model
{
    public const MODES = ['normal', 'liquidation', 'paused', 'abandoned'];

    protected $fillable = ['product_id', 'mode', 'note', 'proof', 'updated_by'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
