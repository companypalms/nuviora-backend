<?php

namespace App\Services\StoreChat;

use App\Models\StoreChatCallback;
use App\Models\StoreChatConversation;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Calendar\Calendar;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * `.161` "Por contactar": los clientes que dejaron su WhatsApp porque el chat no supo responderles. Cada uno avisa al dueño
 * en la campanita y sale en su calendario ("Responder a los clientes del chat") hasta que alguien toca "Ya lo contacté".
 * Más adelante se le asignan a una vendedora o a la líder de ventas (assigned_to).
 */
class StoreChatCallbacks
{
    public const LINK = '/chat-tienda';

    /** Guarda al cliente por contactar y le avisa al dueño (si el aviso falla, el cliente igual queda guardado). */
    public static function create(StoreChatConversation $conversation, string $question, string $phone): StoreChatCallback
    {
        $callback = StoreChatCallback::create([
            'conversation_id' => $conversation->id, 'product_id' => $conversation->product_id, 'question' => $question,
            'phone' => $phone, 'source' => $conversation->source, 'status' => StoreChatCallback::PENDING,
        ]);
        // el "Hoy" del calendario se entera enseguida
        Calendar::forgetToday();
        try {
            $product = $conversation->product ? StoreChatFacts::name($conversation->product) : 'un producto';
            $message = ($conversation->source === StoreChatConversation::TEST ? '(Prueba) ' : '')
                . "Un cliente del chat de la tienda pregunta por {$product}: «" . mb_strimwidth($question, 0, 120, '…') . "». Escríbele al {$phone}.";
            foreach (User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->where('description', 'Admin'))->get() as $owner) {
                $owner->notify(new MetaWorkNotification('store_chat_callback', $message, self::LINK));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $callback;
    }

    /** @return list<array> los que esperan respuesta, los más viejos primero */
    public static function pending(): array
    {
        return StoreChatCallback::with('product')->where('status', StoreChatCallback::PENDING)->orderBy('id')->get()
            ->map(fn (StoreChatCallback $c) => self::present($c))->values()->all();
    }

    public static function present(StoreChatCallback $c): array
    {
        $product = $c->product ? StoreChatFacts::name($c->product) : 'Producto borrado';

        return [
            'id' => $c->id,
            'product' => $product,
            'question' => $c->question,
            'phone' => $c->phone,
            'wa' => 'https://wa.me/' . StoreChatRules::waNumber($c->phone) . '?text='
                . rawurlencode("¡Hola! 😊 Te escribimos de la tienda por tu pregunta sobre {$product}: «{$c->question}»."),
            'test' => $c->source === StoreChatConversation::TEST,
            'at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** "Ya lo contacté". */
    public static function done(StoreChatCallback $c, User $by): void
    {
        abort_if($c->status === StoreChatCallback::DONE, 422, 'Ese cliente ya está marcado como contactado.');
        $c->forceFill(['status' => StoreChatCallback::DONE, 'contacted_by' => $by->id, 'contacted_at' => now()])->save();
        Calendar::forgetToday();
    }

    /** Para el calendario de hoy: los que esperan y cuántos se contactaron hoy. */
    public static function forCalendar(CarbonImmutable $now): array
    {
        return [
            'pending' => self::pending(),
            'done_today' => StoreChatCallback::where('status', StoreChatCallback::DONE)->where('contacted_at', '>=', $now->startOfDay())->count(),
        ];
    }
}
