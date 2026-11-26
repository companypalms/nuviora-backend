<?php

namespace App\Services\Storefront;

use App\Constants\OrderStatus;
use App\Models\OrderActivityLog;
use App\Models\ReceiptCheck;
use App\Models\StorefrontOrder;
use App\Models\WhatsappMessage;
use App\Services\Assignment\AssignOrderService;
use App\Services\Payments\ReceiptChecker;
use App\Services\WhatsAppService;

/**
 * `.168` (dueño, 2026-11-21): lo que le escribe el sistema al cliente que pagó por adelantado en el formulario, como si lo
 * escribiera una persona ("nunca hace referencia a que es una IA… así evitamos que nos busquen hacer fraudes y estafas"):
 * - Pidió con la tienda cerrada: "en nuestro horario una asesora validará tu pago y procederá con tu envío" (una vez).
 * - Su comprobante quedó bien (o administración lo aprobó): "ya confirmamos tu pago", entre 5 y 15 minutos después y solo
 *   con la tienda abierta (si cierra antes, al abrir). A domicilio, también le pide la ubicación.
 * - Si tiene un problema, no sale nada: lo atiende la vendedora, como hoy.
 * - `.181`: la encomienda que se paga con una asesora: "respóndenos y una asesora te ayuda a hacer el pago móvil", con la
 *   tienda abierta (una vez).
 * `.181`: todo sale con su plantilla de Meta (StorefrontRules::TEMPLATES), aunque el cliente no nos haya escrito ("muchas
 * personas no envían ese mensaje"). Si una no sale (por ejemplo, Meta todavía no la aprobó), no se reintenta: la vendedora
 * ve "Confírmale el pago" con el motivo. Corre cada minuto (`tienda:confirmar-pagos`).
 */
class PrepaidMessages
{
    /** Hasta cuándo se ocupa de un pedido. */
    private const DAYS = 3;

    public function __construct(private WhatsAppService $whatsapp, private AssignOrderService $business)
    {
    }

    /** @return array{ack: int, confirmed: int, advisor: int, seller: int, waiting: int, items: list<string>} */
    public function run(bool $dryRun = false): array
    {
        $out = ['ack' => 0, 'confirmed' => 0, 'advisor' => 0, 'seller' => 0, 'waiting' => 0, 'items' => []];
        $fronts = StorefrontOrder::with(['order.client', 'order.status'])->where('payment', StorefrontOrder::PREPAID)->whereNotNull('order_id')
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->where(fn ($q) => $q->whereNull('confirm_status')->orWhere(fn ($q) => $q->where('after_hours', true)->whereNull('ack_sent_at')))
            ->orderBy('id')->get();
        foreach ($fronts as $f) {
            $order = $f->order;
            if (!$order) {
                continue;
            }
            if (in_array($order->status?->description, [OrderStatus::CANCELADO, OrderStatus::RECHAZADO], true)) {
                $dryRun ?: $f->forceFill(['confirm_status' => 'skip', 'ack_sent_at' => $f->ack_sent_at ?? now()])->save();
                continue;
            }
            $open = !$order->shop_id || $this->business->isBusinessOpen($order->shop_id);

            // Fuera de horario: el aviso, un minuto después de pedir (una vez: si no sale, no se reintenta)
            if ($f->after_hours && !$f->ack_sent_at && !$open && $f->created_at->lte(now()->subMinute())) {
                $out['items'][] = "{$order->name}: aviso de fuera de horario";
                if (!$dryRun) {
                    $error = $this->send($f, 'ack', StorefrontRules::templateParams('ack', StorefrontOrders::vars($order), StorefrontCatalog::hours($order->shop_id)));
                    $f->forceFill(['ack_sent_at' => now()])->save();
                    if ($error !== null) {
                        OrderActivityLog::create(['order_id' => $f->order_id, 'action' => 'storefront_ack_failed',
                            'description' => "El aviso de fuera de horario no salió por WhatsApp ({$error})."]);
                    }
                }
                $out['ack']++;
            }

            if ($f->confirm_status !== null) {
                continue; // ya lo atiende la vendedora (solo faltaba el aviso)
            }
            $state = self::state($order->id);
            if ($state !== 'ok') {
                if ($state === 'manual' && !$dryRun) {
                    $this->toSeller($f, 'La revisión automática de comprobantes está apagada: revisa el pago y confírmale al cliente por WhatsApp.');
                }
                $out[$state === 'manual' ? 'seller' : 'waiting']++;
                continue;
            }
            if (!$open) {
                // Validado con la tienda cerrada: sale al abrir, 5 a 15 minutos después
                if ($f->confirm_due_at && !$dryRun) {
                    $f->forceFill(['confirm_due_at' => null])->save();
                }
                $out['waiting']++;
                continue;
            }
            if (!$f->confirm_due_at) {
                $dryRun ?: $f->forceFill(['confirm_due_at' => now()->setTimestamp(StorefrontRules::confirmDue(time(), mt_rand() / mt_getrandmax()))])->save();
                $out['waiting']++;
                continue;
            }
            if ($f->confirm_due_at->isFuture()) {
                $out['waiting']++;
                continue;
            }
            $out['items'][] = "{$order->name}: ya confirmamos tu pago";
            if (!$dryRun) {
                $key = $order->courier_shipment ? 'confirm_courier' : 'confirm_home';
                $error = $this->send($f, $key, StorefrontRules::templateParams($key, StorefrontOrders::vars($order)));
                $error === null
                    ? $f->forceFill(['confirm_status' => 'sent', 'confirm_sent_at' => now()])->save()
                    : $this->toSeller($f, "Pago validado ✅, pero el WhatsApp de confirmación no salió ({$error}): escríbele que ya confirmamos su pago.");
            }
            $out['confirmed']++;
        }
        $this->advisors($out, $dryRun);

        return $out;
    }

