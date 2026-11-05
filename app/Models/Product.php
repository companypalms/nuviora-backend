<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    //
    use HasFactory;
    
    protected static function booted()
    {
        static::updated(function ($product) {
            if ($product->wasChanged('showable_name')) {
                $product->orderProducts()->update([
                    'showable_name' => $product->showable_name
                ]);
            }
        });
    }

    /**
     * El producto de una línea de un pedido de Shopify: por su título, como siempre, y `.89`, si el título no coincide, por
     * su número de Shopify (el que guarda el asistente con el link de la tienda, o uno al que le cambiaron el nombre en
     * Shopify). Así un producto creado por el asistente recibe sus pedidos y no se crea uno repetido.
     */
    public static function forShopifyLine(string $title, $shopifyProductId): ?self
    {
        return self::whereRaw('LOWER(title) = ?', [strtolower(trim($title))])->first()
            ?? ((int) $shopifyProductId > 0 ? self::where('product_id', (int) $shopifyProductId)->orderBy('id')->first() : null);
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /** Tallas, colores o combinaciones del producto (tarea 4). Sin variantes, el stock es solo el total. */
    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function gallery()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Stock disponible en inventario general
     */
    public function getAvailableStockAttribute(): int
    {
        // Entradas (IN, RETURN) y salidas (OUT, ASSIGN, SALE)
        $in = $this->stockMovements()
            ->whereIn('type', ['IN', 'RETURN'])
            ->sum('quantity');

        $out = $this->stockMovements()
            ->whereIn('type', ['OUT', 'ASSIGN', 'SALE'])
            ->sum('quantity');

        return $in - $out;
    }
    public function adSpends()
    {
        return $this->hasMany(ProductAdSpend::class);
    }

    protected $fillable = [
        'product_id',
        'variant_id',
        'title',
        'name',
        'showable_name',
        'price',
        'cost_usd',
        'sku',
        'image',
        'description',
        'available_sizes',
        // SCM fields
        'lead_time_days',
        'defect_percentage',
    ];

    protected $casts = [
        'available_sizes' => 'array',
        'delivery_pdf_since' => 'datetime', // .106: el PDF al entregar (ProductDeliveryPdfController)
    ];

    protected $appends = ['stock', 'stock_available'];

    /**
     * Get stock from main warehouse (or central point) 
     * Compatible with Inventory table structure
     */
    public function getStockAttribute(): int
    {
        return \App\Models\Inventory::where('product_id', $this->id)->sum('quantity');
    }

    /**
     * Legacy support / alias
     */
    public function getStockAvailableAttribute(): int
    {
        return $this->stock;
    }
}
