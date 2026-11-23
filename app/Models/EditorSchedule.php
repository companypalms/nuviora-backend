<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** `.175`: el horario de un editor (los días que trabaja y de qué hora a qué hora, hora de Venezuela). */
class EditorSchedule extends Model
{
    protected $fillable = ['user_id', 'days', 'start_time', 'end_time', 'updated_by'];

    protected $casts = ['days' => 'array'];

    /** @return array{days: list<int>, start: string, end: string} */
    public function rule(): array
    {
        return ['days' => array_values(array_map('intval', $this->days ?? [])), 'start' => (string) $this->start_time, 'end' => (string) $this->end_time];
    }
}
