<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    // ─── Message type constants ───────────────────────────────────────────────
    // incoming_message         → cliente escribió (activa requires_attention)
    // outgoing_agent_message   → vendedora respondió manualmente (activa follow_up)
    // outgoing_automated_message → template/n8n (NO cuenta como respuesta humana)
    // system_event             → cambio de status, asignación, etc. (no afecta bucket)

    const TYPE_INCOMING   = 'incoming_message';
    const TYPE_AGENT      = 'outgoing_agent_message';
    const TYPE_AUTOMATED  = 'outgoing_automated_message';
    const TYPE_SYSTEM     = 'system_event';

    protected $fillable = [
        'order_id',
        'client_id',
        'message_id',
        'body',
        'is_from_client',
        'message_type',
        'template_name',
        'status',
        'error_code',
        'error_reason',
        'media',
        'sent_at'
    ];

    protected $casts = [
        'is_from_client' => 'boolean',
        'error_code' => 'integer',
        'media' => 'array',
        'sent_at' => 'datetime'
    ];

    /**
     * La plantilla de confirmación del pedido (el ancla de los recordatorios de n8n): por el nombre de la plantilla
     * (template_name) o, en los mensajes de antes de guardarlo, por el texto "Plantilla: confirmacion…". Los
     * recordatorios ("no_respondio_confirmacion_…") no empiezan con "confirmacion", así que no mueven el ancla.
     */
    public function scopeConfirmationTemplate($query)
    {
        return $query->where(fn ($q) => $q->where('template_name', 'like', 'confirmacion%')
            ->orWhere('body', 'like', 'Plantilla: confirmacion%'));
    }

    /**
     * Los nombres de las plantillas que ya se le mandaron a una orden (las que Meta rechazó no cuentan). Con esto n8n
     * manda "Sin Stock" una sola vez por pedido (dueño, 2026-11-02). Los mensajes de antes de guardar template_name
     * se reconocen por el texto "Plantilla: …"; los de plantillas importadas de antes guardaban su texto y no se ven.
     */
    public static function templatesSentToOrder(int $orderId): array
    {
        return static::where('order_id', $orderId)
            ->where('is_from_client', false)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'failed'))
            ->where(fn ($q) => $q->whereNotNull('template_name')->orWhere('body', 'like', 'Plantilla:%'))
            ->get(['template_name', 'body'])
            ->map(fn ($m) => $m->template_name ?: trim(substr($m->body, strlen('Plantilla:'))))
            ->filter()->unique()->values()->all();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
