<?php

namespace App\Models;

use App\Services\Calendar\Rules\NoteRules;
use Illuminate\Database\Eloquent\Model;

/** Una nota del calendario del dueño (Etapa 3): una tarea o un pago, de un día o repetido. Se archiva, no se borra. */
class CalendarNote extends Model
{
    protected $fillable = ['title', 'details', 'date', 'time', 'kind', 'repeat', 'repeat_until', 'source', 'created_by', 'updated_by',
        'archived_at', 'archived_by'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'repeat_until' => 'date:Y-m-d',
        'archived_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function itemKey(): string
    {
        return 'note:' . $this->id;
    }

    /** Los campos como los lee NoteRules (fechas "Y-m-d", hora "H:i"). */
    public function fields(): array
    {
        return [
            'title' => $this->title,
            'details' => $this->details,
            'date' => $this->date?->toDateString(),
            'time' => $this->time ? substr((string) $this->time, 0, 5) : null,
            'kind' => $this->kind,
            'repeat' => $this->repeat,
            'repeat_until' => $this->repeat_until?->toDateString(),
        ];
    }

    public function pattern(): array
    {
        return NoteRules::pattern($this->fields());
    }
}
