<?php

namespace App\Http\Controllers;

use App\Models\CreativeReference;
use App\Services\Files\FileStore;
use App\Services\Files\Uploads;
use App\Services\Meta\Queue\EditorWork;
use App\Services\Meta\Queue\ReferenceLibrary;
use Illuminate\Http\Request;

/**
 * `.92`: la biblioteca de referencias. La usan los editores (/editor/references: ver, subir; el líder también quita) y el
 * dueño (/meta/references: ver y quitar). Los resultados (ganó, perdió) solo los reciben el líder y el dueño.
 */
class CreativeReferenceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['product_id' => 'nullable|integer', 'type' => 'nullable|string|max:20']);
        $who = EditorWork::who($request->user());

        return response()->json([
            'references' => ReferenceLibrary::list($who, isset($data['product_id']) ? (int) $data['product_id'] : null, $data['type'] ?? null),
            'top' => ReferenceLibrary::top($who),
            'results' => ReferenceLibrary::seesResults($who),
            'products' => ReferenceLibrary::productOptions(),
            'types' => ReferenceLibrary::TYPES,
            'uploads' => FileStore::on(),
        ]);
    }

    /** El enlace para subir el video de una referencia directo al almacén (se confirma con /editor/files/{file}/confirm). */
    public function upload(Request $request, Uploads $uploads)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'mime' => 'nullable|string|max:120', 'size' => 'required|integer|min:1']);

        return response()->json($uploads->start(['kind' => 'reference', 'name' => $data['name']], (string) ($data['mime'] ?? ''), (int) $data['size'], $request->user()));
    }

    public function store(Request $request, ReferenceLibrary $library)
    {
        $data = $request->validate([
            'file_id' => 'required|integer', 'link' => 'required|string|max:1000', 'type' => 'required|string|max:20',
            'why' => 'required|string|max:' . ReferenceLibrary::WHY_MAX, 'product_id' => 'nullable|integer', 'angle_code' => 'nullable|string|max:12',
        ], [
            'link.required' => 'Pega el link de donde salió.', 'type.required' => 'Elige el tipo de video.', 'why.required' => 'Escribe en una frase por qué la elegiste.',
        ]);
        $ref = $library->create($data, $request->user());
        $who = EditorWork::who($request->user());

        return response()->json(['reference' => ReferenceLibrary::present(collect([$ref->fresh(['file', 'product', 'uploader'])]), $who)[0]]);
    }

    /** En qué creativos se usó. */
    public function uses(Request $request, CreativeReference $reference)
    {
        $who = EditorWork::who($request->user());

        return response()->json(['code' => $reference->code(), 'uses' => ReferenceLibrary::uses($reference, $who), 'results' => ReferenceLibrary::seesResults($who)]);
    }

    /** Quitarla de la biblioteca (el líder o el dueño). */
    public function archive(Request $request, CreativeReference $reference, ReferenceLibrary $library)
    {
        $library->archive($reference, $request->user());

        return response()->json(['archived' => $reference->code()]);
    }
}
