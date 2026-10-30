<?php

namespace App\Http\Controllers;

use App\Models\CreativeBatch;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Http\Request;

/**
 * Las pantallas de los editores (Etapa 5, paso 5a): "Mis encargos" para los dos y, para el editor líder, "Encargos del
 * equipo" (asignar y revisar). Solo entran los roles "Editor líder" y "Editor de video", y RestrictLimitedRoles no les
 * deja llegar a ninguna otra parte de la API. Los permisos de cada encargo son los de EditorRules. Sin dinero.
 */
class EditorQueueController extends Controller
{
    /** Los terminados (en banca, en test, testeados o descartados) que se siguen mostrando, en días. */
    private const RECENT_DAYS = 14;

    public function index(Request $request)
    {
        $user = $request->user();
        $who = EditorWork::who($user);
        $leader = $who['role'] === EditorRules::LEADER;
        $batches = $this->visible($who)->with(EditorWork::WITH)->get();

        return response()->json([
            'me' => ['id' => $user->id, 'name' => trim($user->names . ' ' . ($user->surnames ?? '')), 'leader' => $leader],
            'batches' => EditorWork::present($batches, $who),
            'editors' => $leader ? EditorWork::editors() : [],
            'pending' => EditorRules::pending($who, EditorWork::states($batches)),
        ]);
    }

    /** El número del menú: lo que tiene pendiente (se pide cada pocos minutos). */
    public function summary(Request $request)
    {
        $who = EditorWork::who($request->user());
        $batches = $this->visible($who)->whereIn('status', ['approved', 'production', 'review'])->with('deliveries:id,creative_batch_id,editor_id,review,version')->get();

        return response()->json(['pending' => EditorRules::pending($who, EditorWork::states($batches))]);
    }

    public function start(Request $request, CreativeBatch $batch, EditorWork $work)
    {
        $work->start($batch, $request->user());

        return $this->one($request, $batch);
    }

    public function deliver(Request $request, CreativeBatch $batch, EditorWork $work)
    {
        $data = $request->validate([
            'concept' => 'required|string|max:255',
            'angle' => 'required|string|max:120',
            'notes' => 'nullable|string|max:2000',
            'pieces' => 'required|array|max:10',
            'pieces.*.id' => 'required|integer',
            'pieces.*.link' => 'nullable|string|max:1000',
            'pieces.*.hook' => 'nullable|string|max:300',
        ], [
            'concept.required' => 'Escribe el concepto del batch.',
            'angle.required' => 'Escribe el ángulo que usaste.',
        ]);
        $work->deliver($batch, $request->user(), $data);

        return $this->one($request, $batch);
    }

    public function assign(Request $request, CreativeBatch $batch, EditorWork $work)
    {
        $data = $request->validate(['editor_id' => 'present|nullable|integer']);
        $work->assign($batch, $data['editor_id'] !== null ? (int) $data['editor_id'] : null, $request->user());

        return $this->one($request, $batch);
    }

    public function review(Request $request, CreativeBatch $batch, EditorWork $work)
    {
        $data = $request->validate(['approve' => 'required|boolean', 'comment' => 'nullable|string|max:2000']);
        $work->review($batch, $request->user(), (bool) $data['approve'], $data['comment'] ?? null);

        return $this->one($request, $batch);
    }

    /** Lo que ve: el editor, lo suyo; el líder, todo lo aprobado. Lo terminado, solo lo reciente. */
    private function visible(array $who)
    {
        return CreativeBatch::query()
            ->where('status', '!=', 'proposed')
            ->where(fn ($q) => $q->whereIn('status', ['approved', 'production', 'review', 'bank'])->orWhere('status_at', '>=', now()->subDays(self::RECENT_DAYS)))
            ->when($who['role'] !== EditorRules::LEADER, fn ($q) => $q->where('editor_id', $who['id']));
    }

    private function one(Request $request, CreativeBatch $batch)
    {
        $who = EditorWork::who($request->user());
        $fresh = CreativeBatch::with(EditorWork::WITH)->findOrFail($batch->id);
        if (!EditorRules::canSee($who, ['status' => $fresh->status, 'editor_id' => $fresh->editor_id])) {
            return response()->json(['batch' => null]); // se lo pasaron a otro editor
        }

        return response()->json(['batch' => EditorWork::present(collect([$fresh]), $who)[0]]);
    }
}
