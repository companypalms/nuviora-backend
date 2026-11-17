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
 * - `.155` (dueño, 2026-11-17: "Las primeras 5 en pausa"): las primeras 5 subidas de verdad dejan el ad set apagado, para
 *   que el dueño lo revise en el Administrador y lo prenda; de ahí en adelante quedan prendidos.
 */
final class UploadRules
{
    public const STATUS = 'ACTIVE';

    /** `.155`: cuántas subidas se crean en pausa antes de crearlas prendidas. */
    public const PAUSED_FIRST = 5;

    /** `.155`: el interruptor "Subir a Meta" (apagado hasta que el dueño lo prenda). */
    public const ENABLED = 'meta_upload_enabled';

    /** `.155`: cuánto se espera a que Meta procese los videos antes de avisar. */
    public const WAIT_MINUTES = 30;

    /** `.155`: el permiso de Meta que deja crear anuncios. */
    public const WRITE_SCOPE = 'ads_management';

    /** `.155`: ¿el token de una conexión puede subir anuncios? null si Meta no dijo sus permisos. */
    public static function canUpload(?array $scopes): ?bool
    {
        return $scopes === null ? null : in_array(self::WRITE_SCOPE, $scopes, true);
    }

    /** `.155`: ¿un ad set que ya está en la campaña es el mismo batch? ("B0123 FASP" y "B0123 FASP - Copia", sí). */
    public static function sameAdset(string $existing, string $wanted): bool
    {
        $code = strtoupper((string) strtok(trim($wanted), ' '));

        return $code !== '' && strtoupper((string) strtok(trim($existing), ' ')) === $code;
    }

    /** `.155`: el gasto mínimo en centavos, como lo pide Meta. */
    public static function cents(float $usd): int
    {
        return (int) round($usd * 100);
    }

    /**
     * `.155`: lo que dice Meta de un video que se subió: listo, procesando o con error.
     *
     * @return array{state: string, note: ?string}
     */
    public static function videoState(array $status): array
    {
        $phase = (array) ($status['processing_phase'] ?? []);
        $video = (string) ($status['video_status'] ?? '');
        if ($video === 'error' || ($phase['status'] ?? null) === 'error' || (($status['uploading_phase'] ?? [])['status'] ?? null) === 'error') {
            $errors = array_merge((array) ($phase['errors'] ?? []), (array) (($status['uploading_phase'] ?? [])['errors'] ?? []));
            $note = null;
            foreach ($errors as $e) {
                if (is_array($e) && !empty($e['message'])) {
                    $note = (string) $e['message'];
                    break;
                }
            }

            return ['state' => 'error', 'note' => $note];
        }

        return ['state' => $video === 'ready' ? 'ready' : 'processing', 'note' => null];
    }

