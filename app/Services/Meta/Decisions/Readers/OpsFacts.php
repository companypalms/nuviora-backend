<?php

namespace App\Services\Meta\Decisions\Readers;

use App\Constants\OrderStatus;
use App\Models\CreativeBatch;
use App\Models\Status;
use App\Services\Finance\FinancialCpa;
use App\Services\Inventory\CityStock;
use App\Services\Meta\Products\TestingPhase;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Meta\Queue\QueueRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que necesitan las acciones de campaña (Etapa 5, paso 2b), solo lectura, desde nuestra base:
 * - de cada campaña: el gasto y las compras por día, el historial de su presupuesto y estado (la foto que guarda la
 *   sincronización desde la v.33), los anuncios que se apagaron o entraron (las intervenciones del dueño) y los
 *   mínimos de gasto de sus ad sets;
 * - de cada producto: el stock en cada ciudad (y por talla), cuánto vende por día, si se vende en esa ciudad, su precio,
 *   si el precio cambió hace poco y la versión del CPA de finanzas (para el precio mínimo).
 */
class OpsFacts
{
    /** `.101`: los pedidos que esperan stock se cuentan desde hace 14 días (los de hace meses ya no se van a entregar). */
    public const WAITING_DAYS = 14;

    public function __construct(private CityStock $stock = new CityStock())
    {
    }

    /**
     * @param string[] $campaignIds
     * @return array{history_since: ?string, today: string, yesterday: string, campaigns: array<string, array>}
     */
    public function campaigns(array $campaignIds): array
    {
        $tz = config('app.timezone');
        $today = now($tz)->toDateString();
        $yesterday = now($tz)->subDay()->toDateString();
        $out = ['history_since' => null, 'today' => $today, 'yesterday' => $yesterday, 'campaigns' => []];
        $campaignIds = array_values(array_unique(array_map('strval', $campaignIds)));
        if (!$campaignIds) {
            return $out;
        }
        $first = DB::table('meta_entity_snapshots')->min('recorded_at');
        $out['history_since'] = $first ? Carbon::parse($first)->setTimezone($tz)->toDateString() : null;
        $from = now($tz)->subDays(40)->toDateString();

        foreach ($campaignIds as $id) {
            $out['campaigns'][$id] = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
        }
        $daily = DB::table('meta_insights_daily')->where('level', 'campaign')->whereIn('meta_id', $campaignIds)->where('date', '>=', $from)
            ->get(['meta_id', 'date', 'spend', 'purchases']);
        foreach ($daily as $r) {
            $out['campaigns'][(string) $r->meta_id]['daily'][substr((string) $r->date, 0, 10)] = ['spend' => (float) $r->spend, 'purchases' => (float) ($r->purchases ?? 0)];
        }

        // Presupuesto y estado de cada campaña: cada foto contra la anterior
        $snaps = DB::table('meta_entity_snapshots')->where('level', 'campaign')->whereIn('meta_id', $campaignIds)
            ->orderBy('recorded_at')->orderBy('id')->get(['meta_id', 'daily_budget', 'status', 'effective_status', 'recorded_at'])->groupBy('meta_id');
        foreach ($snaps as $id => $rows) {
            $prev = null;
            foreach ($rows as $r) {
                $at = Carbon::parse($r->recorded_at)->setTimezone($tz);
                if ($prev && $r->daily_budget !== null && $prev->daily_budget !== null && abs((float) $r->daily_budget - (float) $prev->daily_budget) >= 0.01) {
                    $out['campaigns'][(string) $id]['budget_events'][] = ['at' => $at->toDateTimeString(), 'date' => $at->toDateString(),
                        'from' => (float) $prev->daily_budget, 'to' => (float) $r->daily_budget];
                }
                if ($prev && $r->effective_status !== $prev->effective_status) {
                    $out['campaigns'][(string) $id]['status_events'][] = ['at' => $at->toDateTimeString(), 'date' => $at->toDateString(),
                        'from' => $prev->effective_status, 'to' => $r->effective_status, 'budget' => $r->daily_budget !== null ? (float) $r->daily_budget : null];
                }
                $prev = $r;
            }
        }

        // Anuncios apagados (ACTIVE → otro estado) y anuncios nuevos, por campaña
        $ads = DB::table('meta_ads')->whereIn('campaign_meta_id', $campaignIds)->get(['meta_id', 'campaign_meta_id', 'created_time']);
        $campaignOfAd = $ads->pluck('campaign_meta_id', 'meta_id')->map(fn ($v) => (string) $v)->all();
        foreach ($ads as $a) {
            if ($a->created_time && $a->created_time >= $from) {
                $out['campaigns'][(string) $a->campaign_meta_id]['new_ads'][] = Carbon::parse($a->created_time)->setTimezone($tz)->toDateString();
            }
        }
        $adSnaps = $ads->isEmpty() ? collect() : DB::table('meta_entity_snapshots')->where('level', 'ad')
            ->whereIn('meta_id', array_map('strval', array_keys($campaignOfAd)))->orderBy('recorded_at')->orderBy('id')
            ->get(['meta_id', 'status', 'recorded_at'])->groupBy('meta_id');
        foreach ($adSnaps as $adId => $rows) {
            $prev = null;
            foreach ($rows as $r) {
                if ($prev && $prev->status === 'ACTIVE' && $r->status !== 'ACTIVE') {
                    $out['campaigns'][$campaignOfAd[(string) $adId]]['ad_pauses'][] = Carbon::parse($r->recorded_at)->setTimezone($tz)->toDateString();
                }
                $prev = $r;
            }
        }

        // Los mínimos de gasto de los ad sets activos (CBO), en la moneda de la cuenta
        foreach (DB::table('meta_adsets')->whereIn('campaign_meta_id', $campaignIds)->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->get(['campaign_meta_id', 'meta']) as $s) {
            $meta = json_decode((string) $s->meta, true) ?: [];
            if (isset($meta['daily_min_spend_target']) && is_numeric($meta['daily_min_spend_target'])) {
                $out['campaigns'][(string) $s->campaign_meta_id]['mins'] += (float) $meta['daily_min_spend_target'] / 100;
            }
        }

        return $out;
    }

