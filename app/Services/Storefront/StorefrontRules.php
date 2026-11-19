<?php

namespace App\Services\Storefront;

/**
 * `.164` (dueño, 2026-11-19: "mejor olvida lo de medir el formulario de Releasit… vamos a utilizar solo nuestro nuevo
 * formulario"): las reglas de nuestro formulario de pedido en la landing, en PHP puro. Pago al recibir, como el de Releasit
 * del dueño: las mismas ofertas (unidades × precio × (1 − %)), los precios en bolívares con su tasa de Shopify (la cuenta y el
 * redondeo de su Liquid), el Delivery Express, el teléfono venezolano, los nombres de los pedidos (WEB-12), el Content ID de
 * su catálogo y el mensaje de WhatsApp después del pedido (los códigos de Releasit).
 */
final class StorefrontRules
{
    public const SETTING = 'storefront_form';

    public const CONTENT_FORMATS = ['shopify' => 'shopify_{REGION}_{producto}_{variante}', 'product' => '{producto}', 'variant' => '{variante}'];

    /** El mensaje de WhatsApp del dueño en Releasit (19/11). */
    public const WHATSAPP_MESSAGE = '*Es importante enviar este mensaje* ¡Hola! Soy {first_name} y he realizado el pedido {order_id}. Deseo pagarlo al momento de recibirlo. Muchas gracias!';

    public const DEFAULTS = ['enabled' => false, 'shop_id' => null, 'product_ids' => [], 'express_usd' => 2.0, 'whatsapp_number' => '+584221792891',
        'whatsapp_message' => self::WHATSAPP_MESSAGE, 'allowed_origins' => [], 'releasit_selector' => '', 'pixel_id' => '', 'capi_token' => null,
        'capi_test_code' => '', 'content_id_format' => 'shopify', 'content_region' => 'VE', 'send_initiate' => true, 'send_pageview' => false,
        'daily_max' => 500];

    /** Los celulares de Venezuela (Movilnet, Movistar y Digitel). */
    public const MOBILE = ['412', '414', '416', '422', '424', '426'];

    /** El mismo teléfono y el mismo producto en este tiempo es el mismo pedido (un doble toque o un reenvío). */
    public const SAME_ORDER_MINUTES = 30;

    /** Cuántos pedidos puede hacer un mismo teléfono en 24 horas (cada uno manda plantillas de WhatsApp). */
    public const PHONE_DAY_MAX = 3;

    /** Lo mínimo que tarda una persona en llenar el formulario (los robots lo mandan al instante). */
    public const MIN_SECONDS = 3;

    /** Para que nunca choque con un número de Shopify (un id de pedido de Shopify tiene 13 dígitos). */
    public const ORDER_ID_BASE = 9_000_000_000_000_000;

    /** Los textos del formulario de Releasit del dueño (captura del 19/11); cada producto puede cambiar los suyos. */
    public const TEXTS = [
        'title' => 'PIDE EL TUYO Y PAGA AL RECIBIRLO 👇',
        'shipping_label' => 'Método de envío',
        'shipping_option' => 'Delivery en Caracas, Valencia y Maracay',
        'shipping_price' => 'Gratis',
        'size_label' => 'Talla',
        'name_label' => 'Nombre y apellido',
        'name_placeholder' => 'Nombre completo',
        'phone_label' => 'Numero de contacto',
        'phone_placeholder' => 'Ingrese su WhatsApp',
        'city_label' => 'Estado o Ciudad para pagar al recibir',
        'city_placeholder' => 'Estado / Ciudad',
        'address_label' => 'Dirección de entrega (sea detallado)',
        'address_placeholder' => 'Urbanizacion, residencia, calle, etc',
        'reference_placeholder' => 'Puntos de referencia',
        'subtotal' => 'Subtotal',
        'delivery' => 'Delivery',
        'total' => 'Total',
        'express_title' => '(OPCIONAL) Añade Delivery Express por solo {precio}',
        'express_note' => 'TU PEDIDO SE ENVIARA PRIMERO QUE TODOS LOS DEMAS',
        'button' => 'CONFIRMAR PEDIDO',
        'button_note' => 'PAGAR AL RECIBIR',
        'footnote' => '* Nuestro equipo te atenderá por WhatsApp para finalizar el envío.',
        'saving' => 'Ahorras {ahorro}',
        'thanks_title' => '¡Listo! Recibimos tu pedido {pedido}',
        'thanks_text' => 'Ahora te llevamos a WhatsApp: envía el mensaje para confirmar tu pedido.',
        'thanks_button' => 'Enviar mensaje por WhatsApp',
        'repeat_text' => 'Ya tenemos tu pedido {pedido}: no hace falta enviarlo otra vez.',
        'error_required' => 'Completa este dato',
        'error_phone' => 'Escribe un número de WhatsApp de Venezuela (0414, 0424, 0412, 0416, 0426 o 0422)',
    ];

