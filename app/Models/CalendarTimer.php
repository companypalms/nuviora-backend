<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.108`: el reloj de una tarea del calendario en su día (Empezar, Pausar, +5 min, Más tarde, Hecho). started_at null =
 * en pausa. Al hacerla queda finished_at y on_time (si se hizo dentro de los minutos que tenía al empezar).
 */
class CalendarTimer extends Model
{
    protected $fillable = ['item_key', 'occurs_on', 'minutes', 'extra_minutes', 'spent_seconds', 'started_at', 'later_at', 'finished_at', 'on_time', 'user_id'];

    protected $casts = [
        'occurs_on' => 'date:Y-m-d',
        'minutes' => 'integer',
        'extra_minutes' => 'integer',
        'spent_seconds' => 'integer',
        'started_at' => 'datetime',
        'later_at' => 'datetime',
        'finished_at' => 'datetime',
        'on_time' => 'boolean',
    ];
}
