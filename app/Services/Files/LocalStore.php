<?php

namespace App\Services\Files;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * `.90`: el almacén en la carpeta privada de esta PC (FILES_DRIVER=local), para probar sin R2. Funciona igual que R2:
 * el navegador sube (PUT) y ve (GET) con un enlace firmado, aquí la ruta files.local (FilesController::local).
 */
final class LocalStore extends FileStore
{
    public const DISK = 'local';

    public const ROOT = 'files';

    public function uploadUrl(StoredFile $file): array
    {
        return ['url' => URL::temporarySignedRoute('files.local', now()->addHours(self::UPLOAD_HOURS), ['file' => $file->id]),
            'method' => 'PUT', 'headers' => ['Content-Type' => $file->mime]];
    }

    public function viewUrl(StoredFile $file, bool $download): string
    {
        $url = URL::temporarySignedRoute('files.local', now()->startOfHour()->addHours(self::VIEW_HOURS + 1), ['file' => $file->id]);

        return $download ? $url . '&download=1' : $url;
    }

    public function size(StoredFile $file): ?int
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists(self::where($file)) ? (int) $disk->size(self::where($file)) : null;
    }

    public function putFile(StoredFile $file, string $localPath): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false || !Storage::disk(self::DISK)->writeStream(self::where($file), $handle)) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    public function read(StoredFile $file): ?string
    {
        return Storage::disk(self::DISK)->get(self::where($file));
    }

    public function delete(StoredFile $file): void
    {
        Storage::disk(self::DISK)->delete(self::where($file));
    }

    /** Dónde queda dentro de la carpeta privada. */
    public static function where(StoredFile $file): string
    {
        return self::ROOT . '/' . $file->path;
    }
}
