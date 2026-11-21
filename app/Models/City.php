<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'delivery_cost_usd',
        'agency_id',
        'is_working',
    ];

    protected $casts = [
        'is_working' => 'boolean',
    ];

    /**
     * `.174`: las ciudades donde se opera (con agencias, rutas, stock…), sin la zona "Resto de Venezuela", que es solo para
     * los números y Meta Ads (App\Services\Orders\CourierZone).
     */
    public function scopeOperational($query)
    {
        return $query->where('name', '!=', \App\Services\Orders\CourierZone::NAME);
    }

    /** Agencia principal (la primera del reparto). El reparto usa agencies(). */
    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    /** Agencias que entregan en esta ciudad, con su % (null = parejo) y si están activas (tarea 3c). */
    public function agencies()
    {
        return $this->belongsToMany(User::class, 'city_agency', 'city_id', 'agency_id')
            ->withPivot(['weight', 'is_active'])
            ->withTimestamps();
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
