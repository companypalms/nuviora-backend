<?php

namespace App\Services\Storefront;

use App\Models\City;
use App\Models\CourierOffice;
use App\Models\Shop;
use App\Services\Payments\PaymentAccounts;
use App\Models\LogisticsCenter;
use App\Models\Product;
use App\Models\ProductCityAvailability;
use App\Models\ProductOffer;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StorefrontDesign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * `.164`: lo que necesita nuestro formulario en la landing de un producto (por su id de Shopify): si está prendido, las
 * ofertas con sus Bs (con la tasa de la página del dueño), las tallas, dónde se paga al recibir, el Delivery Express, el
 * diseño, el píxel y el WhatsApp. Lo que no depende de la tasa se guarda 30 segundos (cada visita de la landing lo pide).
 *
 * `.168`: también si se puede pagar por adelantado, hablar con una asesora o pedir por encomienda (MRW y Zoom, con sus
 * oficinas), la cuenta de pago móvil que le toca al cliente y el horario de atención (para el mensaje de fuera de horario).
 */
class StorefrontCatalog
{
    private const CACHE_SECONDS = 30;

    public static function settings(): array
    {
        return StorefrontRules::settings(Setting::get(StorefrontRules::SETTING));
    }

    /** Guarda los ajustes; la clave de Meta solo cambia si llega una nueva (y se guarda encriptada). */
    public static function saveSettings(array $in): array
    {
        $current = self::settings();
        $token = $current['capi_token'];
        if (array_key_exists('capi_token', $in)) {
            $new = trim((string) $in['capi_token']);
            $token = $new === '' ? $token : Crypt::encryptString($new);
        }
        if (!empty($in['capi_token_clear'])) {
            $token = null;
        }
        $clean = StorefrontRules::settings(['capi_token' => $token] + array_diff_key($in, ['capi_token' => 1, 'capi_token_clear' => 1]) + $current);
        Setting::set(StorefrontRules::SETTING, $clean);
        self::refresh();

        return $clean;
    }

