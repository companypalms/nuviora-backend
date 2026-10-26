<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'quantity',
        'reserved_stock',
        'defective_stock',
        'blocked_stock',
        'sizes_stock', // ya no se escribe: el stock por talla está en inventory_variants (tarea 4)
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'reserved_stock'  => 'integer',
        'defective_stock' => 'integer',
        'blocked_stock'   => 'integer',
        'sizes_stock'     => 'array',
    ];

    protected $appends = ['useful_stock'];

    /**
     * Stock Útil = Físico - Reservado - Defectuoso - Bloqueado
     * Regla de Oro #1 del plan SCM
     */
    public function getUsefulStockAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_stock - $this->defective_stock - $this->blocked_stock);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
