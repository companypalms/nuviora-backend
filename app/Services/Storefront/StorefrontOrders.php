<?php

namespace App\Services\Storefront;

use App\Constants\OrderStatus;
use App\Events\OrderUpdated;
use App\Jobs\SendStorefrontPurchase;
use App\Models\City;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductVariant;
use App\Models\Province;
use App\Models\Setting;
use App\Models\Status;
use App\Models\StorefrontOrder;
use App\Services\Orders\OrderIntake;
use App\Services\Orders\OrderSources;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `.164`: un pedido de nuestro formulario de la tienda entra al sistema por el mismo camino que uno de Shopify: el cliente
 * por su teléfono, el pedido "Nuevo" (WEB-12) con su tienda, su ciudad y su agencia, el producto con su oferta, de dónde vino
 * (OrderSources), y después la agencia con stock, el webhook de n8n y la vendedora (OrderIntake). El mismo teléfono y
 * producto en 30 minutos es el mismo pedido (no se crea otro); y la compra se le manda a Meta por la API de conversiones.
 */
class StorefrontOrders
{
    /** Lo que el cliente no puede corregir (el formulario lo dice tal cual). */
    public const LIMIT_PHONE = 'Ya recibimos tus pedidos de hoy. Una asesora te escribe por WhatsApp.';

    public const LIMIT_DAY = 'No pudimos recibir tu pedido en este momento.';

    public function __construct(private OrderSources $sources, private OrderIntake $intake)
    {
    }

    /**
     * @param array{offer: ?int, variant: ?int, name: string, phone: string, city: int, address: string, reference: ?string, express: bool,
     *     rate: mixed, visitor: ?string, landing: ?string, referrer: ?string, page: ?string, fbp: ?string, fbc: ?string} $in
     * @param array{ip: ?string, ua: ?string} $meta
     * @return array{order: Order, storefront: StorefrontOrder, duplicate: bool}
     */
    public function place(Product $product, array $in, array $meta): array
    {
        $s = StorefrontCatalog::settings();
        $phone = StorefrontRules::phone($in['phone']) ?? throw new RuntimeException('phone');
        $last10 = StorefrontRules::last10($phone);

        return Cache::lock("tienda-pedido:{$last10}:{$product->id}", 20)->block(8, function () use ($product, $in, $meta, $s, $phone, $last10) {
            // El mismo pedido: un doble toque o el cliente que lo vuelve a mandar
            $before = StorefrontOrder::with('order')->where('phone_last10', $last10)->where('product_id', $product->id)->whereNotNull('order_id')
                ->where('created_at', '>=', now()->subMinutes(StorefrontRules::SAME_ORDER_MINUTES))->latest('id')->first();
            if ($before && $before->order) {
                OrderActivityLog::create(['order_id' => $before->order_id, 'action' => 'storefront_repeat',
                    'description' => 'El cliente volvió a enviar el formulario de la tienda: es el mismo pedido.']);

                return ['order' => $before->order, 'storefront' => $before, 'duplicate' => true];
            }
            if (StorefrontOrder::where('phone_last10', $last10)->where('created_at', '>=', now()->subDay())->count() >= StorefrontRules::PHONE_DAY_MAX) {
                throw new StorefrontLimit(self::LIMIT_PHONE);
            }
            if (StorefrontOrder::where('created_at', '>=', today())->count() >= $s['daily_max']) {
                throw new StorefrontLimit(self::LIMIT_DAY);
            }

            [$order, $front] = DB::transaction(fn () => $this->create($product, $in, $meta, $s, $phone, $last10));
            $this->afterwards($order, $front, $in);

            return ['order' => $order, 'storefront' => $front, 'duplicate' => false];
        });
    }

