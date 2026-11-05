<?php

namespace App\Services\WhatsApp;

use App\Constants\OrderStatus;
use App\Models\Client;
use App\Models\DeliveryPdfSend;
use App\Models\Product;
use App\Models\Status;
use App\Models\WhatsappMessage;
use App\Services\WhatsAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * `.106` (dueño, 2026-11-05): el PDF de un producto (ej. la guía de uso) al cliente que lo recibió. Sale 10 minutos después
 * de que el pedido se marca Entregado, a cualquier hora, si la orden sigue en Entregado y el cliente tiene la ventana de
 * 24 horas abierta (escribió en las últimas 24 h): un documento suelto, sin plantilla de Meta y gratis. Con la ventana
 * cerrada se sigue revisando hasta 24 horas después de la entrega (si el cliente escribe, sale en ese momento); si no
 * escribe, no se manda. Una sola vez por cliente y producto, y solo a los pedidos entregados desde que se subió el PDF.
 * Si Meta falla, se reintenta hasta 3 veces con 10 minutos entre intentos. n8n y su mensaje de Entregado no se tocan.
 */
class DeliveryPdfSender
{
    public const WAIT_MINUTES = 10;
    public const LOOKBACK_HOURS = 24;
    public const MAX_ATTEMPTS = 3;
    public const DEFAULT_CAPTION = '¡Hola {nombre}! 💕 Gracias por tu compra. Aquí tienes la guía de tu {producto} para que le saques el máximo provecho. Si tienes alguna duda, escríbenos por aquí 😊';

    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    /**
     * Los PDFs que ya les toca a los pedidos entregados, uno por cliente y producto (el pedido entregado primero).
     * @return Collection<int, object{order_id: int, client_id: int, product_id: int, delivered_at: string, window_open: bool}>
     */
    public function due(): Collection
    {
        $deliveredId = Status::where('description', OrderStatus::ENTREGADO)->value('id');
        if (!$deliveredId) {
            return collect();
        }
        $rows = DB::table('orders as o')
            ->join('order_products as op', 'op.order_id', '=', 'o.id')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->where('o.status_id', $deliveredId)
            ->whereNotNull('o.client_id')
            ->whereNotNull('p.delivery_pdf_path')
            ->where('o.delivered_at', '>=', now()->subHours(self::LOOKBACK_HOURS)->toDateTimeString())
            ->where('o.delivered_at', '<=', now()->subMinutes(self::WAIT_MINUTES)->toDateTimeString())
            ->whereColumn('o.delivered_at', '>=', 'p.delivery_pdf_since')
            ->select('o.id as order_id', 'o.client_id', 'o.delivered_at', 'p.id as product_id')
            ->distinct()
            ->orderBy('o.delivered_at')
            ->get()
            ->unique(fn ($r) => "{$r->client_id}|{$r->product_id}")
            ->values();
        if ($rows->isEmpty()) {
            return $rows;
        }

        $sends = DeliveryPdfSend::whereIn('client_id', $rows->pluck('client_id')->unique())->get()
            ->keyBy(fn ($s) => "{$s->client_id}|{$s->product_id}");
        $clients = Client::whereIn('id', $rows->pluck('client_id')->unique())->get()->keyBy('id');

        return $rows->filter(function ($r) use ($sends) {
            $send = $sends["{$r->client_id}|{$r->product_id}"] ?? null;

            return !$send || ($send->status === DeliveryPdfSend::FAILED && $send->attempts < self::MAX_ATTEMPTS
                && (!$send->last_attempt_at || $send->last_attempt_at->lte(now()->subMinutes(self::WAIT_MINUTES))));
        })->map(function ($r) use ($clients) {
            $r->window_open = (bool) $clients->get($r->client_id)?->isWhatsappWindowOpen();

            return $r;
        })->values();
    }

