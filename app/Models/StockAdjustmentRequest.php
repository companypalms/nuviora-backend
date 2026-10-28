<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una solicitud de ajuste de stock del operador, que aplica administración (§16.2.3, §29 punto 22). */
class StockAdjustmentRequest extends Model
{
    public const PENDING = 'pendiente';
    public const APPROVED = 'aprobada';
    public const REJECTED = 'rechazada';

    protected $fillable = ['center_id', 'warehouse_id', 'product_id', 'variant_id', 'quantity', 'reason', 'photo_path', 'requested_by',
        'status', 'decided_by', 'decided_at', 'decision_note', 'movement_id'];

    protected $casts = ['decided_at' => 'datetime', 'quantity' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
