<?php

namespace App\Services\Storefront;

use App\Models\StorefrontOrder;
use Illuminate\Support\Facades\Http;

/**
 * `.164` (dueño, 2026-11-19: "el código de mi píxel y de mi API de conversiones está en Releasit"): la compra de un pedido de
 * nuestro formulario, por la API de conversiones de Meta, como la manda hoy Releasit. Lleva el mismo event_id que el
 * "Purchase" del píxel en la página, así Meta no la cuenta doble; los datos del cliente van cifrados (SHA-256), como pide
 * Meta. Sin la clave (la pone el dueño en el sistema) no se manda nada. Nunca guarda ni muestra la clave.
 */
class MetaCapi
{
    /**
     * El evento, sin la clave (PHP puro).
     *
     * @param array{first_name: ?string, last_name: ?string, phone: ?string, city: ?string} $client
     */
    public static function payload(StorefrontOrder $front, array $client, string $contentId, int $time, ?string $testCode): array
    {
        $hash = fn (?string $v) => ($v = mb_strtolower(trim((string) $v))) !== '' ? hash('sha256', $v) : null;
        $plain = fn (?string $v) => preg_replace('/[^a-z]/', '', \Illuminate\Support\Str::ascii(mb_strtolower((string) $v)));
        $userData = array_filter([
            'ph' => ($p = preg_replace('/\D+/', '', (string) $client['phone'])) !== '' ? [hash('sha256', $p)] : null,
            'fn' => ($v = $hash($client['first_name'])) ? [$v] : null,
            'ln' => ($v = $hash($client['last_name'])) ? [$v] : null,
            'ct' => ($v = $plain($client['city'])) !== '' ? [hash('sha256', $v)] : null,
            'country' => [hash('sha256', 've')],
            'external_id' => [hash('sha256', (string) $front->phone_last10)],
            'fbp' => $front->fbp ?: null,
            'fbc' => $front->fbc ?: null,
            'client_ip_address' => $front->ip ?: null,
            'client_user_agent' => $front->user_agent ?: null,
        ], fn ($v) => $v !== null);

        return array_filter([
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => $time,
                'event_id' => $front->event_id,
                'action_source' => 'website',
                'event_source_url' => $front->page_url ?: $front->landing_url,
                'user_data' => $userData,
                'custom_data' => ['value' => round((float) $front->total_usd, 2), 'currency' => 'USD', 'content_ids' => [$contentId],
                    'content_type' => 'product', 'num_items' => (int) $front->units, 'order_id' => $front->order?->name],
            ]],
            'test_event_code' => $testCode ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Manda la compra a Meta y anota cómo salió (sent, failed); sin la clave o sin el píxel, no hace nada. */
    public function send(StorefrontOrder $front): ?string
    {
        $s = StorefrontCatalog::settings();
        $token = StorefrontCatalog::capiToken();
        if ($token === null || $s['pixel_id'] === '' || $front->capi_status === 'sent') {
            return null;
        }
        $front->loadMissing(['order.client', 'product']);
        $client = $front->order?->client;
        $variantShopify = $front->variant_id ? \App\Models\ProductVariant::whereKey($front->variant_id)->value('shopify_variant_id') : null;
        $productId = (string) ($front->product?->product_id ?? '');
        $contentId = StorefrontRules::contentId($s['content_id_format'], $s['content_region'], $productId,
            $variantShopify ? (string) $variantShopify : ($front->product?->variant_id ? (string) $front->product->variant_id : null));
        $payload = self::payload($front, ['first_name' => $client?->first_name, 'last_name' => $client?->last_name, 'phone' => $client?->phone,
            'city' => $client?->province], $contentId, $front->created_at?->timestamp ?? time(), $s['capi_test_code']);
        $version = config('services.meta.graph_version', 'v21.0');
        try {
            $r = Http::timeout(15)->asJson()->post("https://graph.facebook.com/{$version}/{$s['pixel_id']}/events?access_token=" . urlencode($token), $payload);
            $ok = $r->successful();
            $front->forceFill(['capi_status' => $ok ? 'sent' : 'failed',
                'capi_error' => $ok ? null : mb_substr((string) ($r->json('error.message') ?? "HTTP {$r->status()}"), 0, 255)])->save();
        } catch (\Throwable $e) {
            $front->forceFill(['capi_status' => 'failed', 'capi_error' => mb_substr($e->getMessage(), 0, 255)])->save();
        }

        return $front->capi_status;
    }
}
