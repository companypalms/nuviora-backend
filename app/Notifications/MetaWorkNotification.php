<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * `.89`: un aviso de Meta Ads y los editores en la campanita (en el momento y guardado): al dueño, "ya llegó a la banca:
 * lánzalo hoy en…" (meta_ready); al editor líder, "este batch está atrasado y se necesita" (editor_late).
 */
class MetaWorkNotification extends Notification
{
    public function __construct(private string $type, private string $message, private string $url)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['type' => $this->type, 'message' => $this->message, 'url' => $this->url];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(['type' => $this->type, 'message' => $this->message, 'url' => $this->url, 'sound' => 'notification_sound']);
    }
}
