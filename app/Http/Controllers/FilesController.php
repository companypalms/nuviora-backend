<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use App\Services\Files\FileStore;
use App\Services\Files\LocalStore;
use App\Services\Files\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `.90`: los archivos de los creativos. "local" es el almacén de la PC de pruebas (en el servidor, el navegador habla
 * directo con R2); "check" es "Probar el almacén" del dueño.
 */
class FilesController extends Controller
{
    /**
     * PUT/GET /files/local/{file}: subir o ver un archivo del almacén local. Sin sesión (se abre como <video> o <img>),
     * solo con el enlace firmado que da el sistema.
     */
    public function local(Request $request, StoredFile $file)
    {
        abort_unless($file->driver === 'local', 404);
        if (!$request->hasValidSignatureWhileIgnoring(['download'])) {
            abort(403, 'Enlace no válido o vencido. Vuelve a abrir la pantalla.');
        }
        $disk = Storage::disk(LocalStore::DISK);
        $where = LocalStore::where($file);
        if ($request->isMethod('put')) {
            abort_unless($file->status === 'pending', 409, 'Este archivo ya se subió.');
            abort_unless($disk->writeStream($where, $request->getContent(true)), 500, 'No se pudo guardar el archivo.');

            return response()->noContent();
        }
        abort_unless($disk->exists($where), 404, 'El archivo ya no está.');
        $download = $request->boolean('download');
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii((string) $file->name)) ?: 'archivo';

        return response()->file($disk->path($where), [
            'Content-Type' => $download ? 'application/octet-stream' : $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=21600',
        ])->setContentDisposition($download ? 'attachment' : 'inline', (string) $file->name, $fallback);
    }

    /** GET /meta/files/status y POST /meta/files/check (el dueño): dónde se guardan y si funciona. */
    public function status()
    {
        return response()->json(['driver' => FileStore::driver(), 'label' => FileStore::DRIVERS[FileStore::driver()], 'on' => FileStore::on()]);
    }

    public function check(Uploads $uploads)
    {
        return response()->json($uploads->check());
    }
}
