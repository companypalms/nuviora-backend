<?php

namespace App\Services\Meta\Products;

use App\Models\Shop;
use App\Services\ShopifyService;

/**
 * `.89` (dueño, 2026-11-03: "ese producto nuevo de Termafit se debe enlazar de alguna manera con el producto creado en
 * Shopify… cuando llegue un pedido de ese producto va a poder reconocer y enlazarlo?"): el link del producto en Shopify
 * que el dueño le pasa al asistente. Del link del panel sale su número; del de la tienda, su "handle", y el número lo da
 * Shopify (con las credenciales que ya están en el servidor). Con el número guardado en el producto, cada pedido de ese
 * producto se une a él aunque el nombre no sea igual (ShopifyWebhookController y OrderController).
 */
final class ShopifyLink
{
    /**
     * "admin.shopify.com/store/x/products/123" o "x.myshopify.com/admin/products/123" → id; "tienda.com/products/termafit"
     * → handle. PHP puro.
     *
     * @return array{id: ?int, handle: ?string}
     */
    public static function parse(?string $url): array
    {
        $url = trim((string) $url);
        if (preg_match('~/products/(\d{6,})(?:[/?#]|$)~', $url, $m)) {
            return ['id' => (int) $m[1], 'handle' => null];
        }
        if (preg_match('~^(?:https?://)?[^/\s]+/(?:[a-z]{2}(?:-[a-z]{2})?/)?(?:collections/[^/]+/)?products/([a-z0-9][a-z0-9\-_.%]*)~i', $url, $m)) {
            return ['id' => null, 'handle' => strtolower(urldecode($m[1]))];
        }

        return ['id' => null, 'handle' => null];
    }

    /**
     * El número, el nombre exacto y el precio del producto en Shopify. Si Shopify no responde, con el link del panel queda
     * igual el número (sin el nombre); con el de la tienda no se puede saber el número y se dice qué link pasar.
     *
     * @param callable|null $find fn (?int $id, ?string $handle): ?array el producto de Shopify (para las pruebas)
     * @return array{id: ?int, title: ?string, price: ?float, problem: ?string}
     */
    public static function resolve(?string $url, ?callable $find = null): array
    {
        $p = self::parse($url);
        if ($p['id'] === null && $p['handle'] === null) {
            return ['id' => null, 'title' => null, 'price' => null,
                'problem' => 'Ese link no es de un producto de Shopify: cópialo desde el panel de Shopify (Productos › el producto) o desde la página del producto en tu tienda.'];
        }
        $product = ($find ?? self::finder())($p['id'], $p['handle']);
        if ($product === null && $p['id'] === null) {
            return ['id' => null, 'title' => null, 'price' => null,
                'problem' => 'No encontré ese producto en tu tienda Shopify. Pásame el link del panel de Shopify (Productos › el producto): de ahí sale su número.'];
        }
        $price = $product['variants'][0]['price'] ?? null;

        return ['id' => (int) ($product['id'] ?? $p['id']), 'title' => isset($product['title']) ? trim((string) $product['title']) : null,
            'price' => is_numeric($price) ? (float) $price : null, 'problem' => null];
    }

    /** Busca en cada tienda (y, si no hay ninguna cargada, con la del .env del servidor). */
    private static function finder(): callable
    {
        return function (?int $id, ?string $handle): ?array {
            $shops = Shop::whereNotNull('shopify_domain')->whereNotNull('shopify_access_token')->get();
            foreach ($shops->isEmpty() ? [null] : $shops->all() as $shop) {
                try {
                    $s = new ShopifyService($shop);
                    $found = $id !== null ? $s->getProductById($id) : $s->getProductByHandle((string) $handle);
                    if ($found) {
                        return $found;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return null;
        };
    }
}
