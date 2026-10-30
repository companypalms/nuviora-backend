<?php

namespace App\Services\Meta\Landings;

/**
 * Qué landing es una URL de destino (Etapa 5, paso 6a). PHP puro. Dos anuncios van a la misma landing aunque uno lleve
 * UTM, "www." o una variante distinta:
 * - se quitan los parámetros (utm_*, fbclid, variant…) salvo `view`, que en Shopify elige otra plantilla de la página;
 * - el dominio en minúsculas y sin "www."; la ruta en minúsculas, sin "/" al final;
 * - "/collections/x/products/y" es la misma página que "/products/y";
 * - WhatsApp, Messenger, Facebook e Instagram no son landings.
 */
final class LandingUrl
{
    private const NOT_LANDINGS = ['wa.me', 'api.whatsapp.com', 'whatsapp.com', 'm.me', 'messenger.com', 'facebook.com', 'm.facebook.com',
        'fb.com', 'fb.me', 'instagram.com', 'l.facebook.com'];

    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '//')) {
            $raw = 'https:' . $raw;
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $p = parse_url($raw);
        if ($p === false || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower($p['host']));
        if (in_array($host, self::NOT_LANDINGS, true)) {
            return null;
        }
        $path = strtolower(preg_replace('#/+#', '/', $p['path'] ?? ''));
        $path = preg_replace('#^/collections/[^/]+/products/#', '/products/', $path);
        $path = rtrim($path, '/');
        parse_str($p['query'] ?? '', $query);
        $view = is_string($query['view'] ?? null) ? strtolower(trim($query['view'])) : '';

        return "https://{$host}{$path}" . ($view !== '' ? '?view=' . rawurlencode($view) : '');
    }

    public static function hash(string $normalized): string
    {
        return sha1($normalized);
    }

    /** Un nombre corto para mostrar mientras el dueño no le ponga uno: "flexistep", "flexistep · basu" o el dominio. */
    public static function label(string $normalized): string
    {
        $p = parse_url($normalized) ?: [];
        $segments = array_values(array_filter(explode('/', $p['path'] ?? '')));
        $name = $segments ? rawurldecode(end($segments)) : ($p['host'] ?? $normalized);
        parse_str($p['query'] ?? '', $query);

        return $name . (!empty($query['view']) ? ' · ' . $query['view'] : '');
    }
}
