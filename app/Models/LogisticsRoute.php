<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una ruta de 2 paradas, o 3 como excepción (§10). Sus paradas son órdenes logísticas con route_stop (§10.2). La arma el
 * operador aprobando la propuesta del sistema mientras el rider no salió (§9.3, §24.1); "Confirmar salida" la bloquea
 * (§11). Estados del §20.2.
 */
class LogisticsRoute extends Model
{
    public const OPEN = 'abierta';            // §20.2 "BORRADOR / ABIERTA": admite otra parada antes de salir
    public const EN_ROUTE = 'en_via';         // §20.2 "EN VÍA / BLOQUEADA"
    public const INTERRUPTED = 'interrumpida'; // §14
    public const FINISHED = 'finalizada';     // "todas las paradas tienen resultado"
    public const UNDONE = 'deshecha';         // antes de salir le quedó una sola parada (se canceló la otra)

    protected $fillable = ['center_id', 'rider_id', 'status', 'exceptional', 'plan', 'approved_by', 'approved_at', 'locked_at',
        'departed_at', 'interrupted_at', 'finished_at'];

    protected $casts = [
        'exceptional' => 'boolean',
        'plan' => 'array',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'departed_at' => 'datetime',
        'interrupted_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function stops()
    {
        return $this->hasMany(LogisticsOrder::class, 'route_id')->orderBy('route_stop');
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class, 'center_id');
    }
}
