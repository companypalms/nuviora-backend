<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.168` (dueño, 2026-11-21): una campaña que le manda a Meta el Purchase al entregar (o al pagar por adelantado) en vez de
 * al pedir, desde `starts_on` hasta `ends_on` (null = sigue marcada). En esos días el sistema la mide con el "pedido hecho"
 * (AddPaymentInfo), así sus reglas siguen contando pedidos como en las demás campañas.
 */
class MetaPurchaseMode extends Model
{
    protected $fillable = ['product_id', 'campaign_meta_id', 'starts_on', 'ends_on'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
}