    /**
     * @param int[] $productIds
     * @param int[] $cityIds
     * @return array<int, array> por producto: price, price_change, cities (stock, ventas por día, disponible), versions
     */
    public function products(array $productIds, array $cityIds): array
    {
        $out = [];
        if (!$productIds) {
            return $out;
        }
        $tz = config('app.timezone');
        $prices = DB::table('products')->whereIn('id', $productIds)->pluck('price', 'id');
        $versions = app(FinancialCpa::class)->latest();
        $unavailable = DB::table('product_city_availability')->whereIn('product_id', $productIds)->where('is_available', false)
            ->get(['product_id', 'city_id'])->map(fn ($r) => "{$r->product_id}|{$r->city_id}")->all();
        // Paso 4a: la banca (aprobados sin testear) y los que están listos para lanzar
        $bank = DB::table('creative_batches')->whereIn('product_id', $productIds)->whereIn('status', QueueRules::BANK)->whereNotNull('number')
            ->orderBy('position')->orderBy('number')->get(['id', 'product_id', 'number', 'status', 'testing_round', 'angle_code'])->groupBy('product_id');
        // .89: sus nombres exactos en Meta (el ad set "B0110 POSP" y sus anuncios "C0457 POSP"…)
        $pieces = DB::table('creative_batch_pieces')->whereIn('creative_batch_id', $bank->flatten()->pluck('id')->all() ?: [0])->whereNotNull('number')
            ->orderBy('position')->get(['creative_batch_id', 'number'])->groupBy('creative_batch_id');
        // .87: los que ya salieron en Meta (en test o testeados): siguen en banca para las ciudades donde no salieron
        $launched = DB::table('creative_batches')->whereIn('product_id', $productIds)->whereIn('status', ['testing', 'tested'])->whereNotNull('number')
            ->orderBy('number')->get(['product_id', 'number'])->groupBy('product_id');
        $since = now($tz)->subDays(7)->toDateString();
        $sales = DB::table('order_financials')->where('kind', 'normal')->where('created_date', '>=', $since)->whereIn('product_id', $productIds)
            ->groupBy('product_id', 'city_id')->selectRaw('product_id, city_id, COUNT(*) n, SUM(units_main) units')->get()
            ->mapWithKeys(fn ($r) => ["{$r->product_id}|{$r->city_id}" => ['orders' => (int) $r->n, 'units' => (float) $r->units]]);
        $warehouses = $this->stock->warehousesByCity();
        // .101: los pedidos que esperan stock ("Sin Stock") de los últimos 14 días, por producto y ciudad, y desde cuándo
        $waiting = DB::table('order_financials')->where('kind', 'normal')->whereIn('product_id', $productIds)
            ->where('status_id', (int) Status::where('description', OrderStatus::SIN_STOCK)->value('id'))
            ->where('created_date', '>=', now($tz)->subDays(self::WAITING_DAYS - 1)->toDateString())
            ->groupBy('product_id', 'city_id')->selectRaw('product_id, city_id, COUNT(*) n, MIN(created_date) since')->get()
            ->mapWithKeys(fn ($r) => ["{$r->product_id}|{$r->city_id}" => ['orders' => (int) $r->n, 'since' => substr((string) $r->since, 0, 10)]]);

        // .116: las iteraciones ya testeadas de cada creativo, para no seguir iterando lo que ya perdió
        $iterations = CreativeBatch::whereIn('product_id', $productIds)->where('status', 'tested')->where('kind', 'iteration')->whereNotNull('reference_creative_id')->whereNotNull('tested_at')
            ->get(['id', 'product_id', 'number', 'status', 'reference_creative_id', 'result', 'tested_at'])->groupBy('product_id');

        foreach ($productIds as $pid) {
            $cities = [];
            foreach ($cityIds as $cid) {
                $rows = $this->stock->forWarehouses($warehouses[$cid] ?? [], [$pid]);
                $row = $rows[0] ?? null;
                $sold = $sales["{$pid}|{$cid}"] ?? ['orders' => 0, 'units' => 0.0];
                $perDay = $sold['units'] / 7;
                $cities[$cid] = [
                    'available' => !in_array("{$pid}|{$cid}", $unavailable, true),
                    'stock' => $row['total'] ?? 0,
                    'units_per_day' => round($perDay, 2),
                    'days_of_stock' => $perDay > 0 ? round(($row['total'] ?? 0) / $perDay, 1) : null,
                    'sizes_out' => array_values(array_map(fn ($v) => $v['title'], array_filter($row['variants'] ?? [], fn ($v) => $v['available'] <= 0))),
                    'waiting' => $waiting["{$pid}|{$cid}"] ?? null,
                    'version' => $versions["{$pid}|{$cid}"] ?? null,
                ];
            }
            $out[$pid] = ['price' => isset($prices[$pid]) ? (float) $prices[$pid] : null, 'price_change' => $this->priceChange($pid),
                'cities' => $cities, 'version' => $versions["{$pid}|"] ?? null,
                // .116: los ángulos de la ficha, lo que decidió el dueño y las iteraciones de cada creativo
                'angles' => TestingPhase::profileAngles((int) $pid),
                'iterations' => self::iterations($iterations[$pid] ?? collect()),
                'bank' => ['codes' => ($bank[$pid] ?? collect())->map(fn ($b) => QueueRules::batchCode((int) $b->number))->values()->all(),
                    // .89: los del testeo se lanzan juntos ("Lanza el testeo"), no sueltos como reserva de una ciudad
                    'ready' => ($bank[$pid] ?? collect())->filter(fn ($b) => $b->status === 'bank' && $b->testing_round === null)
                        ->map(fn ($b) => QueueRules::batchCode((int) $b->number))->values()->all(),
                    'launched' => ($launched[$pid] ?? collect())->map(fn ($b) => QueueRules::batchCode((int) $b->number))->values()->all(),
                    'names' => ($bank[$pid] ?? collect())->mapWithKeys(fn ($b) => [QueueRules::batchCode((int) $b->number) => \App\Services\Meta\Naming::batch((int) $b->number,
                        $b->angle_code, ($pieces[$b->id] ?? collect())->pluck('number')->all())])->all()]];
        }

        return $out;
    }

