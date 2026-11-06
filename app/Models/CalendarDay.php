<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `.108`: el resumen de un día del calendario (cuántas había, hechas, con reloj y a tiempo). all_done_at = cuándo quedó
 * todo hecho o descartado; de ahí sale la racha.
 */
class CalendarDay extends Model
{
    protected $fillable = ['date', 'total', 'done', 'timed', 'on_time', 'all_done_at'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'all_done_at' => 'datetime',
    ];
}
