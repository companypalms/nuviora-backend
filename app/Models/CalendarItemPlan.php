<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.107`: la prioridad, los minutos y el lugar que el dueño le puso a una cosa de su calendario (para todos los días). Sin
 * fila, la cosa usa lo de siempre (Calendar\Rules\DayPlan).
 */
class CalendarItemPlan extends Model
{
    protected $fillable = ['item_key', 'priority', 'minutes', 'rank', 'updated_by'];

    protected $casts = [
        'minutes' => 'integer',
        'rank' => 'integer',
    ];
}