    /**
     * `.181`: la encomienda que se paga con una asesora: "respóndenos este mensaje y una asesora te ayuda a hacer el pago
     * móvil", un minuto después de pedir y con la tienda abierta (si está cerrada, al abrir). A domicilio, el que pidió
     * hablar con una asesora recibe la confirmación de n8n, como siempre.
     */
    private function advisors(array &$out, bool $dryRun): void
    {
        $fronts = StorefrontOrder::with(['order.client', 'order.status'])->where('payment', StorefrontOrder::ADVISOR)->whereNotNull('order_id')
            ->whereNull('confirm_status')->whereHas('order', fn ($q) => $q->where('courier_shipment', true))
            ->whereBetween('created_at', [now()->subDays(self::DAYS), now()->subMinute()])->orderBy('id')->get();
        foreach ($fronts as $f) {
            $order = $f->order;
            if (in_array($order->status?->description, [OrderStatus::CANCELADO, OrderStatus::RECHAZADO], true)) {
                $dryRun ?: $f->forceFill(['confirm_status' => 'skip'])->save();
                continue;
            }
            if ($order->prepaid_at) {
                $dryRun ?: $f->forceFill(['confirm_status' => 'seller'])->save(); // la vendedora ya le cobró
                continue;
            }
            if ($order->shop_id && !$this->business->isBusinessOpen($order->shop_id)) {
                $out['waiting']++;
                continue;
            }
            $out['items'][] = "{$order->name}: una asesora te ayuda a hacer el pago";
            if (!$dryRun) {
                $error = $this->send($f, 'advisor', StorefrontRules::templateParams('advisor', StorefrontOrders::vars($order)));
                $error === null
                    ? $f->forceFill(['confirm_status' => 'advisor', 'confirm_sent_at' => now()])->save()
                    : $this->toSeller($f, "El WhatsApp para cobrarle con la asesora no salió ({$error}): escríbele tú para hacer el pago móvil.");
            }
            $out['advisor']++;
        }
    }

    /**
     * Cómo va el comprobante de un pedido: ok (todos bien o aprobados por administración), wait (leyéndose), manual (la
     * revisión automática está apagada) o review (tiene un problema: lo atiende la vendedora).
     */
    public static function state(int $orderId): string
    {
        if (ReceiptChecker::mode() === 'off') {
            return 'manual';
        }
        $checks = ReceiptCheck::where('order_id', $orderId)->get(['status', 'reviewed_at']);
        if ($checks->isEmpty() || $checks->contains(fn ($c) => $c->status === ReceiptCheck::PENDING)) {
            return 'wait';
        }

        return $checks->every(fn ($c) => $c->status === ReceiptCheck::OK || $c->isApproved()) ? 'ok' : 'review';
    }

    private function toSeller(StorefrontOrder $f, string $text): void
    {
        $f->forceFill(['confirm_status' => 'seller'])->save();
        OrderActivityLog::create(['order_id' => $f->order_id, 'action' => 'storefront_confirm_seller', 'description' => $text]);
    }

    /**
     * `.181`: lo manda con su plantilla de Meta, como cualquier mensaje automático, y en el chat queda el texto que le llegó;
     * null si salió, o el motivo.
     *
     * @param list<string> $params
     */
    private function send(StorefrontOrder $f, string $key, array $params): ?string
    {
        $order = $f->order;
        $name = StorefrontRules::TEMPLATES[$key]['name'];
        $message = WhatsappMessage::create(['order_id' => $order->id, 'client_id' => $order->client_id, 'body' => StorefrontRules::templateText($key, $params),
            'is_from_client' => false, 'message_type' => WhatsappMessage::TYPE_AUTOMATED, 'template_name' => $name, 'status' => 'sending', 'sent_at' => now()]);
        $result = $this->whatsapp->sendTemplate($order->client->phone, $name, 'es',
            [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $p], $params)]]);
        if (empty($result['messages'][0]['id'])) {
            $fields = WhatsAppService::failureFields($result);
            $message->update(['status' => 'failed'] + $fields);

            return $fields['error_reason'];
        }
        $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
        try {
            event(new \App\Events\WhatsappMessageReceived($message->fresh()->load('client', 'order')));
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }
}
