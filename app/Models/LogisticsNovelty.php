<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una novedad logística (§13): la reporta el rider y la atiende primero el operador. Lo comercial (cambio de dirección,
 * de producto, precio, cancelación…) lo escala a la vendedora.
 */
class LogisticsNovelty extends Model
{
    /** Los tipos de la tabla del §13, y si el operador puede resolverlos él o tiene que escalarlos. */
    public const TYPES = [
        'cliente_no_responde' => ['label' => 'Cliente no responde', 'escalar' => false],
        'cliente_tarda' => ['label' => 'Cliente tarda en bajar', 'escalar' => false],
        'referencia_acceso' => ['label' => 'Referencia o acceso al edificio', 'escalar' => false],
        'cambio_direccion' => ['label' => 'Cambio de ubicación o dirección', 'escalar' => true],
        'cambio_producto' => ['label' => 'Cambio de producto o cantidad', 'escalar' => true],
        'precio_descuento' => ['label' => 'Descuento, precio o negociación', 'escalar' => true],
        'cancelacion_cliente' => ['label' => 'El cliente cancela', 'escalar' => true],
        'pago_vuelto' => ['label' => 'Problema de pago o vuelto', 'escalar' => true],
        // Fran (idea 6 del 24/10, respuesta del 27/10): el operador coordina "no responde", "tarda en bajar" y "referencia o
        // acceso"; todo lo demás, también lo que el rider no sabe clasificar, va a la vendedora
        'otro' => ['label' => 'Otro', 'escalar' => true],
    ];

    protected $fillable = [
        'logistics_order_id', 'order_id', 'type', 'description', 'reported_by', 'opened_at', 'owner', 'comments',
        'escalated_at', 'escalated_by', 'resolved_at', 'resolved_by', 'result', 'lat', 'lng',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'comments' => 'array',
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function logisticsOrder()
    {
        return $this->belongsTo(LogisticsOrder::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? $this->type;
    }
}
