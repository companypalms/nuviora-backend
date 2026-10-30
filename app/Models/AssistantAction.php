<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un cambio que propuso el asistente del dueño (Etapa 4, paso 5): la tarjeta con Confirmar y Cancelar, y cómo terminó.
 * Lo maneja Services\Assistant\Actions\ActionDesk.
 */
class AssistantAction extends Model
{
    public const PROPOSED = 'proposed';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    /** Lo que dice cada estado, en simple. */
    public const STATUS_LABEL = [
        self::PROPOSED => 'Esperando que confirmes',
        self::DONE => 'Hecho',
        self::FAILED => 'No se pudo hacer',
        self::CANCELLED => 'Cancelado',
        self::EXPIRED => 'Venció',
    ];

    /** Una propuesta vale esto: después hay que pedirla de nuevo (para que la tarjeta muestre los datos al día). */
    public const EXPIRES_HOURS = 2;

    protected $fillable = [
        'user_id', 'message_id', 'tool', 'args', 'summary', 'card', 'fingerprint', 'status', 'result', 'error',
        'decided_by', 'decided_at', 'expires_at',
    ];

    protected $casts = [
        'args' => 'array',
        'card' => 'array',
        'result' => 'array',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** El estado de ahora: una propuesta vieja ya venció aunque nadie la haya tocado. */
    public function currentStatus(): string
    {
        return $this->status === self::PROPOSED && $this->expires_at?->isPast() ? self::EXPIRED : $this->status;
    }

    /** La tarjeta para el chat. */
    /** Lo que se muestra una sola vez al confirmar (Outcome::$secret). Nunca se guarda. */
    public ?array $oneTimeSecret = null;

    public function toCard(): array
    {
        $status = $this->currentStatus();

        return [
            'id' => $this->id,
            'title' => $this->card['title'] ?? $this->summary,
            'lines' => $this->card['lines'] ?? [],
            'warnings' => $this->card['warnings'] ?? [],
            'notes' => $this->card['notes'] ?? [],
            'status' => $status,
            'status_label' => self::STATUS_LABEL[$status] ?? $status,
            'error' => $status === self::EXPIRED && !$this->error ? 'Pasaron más de ' . self::EXPIRES_HOURS . ' horas. Si todavía lo quieres, pídemelo de nuevo.' : $this->error,
            'result' => $this->result,
            'created' => $this->created_at?->format('d/m H:i'),
            'decided' => $this->decided_at?->format('d/m H:i'),
        ] + ($this->oneTimeSecret ? ['secret' => $this->oneTimeSecret] : []);
    }
}
