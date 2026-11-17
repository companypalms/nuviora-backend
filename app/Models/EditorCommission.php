<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.153`: una quincena ya congelada de la comisión del líder de editores (Services\Editors\Commissions). Se guarda la
 * ganancia, el descuento y el % de ese momento: lo que se le paga ya no se mueve aunque después cambien.
 */
class EditorCommission extends Model
{
    protected $fillable = [
        'user_id', 'period_start', 'period_end', 'payday', 'profit_usd', 'reserve_pct', 'pct',
        'own_usd', 'carry_usd', 'amount_usd', 'frozen_at',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'payday' => 'date:Y-m-d',
        'profit_usd' => 'float',
        'reserve_pct' => 'float',
        'pct' => 'float',
        'own_usd' => 'float',
        'carry_usd' => 'float',
        'amount_usd' => 'float',
        'frozen_at' => 'datetime',
    ];

    public function leader()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
