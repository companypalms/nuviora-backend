<?php

namespace App\Services\Delivery\Closures;

use App\Models\AgencyClosureSetting;
use App\Models\AgencyWeeklyClosure;

/**
 * La lista de los cierres semanales de las agencias (Módulo 3, §31): cada cierre con su estado, lo que esperaba el
 * sistema, lo que cobra la agencia y lo aprobado. La usan la pantalla de Cierres (AgencyClosureController) y el
 * asistente del dueño. Solo lee.
 */
class ClosureList
{
    /** Los más recientes primero, como mucho $limit. */
    public function list(?int $agencyId = null, ?string $status = null, int $limit = 200, ?string $weekStart = null): array
    {
        $query = AgencyWeeklyClosure::with(['agency:id,names', 'currentFile'])
            ->orderByDesc('period_start')->orderBy('agency_id');
        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }
        if ($status) {
            $query->where('status', $status);
        }
        if ($weekStart) {
            $query->whereDate('period_start', $weekStart);
        }
        $settings = AgencyClosureSetting::all()->keyBy('agency_id');

        return $query->limit($limit)->get()
            ->map(fn (AgencyWeeklyClosure $c) => $this->present($c, $settings->get($c->agency_id) ?? AgencyClosureSetting::for($c->agency_id)))
            ->all();
    }

    public function present(AgencyWeeklyClosure $c, AgencyClosureSetting $setting): array
    {
        return [
            'id' => $c->id,
            'agency_id' => $c->agency_id,
            'agency' => $c->agency?->names,
            'period_start' => $c->period_start->toDateString(),
            'period_end' => $c->period_end->toDateString(),
            'week' => $c->weekLabel(),
            'status' => $c->status,
            'status_label' => AgencyWeeklyClosure::STATUS_LABEL[$c->status] ?? $c->status,
            'trips_count' => $c->trips_count,
            'expected_total' => $c->expected_total,
            'reported_total' => $c->reported_total,
            'difference' => $c->expected_total !== null && $c->reported_total !== null ? round($c->reported_total - $c->expected_total, 2) : null,
            'final_approved_total' => $c->final_approved_total,
            'manual_total' => $c->manual_total,
            'manual_reason' => $c->manual_reason,
            'summary' => $c->summary,
            'processing_error' => $c->processing_error,
            'uploaded_at' => $c->uploaded_at?->toDateTimeString(),
            'processed_at' => $c->processed_at?->toDateTimeString(),
            'paid_at' => $c->paid_at?->toDateTimeString(),
            'file' => $c->relationLoaded('currentFile') && $c->currentFile ? ['name' => $c->currentFile->original_name, 'version' => $c->currentFile->version] : null,
            'reminder_at' => $c->reminderAt($setting)->toDateTimeString(),
            'reminder_due' => $c->reminderDue($setting),
        ];
    }
}
