<?php

namespace App\Services\Meta;

/**
 * Convierte una fila de la Insights API en los valores base que se guardan (documento de Fran del 2026-10-08,
 * Módulo 2, §50: "Guardar los valores base siempre que sea posible"). Las fórmulas se calculan después, con los totales
 * (§51). Un campo que Meta no devolvió queda en null, que no es lo mismo que 0.
 */
class MetaInsightMapper
{
    /** Campos que se piden a la Insights API (§15 métricas generales, §20 video). */
    public const FIELDS = [
        'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'ad_id', 'ad_name', 'date_start', 'date_stop',
        'spend', 'impressions', 'reach', 'frequency', 'clicks', 'inline_link_clicks', 'unique_inline_link_clicks', 'outbound_clicks',
        'actions', 'action_values',
        'video_play_actions', 'video_thruplay_watched_actions', 'video_p25_watched_actions', 'video_p50_watched_actions',
        'video_p75_watched_actions', 'video_p95_watched_actions', 'video_p100_watched_actions', 'video_avg_time_watched_actions',
    ];

    /**
     * El tipo de acción que cuenta como compra: el primero que traiga la fila. El evento principal es Purchase (§15), y
     * Meta lo informa con varios nombres según el origen.
     */
    public static function purchaseActions(): array
    {
        return (array) config('services.meta.purchase_actions', ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase']);
    }

    /**
     * `.168`: el "pedido hecho" de nuestro formulario (AddPaymentInfo). En las campañas marcadas "Purchase al entregar" cuenta
     * como compra: así el sistema las mide por pedidos, como a todas, y el Purchase de Meta queda en real_purchases.
     */
    public const PLACED_ACTIONS = ['omni_add_payment_info', 'add_payment_info', 'offsite_conversion.fb_pixel_add_payment_info'];

    /** @param list<string>|null $countAs con qué acción se cuentan las compras (null = el Purchase, como siempre) */
    public static function map(array $row, ?array $countAs = null): array
    {
        $actions = self::byType($row['actions'] ?? null);
        $values = self::byType($row['action_values'] ?? null);

        $first = function (array $types) use ($actions): ?string {
            foreach ($types as $type) {
                if (array_key_exists($type, $actions)) {
                    return $type;
                }
            }
            return null;
        };
        $realType = $first(self::purchaseActions());
        $purchaseType = $countAs !== null ? $first($countAs) : $realType;
        // Si la fila trae acciones pero ninguna compra, son 0 compras; si no trae acciones, no se sabe.
        $purchases = $purchaseType !== null ? $actions[$purchaseType] : (isset($row['actions']) ? 0.0 : null);
        $real = $realType !== null ? $actions[$realType] : (isset($row['actions']) ? 0.0 : null);

        return [
            'spend' => self::num($row['spend'] ?? null) ?? 0.0,
            'impressions' => (int) (self::num($row['impressions'] ?? null) ?? 0),
            'reach' => self::int($row['reach'] ?? null),
            'frequency' => self::num($row['frequency'] ?? null),
            'clicks' => self::int($row['clicks'] ?? null),
            'link_clicks' => self::int($row['inline_link_clicks'] ?? null),
            // Paso 1a: el CTR único que usa el dueño (clics únicos en el enlace ÷ alcance)
            'unique_link_clicks' => self::int($row['unique_inline_link_clicks'] ?? null),
            'outbound_clicks' => self::sumList($row['outbound_clicks'] ?? null),
            'landing_page_views' => isset($actions['landing_page_view']) ? (int) $actions['landing_page_view']
                : (isset($actions['omni_landing_page_view']) ? (int) $actions['omni_landing_page_view'] : (isset($row['actions']) ? 0 : null)),
            'purchases' => $purchases,
            'purchase_value' => $purchaseType !== null && isset($values[$purchaseType]) ? $values[$purchaseType] : null,
            'real_purchases' => $countAs !== null ? $real : null,
            'video_plays' => self::sumList($row['video_play_actions'] ?? null),
            // §21: "3-second Video Plays" es la acción video_view de Meta
            'video_3s_plays' => isset($actions['video_view']) ? (int) $actions['video_view'] : (isset($row['video_play_actions']) ? 0 : null),
            'thruplays' => self::sumList($row['video_thruplay_watched_actions'] ?? null),
            'video_p25' => self::sumList($row['video_p25_watched_actions'] ?? null),
            'video_p50' => self::sumList($row['video_p50_watched_actions'] ?? null),
            'video_p75' => self::sumList($row['video_p75_watched_actions'] ?? null),
            'video_p95' => self::sumList($row['video_p95_watched_actions'] ?? null),
            'video_p100' => self::sumList($row['video_p100_watched_actions'] ?? null),
            'video_avg_time' => self::avgList($row['video_avg_time_watched_actions'] ?? null),
            'actions' => ($actions || $values) ? ['actions' => $actions, 'values' => $values, 'purchase_type' => $purchaseType] : null,
        ];
    }

    /** [{action_type, value}, …] → [action_type => valor] */
    private static function byType($list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $a) {
            if (isset($a['action_type'])) {
                $out[(string) $a['action_type']] = ($out[(string) $a['action_type']] ?? 0) + (float) ($a['value'] ?? 0);
            }
        }
        return $out;
    }

    private static function sumList($list): ?int
    {
        if (!is_array($list)) {
            return null;
        }
        return (int) round(array_sum(array_map(fn ($a) => (float) ($a['value'] ?? 0), $list)));
    }

    private static function avgList($list): ?float
    {
        if (!is_array($list) || !$list) {
            return null;
        }
        return (float) ($list[0]['value'] ?? 0);
    }

    private static function num($v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function int($v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }
}