    /** `.116`: por creativo, sus iteraciones con su resultado (para AngleRest). */
    private static function iterations($batches): array
    {
        $out = [];
        foreach ($batches as $b) {
            $date = $b->tested_at->setTimezone(config('app.timezone'))->toDateString();
            $out[(int) $b->reference_creative_id][] = ['code' => (string) $b->code(), 'launched' => $date, 'ended' => $date,
                'outcome' => ['Ganó' => 'won', 'Perdió' => 'lost'][EditorRules::result('tested', array_values($b->result['verdicts'] ?? []))] ?? 'tie'];
        }

        return $out;
    }

    /** ¿Cambió el precio hace poco? El precio por unidad de los pedidos de los últimos 3 días contra los 14 anteriores. */
    private function priceChange(int $productId): ?array
    {
        $tz = config('app.timezone');
        // El precio más repetido en las líneas de los pedidos al crearse (finanzas solo anota lo cobrado al entregar), sin
        // los upsells; no el promedio: las promociones lo moverían sin que cambie el precio
        $common = function (string $from, string $to) use ($productId, $tz): ?array {
            $rows = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')
                ->where('op.product_id', $productId)->where(fn ($q) => $q->whereNull('op.is_upsell')->orWhere('op.is_upsell', false))
                ->where('op.price', '>', 0)
                ->whereBetween('o.created_at', [Carbon::parse($from, $tz)->startOfDay(), Carbon::parse($to, $tz)->endOfDay()])
                ->selectRaw('ROUND(op.price, 2) p, COUNT(*) n')->groupBy('p')->orderByDesc('n')->get();
            $total = (int) $rows->sum('n');

            return $total >= 5 && $rows->isNotEmpty() ? ['price' => (float) $rows->first()->p, 'orders' => $total] : null;
        };
        $recent = $common(now($tz)->subDays(3)->toDateString(), now($tz)->subDay()->toDateString());
        $before = $common(now($tz)->subDays(17)->toDateString(), now($tz)->subDays(4)->toDateString());
        if (!$recent || !$before) {
            return null;
        }

        return abs($recent['price'] - $before['price']) >= 1 ? ['from' => $before['price'], 'to' => $recent['price']] : null;
    }
}
