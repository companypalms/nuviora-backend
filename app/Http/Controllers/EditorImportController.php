<?php

namespace App\Http\Controllers;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Services\Files\FileStore;
use App\Services\Meta\Queue\BatchQueue;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Meta\Queue\EditorWork;
use App\Services\Meta\Queue\QueueRules;
use App\Services\Meta\Queue\ReferenceLibrary;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `.95` (dueño, 2026-11-03: "hay batchs que ya están hechos y deberían estar en la banca… todos están en carpetas en drive
 * por cada batch… no nos acordamos de ninguna de las hipótesis"): el editor líder carga cada batch ya hecho. Elige el
 * producto, el ángulo de la ficha (o "no sé"), quién lo hizo, y sube sus videos: el batch recibe sus números (B y C),
 * cada video su nombre en Meta, y entra a la banca sin hipótesis ("de antes del sistema").
 */
class EditorImportController extends Controller
{
    /** Los productos con sus ángulos, los editores y si el almacén está encendido. */
    public function options(Request $request)
    {
        $this->leader($request);

        $products = ReferenceLibrary::productOptions();

        return response()->json(['products' => $products, 'editors' => EditorWork::editors(), 'me' => $request->user()->id,
            'uploads' => FileStore::on(),
            // .99: los creativos de cada producto que ya estuvieron en Meta (por su nombre allí), para cargar variaciones de uno
            'creatives' => \App\Models\MetaCreative::whereIn('product_id', array_column($products, 'id') ?: [0])->orderByDesc('id')
                ->get(['id', 'product_id', 'label', 'tracking_id', 'angle_code'])
                ->map(fn ($c) => ['id' => $c->id, 'product_id' => (int) $c->product_id, 'name' => $c->displayName(), 'angle_code' => $c->angle_code])->values()->all()]);
    }

    /** Empezar la carga: el batch con sus números y una pieza por video. */
    public function store(Request $request, BatchQueue $queue)
    {
        $this->leader($request);
        if (!FileStore::on()) {
            throw ValidationException::withMessages(['uploads' => 'Para cargar batches hace falta el almacén de archivos conectado (Integraciones › Meta Ads › Conexiones).']);
        }
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'angle_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'editor_id' => 'required|integer',
            'concept' => 'nullable|string|max:120',
            'pieces' => 'required|integer|min:1|max:10',
            // .99: si son variaciones de un creativo que ya estuvo en Meta, cuál y qué cambia
            'iterates_id' => 'nullable|integer',
            'changes' => 'nullable|string|max:10',
        ], ['pieces.max' => 'Un batch lleva hasta 10 videos.', 'pieces.min' => 'Sube al menos un video.']);
        $angle = $data['angle_code'] !== null && $data['angle_code'] !== '' ? strtoupper($data['angle_code']) : null;
        if ($angle !== null && !AdAngle::where('product_id', $data['product_id'])->where('code', $angle)->whereNull('alias_of')->exists()) {
            throw ValidationException::withMessages(['angle_code' => 'Ese ángulo no está en la ficha de este producto: elige otro o "No sé".']);
        }
        if (!in_array((int) $data['editor_id'], array_column(EditorWork::editors(), 'id'), true)) {
            throw ValidationException::withMessages(['editor_id' => 'Elige un editor activo.']);
        }
        $batch = $queue->import(['product_id' => (int) $data['product_id'], 'angle_code' => $angle, 'editor_id' => (int) $data['editor_id'],
            'concept' => trim((string) ($data['concept'] ?? '')) ?: null, 'pieces' => (int) $data['pieces'],
            'iterates_id' => isset($data['iterates_id']) ? (int) $data['iterates_id'] : null, 'changes' => $data['changes'] ?? null], $request->user()->id);

        return response()->json(['batch' => $this->summary($batch)], 201);
    }

    /** El enlace para subir el video de una pieza directo al almacén (se confirma con /editor/files/{file}/confirm). */
    public function upload(Request $request, CreativeBatch $batch, CreativeBatchPiece $piece, EditorWork $work)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'mime' => 'nullable|string|max:120', 'size' => 'required|integer|min:1']);

        return response()->json($work->uploadImport($batch, $piece, $request->user(), $data['name'], (string) ($data['mime'] ?? ''), (int) $data['size']));
    }

    /** Terminar: cada pieza con su video y el batch a la banca. */
    public function finish(Request $request, CreativeBatch $batch, EditorWork $work)
    {
        $data = $request->validate([
            'pieces' => 'required|array|min:1|max:10',
            'pieces.*.id' => 'required|integer',
            'pieces.*.file_id' => 'nullable|integer',
            'pieces.*.hook' => 'nullable|string|max:300',
        ]);

        return response()->json(['batch' => $this->summary($work->finishImport($batch, $request->user(), $data['pieces']))]);
    }

    /** Se cerró a mitad: el batch se descarta. */
    public function cancel(Request $request, CreativeBatch $batch, EditorWork $work)
    {
        $work->cancelImport($batch, $request->user());

        return response()->noContent();
    }

    private function leader(Request $request): void
    {
        abort_unless($request->user()->role?->description === EditorRules::LEADER, 403, 'Cargar batches ya hechos lo hace el editor líder.');
    }

    /** Lo que necesita la ventana: el código, los nombres en Meta y cada pieza. */
    private function summary(CreativeBatch $batch): array
    {
        $batch->loadMissing('pieces');

        return [
            'id' => $batch->id, 'code' => $batch->code(), 'status' => $batch->status,
            'meta_names' => QueueRules::metaNames($batch->number, $batch->angle_code, $batch->pieces->pluck('number')->all()),
            'pieces' => $batch->pieces->sortBy('position')->map(fn (CreativeBatchPiece $p) => ['id' => $p->id, 'code' => $p->code()])->values()->all(),
        ];
    }
}
