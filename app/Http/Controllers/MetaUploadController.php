<?php

namespace App\Http\Controllers;

use App\Models\MetaAnalysis;
use App\Models\MetaUpload;
use App\Services\Meta\Upload\MetaUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `.155` "Aprobar y subir" (dueño, 2026-11-17: "que me aparezcan en mis misiones diarias, yo le doy un botón de aprobar y
 * él los suba automáticamente en meta ads"). Solo el Administrador.
 * - Lo que se va a crear de una misión (plan), y aprobarlo (store).
 * - Cómo van las subidas de un análisis (index) y "Reintentar" (retry).
 * - El interruptor "Subir a Meta" (settings), en Integraciones › Meta Ads › Conexiones.
 */
class MetaUploadController extends Controller
{
    public function __construct(private MetaUploads $uploads)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate(['analysis_id' => 'required|integer']);
        $analysis = MetaAnalysis::findOrFail($data['analysis_id']);

        return response()->json(['on' => MetaUploads::on(), 'uploads' => $this->uploads->view($analysis)]);
    }

    public function plan(Request $request)
    {
        [$analysis, $key] = $this->mission($request);

        return response()->json($this->uploads->plans($analysis, $key));
    }

    public function store(Request $request)
    {
        [$analysis, $key] = $this->mission($request);
        $this->uploads->approve($analysis, $key, Auth::user());

        return response()->json(['on' => MetaUploads::on(), 'uploads' => $this->uploads->view($analysis)], 201);
    }

    public function retry(MetaUpload $upload)
    {
        $this->uploads->retry($upload);

        return response()->json(['upload' => MetaUploads::present($upload->refresh())]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        MetaUploads::setOn((bool) $data['enabled']);

        return response()->json(['on' => MetaUploads::on()]);
    }

    /** @return array{0: MetaAnalysis, 1: string} */
    private function mission(Request $request): array
    {
        $data = $request->validate(['analysis_id' => 'required|integer', 'key' => 'required|string|max:120']);

        return [MetaAnalysis::findOrFail($data['analysis_id']), $data['key']];
    }
}
