<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\Analysis\CreativeCalls;
use App\Services\Meta\Analysis\MetaAnalyzer;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\MetaDecisions;

/**
 * `.89` (dueño, 2026-11-03: "todo lo que ellos vayan creando, el sistema lo va guardando en banca y me dice a mí cuándo
 * debo publicarlos y en dónde… en el análisis de campaña del día me debe decir que debo lanzar"): cuando un batch entra a
 * la banca y alguna ciudad lo necesita ya, se agrega como misión al análisis de hoy ("Lanza B0108: ya llegó a la banca") y
 * se le avisa al dueño en la campanita. Si ninguna ciudad lo necesita, se queda en la banca sin avisar: sale como misión el
 * día que una ciudad lo pida.
 */
class BankArrival
{
    /** @return ?array la misión que se agregó (null si se queda en la banca) */
    public static function handle(CreativeBatch $batch, ?MetaDecisions $source = null): ?array
    {
        $pid = (int) $batch->product_id;
        if ($batch->status !== 'bank' || $batch->number === null) {
            return null;
        }
        $decisions = ($source ?? new MetaDecisions())->forProducts([$pid]);
        $d = $decisions[$pid] ?? null;
        if (!$d || ($d['product']['mode'] ?? 'normal') !== 'normal') {
            return null;
        }
        $code = (string) $batch->code();
        $names = MetaAnalyzer::names($decisions)['campaigns'] ?? [];
        $cities = [];
        foreach ($d['campaigns'] as $id => $c) {
            $a = $c['action'] ?? null;
            if (!$a || !($c['active'] ?? false)) {
                continue;
            }
            $key = $a['key'] === 'none' && ($a['label'] ?? null) === 'No se vende aquí' ? 'not_sold' : $a['key'];
            $n = $c['numbers'] ?? null;
            $offer = collect(array_slice($n['offers'] ?? [], 0, UrgentList::need($key, $n, 'normal')))->firstWhere('code', $code);
            if ($offer) {
                $cities[] = ['city' => $c['city'] ?? null, 'campaign' => $names[(string) $id] ?? null, 'offer' => $offer,
                    'why' => DemandRules::reason($key, $n['live'] ?? null)];
            }
        }
        if ($cities === []) {
            return null;
        }
        $tz = config('app.timezone');
        $item = UrgentList::launchReady($pid, (string) $d['product']['name'], $code, $cities, now($tz)->format('H:i'));
        // En el análisis de hoy, si todavía no lo pide (a las 00:30 sale solo)
        $analysis = MetaAnalysis::where('date', now($tz)->toDateString())->orderByDesc('version')->first();
        if ($analysis && !collect($analysis->urgent ?? [])->contains(fn ($u) => ($u['key'] ?? null) === $item['key']
            || str_contains(implode(' ', $u['steps'] ?? []), '«' . $code . '»'))) {
            $analysis->forceFill(['urgent' => [...($analysis->urgent ?? []), $item]])->save();
        }
        $where = CreativeCalls::join(array_values(array_filter(array_column($cities, 'city'))));
        $message = "{$code} de " . UrgentList::short((string) $d['product']['name']) . ' ya llegó a la banca: lánzalo hoy'
            . ($where !== '' ? " en {$where}" : '') . '. Está en tu Análisis del día.';
        foreach (User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->whereIn('description', ['Admin', 'Master']))->get() as $u) {
            $u->notify(new MetaWorkNotification('meta_ready', $message, '/meta-ads/analisis'));
        }

        return $item;
    }

    /** Lo mismo, sin que un error frene la aprobación. */
    public static function safely(CreativeBatch $batch): void
    {
        try {
            self::handle($batch);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
