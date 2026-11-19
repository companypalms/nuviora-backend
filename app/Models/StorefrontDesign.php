<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.164`: cómo se ve nuestro formulario en la landing de un producto (dueño: "que se pueda adaptar al branding de cada
 * landing page, en cuanto a colores, tipografía, todo de hablar, textos, estilos"): los textos que cambió, los colores, la
 * letra (null = la de la página), los bordes y su CSS. Lo que no cambió sale del diseño por defecto (StorefrontRules).
 */
class StorefrontDesign extends Model
{
    protected $fillable = ['product_id', 'texts', 'colors', 'font', 'radius', 'custom_css'];

    protected $casts = ['texts' => 'array', 'colors' => 'array', 'radius' => 'integer'];
}
