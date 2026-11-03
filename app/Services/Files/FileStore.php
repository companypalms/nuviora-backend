<?php

namespace App\Services\Files;

use App\Models\StoredFile;
use Illuminate\Support\Str;

/**
 * `.90`: el almacén de los archivos de los creativos. En el servidor, Cloudflare R2 (R2Store) cuando tiene sus 4 llaves;
 * en la PC de pruebas, la carpeta privada (LocalStore, con FILES_DRIVER=local). Sin almacén ("off") las entregas siguen
 * con el enlace de Google Drive: así nunca se llena el disco del servidor sin querer.
 */
abstract class FileStore
{
    /** Lo que dura un enlace para subir. */
    public const UPLOAD_HOURS = 2;

    /** Lo que dura (por lo menos) un enlace para ver o bajar. */
    public const VIEW_HOURS = 6;

    public const DRIVERS = ['r2' => 'Cloudflare R2', 'local' => 'la carpeta de esta PC', 'off' => 'apagado (las entregas siguen con Drive)'];

    /** r2, local u off. */
    public static function driver(): string
    {
        $set = strtolower(trim((string) config('files.driver')));
        if ($set === 'local' || $set === 'off') {
            return $set;
        }

        return R2Store::configured() ? 'r2' : 'off';
    }

    public static function on(): bool
    {
        return self::driver() !== 'off';
    }

    /** El almacén donde se guarda lo nuevo, o null si está apagado. */
    public static function current(): ?self
    {
        return self::for(self::driver());
    }

    /** El almacén de un archivo (uno viejo puede estar en otro). */
    public static function for(string $driver): ?self
    {
        return match ($driver) {
            'r2' => R2Store::configured() ? new R2Store() : null,
            'local' => new LocalStore(),
            default => null,
        };
    }

    /** @return array{url: string, method: string, headers: array<string, string>} para que el navegador suba el archivo */
    abstract public function uploadUrl(StoredFile $file): array;

    /** El enlace firmado para verlo (o bajarlo con su nombre). */
    abstract public function viewUrl(StoredFile $file, bool $download): string;

    /** Su tamaño en el almacén, o null si no está. */
    abstract public function size(StoredFile $file): ?int;

    /** Lo guarda desde un archivo de este servidor (lo que se adjuntó al asistente). */
    abstract public function putFile(StoredFile $file, string $localPath): void;

    abstract public function read(StoredFile $file): ?string;

    abstract public function delete(StoredFile $file): void;

    /** "attachment; filename=…" con su nombre (y uno sin acentos para los navegadores viejos). */
    protected static function disposition(StoredFile $file, bool $download): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii((string) $file->name)) ?: 'archivo';

        return ($download ? 'attachment' : 'inline') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode((string) $file->name);
    }
}
