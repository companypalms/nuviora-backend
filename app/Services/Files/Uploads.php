<?php

namespace App\Services\Files;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * `.90`: subir un archivo al almacén en dos pasos: "start" lo anota (pendiente) y da el enlace firmado para que el
 * navegador lo suba directo; "confirm" mira que llegó y lo deja listo. Quién puede subir qué lo decide quien llama
 * (EditorWork para las piezas, la ficha del producto para las fotos y la estrategia).
 */
class Uploads
{
    /**
     * @param array{kind: string, name: string, display?: ?string, product_id?: ?int, batch?: ?string, piece_id?: ?int, version?: ?int} $what
     *        name: el nombre del archivo que eligió; display: con qué nombre queda (el de su anuncio: "C0457 POSP")
     * @return array{file: array{id: int, name: string}, upload: array{url: string, method: string, headers: array}}
     */
    public function start(array $what, string $mime, int $size, User $actor): array
    {
        $store = self::store();
        $mime = StoredFileRules::mime($mime, $what['name']);
        if ($problem = StoredFileRules::problem($what['kind'], $mime, $size)) {
            throw ValidationException::withMessages(['file' => $problem]);
        }
        $ext = StoredFileRules::extension($mime, $what['name']);
        $name = mb_substr(($what['display'] ?? null) ?: (pathinfo($what['name'], PATHINFO_FILENAME) ?: 'archivo'), 0, 240) . ".{$ext}";
        $file = StoredFile::create([
            'driver' => FileStore::driver(), 'name' => $name, 'mime' => $mime, 'size' => $size, 'status' => 'pending',
            'kind' => $what['kind'], 'product_id' => $what['product_id'] ?? null, 'creative_batch_piece_id' => $what['piece_id'] ?? null,
            'version' => $what['version'] ?? null, 'uploaded_by' => $actor->id,
            'path' => StoredFileRules::path($what['kind'], $what['product_id'] ?? null, $what['batch'] ?? null, $name, $what['version'] ?? null, $ext),
        ]);

        return ['file' => ['id' => $file->id, 'name' => $file->name], 'upload' => $store->uploadUrl($file)];
    }

    /** Mira que el archivo llegó al almacén (y que es lo que se dijo) y lo deja listo. */
    public function confirm(StoredFile $file, User $actor): StoredFile
    {
        if ((int) $file->uploaded_by !== (int) $actor->id) {
            throw new HttpResponseException(response()->json(['status' => false, 'message' => 'Este archivo lo subió otra persona.'], 403));
        }
        if ($file->status === 'ready') {
            return $file;
        }
        $store = FileStore::for($file->driver) ?? self::store();
        try {
            $size = $store->size($file);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'No se pudo revisar el archivo en el almacén. Intenta de nuevo en un momento.']);
        }
        if ($size === null) {
            throw ValidationException::withMessages(['file' => 'El archivo no llegó: vuelve a subirlo.']);
        }
        if ($problem = StoredFileRules::problem($file->kind, $file->mime, $size)) {
            self::forget($file);
            throw ValidationException::withMessages(['file' => $problem]);
        }
        $file->forceFill(['size' => $size, 'status' => 'ready'])->save();

        return $file;
    }

    /** Guarda un archivo de este servidor (lo que se adjuntó al asistente), ya listo. */
    public function fromLocal(string $kind, ?int $productId, string $localPath, string $name, string $mime, ?int $userId): StoredFile
    {
        $store = self::store();
        $size = (int) filesize($localPath);
        if ($problem = StoredFileRules::problem($kind, $mime, $size)) {
            throw ValidationException::withMessages(['file' => "{$name}: {$problem}"]);
        }
        $ext = StoredFileRules::extension($mime, $name);
        $file = new StoredFile([
            'driver' => FileStore::driver(), 'name' => mb_substr($name, 0, 255), 'mime' => $mime, 'size' => $size, 'status' => 'ready',
            'kind' => $kind, 'product_id' => $productId, 'uploaded_by' => $userId,
            'path' => StoredFileRules::path($kind, $productId, null, $name, null, $ext),
        ]);
        $store->putFile($file, $localPath);
        $file->save();

        return $file;
    }

    /** Lo borra del almacén y de la lista (una foto que el dueño quitó, o un archivo que no sirvió). */
    public static function forget(StoredFile $file): void
    {
        try {
            FileStore::for($file->driver)?->delete($file);
        } catch (Throwable $e) {
            report($e); // queda en el almacén, pero ya no se muestra
        }
        $file->delete();
    }

    /**
     * "Probar el almacén" (el dueño, después de poner las llaves): guarda, lee y borra un archivo chiquito y dice en
     * palabras si funciona.
     *
     * @return array{ok: bool, driver: string, message: string}
     */
    public function check(): array
    {
        $driver = FileStore::driver();
        $store = FileStore::current();
        if ($store === null) {
            return ['ok' => false, 'driver' => $driver, 'message' => 'El almacén está apagado: faltan las llaves de Cloudflare R2 en el servidor. Mientras tanto, las entregas siguen con Drive.'];
        }
        $probe = new StoredFile(['driver' => $driver, 'path' => 'prueba/' . now()->format('YmdHis') . '-' . random_int(1000, 9999) . '.txt',
            'name' => 'prueba.txt', 'mime' => 'text/plain']);
        $tmp = tempnam(sys_get_temp_dir(), 'nuv');
        try {
            file_put_contents($tmp, 'Nuviora ' . now()->toIso8601String());
            $store->putFile($probe, $tmp);
            $ok = $store->read($probe) === file_get_contents($tmp);
            $store->delete($probe);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'driver' => $driver, 'message' => self::why($e->getMessage())];
        } finally {
            @unlink($tmp);
        }

        return $ok
            ? ['ok' => true, 'driver' => $driver, 'message' => 'Funciona: se guardó, se leyó y se borró un archivo de prueba en ' . FileStore::DRIVERS[$driver] . '.']
            : ['ok' => false, 'driver' => $driver, 'message' => 'Se guardó el archivo de prueba pero no se pudo leer igual. Revisa el bucket.'];
    }

    /** Lo que dijo el almacén, en palabras. */
    public static function why(string $error): string
    {
        return match (true) {
            str_contains($error, '403') || str_contains($error, '401') => 'Cloudflare dice que no: revisa las llaves (R2_ACCESS_KEY_ID y R2_SECRET_ACCESS_KEY) y que el token tenga permiso de leer y escribir en ese bucket.',
            str_contains($error, '404') => 'Cloudflare no encuentra el bucket: revisa R2_BUCKET y R2_ACCOUNT_ID.',
            default => 'No se pudo hablar con el almacén: ' . $error,
        };
    }

    private static function store(): FileStore
    {
        return FileStore::current() ?? throw ValidationException::withMessages(['file' => 'Todavía no se pueden subir archivos al sistema (falta configurar el almacén). Mientras tanto, pega el enlace de Drive.']);
    }
}
