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
        // Un batch del testeo no se lanza suelto: cuando están todos, "Lanza el testeo"
        if ($batch->testing_round !== null) {
            $t = TestingLaunch::ready([$pid])[0] ?? null;
            if ($t === null) {
                return null;
            }
            $item = UrgentList::testLaunch($t);
            self::append($item, null);
            self::tell('El testeo de ' . UrgentList::short($t['product']) . ' está listo (' . CreativeCalls::join(array_column($t['batches'], 'code'))
                . '): lánzalo. Está en tu Análisis del día.');

            return $item;
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
            // .123: una ciudad que espera a ver cómo resultó su último cambio (las 24 horas, o los días entre C y BE) no lanza
            // hoy, salvo que le queden menos de 2 batches vivos: el batch le sale en el análisis del día que le toque
            $holds = UrgentList::holds($a);
            $offer = collect(array_slice($n['offers'] ?? [], 0, UrgentList::need($key, $n, 'normal', $holds)))->firstWhere('code', $code);
            if ($offer) {
                $cities[] = ['city' => $c['city'] ?? null, 'campaign' => $names[(string) $id] ?? null, 'offer' => $offer,
                    'why' => DemandRules::reason($holds ? 'hold' : $key, $n['live'] ?? null)];
            }
        }
        if ($cities === []) {
            return null;
        }
        $item = UrgentList::launchReady($pid, (string) $d['product']['name'], $code, $cities, now(config('app.timezone'))->format('H:i'));
        self::append($item, $code);
        $where = CreativeCalls::join(array_values(array_filter(array_column($cities, 'city'))));
        self::tell("{$code} de " . UrgentList::short((string) $d['product']['name']) . ' ya llegó a la banca: lánzalo hoy'
            . ($where !== '' ? " en {$where}" : '') . '. Está en tu Análisis del día.');

        return $item;
    }

    /** En el análisis de hoy, si todavía no lo pide (a las 00:30 sale solo con lo que haya en la banca). */
    private static function append(array $item, ?string $code): void
    {
        $analysis = MetaAnalysis::where('date', now(config('app.timezone'))->toDateString())->orderByDesc('version')->first();
        if ($analysis && !collect($analysis->urgent ?? [])->contains(fn ($u) => ($u['key'] ?? null) === $item['key']
            || $code !== null && str_contains(implode(' ', $u['steps'] ?? []), '«' . $code . '»'))) {
            $analysis->forceFill(['urgent' => [...($analysis->urgent ?? []), $item]])->save();
        }
    }

    /** El aviso al dueño (y a Master) en la campanita. */
    private static function tell(string $message): void
    {
        foreach (User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->whereIn('description', ['Admin', 'Master']))->get() as $u) {
            $u->notify(new MetaWorkNotification('meta_ready', $message, '/meta-ads/analisis'));
        }
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
