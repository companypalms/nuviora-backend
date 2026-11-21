<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `.164`: un pedido que entró por nuestro formulario de la tienda. Su id es el número del pedido (WEB-12); guarda lo que
 * vio el cliente (oferta, Bs y tasa), de dónde vino y lo que necesita la API de conversiones de Meta (el mismo event_id del
 * píxel, para que no se cuente doble). Nunca guarda la clave de Meta.
 */
class StorefrontOrder extends Model
{
    protected $fillable = ['order_id', 'product_id', 'offer_id', 'variant_id', 'units', 'phone_last10', 'city_id', 'total_usd', 'total_bs',
        'rate', 'express_fee', 'event_id', 'visitor_id', 'landing_url', 'referrer', 'page_url', 'fbp', 'fbc', 'ip', 'user_agent',
        'capi_status', 'capi_error', 'whatsapp_opened_at', 'payment', 'purchase_mode', 'placed_capi_status', 'purchase_sent_at', 'after_hours',
        'ack_sent_at', 'confirm_due_at', 'confirm_sent_at', 'confirm_status'];

    protected $casts = ['total_usd' => 'float', 'total_bs' => 'integer', 'rate' => 'float', 'express_fee' => 'float', 'units' => 'integer',
        'whatsapp_opened_at' => 'datetime', 'purchase_sent_at' => 'datetime', 'after_hours' => 'boolean', 'ack_sent_at' => 'datetime',
        'confirm_due_at' => 'datetime', 'confirm_sent_at' => 'datetime'];

    /** `.168`: las formas de pago del formulario. */
    public const COD = 'cod';
    public const PREPAID = 'pago_movil';
    public const ADVISOR = 'asesora';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