    /**
     * Manda los que tocan (o, con $dryRun, solo dice cuáles). Los de ventana cerrada esperan: no gastan intentos.
     * @return array{sent: int, failed: int, waiting: int, items: array<int, array{order_id: int, product_id: int, result: string}>}
     */
    public function run(bool $dryRun = false): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'items' => []];
        foreach ($this->due() as $r) {
            if (!$r->window_open) {
                $out['waiting']++;
                $out['items'][] = ['order_id' => $r->order_id, 'product_id' => $r->product_id, 'result' => 'ventana cerrada: espera a que el cliente escriba'];
                continue;
            }
            if ($dryRun) {
                $out['items'][] = ['order_id' => $r->order_id, 'product_id' => $r->product_id, 'result' => 'se mandaría ahora'];
                continue;
            }
            $error = $this->send($r);
            $out[$error ? 'failed' : 'sent']++;
            $out['items'][] = ['order_id' => $r->order_id, 'product_id' => $r->product_id, 'result' => $error ? "falló: {$error}" : 'enviado'];
        }

        return $out;
    }

    /** El texto que acompaña al PDF, con {nombre} y {producto} ya puestos. */
    public static function caption(Product $product, ?Client $client): string
    {
        $name = trim(explode(' ', trim((string) $client?->first_name))[0] ?? '');
        $text = strtr(trim((string) $product->delivery_pdf_caption) ?: self::DEFAULT_CAPTION, [
            '{nombre}' => $name,
            '{producto}' => $product->showable_name ?: ($product->name ?: $product->title),
        ]);

        // Sin nombre no queda "¡Hola !": se quita el espacio antes del signo
        return trim(preg_replace('/ +([!,.])/u', '$1', $text));
    }

    /** Manda uno. Devuelve null si salió, o el motivo del fallo (queda anotado para reintentar). */
    private function send(object $r): ?string
    {
        $product = Product::find($r->product_id);
        $client = Client::find($r->client_id);
        $caption = self::caption($product, $client);
        $disk = Storage::disk('public');

        $message = WhatsappMessage::create([
            'order_id' => $r->order_id,
            'client_id' => $client->id,
            'body' => $caption,
            'media' => ['link' => $disk->url($product->delivery_pdf_path), 'type' => 'document'],
            'is_from_client' => false,
            'message_type' => WhatsappMessage::TYPE_AUTOMATED,
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        $error = null;
        $result = null;
        if (!$disk->exists($product->delivery_pdf_path)) {
            $error = 'el PDF no está en el servidor: vuelve a subirlo en el producto.';
        } else {
            $upload = $this->whatsapp->uploadMedia($disk->path($product->delivery_pdf_path), 'application/pdf');
            if (!is_array($upload) || empty($upload['id'])) {
                $error = 'no se pudo subir el PDF a WhatsApp' . ($this->whatsapp->lastError ? ": {$this->whatsapp->lastError}" : '.');
            } else {
                $result = $this->whatsapp->sendMedia($client->phone, $upload['id'], 'document', $caption, $product->delivery_pdf_name);
                if (empty($result['messages'][0]['id'])) {
                    $error = WhatsAppService::failureFields($result)['error_reason'];
                }
            }
        }

        if ($error) {
            $message->update(['status' => 'failed'] + ($result ? WhatsAppService::failureFields($result) : ['error_code' => null, 'error_reason' => mb_substr($error, 0, 500)]));
        } else {
            $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
            event(new \App\Events\WhatsappMessageReceived($message->fresh()->load('client', 'order')));
        }

        $send = DeliveryPdfSend::firstOrNew(['client_id' => $client->id, 'product_id' => $product->id]);
        $send->fill([
            'order_id' => $r->order_id,
            'status' => $error ? DeliveryPdfSend::FAILED : DeliveryPdfSend::SENT,
            'attempts' => ($send->attempts ?? 0) + 1,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'last_attempt_at' => now(),
            'sent_at' => $error ? null : now(),
        ])->save();

        return $error;
    }
}
