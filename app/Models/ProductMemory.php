<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El resumen de "Lo que sabemos" de un producto (`.66`). Ver Services\Meta\Products\ProductMemoryRules. */
class ProductMemory extends Model
{
    protected $fillable = ['product_id', 'summary', 'facts_hash', 'status', 'model', 'error', 'unknown_numbers', 'generated_at'];

    protected $casts = ['summary' => 'array', 'unknown_numbers' => 'array', 'generated_at' => 'datetime'];
}
