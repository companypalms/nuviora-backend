<?php

namespace App\Services\Meta\Upload;

/**
 * Las reglas de "Subir a Meta" (`.57`/`.58`, decisiones del dueño del 2026-10-31). PHP puro.
 * - Nombres como los pone el dueño: el ad set "B0123 FASP" y cada anuncio "C0457 FASP" (código + ángulo).
 * - Los parámetros de URL del anuncio llevan siempre los de la `.53` (para saber de qué anuncio viene cada pedido); lo
 *   demás que tenga la referencia se conserva.
 * - Los archivos se bajan de Drive con su enlace público ("cualquiera con el enlace").
 * - Los anuncios se crean ACTIVOS (decisión del dueño) y el ad set sin presupuesto propio (sus campañas reparten el
 *   presupuesto: CBO).
 */
final class UploadRules
{
    public const STATUS = 'ACTIVE';

    /** Los de la `.53`: utm_content={{ad.id}} dice el anuncio de cada pedido. */
    public const UTM = [
        'utm_source' => 'facebook',
        'utm_medium' => 'paid',
        'utm_campaign' => '{{campaign.id}}',
        'utm_term' => '{{adset.id}}',
        'utm_content' => '{{ad.id}}',
    ];

    public static function adsetName(string $batchCode, ?string $angle): string
    {
        return trim($batchCode . ' ' . strtoupper((string) $angle));
    }

    public static function adName(string $pieceCode, ?string $angle): string
    {
        return trim($pieceCode . ' ' . strtoupper((string) $angle));
    }

    /**
     * Los parámetros de URL de la referencia con los de la `.53` encima (en el mismo orden de siempre; lo demás que
     * tenga, después). Las plantillas de Meta ({{ad.id}}) quedan tal cual.
     */
    public static function urlTags(?string $existing): string
    {
        $keep = [];
        foreach (explode('&', trim((string) $existing, " \t\n\r\0\x0B?&")) as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $k = trim($k);
            if ($k !== '' && !array_key_exists(strtolower($k), self::UTM)) {
                $keep[$k] = $v;
            }
        }
        $all = self::UTM + $keep;

        return implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($all), $all));
    }

    /** El enlace de descarga directa de un archivo público de Drive. */
    public static function driveDownloadUrl(string $fileId): string
    {
        return 'https://drive.google.com/uc?export=download&id=' . rawurlencode($fileId);
    }

    /**
     * Lo que dice Drive al pedir el archivo (los primeros bytes): si se puede bajar o por qué no.
     *
     * @return array{ok: bool, note: ?string}
     */
    public static function driveAnswer(int $status, ?string $contentType, string $bodyStart): array
    {
        $html = str_contains(strtolower((string) $contentType), 'text/html');
        if ($status === 404) {
            return ['ok' => false, 'note' => 'Drive dice que el archivo no existe (¿lo borraron?).'];
        }
        if ($status === 401 || $status === 403 || ($html && preg_match('/accounts\.google\.com|ServiceLogin|Solicitar acceso|Request access/i', $bodyStart))) {
            return ['ok' => false, 'note' => 'El archivo no es público: el editor tiene que compartirlo como "cualquiera con el enlace".'];
        }
        if ($status >= 400) {
            return ['ok' => false, 'note' => "Drive respondió {$status}."];
        }
        if ($html && preg_match('/download_warning|virus|confirm=/i', $bodyStart)) {
            return ['ok' => true, 'note' => 'Es un archivo grande: Drive pide confirmar la descarga (se hace sola al subir).'];
        }
        if ($html) {
            return ['ok' => false, 'note' => 'Drive no devolvió el archivo sino una página: revisa que el enlace sea del archivo y que sea público.'];
        }

        return ['ok' => true, 'note' => null];
    }

    /** ¿La campaña reparte el presupuesto (CBO)? Lo dice su configuración guardada de Meta. */
    public static function campaignBudget(?array $meta): ?float
    {
        foreach (['daily_budget', 'lifetime_budget'] as $k) {
            if (isset($meta[$k]) && is_numeric($meta[$k]) && (float) $meta[$k] > 0) {
                return (float) $meta[$k] / 100; // Meta lo guarda en centavos
            }
        }

        return null;
    }
}
