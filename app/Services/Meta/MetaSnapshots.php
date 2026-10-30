<?php

namespace App\Services\Meta;

use App\Models\MetaAdAccount;
use Illuminate\Support\Facades\DB;

/**
 * El historial de presupuestos y estados de campañas, ad sets y anuncios (Etapa 5, paso 1a): Meta solo dice cómo están
 * ahora, así que en cada sincronización de la jerarquía se guarda una foto de lo que cambió desde la última. Sirve para
 * saber cuál fue la última decisión (subir, bajar, apagar), cuántos días pasaron desde una intervención y si ya hubo un
 * aumento ese día (el manual del dueño: "como máximo un 20 % por día"). Empieza a contar desde que se publica.
 */
class MetaSnapshots
{
    public const FIELDS = ['status', 'effective_status', 'daily_budget', 'lifetime_budget', 'min_spend', 'spend_cap', 'bid_strategy'];

    private const MONEY = ['daily_budget', 'lifetime_budget', 'min_spend', 'spend_cap'];

    /**
     * Lo que se guarda de una fila de Meta (PHP puro). Los montos llegan en centavos de la moneda de la cuenta ("3960" =
     * 39,60); 0 o vacío es "sin presupuesto" (en una CBO el presupuesto está en la campaña, no en el ad set).
     */
    public static function state(string $level, array $row): array
    {
        $money = function (string $key) use ($row): ?float {
            $v = $row[$key] ?? null;

            return is_numeric($v) && (float) $v > 0 ? round((float) $v / 100, 2) : null;
        };

        return [
            'status' => $row['status'] ?? null,
            'effective_status' => $row['effective_status'] ?? null,
            'daily_budget' => $level === 'ad' ? null : $money('daily_budget'),
            'lifetime_budget' => $level === 'ad' ? null : $money('lifetime_budget'),
            'min_spend' => $level === 'adset' ? $money('daily_min_spend_target') : null,
            'spend_cap' => $level === 'adset' ? $money('daily_spend_cap') : null,
            'bid_strategy' => $level === 'campaign' ? ($row['bid_strategy'] ?? null) : null,
        ];
    }

    /** ¿Algo cambió desde la última foto? (PHP puro). Sin foto anterior, siempre. */
    public static function changed(?array $last, array $now): bool
    {
        if ($last === null) {
            return true;
        }
        foreach (self::FIELDS as $f) {
            $a = $last[$f] ?? null;
            $b = $now[$f] ?? null;
            if (in_array($f, self::MONEY, true)) {
                if (($a === null) !== ($b === null) || ($a !== null && abs((float) $a - (float) $b) >= 0.005)) {
                    return true;
                }
            } elseif ($a !== $b) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guarda una foto de cada entidad que cambió.
     *
     * @param array<string, array> $states meta_id => self::state()
     * @return int fotos nuevas
     */
    public function record(MetaAdAccount $account, string $level, array $states, $now = null): int
    {
        $now ??= now();
        $rows = [];
        foreach (array_chunk(array_keys($states), 1000) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $last = DB::table('meta_entity_snapshots as s')
                ->joinSub(DB::table('meta_entity_snapshots')->where('level', $level)->whereIn('meta_id', $chunk)
                    ->groupBy('meta_id')->selectRaw('meta_id, MAX(id) id'), 'm', 'm.id', '=', 's.id')
                ->get(array_merge(['s.meta_id'], array_map(fn ($f) => "s.{$f}", self::FIELDS)))
                ->mapWithKeys(fn ($r) => [(string) $r->meta_id => (array) $r]);
            foreach ($chunk as $metaId) {
                $state = $states[$metaId];
                if (self::changed($last[$metaId] ?? null, $state)) {
                    $rows[] = ['meta_ad_account_id' => $account->id, 'level' => $level, 'meta_id' => $metaId, 'recorded_at' => $now] + $state;
                }
            }
        }
        foreach (array_chunk($rows, 500) as $part) {
            DB::table('meta_entity_snapshots')->insert($part);
        }

        return count($rows);
    }
}
