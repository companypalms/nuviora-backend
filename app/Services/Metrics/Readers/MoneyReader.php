<?php

namespace App\Services\Metrics\Readers;

use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Finance\CashLedger;
use App\Services\Finance\ExpenseLedger;
use App\Services\Finance\Shrinkage;
use App\Services\Meta\MetaAdsData;
use App\Services\Metrics\Period;
use App\Services\Metrics\PersonName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lee, sin cambiar nada, el dinero que no es de un pedido: la publicidad de Meta, los gastos y la mercancía dada de
 * baja (los mismos de Finanzas › Rentabilidad) y el efectivo que tienen las agencias (Finanzas › Caja). Las reglas que
 * lo convierten en números están en Metrics\Rules\MoneyRules.
 */
class MoneyReader
{
    public function __construct(
        private MetaAdsData $meta,
        private ExpenseLedger $expenses,
        private Shrinkage $shrinkage,
        private CashLedger $cash,
    ) {
    }

    /**
     * Lo gastado en publicidad cada día. Las campañas con producto y ciudad, por producto y ciudad; las que todavía no
     * se clasificaron, con producto y ciudad vacíos.
     *
     * @return array<int, array{date: string, product_id: ?int, city_id: ?int, spend: float}>
     */
    public function adSpend(Period $period): array
    {
        $rows = $this->meta->dailySpend($period->fromDate(), $period->toDate(), ['product', 'city'])
            ->map(fn ($s) => ['date' => $s['date'], 'product_id' => (int) $s['product_id'], 'city_id' => (int) $s['city_id'], 'spend' => (float) $s['spend']])
            ->all();
        $unclassified = DB::table('meta_insights_daily as d')
            ->leftJoin('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->whereBetween('d.date', [$period->fromDate(), $period->toDate()])
            ->where(fn ($q) => $q->whereNull('c.product_id')->orWhereNull('c.city_id'))
            ->groupBy('d.date')->selectRaw('d.date, SUM(d.spend) AS spend')->get();
        foreach ($unclassified as $u) {
            $rows[] = ['date' => substr((string) $u->date, 0, 10), 'product_id' => null, 'city_id' => null, 'spend' => round((float) $u->spend, 2)];
        }

        return array_values(array_filter($rows, fn ($r) => $r['spend'] != 0));
    }

    /**
     * Gastos y mercancía dada de baja de cada grupo de días (día, semana o mes), con su producto y ciudad si los tienen.
     * Los gastos fijos ya vienen repartidos por día; los puntuales cuentan el día de su fecha (ExpenseLedger).
     *
     * @param array<string, array{0: string, 1: string}> $ranges los grupos (Buckets::ranges)
     * @return array<int, array{date: string, kind: string, product_id: ?int, city_id: ?int, amount: float}>
     */
    public function expenses(array $ranges): array
    {
        $rows = [];
        foreach ($ranges as $key => [$from, $to]) {
            array_push($rows, ...$this->expenseRows($key, $from, $to));
        }
        // Cada grupo de días redondea sus gastos fijos a centavos: lo que sobra o falta contra el período completo (lo
        // mismo que muestra Finanzas) se ajusta en el último grupo, para que el total cuadre al centavo
        if (count($ranges) > 1) {
            $keys = array_keys($ranges);
            $whole = [];
            foreach ($this->expenseRows(end($keys), reset($ranges)[0], end($ranges)[1]) as $w) {
                $g = self::group($w);
                $whole[$g] = isset($whole[$g]) ? ['amount' => $whole[$g]['amount'] + $w['amount']] + $whole[$g] : $w;
            }
            $sum = [];
            foreach ($rows as $r) {
                $g = self::group($r);
                $sum[$g] = ($sum[$g] ?? 0) + $r['amount'];
            }
            foreach ($whole as $g => $w) {
                $diff = round($w['amount'] - ($sum[$g] ?? 0), 2);
                if ($diff != 0) {
                    $rows[] = ['amount' => $diff] + $w;
                }
            }
        }

        return array_values(array_filter($rows, fn ($r) => $r['amount'] != 0));
    }

    private function expenseRows(string $key, string $from, string $to): array
    {
        // Carbon que se puede modificar: RecurringExpense::accrued avanza el día con addDay() sobre su copia
        $a = Carbon::parse($from);
        $b = Carbon::parse($to);
        $rows = [];
        foreach ($this->expenses->forRange($a, $b)['items'] as $i) {
            $rows[] = ['date' => $key, 'kind' => $i['scope'], 'product_id' => $i['product_id'], 'city_id' => $i['city_id'], 'amount' => (float) $i['amount_usd']];
        }
        foreach ($this->shrinkage->forRange($a, $b)['items'] as $i) {
            $rows[] = ['date' => $key, 'kind' => 'shrinkage', 'product_id' => $i['product_id'], 'city_id' => $i['city_id'], 'amount' => (float) $i['amount_usd']];
        }

        return $rows;
    }

    private static function group(array $r): string
    {
        return "{$r['kind']}|" . ($r['product_id'] ?? '-') . '|' . ($r['city_id'] ?? '-');
    }

    /**
     * El efectivo que tiene cada agencia ahora mismo (y los repartidores propios, juntos), en dólares: el de sus cuentas
     * "Efectivo pendiente" de la caja. Los bolívares, a la tasa de Binance, como en Finanzas › Caja.
     *
     * @return array<int, array{agency_id: ?int, name: string, usd: float}>
     */
    public function receivables(): array
    {
        $by = [];
        foreach ($this->cash->balances()->where('kind', FinancialAccount::AGENCY_CASH)->where('is_active', true) as $a) {
            $key = $a['agency_id'] ?? 0;
            $by[$key] = ($by[$key] ?? 0) + (float) ($a['balance_usd'] ?? 0);
        }
        // El nombre completo, como en Equipo y en las alertas ("Agencia Centro", no solo "Agencia")
        $names = User::whereIn('id', array_filter(array_keys($by)))->get(['id', 'names', 'surnames'])
            ->mapWithKeys(fn ($u) => [$u->id => PersonName::of($u->names, $u->surnames)]);

        return collect($by)->map(fn ($usd, $id) => [
            'agency_id' => $id ?: null,
            'name' => $id ? ($names[$id] ?? "Agencia {$id}") : 'Repartidores propios',
            'usd' => round($usd, 2),
        ])->values()->all();
    }

    /** Los nombres de los productos, para la ganancia por producto. */
    public function productNames(array $ids): array
    {
        return DB::table('products')->whereIn('id', array_filter($ids))->get(['id', 'title', 'showable_name'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => $p->showable_name ?: $p->title])->all();
    }
}