    private function create(Product $product, array $in, array $meta, array $s, string $phone, string $last10): array
    {
        $city = City::findOrFail($in['city']);
        $offer = $in['offer'] ? ProductOffer::where('product_id', $product->id)->where('is_active', true)->findOrFail($in['offer']) : null;
        $variant = $in['variant'] ? ProductVariant::where('product_id', $product->id)->where('is_active', true)->findOrFail($in['variant']) : null;
        $rate = StorefrontRules::rate($in['rate'], (float) Setting::get('rate_binance_usd', 0));
        $units = $offer?->units ?? 1;
        $productUsd = StorefrontRules::offerUsd((float) $product->price, $units, (float) ($offer?->discount_pct ?? 0));
        $express = $in['express'] && $s['express_usd'] > 0 ? (float) $s['express_usd'] : null;
        $totalUsd = round($productUsd + ($express ?? 0), 2);

        $front = StorefrontOrder::create([
            'product_id' => $product->id, 'offer_id' => $offer?->id, 'variant_id' => $variant?->id, 'units' => $units, 'phone_last10' => $last10,
            'city_id' => $city->id, 'total_usd' => $totalUsd,
            'total_bs' => $rate !== null ? StorefrontRules::bs($productUsd, $rate) + ($express !== null ? StorefrontRules::bs($express, $rate) : 0) : null,
            'rate' => $rate, 'express_fee' => $express, 'event_id' => 'tmp-' . bin2hex(random_bytes(8)),
            'visitor_id' => self::cut($in['visitor'] ?? null, 40), 'landing_url' => self::cut($in['landing'] ?? null, 500),
            'referrer' => self::cut($in['referrer'] ?? null, 500), 'page_url' => self::cut($in['page'] ?? null, 500),
            'fbp' => self::cut($in['fbp'] ?? null, 120), 'fbc' => self::cut($in['fbc'] ?? null, 255),
            'ip' => self::cut($meta['ip'] ?? null, 45), 'user_agent' => self::cut($meta['ua'] ?? null, 255),
        ]);
        $n = (int) $front->id;
        $front->forceFill(['event_id' => StorefrontRules::eventId($n)])->save();

        // El cliente, por los últimos 10 dígitos de su teléfono (como un pedido de Shopify)
        [$first, $last] = StorefrontRules::splitName($in['name']);
        $address = ['first_name' => $first, 'last_name' => $last, 'province' => $city->name, 'city' => $city->name, 'address1' => $in['address'],
            'address2' => $in['reference'] ?: null, 'country_name' => 'Venezuela'];
        $client = Client::where('phone', 'like', "%{$last10}")->orderBy('id')->first();
        if ($client) {
            $client->update($address);
        } else {
            $client = Client::create($address + ['phone' => $phone, 'customer_id' => StorefrontRules::orderId($n), 'customer_number' => StorefrontRules::orderName($n)]);
        }

        $name = StorefrontRules::orderName($n);
        $order = OrderIntake::deferring(fn () => Order::create([
            'order_id' => StorefrontRules::orderId($n), 'name' => $name, 'order_number' => $name, 'currency' => 'USD',
            'current_total_price' => $totalUsd, 'processed_at' => now(), 'client_id' => $client->id, 'shop_id' => $s['shop_id'],
            'city_id' => $city->id, 'agency_id' => $city->agency_id, 'province_id' => Province::where('name', $city->name)->value('id'),
            'status_id' => Status::where('description', OrderStatus::NUEVO)->value('id') ?? 1, 'express_fee' => $express,
        ]));

        $title = (string) ($product->showable_name ?: $product->title);
        OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'product_number' => $product->product_id,
            'name' => $product->name, 'title' => $product->title, 'showable_name' => $variant ? "{$title} - {$variant->title}" : $product->showable_name,
            'price' => round($productUsd / $units, 2), 'quantity' => $units, 'image' => $product->image,
            'size' => $variant?->title,
        ]);
        // El Delivery Express va en el total, como el de Releasit, sin avisarle a la vendedora ni a la agencia (dueño, 19/11)
        OrderActivityLog::create(['order_id' => $order->id, 'action' => 'created', 'description' => 'Pedido del formulario de la tienda.']);
        $front->forceFill(['order_id' => $order->id])->save();

        return [$order, $front];
    }

    /** Lo que sigue a un pedido de Shopify; si algo falla, el pedido queda guardado igual. */
    private function afterwards(Order $order, StorefrontOrder $front, array $in): void
    {
        try {
            $this->sources->record($order, StorefrontRules::sourcePayload($in['landing'] ?? null, $in['referrer'] ?? null, $in['page'] ?? null));
        } catch (\Throwable $e) {
            report($e);
        }
        $this->intake->finish($order);
        try {
            event(new OrderUpdated($order->fresh()));
        } catch (\Throwable $e) {
            report($e);
        }
        if (StorefrontCatalog::capiToken() !== null) {
            SendStorefrontPurchase::dispatch($front->id)->afterResponse();
        }
    }

    /**
     * El mensaje de WhatsApp de este pedido con los códigos de Releasit, y su enlace.
     *
     * @return array{message: string, link: ?string}
     */
    public static function whatsapp(Order $order, array $settings): array
    {
        $order->loadMissing(['client', 'products']);
        $c = $order->client;
        $lines = $order->products;
        $message = StorefrontRules::whatsappMessage($settings['whatsapp_message'], [
            'first_name' => (string) ($c->first_name ?? ''), 'last_name' => (string) ($c->last_name ?? ''), 'order_id' => (string) $order->name,
            'order_number' => (string) $order->name, 'order_total' => number_format((float) $order->current_total_price, 2, ',', '.'),
            'product_title' => (string) ($lines->first()->showable_name ?? $lines->first()->title ?? ''), 'product_quantity' => (string) $lines->sum('quantity'),
            'products_summary_with_quantity' => $lines->map(fn ($l) => ($l->showable_name ?: $l->title) . ' x' . $l->quantity)->implode(', '),
            'phone' => (string) ($c->phone ?? ''), 'address' => (string) ($c->address1 ?? ''), 'address2' => (string) ($c->address2 ?? ''),
            'province' => (string) ($c->province ?? ''),
        ]);

        return ['message' => $message, 'link' => StorefrontRules::waLink($settings['whatsapp_number'], $message)];
    }

    private static function cut(?string $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
