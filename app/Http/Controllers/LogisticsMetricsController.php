<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCenter;
use App\Services\Logistics\Metrics;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Las métricas del §27 de un centro, por rango de días. Para administración. */
class LogisticsMetricsController extends Controller
{
    public function show(Request $request, LogisticsCenter $center, Metrics $metrics)
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        $from = Carbon::parse($data['from'], config('app.timezone'));
        $to = Carbon::parse($data['to'], config('app.timezone'));
        if ($from->diffInDays($to) > 92) {
            return response()->json(['status' => false, 'message' => 'Elige un rango de hasta 3 meses.'], 422);
        }

        return response()->json(['status' => true, 'metrics' => $metrics->for($center, $from, $to)]);
    }
}
