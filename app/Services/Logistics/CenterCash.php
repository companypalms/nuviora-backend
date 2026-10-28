<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\LogisticsShift;
use App\Models\RiderCashMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El efectivo en manos de la operadora (informe del 27/10, sección 06 punto 3; Fran: "como lo hacemos para las agencias…
 * me debe salir a mí y a ella el dinero que tiene ese almacén recaudado; cuando me lo entregue, se marca como pagado").
 * Es lo que recibió de los riders menos lo que administración marcó como recibido. El cambio de turno (punto 4) deja
 * dicho cuánto dejó la que sale y cuánto recibió la que entra; si no cuadra con el sistema, alerta de caja (§25).
 */
class CenterCash
{
    public const DELIVERED_TO_ADMIN = 'entrega_admin';

    public function __construct(private Alerts $alerts)
    {
    }

    /** @return array{usd: float, ves: float, returns: int} */
    public function balance(LogisticsCenter $center): array
    {
        $in = RiderCashMovement::where('center_id', $center->id)->where('type', RiderCashMovement::HANDOVER)
            ->selectRaw('COALESCE(SUM(received_usd), 0) AS usd, COALESCE(SUM(received_ves), 0) AS ves')->first();
        $out = DB::table('center_cash_movements')->where('center_id', $center->id)
            ->selectRaw('COALESCE(SUM(usd), 0) AS usd, COALESCE(SUM(ves), 0) AS ves')->first();

        return [
            'usd' => round((float) $in->usd - (float) $out->usd, 2),
            'ves' => round((float) $in->ves - (float) $out->ves, 2),
            // Los retornos que el operador ya recibió y siguen en el centro esperando revisión (§16.1)
            'returns' => LogisticsOrder::where('center_id', $center->id)->where('status', LogisticsStatus::IN_REVIEW)->count(),
        ];
    }

    /** Administración marca como recibido el efectivo que le entregó la operadora ("se marca como pagado"). */
    public function deliverToAdmin(LogisticsCenter $center, User $admin, float $usd, float $ves, ?string $method, ?string $note): array
    {
        if ($usd < 0 || $ves < 0 || ($usd < 0.005 && $ves < 0.005)) {
            return ['ok' => false, 'message' => 'Escribe cuánto recibiste, en dólares o en bolívares.', 'code' => 422];
        }
        $balance = $this->balance($center);
        if ($usd > $balance['usd'] + 0.004 || $ves > $balance['ves'] + 0.004) {
            return ['ok' => false, 'message' => 'Es más de lo que tiene la caja del centro según el sistema.', 'code' => 422];
        }
        DB::table('center_cash_movements')->insert([
            'center_id' => $center->id, 'type' => self::DELIVERED_TO_ADMIN, 'usd' => round($usd, 2), 'ves' => round($ves, 2),
            'method' => $method, 'note' => $note, 'recorded_by' => $admin->id, 'created_at' => now(),
        ]);

        return ['ok' => true, 'message' => 'Efectivo del centro marcado como recibido.', 'code' => 200];
    }

    /**
     * Lo que dejó (al cerrar) o recibió (al abrir) la operadora contra lo que dice el sistema. Sin monto escrito, confirmó
     * que está completo. Si no cuadra, alerta de diferencia de caja para administración.
     */
    public function recordHandover(LogisticsShift $shift, LogisticsCenter $center, string $moment, ?float $usd, ?float $ves, User $by): void
    {
        $expected = $this->balance($center);
        $usd ??= $expected['usd'];
        $ves ??= $expected['ves'];
        $shift->forceFill([
            "{$moment}_cash_usd" => round($usd, 2),
            "{$moment}_cash_ves" => round($ves, 2),
            "{$moment}_expected" => $expected,
        ])->save();
        $diffUsd = round($usd - $expected['usd'], 2);
        $diffVes = round($ves - $expected['ves'], 2);
        if (abs($diffUsd) >= 0.01 || abs($diffVes) >= 0.01) {
            $when = $moment === 'close' ? 'dejó al cerrar el turno' : 'recibió al abrir el turno';
            $this->alerts->raise($center, 'caja_diferencia', LogisticsAlert::RED,
                "{$by->names} {$when} USD " . number_format($usd, 2, ',', '.') . ' y Bs ' . number_format($ves, 2, ',', '.')
                . '; el sistema dice USD ' . number_format($expected['usd'], 2, ',', '.') . ' y Bs ' . number_format($expected['ves'], 2, ',', '.') . '.',
                "caja_turno:{$shift->id}:{$moment}", null, null, ['diferencia_usd' => $diffUsd, 'diferencia_ves' => $diffVes]);
        }
    }
}
