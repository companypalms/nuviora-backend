<?php

namespace App\Services\Meta\Upload;

use App\Models\MetaConnection;
use App\Services\Meta\MetaApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * `.155`: lo único que el sistema puede escribir en Meta, para "Aprobar y subir" (dueño, 2026-11-17). Va aparte de
 * MetaClient, que sigue siendo de solo lectura.
 * - Solo crea: videos, imágenes, creativos y anuncios, y copia un ad set dentro de su misma campaña.
 * - Del ad set nuevo solo cambia el nombre, el gasto mínimo y si está prendido.
 * - No tiene ningún método para borrar, ni para tocar el presupuesto de una campaña.
 * - El token va en la cabecera, como en MetaClient, y nunca queda en un mensaje de error.
 */
final class MetaWriter
{
    private const BASE = 'https://graph.facebook.com';
    private const VIDEO_BASE = 'https://graph-video.facebook.com';

    /** Lo único que se le cambia a un ad set. */
    public const ADSET_FIELDS = ['name', 'status', 'daily_min_spend_target'];

    private string $version;

    public function __construct(private readonly string $token, private readonly ?string $appSecret = null, ?string $version = null)
    {
        $this->version = $version ?: (string) config('services.meta.graph_version', 'v26.0');
    }

    public static function for(MetaConnection $connection): self
    {
        return new self((string) $connection->access_token, $connection->app_secret ?: null);
    }

    /** Un video que Meta baja de un enlace (el del almacén o el de Drive). @return string el id del video */
    public function videoFromUrl(string $act, string $url, string $name): string
    {
        return (string) $this->post(self::BASE, "{$act}/advideos", ['file_url' => $url, 'name' => $name])['id'];
    }

    /** Un video que se le manda a Meta (los del almacén de esta PC, que Meta no puede bajar). @return string el id */
    public function videoFromFile(string $act, string $bytes, string $fileName, string $name): string
    {
        return (string) $this->post(self::VIDEO_BASE, "{$act}/advideos", ['name' => $name], ['source', $bytes, $fileName])['id'];
    }

    /** Una imagen. @return string su hash */
    public function image(string $act, string $bytes, string $name): string
    {
        $r = $this->post(self::BASE, "{$act}/adimages", ['bytes' => base64_encode($bytes), 'name' => $name]);
        $first = is_array($r['images'] ?? null) ? reset($r['images']) : null;
        if (!is_array($first) || empty($first['hash'])) {
            throw new MetaApiException(MetaApiException::META, 'Meta no devolvió la imagen subida.');
        }

        return (string) $first['hash'];
    }

    /** Copia un ad set (sin sus anuncios) dentro de una campaña, apagado. @return string el id del ad set nuevo */
    public function copyAdset(string $adsetId, string $campaignId): string
    {
        $r = $this->post(self::BASE, "{$adsetId}/copies", ['campaign_id' => $campaignId, 'deep_copy' => false, 'status_option' => 'PAUSED']);
        $id = $r['copied_adset_id'] ?? null;
        if (!$id) {
            throw new MetaApiException(MetaApiException::META, 'Meta no devolvió el ad set copiado.');
        }

        return (string) $id;
    }

    /** El nombre, el gasto mínimo o el estado de un ad set (nada más). */
    public function updateAdset(string $adsetId, array $fields): void
    {
        $extra = array_diff(array_keys($fields), self::ADSET_FIELDS);
        if ($extra !== [] || !preg_match('/^\d+$/', $adsetId)) {
            throw new \InvalidArgumentException('Al ad set solo se le cambia el nombre, el gasto mínimo o el estado.');
        }
        $this->post(self::BASE, $adsetId, $fields);
    }

    /** @return string el id del creativo */
    public function creative(string $act, array $spec): string
    {
        return (string) $this->post(self::BASE, "{$act}/adcreatives", $spec)['id'];
    }

    /** @return string el id del anuncio */
    public function ad(string $act, array $fields): string
    {
        return (string) $this->post(self::BASE, "{$act}/ads", $fields)['id'];
    }

    /** @param ?array{0: string, 1: string, 2: string} $file el campo, el contenido y el nombre del archivo */
    private function post(string $base, string $path, array $params, ?array $file = null): array
    {
        if (!preg_match('#^(act_\d+/(advideos|adimages|adcreatives|ads)|\d+/copies|\d+)$#', $path)) {
            throw new \InvalidArgumentException("No se escribe en {$path}.");
        }
        $proof = $this->appSecret ? hash_hmac('sha256', $this->token, $this->appSecret) : null;
        if ($proof) {
            $params['appsecret_proof'] = $proof;
        }
        $params = array_map(fn ($v) => is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : $v), $params);
        $url = $base . '/' . $this->version . '/' . $path;

        try {
            $request = Http::withToken($this->token)->acceptJson()->connectTimeout(10)->timeout($file ? 75 : 50);
            $response = $file ? $request->attach(...$file)->post($url, $params) : $request->asForm()->post($url, $params);
        } catch (ConnectionException $e) {
            throw new MetaApiException(MetaApiException::TIMEOUT, MetaApiException::clean($e->getMessage(), [$this->token, $proof]));
        }
        if ($response->failed()) {
            $error = $response->json('error');
            if (!is_array($error)) {
                $error = ['message' => 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300)];
            }
            throw MetaApiException::fromError($response->status(), $error, [$this->token, $proof]);
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
