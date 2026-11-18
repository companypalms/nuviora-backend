<?php

namespace App\Services\StoreChat;

use App\Models\ProductProfile;
use App\Models\Setting;
use App\Models\StoreChatConversation;
use App\Models\StoreChatMessage;
use Carbon\CarbonImmutable;

/**
 * `.158`: la página "Chat de la tienda" del dueño: los ajustes, la tasa, los productos que tienen ficha, las preguntas
 * que no supo responder y las últimas conversaciones. Solo lee.
 */
class StoreChatReport
{
    private const UNANSWERED_DAYS = 30;

    private const LIST_MAX = 50;

    private const CONVERSATIONS = 30;

    public function __construct(private StoreChat $chat, private StoreChatFacts $facts)
    {
    }

    public function overview(CarbonImmutable $now): array
    {
        $settings = StoreChat::settings();
        $products = $this->products($now, $settings['product_ids']);

        return [
            'settings' => $settings,
            'mode' => $this->chat->mode(),
            'rate' => $this->rate($now),
            'today' => ['answers' => $this->chat->answeredToday(), 'cap' => $settings['daily_answers']],
            'products' => $products,
            'unanswered' => $this->unanswered($now),
            'conversations' => $this->conversations(),
            'note' => StoreChatRules::FORM_NOTE,
            'button' => StoreChatRules::BUTTON,
        ];
    }

    /** @return list<array{id: int, name: string, price_bs: ?int, enabled: bool}> los productos con ficha (los demás no pueden tener chat) */
    public function products(CarbonImmutable $now, array $enabled): array
    {
        $out = [];
        foreach (ProductProfile::with('product')->get() as $profile) {
            if ($profile->product === null || !StoreChatRules::hasFicha($profile->only(['what_it_is', 'benefits', 'mechanism']))) {
                continue;
            }
            $facts = $this->facts->for($profile->product, $now);
            $out[] = ['id' => $profile->product->id, 'name' => $facts['name'], 'price_bs' => $facts['price_bs'],
                'enabled' => in_array($profile->product->id, $enabled, true)];
        }
        usort($out, fn ($a, $b) => [!$a['enabled'], $a['name']] <=> [!$b['enabled'], $b['name']]);

        return $out;
    }

    /** @return array{binance: ?float, at: ?string, fresh: bool} */
    private function rate(CarbonImmutable $now): array
    {
        $rate = Setting::get('rate_binance_usd');
        $at = Setting::get('rate_updated_at');
        $at = is_string($at) && $at !== '' ? $at : null;

        return ['binance' => is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null, 'at' => $at, 'fresh' => StoreChatRules::rateFresh($at, $now)];
    }

    /** Las preguntas que no supo responder (o que la revisión paró) de los últimos 30 días, las más nuevas primero. */
    private function unanswered(CarbonImmutable $now): array
    {
        return StoreChatMessage::with('conversation.product')
            ->whereIn('status', [StoreChatMessage::UNKNOWN, StoreChatMessage::BLOCKED])
            ->where('created_at', '>=', $now->subDays(self::UNANSWERED_DAYS)->startOfDay())
            ->orderByDesc('id')->take(self::LIST_MAX)->get()
            ->map(fn (StoreChatMessage $m) => [
                'id' => $m->id,
                'product_id' => $m->conversation?->product_id,
                'product' => $m->conversation?->product ? StoreChatFacts::name($m->conversation->product) : 'Producto borrado',
                'question' => $m->question,
                'status' => $m->status,
                'reason' => $m->blocked_reason,
                'test' => $m->conversation?->source === StoreChatConversation::TEST,
                'at' => $m->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** Las últimas conversaciones con sus mensajes. */
    private function conversations(): array
    {
        return StoreChatConversation::with(['product', 'messages' => fn ($q) => $q->orderBy('id')])
            ->where('questions', '>', 0)->orderByDesc('updated_at')->take(self::CONVERSATIONS)->get()
            ->map(fn (StoreChatConversation $c) => [
                'id' => $c->id,
                'product' => $c->product ? StoreChatFacts::name($c->product) : 'Producto borrado',
                'test' => $c->source === StoreChatConversation::TEST,
                'questions' => $c->questions,
                'unanswered' => $c->unanswered,
                'at' => $c->updated_at?->toIso8601String(),
                'messages' => $c->messages->map(fn (StoreChatMessage $m) => StoreChat::toChat($m) + ['at' => $m->created_at?->toIso8601String()])->values()->all(),
            ])->values()->all();
    }
}
