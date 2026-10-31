<?php

namespace App\Services\Orders;

use App\Services\Meta\Landings\LandingUrl;

/**
 * De dónde vino un pedido de Shopify (`.53`), leído de lo que manda Shopify al crearlo. PHP puro.
 * - landing_site: la página donde entró la visita, con sus parámetros (utm_*, fbclid). Shopify la manda casi siempre
 *   como ruta ("/products/x?…"); a veces, con el dominio.
 * - note_attributes: algunos formularios contra entrega (Releasit…) copian ahí los parámetros. Solo se leen los de origen
 *   (utm_*, fbclid, ad_id…): los datos del cliente que también vienen ahí no se tocan.
 * - Con los parámetros que el dueño pone en Ads Manager (utm_content={{ad.id}}, utm_term={{adset.id}},
 *   utm_campaign={{campaign.id}}), el pedido dice su anuncio.
 */
final class OrderSourceRules
{
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** Lo que se acepta de note_attributes (en minúsculas): nada más. */
    private const NOTE_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'ad_id', 'adset_id',
        'campaign_id', 'landing_page', 'landing_site'];

    /** Un id de Meta: solo números, largo. "{{ad.id}}" sin reemplazar no es un id. */
    private const META_ID = '/^\d{6,25}$/';

    /**
     * @param array $order lo que manda Shopify (orders/create)
     * @return array{landing_url: ?string, landing_absolute: bool, referring_site: ?string, source_name: ?string, utm_source: ?string,
     *               utm_medium: ?string, utm_campaign: ?string, utm_term: ?string, utm_content: ?string, has_fbclid: bool,
     *               ad_meta_id: ?string, adset_meta_id: ?string, campaign_meta_id: ?string}
     */
    public static function parse(array $order): array
    {
        $notes = [];
        foreach ((array) ($order['note_attributes'] ?? []) as $a) {
            $name = is_array($a) ? mb_strtolower(trim((string) ($a['name'] ?? ''))) : '';
            if (in_array($name, self::NOTE_KEYS, true) && is_scalar($a['value'] ?? null) && trim((string) $a['value']) !== '') {
                $notes[$name] = trim((string) $a['value']);
            }
        }
        $landing = is_string($order['landing_site'] ?? null) && trim($order['landing_site']) !== '' ? trim($order['landing_site'])
            : ($notes['landing_site'] ?? $notes['landing_page'] ?? null);
        parse_str((string) (parse_url((string) $landing, PHP_URL_QUERY) ?? ''), $query);
        $query = array_change_key_case(array_filter($query, 'is_string'), CASE_LOWER);
        // Lo de la URL manda; lo de note_attributes completa lo que falte
        $param = fn (string $k) => self::clean($query[$k] ?? null) ?? self::clean($notes[$k] ?? null);

        $out = [
            'landing_url' => null,
            'landing_absolute' => false,
            'referring_site' => self::withoutQuery($order['referring_site'] ?? null),
            'source_name' => self::clean($order['source_name'] ?? null, 60),
        ];
        if ($landing !== null) {
            [$out['landing_url'], $out['landing_absolute']] = self::landing($landing);
        }
        foreach (self::UTM as $k) {
            $out[$k] = $param($k);
        }
        $out['has_fbclid'] = $param('fbclid') !== null;
        $id = fn (?string $v) => $v !== null && preg_match(self::META_ID, $v) ? $v : null;
        $out['ad_meta_id'] = $id($param('ad_id')) ?? $id($out['utm_content']);
        $out['adset_meta_id'] = $id($param('adset_id')) ?? $id($out['utm_term']);
        $out['campaign_meta_id'] = $id($param('campaign_id')) ?? $id($out['utm_campaign']);

        return $out;
    }

    /**
     * La landing como la guarda el sistema (LandingUrl): con dominio, la URL normalizada; si Shopify mandó solo la ruta,
     * la ruta normalizada igual ("/products/x", "/products/x?view=y").
     *
     * @return array{0: ?string, 1: bool} la landing y si tiene dominio
     */
    public static function landing(string $raw): array
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '/') && !str_starts_with($raw, '//')) {
            $n = LandingUrl::normalize('https://ruta.invalid' . $raw);

            return [$n !== null ? (substr($n, strlen('https://ruta.invalid')) ?: '/') : null, false];
        }

        return [LandingUrl::normalize($raw), true];
    }

    /** La parte de la ruta de una landing normalizada con dominio ("https://x.com/products/y" → "/products/y"). */
    public static function pathOf(string $normalized): string
    {
        $p = parse_url($normalized) ?: [];

        return ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    private static function clean(mixed $v, int $max = 255): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function withoutQuery(mixed $url): ?string
    {
        $url = self::clean($url, 2000);
        if ($url === null) {
            return null;
        }

        return mb_substr(preg_replace('/[?#].*$/', '', $url), 0, 500);
    }
}
