<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** `.175`: un día que un editor no trabajó por permiso: ese día no cuenta para su meta. */
class EditorDayOff extends Model
{
    protected $table = 'editor_days_off';

    /** La fecha queda como texto ("2026-11-23"): así se compara igual en MySQL y en la base de las pruebas. */
    protected $fillable = ['user_id', 'date', 'note', 'created_by'];
}
