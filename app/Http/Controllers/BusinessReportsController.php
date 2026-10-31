<?php

namespace App\Http\Controllers;

use App\Models\BusinessReport;
use App\Services\Metrics\Reports\BusinessReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * La página "Reportes del negocio" (`.48b`). Solo el Administrador, como el panel del dueño.
 * - Ver un reporte (el último de su tipo, si no se elige otro), con el historial de ese tipo. Al abrirlo queda la hora en
 *   que lo vio (48b-2: tilda la revisión del calendario).
 * - "Volver a generar": otra versión del mismo período, como mucho 10 por día.
 */
class BusinessReportsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(BusinessReport::TYPES)],
            'id' => 'nullable|integer',
        ]);
        $type = $data['type'] ?? 'daily';
        $q = BusinessReport::query()->with('requester:id,names')->where('type', $type);
        $report = isset($data['id']) ? (clone $q)->whereKey($data['id'])->first() : (clone $q)->orderByDesc('period_to')->orderByDesc('version')->first();
        if ($report && $report->viewed_at === null) {
            $report->forceFill(['viewed_at' => now(), 'viewed_by' => Auth::id()])->save();
        }

        return response()->json([
            'type' => $type,
            'report' => $report ? $this->present($report) : null,
            'history' => BusinessReport::where('type', $type)->orderByDesc('period_to')->orderByDesc('version')->limit(60)
                ->get(['id', 'period_from', 'period_to', 'version', 'status', 'requested_by', 'created_at'])
                ->map(fn (BusinessReport $r) => ['id' => $r->id, 'from' => $r->period_from->toDateString(), 'to' => $r->period_to->toDateString(),
                    'version' => $r->version, 'auto' => $r->requested_by === null, 'created_at' => $r->created_at?->toIso8601String()]),
            'counts' => BusinessReport::selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type'),
            'runs_left' => BusinessReporter::runsLeft(),
        ]);
    }

    public function run(Request $request, BusinessReporter $reporter)
    {
        $data = $request->validate(['type' => ['required', Rule::in(BusinessReport::TYPES)]]);
        if (BusinessReporter::runsLeft() <= 0) {
            return response()->json(['message' => 'Ya se volvió a generar ' . BusinessReporter::MAX_RUNS_PER_DAY . ' veces hoy. Mañana a las 7:15 salen los nuevos.'], 429);
        }
        $report = $reporter->run($data['type'], Auth::id());
        $report->forceFill(['viewed_at' => now(), 'viewed_by' => Auth::id()])->save();

        return response()->json(['report' => $this->present($report->load('requester:id,names')), 'runs_left' => BusinessReporter::runsLeft()]);
    }

    private function present(BusinessReport $r): array
    {
        return [
            'id' => $r->id, 'type' => $r->type, 'from' => $r->period_from->toDateString(), 'to' => $r->period_to->toDateString(),
            'version' => $r->version, 'status' => $r->status, 'created_at' => $r->created_at?->toIso8601String(),
            'requested_by' => $r->requester?->names, 'model' => $r->model, 'error' => $r->error, 'warnings' => $r->warnings ?? [],
            'facts' => $r->facts, 'text' => $r->text,
        ];
    }
}
