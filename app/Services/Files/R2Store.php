<?php

namespace App\Services\Files;

use App\Models\StoredFile;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * `.90`: Cloudflare R2 (habla como S3). El navegador sube y ve los archivos directo con URLs firmadas (SigV4): no pasan
 * por el servidor. Las llaves (R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET) las pone el dueño en el
 * .env del servidor; el bucket necesita su regla CORS para que el navegador pueda subir (está en la guía de publicar).
 */
final class R2Store extends FileStore
{
    public static function configured(): bool
    {
        $c = (array) config('files.r2');

        return filled($c['account'] ?? null) && filled($c['key'] ?? null) && filled($c['secret'] ?? null) && filled($c['bucket'] ?? null);
    }

    public function uploadUrl(StoredFile $file): array
    {
        return ['url' => $this->url('PUT', $file->path, self::UPLOAD_HOURS * 3600, now()), 'method' => 'PUT', 'headers' => ['Content-Type' => $file->mime]];
    }

    public function viewUrl(StoredFile $file, bool $download): string
    {
        // Firmado al empezar la hora: el mismo enlace por un rato, así el navegador no vuelve a bajar el video
        return $this->url('GET', $file->path, (self::VIEW_HOURS + 1) * 3600, now()->startOfHour(), [
            'response-content-disposition' => self::disposition($file, $download),
            'response-content-type' => $download ? 'application/octet-stream' : $file->mime,
        ]);
    }

    public function size(StoredFile $file): ?int
    {
        $response = Http::timeout(20)->head($this->url('HEAD', $file->path, 300, now()));
        if ($response->status() === 404) {
            return null;
        }
        if (!$response->successful()) {
            throw new RuntimeException("El almacén respondió {$response->status()}.");
        }

        return (int) $response->header('Content-Length');
    }

    public function putFile(StoredFile $file, string $localPath): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        $response = Http::timeout(300)->withBody(Utils::streamFor($handle), $file->mime)->put($this->url('PUT', $file->path, 3600, now()));
        if (!$response->successful()) {
            throw new RuntimeException("El almacén respondió {$response->status()} al guardar.");
        }
    }

    public function read(StoredFile $file): ?string
    {
        $response = Http::timeout(30)->get($this->url('GET', $file->path, 300, now()));

        return $response->successful() ? $response->body() : null;
    }

    public function delete(StoredFile $file): void
    {
        $response = Http::timeout(20)->delete($this->url('DELETE', $file->path, 300, now()));
        if (!$response->successful() && $response->status() !== 404) {
            throw new RuntimeException("El almacén respondió {$response->status()} al borrar.");
        }
    }

    private function url(string $method, string $path, int $seconds, \DateTimeInterface $now, array $query = []): string
    {
        $c = (array) config('files.r2');

        return SigV4::presign($method, "{$c['account']}.r2.cloudflarestorage.com", "/{$c['bucket']}/{$path}", 'auto',
            (string) $c['key'], (string) $c['secret'], $seconds, $now, $query);
    }
}