    /** La clave de la API de conversiones, solo para mandar el evento (nunca sale a la pantalla). */
    public static function capiToken(): ?string
    {
        $enc = self::settings()['capi_token'];
        if ($enc === null) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function product(string $shopifyId): ?Product
    {
        return ctype_digit($shopifyId) ? Product::where('product_id', $shopifyId)->first() : null;
    }

    /** Si el formulario va en este producto: prendido, con su tienda y el producto elegido. */
    public function enabledFor(?Product $product, ?array $settings = null): bool
    {
        $s = $settings ?? self::settings();

        return $product !== null && $s['enabled'] && $s['shop_id'] !== null && in_array((int) $product->id, $s['product_ids'], true);
    }

    /**
     * La configuración que dibuja el formulario.
     *
     * @param mixed $pageRate la tasa de la landing (su metafield binance_rate)
     * @param mixed $pagePrice el precio en dólares que muestra la landing (para avisarle al dueño si no cuadra)
     */
    public function config(Product $product, mixed $pageRate, mixed $pagePrice = null): array
    {
        $s = self::settings();
        $base = $this->base($product);
        $rate = StorefrontRules::rate($pageRate, (float) Setting::get('rate_binance_usd', 0));
        $price = (float) $product->price;
        if (is_numeric($pagePrice) && (float) $pagePrice > 0) {
            Cache::put("storefront:page_price:{$product->id}", round((float) $pagePrice, 2), now()->addDay());
        }
        $name = $base['name'];
        $express = $s['express_usd'] > 0 ? ['usd' => $s['express_usd'], 'bs' => $rate !== null ? StorefrontRules::bs($s['express_usd'], $rate) : null] : null;

        return [
            'enabled' => $this->enabledFor($product, $s),
            'product' => ['id' => (string) $product->product_id, 'name' => $name, 'image' => $base['image']],
            'rate' => $rate,
            'offers' => StorefrontRules::offers($price, $base['offers'], $rate, mb_strtoupper($name)),
            'variants' => $base['variants'],
            'cities' => $base['cities'],
            'express' => $express,
            'texts' => $base['texts'],
            'colors' => $base['colors'],
            'font' => $base['font'],
            'radius' => $base['radius'],
            'css' => $base['css'],
            'pixel' => ['id' => $s['pixel_id'], 'initiate' => $s['send_initiate'], 'pageview' => $s['send_pageview'],
                'content_id' => StorefrontRules::contentId($s['content_id_format'], $s['content_region'], (string) $product->product_id, $product->variant_id ? (string) $product->variant_id : null)],
            'releasit' => $s['releasit_selector'],
            'pay' => ['prepaid' => $s['prepaid_enabled'], 'advisor' => $s['advisor_enabled']],
            'courier' => self::courierOn($s) ? ['companies' => $s['courier_companies']] : null,
            'ts' => $ts = now()->timestamp,
            'sig' => StorefrontRules::sign($ts, (string) config('app.key')),
        ];
    }

    /** Lo del producto que no depende de la tasa (30 segundos). */
    private function base(Product $product): array
    {
        return Cache::remember("storefront:base:{$product->id}:" . (Cache::get('storefront:version') ?? 0), self::CACHE_SECONDS, function () use ($product) {
            $design = StorefrontDesign::where('product_id', $product->id)->first();

            return [
                'name' => (string) ($product->showable_name ?: $product->title),
                'image' => $product->image ?: null,
                'offers' => ProductOffer::where('product_id', $product->id)->orderBy('position')->orderBy('id')->get()
                    ->map(fn ($o) => ['id' => $o->id, 'units' => $o->units, 'discount_pct' => $o->discount_pct, 'title' => $o->title, 'badge' => $o->badge,
                        'is_default' => $o->is_default, 'is_active' => $o->is_active])->all(),
                'variants' => ProductVariant::where('product_id', $product->id)->where('is_active', true)->orderBy('id')->get(['id', 'title', 'shopify_variant_id'])
                    ->map(fn ($v) => ['id' => $v->id, 'title' => (string) $v->title, 'shopify_id' => $v->shopify_variant_id ? (string) $v->shopify_variant_id : null])->all(),
                'cities' => self::codCities((int) $product->id),
                'texts' => StorefrontRules::texts($design?->texts),
                'colors' => StorefrontRules::colors($design?->colors),
                'font' => StorefrontRules::font($design?->font),
                'radius' => $design?->radius ?? 10,
                'css' => StorefrontRules::css($design?->custom_css),
            ];
        });
    }

    /** Dónde se paga al recibir este producto (las ciudades con agencia o centro logístico activos). @return list<array{id: int, name: string}> */
    public static function codCities(int $productId): array
    {
        $withAgency = DB::table('city_agency')->join('users', 'users.id', '=', 'city_agency.agency_id')->where('city_agency.is_active', true)
            ->whereNull('users.deactivated_at')->distinct()->pluck('city_agency.city_id')->map(fn ($v) => (int) $v)->all();
        $withCenter = LogisticsCenter::where('is_active', true)->pluck('city_id')->map(fn ($v) => (int) $v)->all();
        $unavailable = ProductCityAvailability::where('product_id', $productId)->where('is_available', false)->pluck('city_id')->map(fn ($v) => (int) $v)->all();

        return StorefrontRules::codCities(City::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            $withAgency, $withCenter, $unavailable);
    }

    /** `.168`: las encomiendas van si el dueño las prendió y eligió de qué ciudad salen. */
    public static function courierOn(array $s): bool
    {
        return $s['courier_enabled'] && $s['courier_origin_city_id'] !== null;
    }

    /**
     * `.168`: las oficinas activas de una empresa, por estado (10 minutos).
     *
     * @return list<array{id: int, state: string, city: ?string, name: string, address: ?string}>
     */
    public static function offices(string $company): array
    {
        return Cache::remember("storefront:offices:{$company}:" . (Cache::get('storefront:version') ?? 0), 600, fn () => CourierOffice::where('company', $company)
            ->where('is_active', true)->orderBy('state')->orderBy('city')->orderBy('name')->get(['id', 'state', 'city', 'name', 'address'])
            ->map(fn ($o) => ['id' => $o->id, 'state' => $o->state, 'city' => $o->city, 'name' => $o->name, 'address' => $o->address])->all());
    }

    /**
     * `.168`: la cuenta de pago móvil que le toca ahora al cliente (la que menos Bs lleva hoy), con lo que ve: sus datos.
     * Va firmada: al llegar el pedido, la orden se queda con esa.
     *
     * @return ?array{id: string, name: string, details: list<array{label: string, value: string}>}
     */
    public static function account(): ?array
    {
        $account = app(PaymentAccounts::class)->suggest();
        if (!$account) {
            return null;
        }
        $shown = PaymentAccounts::present($account);

        return ['id' => $account->id . '.' . self::accountSig($account->id), 'name' => $account->name,
            'details' => array_map(fn ($d) => ['label' => (string) ($d['label'] ?? ''), 'value' => (string) ($d['value'] ?? '')], $shown['details'])];
    }

    /** "12.firma" → 12 si la firma es nuestra (así nadie elige otra cuenta a mano). */
    public static function accountId(?string $signed): ?int
    {
        [$id, $sig] = array_pad(explode('.', (string) $signed, 2), 2, '');

        return ctype_digit($id) && hash_equals(self::accountSig((int) $id), $sig) ? (int) $id : null;
    }

    private static function accountSig(int $id): string
    {
        return substr(hash_hmac('sha256', "nvf-acc|{$id}", (string) config('app.key')), 0, 24);
    }

    /**
     * `.168`: el horario de atención de la tienda para el cliente y cuándo se vuelve a atender. Sin horario automático, el
     * del equipo (de lunes a sábado, de 8:00 a. m. a 7:00 p. m., el mismo de los mensajes de n8n).
     *
     * @return array{text: string, when: string}
     */
    public static function hours(?int $shopId): array
    {
        $shop = $shopId ? Shop::find($shopId) : null;
        $auto = $shop && $shop->auto_schedule_enabled && $shop->auto_open_at && $shop->auto_close_at;
        $open = $auto ? (string) $shop->auto_open_at : '08:00';
        $close = $auto ? (string) $shop->auto_close_at : '19:00';
        $days = $auto ? (is_array($shop->auto_open_days) ? $shop->auto_open_days : null) : [1, 2, 3, 4, 5, 6];
        $now = now()->toDateTimeImmutable();

        return ['text' => StorefrontRules::hoursText($open, $close, $days) ?? 'en horario de oficina',
            'when' => StorefrontRules::whenText($now, StorefrontRules::nextOpening($now, $open, $days))];
    }

    /** Después de cambiar ofertas o diseño, el formulario los toma enseguida. */
    public static function refresh(): void
    {
        Cache::forever('storefront:version', (int) (Cache::get('storefront:version') ?? 0) + 1);
    }
}
