<?php

namespace App\Services\StoreChat;

use App\Models\City;
use App\Models\Product;
use App\Models\ProductCityAvailability;
use App\Models\ProductProfile;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * `.158`: lo que sabe el chat de la tienda de un producto, leído del sistema. Solo lee.
 * - la ficha (Meta Ads › Ficha del producto, `.64`); los buyer personas y "Lo que sabemos" no: son estrategia;
 * - el precio de hoy en bolívares: el precio del producto por la tasa Binance (la que usan las órdenes); `.162`: con la última
 *   tasa aunque tenga más de un día (el dueño: "debe responder el precio"); la página avisa si está vieja (price_issue);
 * - las ciudades donde ese producto se entrega (las que no están marcadas "no disponible" en Inventario);
 * - `.159`: los "Más detalles" de la ficha (solo para el chat) y el teléfono de atención al cliente de los ajustes.
 */
class StoreChatFacts
{
    /**
     * @return array{product_id: int, name: string, ficha: array<string, ?string>, price_usd: float, price_bs: ?int, rate: ?float,
     *               rate_at: ?string, rate_fresh: bool, cities: list<string>, other_cities: list<string>, details: ?string, support_phone: string, hours: string, price_issue: ?string, policies: string}
     */
    public function for(Product $product, CarbonImmutable $now): array
    {
        $profile = ProductProfile::where('product_id', $product->id)->first();
        $rate = Setting::get('rate_binance_usd');
        $rate = is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
        $rateAt = Setting::get('rate_updated_at');
        $rateAt = is_string($rateAt) && $rateAt !== '' ? $rateAt : null;
        $fresh = StoreChatRules::rateFresh($rateAt, $now);
        $off = ProductCityAvailability::where('product_id', $product->id)->where('is_available', false)->pluck('city_id')->all();
        $cities = City::operational()->orderBy('name')->get(['id', 'name']); // .174: la zona no es una ciudad para el cliente
        $settings = StoreChatRules::settings(Setting::get(StoreChatRules::SETTING));

        return [
            'product_id' => $product->id,
            'name' => self::name($product),
            'ficha' => [
                'what_it_is' => $profile?->what_it_is,
                'benefits' => $profile?->benefits,
                'mechanism' => $profile?->mechanism,
                'not_this' => $profile?->not_this,
                'key_info' => $profile?->key_info,
            ],
            'price_usd' => (float) $product->price,
            'price_bs' => StoreChatRules::priceBs((float) $product->price, $rate),
            // .162: por qué no hay precio (o si la tasa está vieja), para la página del dueño
            'price_issue' => match (true) {
                (float) $product->price <= 0 => 'no_price',
                $rate === null => 'no_rate',
                !$fresh => 'old_rate',
                default => null,
            },
            'rate' => $rate,
            'rate_at' => $rateAt,
            'rate_fresh' => $fresh,
            'cities' => $cities->reject(fn ($c) => in_array($c->id, $off))->pluck('name')->values()->all(),
            'other_cities' => $cities->filter(fn ($c) => in_array($c->id, $off))->pluck('name')->values()->all(),
            'details' => $profile?->chat_details,
            'support_phone' => $settings['support_phone'],
            'hours' => $settings['hours'],
            'policies' => $settings['policies'],
        ];
    }

    public static function name(Product $product): string
    {
        return (string) ($product->showable_name ?: ($product->name ?: $product->title));
    }
}