    /** `.155`: la miniatura que eligió Meta (o la primera). */
    public static function thumbnail(array $rows): ?string
    {
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && !empty($r['uri'])));
        foreach ($rows as $r) {
            if (!empty($r['is_preferred'])) {
                return (string) $r['uri'];
            }
        }

        return isset($rows[0]) ? (string) $rows[0]['uri'] : null;
    }

    /** `.155`: el botón del anuncio de donde se copia (Comprar, Más información…); si no dice, "Comprar". */
    public static function ctaType(array $creative): string
    {
        $story = (array) ($creative['object_story_spec'] ?? []);
        foreach ([$story['video_data']['call_to_action']['type'] ?? null, $story['link_data']['call_to_action']['type'] ?? null,
            $creative['asset_feed_spec']['call_to_action_types'][0] ?? null] as $type) {
            if (is_string($type) && preg_match('/^[A-Z_]+$/', $type)) {
                return $type;
            }
        }

        return 'SHOP_NOW';
    }

    /** `.155`: la página de Facebook y el Instagram del anuncio de donde se copia. @return array{page_id: ?string, instagram: ?array{0: string, 1: string}} */
    public static function identity(array $creative): array
    {
        $story = (array) ($creative['object_story_spec'] ?? []);
        $ig = match (true) {
            !empty($story['instagram_user_id']) => ['instagram_user_id', (string) $story['instagram_user_id']],
            !empty($story['instagram_actor_id']) => ['instagram_actor_id', (string) $story['instagram_actor_id']],
            default => null,
        };

        return ['page_id' => !empty($story['page_id']) ? (string) $story['page_id'] : null, 'instagram' => $ig];
    }

    /**
     * `.155`: el creativo de una pieza: la página y el Instagram del anuncio de donde se copia, su video (o imagen), el
     * texto, el título, la landing con su botón y los parámetros de URL.
     *
     * @param array $source el creativo del anuncio de donde se copia (object_story_spec, asset_feed_spec)
     * @param array{type: string, video_id?: string, thumbnail?: ?string, hash?: string} $media
     * @param array{title: ?string, body: ?string, landing_url: ?string, url_tags: ?string} $texts
     */
    public static function creativeSpec(array $source, array $media, array $texts, string $name): array
    {
        $who = self::identity($source);
        $link = (string) $texts['landing_url'];
        $cta = ['type' => self::ctaType($source), 'value' => ['link' => $link]];
        $clean = fn (array $a) => array_filter($a, fn ($v) => $v !== null && $v !== '');
        $story = ['page_id' => (string) $who['page_id']];
        if ($who['instagram']) {
            $story[$who['instagram'][0]] = $who['instagram'][1];
        }
        if ($media['type'] === 'image') {
            $story['link_data'] = $clean(['image_hash' => $media['hash'] ?? null, 'link' => $link, 'message' => $texts['body'] ?? null,
                'name' => $texts['title'] ?? null, 'call_to_action' => $cta]);
        } else {
            $story['video_data'] = $clean(['video_id' => $media['video_id'] ?? null, 'image_url' => $media['thumbnail'] ?? null,
                'message' => $texts['body'] ?? null, 'title' => $texts['title'] ?? null, 'call_to_action' => $cta]);
        }

        return $clean(['name' => $name, 'object_story_spec' => $story, 'url_tags' => $texts['url_tags'] ?? null]);
    }

    /** `.155`: un video de Drive que Meta baja solo (este enlace no pide confirmar los archivos grandes). */
    public static function driveVideoUrl(string $fileId): string
    {
        return 'https://drive.usercontent.google.com/download?id=' . rawurlencode($fileId) . '&export=download&confirm=t';
    }

    /**
     * `.155`: lo que se ve en la misión mientras sube y al terminar.
     *
     * @param array{files: int, files_done: int, ads: int, ads_done: int} $count
     */
    public static function progress(string $status, ?string $step, array $count, bool $paused, string $adset, ?string $error): string
    {
        return match (true) {
            $status === 'failed' => 'No se pudo terminar: ' . rtrim((string) $error, '.') . '. Lo que alcanzó a crearse quedó apagado; «Reintentar» sigue donde se quedó, sin repetir nada.',
            $status === 'done' && $paused => "Listo, EN PAUSA: revisa el ad set «{$adset}» en el Administrador de anuncios y préndelo. Después marca la misión como hecha.",
            $status === 'done' => "Listo: «{$adset}» quedó prendido con " . ($count['ads'] === 1 ? 'su anuncio.' : "sus {$count['ads']} anuncios."),
            $status === 'queued' => 'En cola: empieza en menos de un minuto.',
            $status === 'waiting' || $step === 'processing' => 'Meta está procesando los videos (puede tardar unos minutos).',
            $step === 'adset' => "Creando el ad set «{$adset}».",
            $step === 'ads' => "Creando los anuncios: {$count['ads_done']} de {$count['ads']}.",
            default => "Subiendo los archivos a Meta: {$count['files_done']} de {$count['files']}.",
        };
    }

    /** `.155`: un error de Meta en palabras. */
    public static function metaError(string $kind, string $message): string
    {
        return match ($kind) {
            'token' => 'el token de esta conexión ya no sirve (venció o lo quitaron): pega uno nuevo en Integraciones › Meta Ads › Conexiones',
            'permission' => 'Meta dice que el token no tiene permiso para eso (' . mb_substr($message, 0, 160) . '). Revisa la guía del permiso',
            'rate_limit' => 'Meta pidió esperar (límite de llamadas): reintenta en un rato',
            'timeout' => 'Meta no respondió a tiempo: reintenta',
            default => 'Meta respondió: ' . mb_substr($message, 0, 220),
        };
    }

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
