<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderUpdate;
use App\Models\StatusChangeLog;
use Illuminate\Support\Collection;

/**
 * El historial de una orden. Lo usan la pestaña "Acciones" de la ficha (activityLogs, lo de siempre) y el asistente
 * del dueño (timeline: todo en orden de tiempo, en frases cortas).
 *
 * La fuente es order_activity_logs, que escriben los observadores desde enero de 2026: cambios de estado y de datos,
 * notas, cancelaciones con su motivo, reprogramaciones, pagos, stock, carreras. Las órdenes más viejas que no tienen esas
 * filas se arman con los cambios de estado (StatusChangeLog) y las notas.
 */
class OrderHistory
{
    /** Lo que se le cuenta al asistente de una orden, como mucho (las más recientes). */
    public const TIMELINE_MAX = 80;

    /** La pestaña "Acciones" de la ficha: todo, lo más nuevo primero, con quién lo hizo. */
    public function activityLogs(Order $order): Collection
    {
        return $order->activityLogs()
            ->with('user:id,names,surnames,email')
            ->latest()
            ->get();
    }

    /**
     * Lo que pasó con la orden, de lo más viejo a lo más nuevo: cuándo, quién (con su rol) y qué.
     *
     * @return array{events: array<int, array{at: string, who: string, action: string, text: string}>, total: int}
     */
    public function timeline(Order $order, int $max = self::TIMELINE_MAX): array
    {
        $logs = OrderActivityLog::with('user:id,names,surnames')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get();
        if ($logs->isNotEmpty()) {
            $events = $logs->map(fn (OrderActivityLog $l) => [
                'at' => $l->created_at?->format('Y-m-d H:i') ?? '',
                'who' => self::who($l->actor_role, $l->user_id ? trim("{$l->user?->names} {$l->user?->surnames}") : null),
                'action' => (string) $l->action,
                // La nota completa (la descripción la corta en 100 letras)
                'text' => $l->action === 'note_added' && !empty($l->properties['message'])
                    ? 'Añadió una nota: ' . $l->properties['message']
                    : (string) $l->description,
            ])->all();
        } else {
            $events = $this->older($order);
        }

        return ['events' => array_slice($events, -$max), 'total' => count($events)];
    }

    /** Las órdenes de antes del historial completo: sus cambios de estado y sus notas. */
    private function older(Order $order): array
    {
        $events = StatusChangeLog::with(['fromStatus:id,description', 'toStatus:id,description', 'user:id,names,surnames,role_id', 'user.role:id,description'])
            ->where('order_id', $order->id)->orderBy('created_at')->get()
            ->map(fn ($s) => [
                'at' => substr((string) $s->created_at, 0, 16),
                'who' => self::who($s->user?->role?->description, $s->user ? trim("{$s->user->names} {$s->user->surnames}") : null),
                'action' => 'status_changed',
                'text' => $s->fromStatus
                    ? "Estado cambió de '{$s->fromStatus->description}' a '{$s->toStatus?->description}'"
                    : "Se creó en '{$s->toStatus?->description}'",
            ])->all();
        foreach (OrderUpdate::with('user:id,names,surnames')->where('order_id', $order->id)->get() as $u) {
            $events[] = [
                'at' => $u->created_at?->format('Y-m-d H:i') ?? '',
                'who' => self::who(null, $u->user ? trim("{$u->user->names} {$u->user->surnames}") : null),
                'action' => 'note_added',
                'text' => 'Añadió una nota: ' . $u->message,
            ];
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    /** "Admin (Francisco Ruiz)", "Vendedor (Carla Demo)" o "Sistema". */
    private static function who(?string $role, ?string $name): string
    {
        if (!$name) {
            return $role && $role !== 'Sistema' ? $role : 'Sistema';
        }

        return $role ? "{$role} ({$name})" : $name;
    }
}
