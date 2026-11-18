<?php

namespace App\Services\StoreChat;

use App\Models\Product;
use App\Models\ProductProfile;
use App\Models\Setting;
use App\Models\StoreChatConversation;
use App\Models\StoreChatMessage;
use Carbon\CarbonImmutable;

/**
 * `.158`: la página "Chat de la tienda" del dueño: los ajustes, la tasa, los productos que tienen ficha (con su último
 * examen), las preguntas del examen, las que no supo responder y las últimas conversaciones. Solo lee.
 */
class StoreChatReport
{
    private const UNANSWERED_DAYS = 30;

    private const LIST_MAX = 50;

    private const CONVERSATIONS = 30;

    public function __construct(private StoreChat $chat, private StoreChatFacts $facts, private StoreChatExam $exam)
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
            'exam' => ['questions' => $this->exam->questions(), 'groups' => StoreChatExamRules::GROUPS, 'expects' => StoreChatExamRules::EXPECTS],
        ];
    }

    /**
     * @return list<array{id: int, name: string, price_bs: ?int, enabled: bool, last_exam: ?array}> los productos con ficha (los demás no
     *         pueden tener chat), con su último examen
     */
    public function products(CarbonImmutable $now, array $enabled): array
    {
        $exams = $this->exam->lastByProduct();
        $out = [];
        foreach (ProductProfile::with('product')->get() as $profile) {
            if ($profile->product === null || !StoreChatRules::hasFicha($profile->only(['what_it_is', 'benefits', 'mechanism']))) {
                continue;
            }
            $facts = $this->facts->for($profile->product, $now);
            $out[] = ['id' => $profile->product->id, 'name' => $facts['name'], 'price_bs' => $facts['price_bs'],
                'enabled' => in_array($profile->product->id, $enabled, true), 'last_exam' => $exams[$profile->product->id] ?? null];
        }
        usort($out, fn ($a, $b) => [!$a['enabled'], $a['name']] <=> [!$b['enabled'], $b['name']]);

        return $out;
    }

    /**
     * `.159` "Lo que sabe el chat" de un producto: los campos de su ficha (se cambian en la ficha) y sus "Más detalles"
     * (se cambian aquí o en la ficha). Sin ficha guardada, no hay detalles que guardar.
     */
    public function knowledge(Product $product, CarbonImmutable $now): array
    {
        $facts = $this->facts->for($product, $now);
        $saved = ProductProfile::where('product_id', $product->id)->exists();

        return [
            'product_id' => $product->id,
            'name' => $facts['name'],
            'has_profile' => $saved,
            'has_ficha' => StoreChatRules::hasFicha($facts['ficha']),
            'ficha' => $facts['ficha'],
            'labels' => ['what_it_is' => 'Qué es', 'benefits' => 'Para qué sirve', 'mechanism' => 'Cómo funciona', 'key_info' => 'Información clave',
                'not_this' => 'Lo que NO es'],
            'details' => (string) ($facts['details'] ?? ''),
            'max' => StoreChatRules::DETAILS_MAX,
        ];
    }

    /** @return array{binance: ?float, at: ?string, fresh: bool} */
    private function rate(CarbonImmutable $now): array
    {
        $rate = Setting::get('rate_binance_usd');
        $at = Setting::get('rate_updated_at');
        $at = is_string($at) && $at !== '' ? $at : null;

        return ['binance' => is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null, 'at' => $at, 'fresh' => StoreChatRules::rateFresh($at, $now)];
    }

    /** Las preguntas que no supo responder (o que la revisión paró) de los últimos 30 días, las más nuevas primero (sin los exámenes). */
    private function unanswered(CarbonImmutable $now): array
    {
        return StoreChatMessage::with('conversation.product')
            ->whereIn('status', [StoreChatMessage::UNKNOWN, StoreChatMessage::BLOCKED])
            ->whereHas('conversation', fn ($q) => $q->where('source', '<>', StoreChatConversation::EXAM))
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

    /** Las últimas conversaciones con sus mensajes (sin los exámenes). */
    private function conversations(): array
    {
        return StoreChatConversation::with(['product', 'messages' => fn ($q) => $q->orderBy('id')])
            ->where('questions', '>', 0)->where('source', '<>', StoreChatConversation::EXAM)->orderByDesc('updated_at')->take(self::CONVERSATIONS)->get()
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