    /** Los colores del formulario de Releasit del dueño; cada producto puede cambiar los suyos. */
    public const COLORS = ['title' => '#1A73E8', 'button' => '#0A6CFF', 'button_text' => '#FFFFFF', 'background' => '#FFFFFF', 'text' => '#333333',
        'label' => '#5F6368', 'border' => '#D9D9D9', 'field_icon_bg' => '#E5E5E5', 'offer_selected' => '#2EA44F', 'offer_selected_bg' => '#E3F5E8',
        'badge' => '#2EA44F', 'badge_text' => '#FFFFFF', 'summary_bg' => '#F3F3F3', 'express_bg' => '#A8E6C1', 'express_border' => '#2EA44F',
        'error' => '#D93025'];

    /** Lo que se guarda: lo que no llega queda como estaba; la clave de Meta nunca se borra sin querer. */
    public static function settings(?array $raw): array
    {
        $raw = (array) $raw;
        $d = self::DEFAULTS;
        $origins = array_values(array_unique(array_filter(array_map(fn ($o) => self::origin((string) $o), (array) ($raw['allowed_origins'] ?? [])))));

        return [
            'enabled' => (bool) ($raw['enabled'] ?? $d['enabled']),
            'shop_id' => isset($raw['shop_id']) && (int) $raw['shop_id'] > 0 ? (int) $raw['shop_id'] : null,
            'product_ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($raw['product_ids'] ?? []))))),
            'express_usd' => round(min(50, max(0, (float) ($raw['express_usd'] ?? $d['express_usd']))), 2),
            'whatsapp_number' => self::waNumber((string) ($raw['whatsapp_number'] ?? $d['whatsapp_number'])),
            'whatsapp_message' => mb_substr(trim((string) ($raw['whatsapp_message'] ?? '')) ?: $d['whatsapp_message'], 0, 1000),
            'allowed_origins' => array_slice($origins, 0, 10),
            'releasit_selector' => mb_substr(trim((string) ($raw['releasit_selector'] ?? '')), 0, 500),
            'pixel_id' => preg_match('/^\d{6,20}$/', trim((string) ($raw['pixel_id'] ?? ''))) ? trim((string) $raw['pixel_id']) : '',
            'capi_token' => is_string($raw['capi_token'] ?? null) && $raw['capi_token'] !== '' ? $raw['capi_token'] : null,
            'capi_test_code' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['capi_test_code'] ?? '')), 0, 40),
            'content_id_format' => array_key_exists($raw['content_id_format'] ?? '', self::CONTENT_FORMATS) ? $raw['content_id_format'] : $d['content_id_format'],
            'content_region' => preg_match('/^[A-Za-z]{2}$/', (string) ($raw['content_region'] ?? '')) ? strtoupper($raw['content_region']) : $d['content_region'],
            'send_initiate' => (bool) ($raw['send_initiate'] ?? $d['send_initiate']),
            'send_pageview' => (bool) ($raw['send_pageview'] ?? $d['send_pageview']),
            'daily_max' => min(5000, max(10, (int) ($raw['daily_max'] ?? $d['daily_max']))),
        ];
    }

    /** "https://mitienda.com/" → "https://mitienda.com"; lo que no es un origen https (o localhost) no vale. */
    public static function origin(string $url): ?string
    {
        $p = parse_url(trim($url));
        $host = strtolower((string) ($p['host'] ?? ''));
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($host === '' || !in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true))) {
            return null;
        }

        return "{$scheme}://{$host}" . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    }

    /**
     * El WhatsApp del cliente, como lo guardan los pedidos de Shopify: "+58" y los 10 dígitos de un celular. "0414-123.45.67",
     * "+58 414 1234567", "584141234567", "4141234567" y "00584141234567" → "+584141234567". Un fijo o algo raro → null.
     */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        $d = preg_replace('/^(00)?58/', '', $d);
        $d = ltrim($d, '0');

        return strlen($d) === 10 && in_array(substr($d, 0, 3), self::MOBILE, true) ? '+58' . $d : null;
    }

    /** Los últimos 10 dígitos (así se reconoce al mismo cliente en todo el sistema). */
    public static function last10(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone), -10);
    }

    /** "María José Pérez" → ["María", "José Pérez"]. @return array{0: string, 1: string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** El total en dólares de una oferta: unidades × precio × (1 − %), con centavos (como Releasit: 3 × $33 − 33 % = $66,33). */
    public static function offerUsd(float $price, int $units, float $pct): float
    {
        return round($units * $price * (1 - min(90, max(0, $pct)) / 100), 2);
    }

    /** Los bolívares como los calcula la landing del dueño: dólares × su tasa, redondeado (Math.round). */
    public static function bs(float $usd, float $rate): int
    {
        return (int) round($usd * $rate);
    }

    /** "Bs 12.345", como lo muestra su landing. */
    public static function bsText(int $bs): string
    {
        return 'Bs ' . number_format($bs, 0, ',', '.');
    }

    /** La tasa con que se calculan los Bs: la de su página (su metafield binance_rate) o, si no llega bien, la del sistema. */
    public static function rate(mixed $page, float $system): ?float
    {
        $r = is_numeric($page) ? (float) $page : 0.0;
        if ($r > 0 && $r < 10_000_000) {
            return $r;
        }

        return $system > 0 ? $system : null;
    }

    /**
     * Las ofertas de un producto, listas para el formulario: las activas en su orden, con su total en dólares y en Bs, lo que
     * ahorra y una sola marcada (la que el dueño marcó o, si no, la de 1 unidad). Sin ofertas, una: 1 unidad al precio.
     *
     * @param list<array{id: int, units: int, discount_pct: float, title: ?string, badge: ?string, is_default: bool, is_active: bool}> $offers
     * @return list<array{id: ?int, units: int, title: string, badge: ?string, is_default: bool, price_usd: float, price_bs: ?int, saving_bs: ?int}>
     */
    public static function offers(float $price, array $offers, ?float $rate, string $name): array
    {
        $list = array_values(array_filter($offers, fn ($o) => ($o['is_active'] ?? true) && (int) $o['units'] >= 1));
        if ($list === []) {
            $list = [['id' => null, 'units' => 1, 'discount_pct' => 0.0, 'title' => null, 'badge' => null, 'is_default' => true]];
        }
        $out = array_map(function ($o) use ($price, $rate, $name) {
            $usd = self::offerUsd($price, (int) $o['units'], (float) $o['discount_pct']);
            $full = round($price * (int) $o['units'], 2);

            return ['id' => $o['id'] !== null ? (int) $o['id'] : null, 'units' => (int) $o['units'],
                'title' => trim((string) ($o['title'] ?? '')) ?: ((int) $o['units'] === 1 ? "LLEVA 1 {$name}" : 'LLEVA ' . (int) $o['units'] . " {$name}"),
                'badge' => trim((string) ($o['badge'] ?? '')) ?: null, 'is_default' => (bool) ($o['is_default'] ?? false), 'price_usd' => $usd,
                'price_bs' => $rate !== null ? self::bs($usd, $rate) : null,
                'saving_bs' => $rate !== null && $full > $usd ? self::bs($full, $rate) - self::bs($usd, $rate) : null];
        }, $list);
        $default = array_search(true, array_column($out, 'is_default'), true);
        if ($default === false) {
            $default = array_search(1, array_column($out, 'units'), true);
        }
        foreach ($out as $i => $o) {
            $out[$i]['is_default'] = $i === ($default === false ? 0 : $default);
        }

        return $out;
    }

    /**
     * Dónde se paga al recibir este producto: las ciudades con una agencia activa (o un centro logístico activo), salvo donde
     * el dueño lo marcó como no disponible.
     *
     * @param list<array{id: int, name: string}> $cities
     * @param list<int> $withAgency ciudades con una agencia activa en city_agency
     * @param list<int> $withCenter ciudades con un centro logístico activo
     * @param list<int> $unavailable ciudades donde este producto no se vende
     * @return list<array{id: int, name: string}>
     */
    public static function codCities(array $cities, array $withAgency, array $withCenter, array $unavailable): array
    {
        $ok = array_flip(array_map('intval', [...$withAgency, ...$withCenter]));
        $no = array_flip(array_map('intval', $unavailable));
        $out = array_values(array_filter($cities, fn ($c) => isset($ok[(int) $c['id']]) && !isset($no[(int) $c['id']])));
        usort($out, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name']], $out);
    }

    /** Si un pedido anterior del mismo teléfono y producto es el mismo pedido (un doble toque o un reenvío). */
    public static function samePedido(string $before, string $now): bool
    {
        return strtotime($now) - strtotime($before) <= self::SAME_ORDER_MINUTES * 60;
    }

    public static function orderName(int $n): string
    {
        return "WEB-{$n}";
    }

    public static function orderId(int $n): int
    {
        return self::ORDER_ID_BASE + $n;
    }

    public static function eventId(int $n): string
    {
        return "nuv-web-{$n}";
    }

    /** El Content ID del catálogo de Meta, con el formato que tenía en Releasit ("shopify_VE_7558816…_4521…"). */
    public static function contentId(string $format, string $region, string $productId, ?string $variantId): string
    {
        return match ($format) {
            'product' => $productId,
            'variant' => (string) ($variantId ?: $productId),
            default => 'shopify_' . strtoupper($region) . "_{$productId}" . ($variantId ? "_{$variantId}" : ''),
        };
    }

    /**
     * El mensaje que el cliente manda por WhatsApp después de pedir, con los códigos de Releasit: {first_name}, {last_name},
     * {order_id}, {order_number}, {order_total}, {product_title}, {product_quantity}, {products_summary_with_quantity},
     * {phone}, {address}, {address2}, {province}. Lo que no se conoce queda vacío.
     *
     * @param array<string, string> $vars
     */
    public static function whatsappMessage(string $template, array $vars): string
    {
        return trim(preg_replace_callback('/\{([a-z0-9_]+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template));
    }

    /** "https://wa.me/584221792891?text=…". */
    public static function waLink(string $number, string $message): ?string
    {
        $d = self::waDigits($number);

        return $d === '' ? null : "https://wa.me/{$d}?text=" . rawurlencode($message);
    }

    /** El origen del pedido con la forma de uno de Shopify, para guardarlo igual (OrderSources: anuncio, landing, utm). */
    public static function sourcePayload(?string $landing, ?string $referrer, ?string $page): array
    {
        return ['landing_site' => $landing ?: $page, 'referring_site' => $referrer, 'source_name' => 'nuviora_form'];
    }

    /** Los textos del formulario de un producto: los suyos sobre los de siempre (lo vacío vuelve al de siempre). */
    public static function texts(?array $own): array
    {
        $out = self::TEXTS;
        foreach ((array) $own as $k => $v) {
            if (array_key_exists($k, self::TEXTS) && is_string($v) && trim($v) !== '') {
                $out[$k] = mb_substr(trim($v), 0, 300);
            }
        }

        return $out;
    }

    /** Los colores de un producto: los suyos (solo #RGB o #RRGGBB) sobre los de siempre. */
    public static function colors(?array $own): array
    {
        $out = self::COLORS;
        foreach ((array) $own as $k => $v) {
            if (array_key_exists($k, self::COLORS) && is_string($v) && preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', trim($v))) {
                $out[$k] = strtoupper(trim($v));
            }
        }

        return $out;
    }

    /** Una letra de Google o la de la página (null). Solo letras, números y espacios: va dentro de un CSS. */
    public static function font(?string $font): ?string
    {
        $f = trim((string) $font);

        return $f !== '' && preg_match('/^[A-Za-z0-9 ]{2,60}$/', $f) ? $f : null;
    }

    /** El CSS del dueño, sin lo que podría salirse de su <style>. */
    public static function css(?string $css): string
    {
        return mb_substr(str_ireplace(['</style', '<script', '</script'], '', (string) $css), 0, 20000);
    }

    /** La firma del momento en que se dibujó el formulario (para saber que no lo mandó un robot al instante). */
    public static function sign(int $ts, string $key): string
    {
        return substr(hash_hmac('sha256', "nvf|{$ts}", $key), 0, 32);
    }

    /** El WhatsApp de la tienda con su código de país: "0422-1792891" → "+584221792891". */
    private static function waNumber(string $n): string
    {
        $d = self::waDigits($n);
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = '58' . substr($d, 1);
        }

        return $d !== '' ? "+{$d}" : '';
    }

    private static function waDigits(string $n): string
    {
        return preg_replace('/\D+/', '', $n);
    }
}
