<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cada desactivación y reactivación de una cuenta (paso 6), para siempre. */
class UserAccountChange extends Model
{
    public const DEACTIVATED = 'deactivated';
    public const REACTIVATED = 'reactivated';

    protected $fillable = ['user_id', 'action', 'reason', 'details', 'changed_by'];

    protected $casts = ['details' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
