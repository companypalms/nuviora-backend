<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * De dónde vino un pedido de Shopify (`.53`): su landing, los parámetros del anuncio y, si se supo, el anuncio y la
 * landing del sistema. Lo guarda OrderSources al entrar el pedido.
 */
class OrderSource extends Model
{
    protected $fillable = ['order_id', 'landing_url', 'referring_site', 'source_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
        'utm_content', 'has_fbclid', 'ad_meta_id', 'adset_meta_id', 'campaign_meta_id', 'meta_ad_id', 'landing_id', 'landing_from'];

    protected $casts = ['has_fbclid' => 'boolean'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function ad()
    {
        return $this->belongsTo(MetaAd::class, 'meta_ad_id');
    }

    public function landing()
    {
        return $this->belongsTo(Landing::class);
    }
}
