<?php

namespace App\Services\Storefront;

use App\Constants\OrderStatus;
use App\Events\OrderUpdated;
use App\Jobs\AnalyzePaymentReceipt;
use App\Jobs\SendStorefrontEvent;
use App\Models\City;
use App\Models\Client;
use App\Models\CompanyAccount;
use App\Models\CourierOffice;
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
use App\Services\Assignment\AssignOrderService;
use App\Services\Orders\OrderIntake;
use App\Services\Orders\OrderSources;
use App\Services\Payments\PaymentAccounts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `.164`: un pedido de nuestro formulario de la tienda entra al sistema por el mismo camino que uno de Shopify: el cliente
 * por su teléfono, el pedido "Nuevo" (WEB-12) con su tienda, su ciudad y su agencia, el producto con su oferta, de dónde vino
 * (OrderSources), y después la agencia con stock, el webhook de n8n y la vendedora (OrderIntake). El mismo teléfono y
 * producto en 30 minutos es el mismo pedido (no se crea otro); y la compra se le manda a Meta por la API de conversiones.
 *
 * `.168`: si pagó por adelantado, el pedido entra con su pago móvil, la cuenta que vio y la foto del comprobante (que se
 * revisa como siempre); una encomienda sale de la ciudad que eligió el dueño, con la empresa y la oficina donde retira; y
 * a Meta le llega el "pedido hecho" siempre y el Purchase al pedir, salvo en las campañas marcadas "al entregar".
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
     * @param array{offer: ?int, variant: ?int, name: string, phone: string, city: ?int, address: ?string, reference: ?string, express: bool,
     *     rate: mixed, visitor: ?string, landing: ?string, referrer: ?string, page: ?string, fbp: ?string, fbc: ?string,
     *     payment?: string, account?: ?int, receipt?: ?array{bytes: string, ext: string},
     *     courier?: ?array{company: string, office_id: ?int, office_text: ?string, state: string, recipient_name: string, recipient_id: string, recipient_phone: string}} $in
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
            // .172: ¿todavía hay? Otro cliente pudo llevarse lo último mientras este llenaba el formulario. Si no ha pagado, no
            // entra; si ya pagó, entra igual con el aviso para la vendedora
            if (($short = $this->soldOut($product, $in, $s)) !== null) {
                if (($in['payment'] ?? null) !== StorefrontOrder::PREPAID) {
                    throw new StorefrontNoStock($short);
                }
                $in['sold_out'] = $short;
            }

            // .168/.171: su producto (o su campaña) dice cuándo sale su Purchase; y si la tienda está cerrada, el cliente lo sabe
            $campaign = PurchaseModes::campaignOf($in['landing'] ?? null, $in['page'] ?? null);
            $mode = StorefrontRules::purchaseMode($campaign, PurchaseModes::ranges(), today()->toDateString(), (int) $product->id);
            $closed = $s['shop_id'] !== null && !app(AssignOrderService::class)->isBusinessOpen($s['shop_id']);

            [$order, $front] = DB::transaction(fn () => $this->create($product, $in, $meta, $s, $phone, $last10, $mode, $closed));
            $this->afterwards($order, $front, $in);

            return ['order' => $order, 'storefront' => $front, 'duplicate' => false];
        });
    }

    /** `.172`: el nombre de la ciudad que ya no puede entregar este pedido (la del cliente, o la de las encomiendas), o null. */
    private function soldOut(Product $product, array $in, array $s): ?string
    {
        $cityId = !empty($in['courier']) ? $s['courier_origin_city_id'] : $in['city'];
        $offer = $in['offer'] ? ProductOffer::where('product_id', $product->id)->where('is_active', true)->find($in['offer']) : null;
        $stock = app(StorefrontStock::class)->byCity((int) $product->id, [(int) $cityId], true)[(int) $cityId] ?? null;

        return StorefrontRules::canDeliver($stock, $offer?->units ?? 1, $in['variant'] ?? null) ? null : (string) City::whereKey($cityId)->value('name');
    }

    private function create(Product $product, array $in, array $meta, array $s, string $phone, string $last10, string $mode, bool $closed): array
    {
        $courier = $in['courier'] ?? null;
        // .169: una encomienda se paga ahora (pago móvil) o con una asesora
        $payment = $in['payment'] ?? ($courier ? StorefrontOrder::PREPAID : StorefrontOrder::COD);
        $prepaid = $payment === StorefrontOrder::PREPAID;
        // Una encomienda sale de la ciudad que eligió el dueño (su agencia y su stock); el cliente retira en su oficina
        $city = City::findOrFail($courier ? $s['courier_origin_city_id'] : $in['city']);
        $offer = $in['offer'] ? ProductOffer::where('product_id', $product->id)->where('is_active', true)->findOrFail($in['offer']) : null;
        $variant = $in['variant'] ? ProductVariant::where('product_id', $product->id)->where('is_active', true)->findOrFail($in['variant']) : null;
        $rate = StorefrontRules::rate($in['rate'], (float) Setting::get('rate_binance_usd', 0));
        $units = $offer?->units ?? 1;
        $productUsd = StorefrontRules::offerUsd((float) $product->price, $units, (float) ($offer?->discount_pct ?? 0));
        $express = !$courier && $in['express'] && $s['express_usd'] > 0 ? (float) $s['express_usd'] : null;
        $totalUsd = round($productUsd + ($express ?? 0), 2);
        $office = $courier ? $this->office($courier) : null;

        $front = StorefrontOrder::create([
            'product_id' => $product->id, 'offer_id' => $offer?->id, 'variant_id' => $variant?->id, 'units' => $units, 'phone_last10' => $last10,
            'city_id' => $city->id, 'total_usd' => $totalUsd,
            'total_bs' => $rate !== null ? StorefrontRules::bs($productUsd, $rate) + ($express !== null ? StorefrontRules::bs($express, $rate) : 0) : null,
            'rate' => $rate, 'express_fee' => $express, 'event_id' => 'tmp-' . bin2hex(random_bytes(8)),
            'visitor_id' => self::cut($in['visitor'] ?? null, 40), 'landing_url' => self::cut($in['landing'] ?? null, 500),
            'referrer' => self::cut($in['referrer'] ?? null, 500), 'page_url' => self::cut($in['page'] ?? null, 500),
            'fbp' => self::cut($in['fbp'] ?? null, 120), 'fbc' => self::cut($in['fbc'] ?? null, 255),
            'ip' => self::cut($meta['ip'] ?? null, 45), 'user_agent' => self::cut($meta['ua'] ?? null, 255),
            'payment' => $payment, 'purchase_mode' => $mode, 'after_hours' => $closed,
        ]);
        $n = (int) $front->id;
        $front->forceFill(['event_id' => StorefrontRules::eventId($n)])->save();

        // El cliente, por los últimos 10 dígitos de su teléfono (como un pedido de Shopify). En una encomienda, su "dirección"
        // es la oficina donde retira
        [$first, $last] = StorefrontRules::splitName($in['name']);
        $address = $courier
            ? ['first_name' => $first, 'last_name' => $last, 'province' => $courier['state'], 'city' => $courier['state'], 'address1' => mb_substr("Retira en {$office}", 0, 250),
                'address2' => null, 'country_name' => 'Venezuela']
            : ['first_name' => $first, 'last_name' => $last, 'province' => $city->name, 'city' => $city->name, 'address1' => $in['address'] ?: null,
                'address2' => $in['reference'] ?: null, 'country_name' => 'Venezuela'];
        $client = Client::where('phone', 'like', "%{$last10}")->orderBy('id')->first();
        if ($client) {
            // Con asesora y sin dirección, la que ya tenía se queda
            $client->update($address['address1'] === null ? array_diff_key($address, ['address1' => 1, 'address2' => 1]) : $address);
        } else {
            $client = Client::create($address + ['phone' => $phone, 'customer_id' => StorefrontRules::orderId($n), 'customer_number' => StorefrontRules::orderName($n)]);
        }

        $name = StorefrontRules::orderName($n);
        $order = OrderIntake::deferring(fn () => Order::create([
            'order_id' => StorefrontRules::orderId($n), 'name' => $name, 'order_number' => $name, 'currency' => 'USD',
            'current_total_price' => $totalUsd, 'processed_at' => now(), 'client_id' => $client->id, 'shop_id' => $s['shop_id'],
            'city_id' => $city->id, 'agency_id' => $city->agency_id, 'province_id' => Province::where('name', $city->name)->value('id'),
            'status_id' => Status::where('description', OrderStatus::NUEVO)->value('id') ?? 1, 'express_fee' => $express,
        ] + ($prepaid ? ['prepaid_at' => now(), 'cash_received' => $totalUsd, 'change_amount' => 0] : [])
          + ($courier ? ['courier_shipment' => true, 'courier_recipient_name' => $courier['recipient_name'], 'courier_recipient_id_number' => $courier['recipient_id'],
              'courier_recipient_phone' => $courier['recipient_phone'], 'courier_company' => $courier['company'], 'courier_office' => $office] : [])));
        // La oficina donde el motorizado deja el paquete (la del dueño por empresa); si no está, la pone la vendedora (.112)
        if ($courier && ($dropoff = $s['courier_dropoff'][$courier['company']] ?? '') !== '') {
            $order->forceFill(['location' => $dropoff])->saveQuietly();
        }

        $title = (string) ($product->showable_name ?: $product->title);
        OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'product_number' => $product->product_id,
            'name' => $product->name, 'title' => $product->title, 'showable_name' => $variant ? "{$title} - {$variant->title}" : $product->showable_name,
            'price' => round($productUsd / $units, 2), 'quantity' => $units, 'image' => $product->image,
            'size' => $variant?->title,
        ]);
        // El Delivery Express va en el total, como el de Releasit, sin avisarle a la vendedora ni a la agencia (dueño, 19/11)
        OrderActivityLog::create(['order_id' => $order->id, 'action' => 'created', 'description' => 'Pedido del formulario de la tienda.']);
        if ($prepaid) {
            $this->prepay($order, $totalUsd, $rate, $in['account'] ?? null, $in['receipt'] ?? null, $name);
        }
        if ($courier) {
            OrderActivityLog::create(['order_id' => $order->id, 'action' => 'storefront_courier',
                'description' => "Encomienda por {$courier['company']}: retira en {$office}. Sale de {$city->name}."]);
        }
        if (!empty($in['sold_out'])) {
            OrderActivityLog::create(['order_id' => $order->id, 'action' => 'storefront_sold_out',
                'description' => "Se agotó en {$in['sold_out']} mientras el cliente pagaba: ofrécele esperar o devuélvele el pago."]);
        }
        if ($payment === StorefrontOrder::ADVISOR) {
            OrderActivityLog::create(['order_id' => $order->id, 'action' => 'storefront_advisor', 'description' => $courier
                ? 'Encomienda: el cliente quiere hacer el pago móvil con una asesora. Escríbele para cobrarle y confirmar el envío.'
                : 'El cliente quiere hablar con una asesora antes de confirmar su pedido.']);
        }
        $front->forceFill(['order_id' => $order->id])->save();

        return [$order, $front];
    }

    /**
     * `.168`: el pago por adelantado: su pago móvil por el total (con la tasa de la landing: los Bs que vio), la cuenta que vio
     * y la foto del comprobante, que se revisa como cualquier otra (y pasa a la conciliación).
     *
     * @param ?array{bytes: string, ext: string} $receipt
     */
    private function prepay(Order $order, float $totalUsd, ?float $rate, ?int $accountId, ?array $receipt, string $name): void
    {
        $rates = \App\Models\Currency::whereHas('status', fn ($q) => $q->where('description', 'Activo'))->get()->keyBy('description');
        $order->payments()->create(['method' => PaymentAccounts::METHOD, 'amount' => $totalUsd, 'rate' => $rate,
            'usd_rate' => $rates->get('bcv_usd')?->value, 'eur_rate' => $rates->get('bcv_eur')?->value, 'binance_usd_rate' => $rates->get('binance_usd')?->value]);
        $accounts = app(PaymentAccounts::class);
        $account = $accountId ? CompanyAccount::where('method', PaymentAccounts::KIND)->where('is_active', true)->find($accountId) : null;
        $account ? $accounts->change($order, $account, null) : $accounts->ensure($order);
        if ($receipt) {
            $path = 'payment_receipts/' . Str::random(40) . '.' . $receipt['ext'];
            Storage::disk('public')->put($path, $receipt['bytes']);
            $row = $order->paymentReceipts()->create(['path' => $path, 'original_name' => "comprobante-{$name}.{$receipt['ext']}"]);
            $order->forceFill(['payment_receipt' => $path])->saveQuietly();
            AnalyzePaymentReceipt::forReceipt($row);
        }
        OrderActivityLog::create(['order_id' => $order->id, 'action' => 'storefront_prepaid',
            'description' => 'Pagó por adelantado por pago móvil desde el formulario de la tienda (el comprobante está en el pedido). No se le cobra al entregar.']);
    }

    /** La oficina donde retira, como queda escrita en la orden (la de la lista o la que escribió el cliente). */
    private function office(array $courier): string
    {
        $office = !empty($courier['office_id']) ? CourierOffice::where('company', $courier['company'])->where('is_active', true)->find($courier['office_id']) : null;

        return $office ? $office->label() : mb_substr("{$courier['company']} (la escribió el cliente): " . trim((string) $courier['office_text']) . ", {$courier['state']}", 0, 300);
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
            // .168: el "pedido hecho" siempre; el Purchase ahora, salvo en una campaña "al entregar" sin pago adelantado
            SendStorefrontEvent::dispatch($front->id, MetaCapi::PLACED)->afterResponse();
            if (StorefrontRules::purchaseNow($front->purchase_mode, $front->payment)) {
                SendStorefrontEvent::dispatch($front->id)->afterResponse();
            }
        }
    }

    /**
     * El mensaje de WhatsApp de este pedido con los códigos de Releasit, y su enlace. `.168`: según cómo pagó.
     *
     * @return array{message: string, link: ?string}
     */
    public static function whatsapp(Order $order, array $settings, string $payment = StorefrontOrder::COD): array
    {
        $order->loadMissing(['client', 'products']);
        $c = $order->client;
        $lines = $order->products;
        $template = match (true) {
            (bool) $order->courier_shipment && $payment === StorefrontOrder::PREPAID => $settings['whatsapp_message_courier'],
            $payment === StorefrontOrder::PREPAID => $settings['whatsapp_message_prepaid'],
            $payment === StorefrontOrder::ADVISOR => $settings['whatsapp_message_advisor'],
            default => $settings['whatsapp_message'],
        };
        $message = StorefrontRules::whatsappMessage($template, self::vars($order) + [
            'order_number' => (string) $order->name, 'order_total' => number_format((float) $order->current_total_price, 2, ',', '.'),
            'product_title' => (string) ($lines->first()->showable_name ?? $lines->first()->title ?? ''), 'product_quantity' => (string) $lines->sum('quantity'),
            'products_summary_with_quantity' => $lines->map(fn ($l) => ($l->showable_name ?: $l->title) . ' x' . $l->quantity)->implode(', '),
            'phone' => (string) ($c->phone ?? ''), 'address' => (string) ($c->address1 ?? ''), 'address2' => (string) ($c->address2 ?? ''),
            'province' => (string) ($c->province ?? ''),
        ]);

        return ['message' => $message, 'link' => StorefrontRules::waLink($settings['whatsapp_number'], $message)];
    }

    /** Los códigos que usan también los mensajes que le escribe el sistema al cliente. @return array<string, string> */
    public static function vars(Order $order): array
    {
        $order->loadMissing('client');

        return ['first_name' => trim((string) ($order->client->first_name ?? '')), 'last_name' => (string) ($order->client->last_name ?? ''),
            'order_id' => (string) $order->name, 'courier' => (string) ($order->courier_company ?? '')];
    }

    private static function cut(?string $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
