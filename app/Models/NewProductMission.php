<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** `.118`: la misión de lanzar un producto nuevo, con sus 5 etapas (Services\Meta\Products\NewProductRules). */
class NewProductMission extends Model
{
    protected $table = 'meta_new_product_missions';

    protected $fillable = ['status', 'opened_on', 'product_id', 'search_done_at', 'strategy_done_at', 'creatives_done_at', 'landing_done_at', 'publish_done_at'];

    protected $casts = ['opened_on' => 'date', 'search_done_at' => 'datetime', 'strategy_done_at' => 'datetime', 'creatives_done_at' => 'datetime',
        'landing_done_at' => 'datetime', 'publish_done_at' => 'datetime'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
