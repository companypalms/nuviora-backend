<?php

namespace App\Services\Assistant\Tools;

use App\Models\FinancialAccount;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;
use App\Services\EarningsService;
use App\Services\Finance\CashLedger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El efectivo de las agencias en un período, como Ganancias globales › Liquidación de Agencias (el mismo
 * EarningsService::calculateAgencySettlement): lo que cobraron en dólares y en bolívares de las órdenes entregadas, los
 * vueltos que dieron, el saldo y las carreras. Además, lo que cada una tiene todavía hoy (Finanzas › Caja) y los pagos
 * en "Euros efectivo", que la liquidación no cuenta (se muestran aparte, con su aviso).
 */
class AgencyCashTool implements Tool
{
    private const MAX_AGENCIES = 30;

    public function __construct(private EarningsService $earnings, private CashLedger $cash, private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'efectivo_agencias';
    }

    public function description(): string
    {
        return 'El efectivo que cobraron las agencias en un período (lo mismo de Ganancias globales › Liquidación de Agencias): por agencia, las órdenes entregadas en esas fechas, el efectivo cobrado en dólares y en bolívares, los vueltos que dieron, el saldo del período en dólares y en bolívares (cobrado − vueltos: lo que deben entregar de esas fechas) y sus carreras con lo que se les paga. Además, lo que cada agencia tiene todavía hoy sin entregar (Finanzas › Caja) y los pagos en "Euros efectivo", que la liquidación no cuenta. Úsala para "cuánto me deben las agencias", "cuánto efectivo cobraron", "la liquidación de la semana". Nunca sumes dólares con bolívares.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => PeriodArgs::schema() + [
                'agencia' => ['type' => ['string', 'null'], 'description' => 'Solo esa agencia. null = todas.'],
                'grafico' => ['type' => 'boolean', 'description' => 'true para mostrar una gráfica del saldo en dólares de cada agencia.'],
            ],
            'required' => ['periodo', 'desde', 'hasta', 'agencia', 'grafico'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando el efectivo de las agencias…';
    }

    public function run(array $args): ToolResult
    {
        $period = PeriodArgs::period($args, CarbonImmutable::now());
        $agencyId = $this->lookup->agencyId($args['agencia'] ?? null);
        // EarningsService cambia las fechas que recibe: se le pasan nuevas
        $settlement = $this->earnings->calculateAgencySettlement(Carbon::parse($period->fromDate()), Carbon::parse($period->toDate()), $agencyId)->all();
        $orderIds = collect($settlement)->flatMap(fn ($a) => collect($a['order_details'] ?? [])->pluck('order_id'))->all();
        $today = $this->cash->balances()->where('kind', FinancialAccount::AGENCY_CASH)->where('is_active', true)
            ->when($agencyId, fn ($c) => $c->where('agency_id', $agencyId))->values()->all();

        $s = self::summarize($settlement, self::eurosByOrder($orderIds), $today);

        $data = ['periodo' => Present::period($period)] + ($s['agencies'] === []
            ? ['mensaje' => 'Ninguna agencia entregó pedidos ni hizo carreras en esas fechas.']
            : ['total' => $s['total'], 'agencias' => array_slice($s['agencies'], 0, self::MAX_AGENCIES)]);
        if ($s['today'] !== []) {
            $data['tienen_hoy_sin_entregar'] = $s['today'];
        }
        $data['como_se_calcula'] = 'Órdenes entregadas en esas fechas (por el día en que se entregaron). Los bolívares cobrados van a la tasa Binance del pago y el vuelto en bolívares a la tasa euro, igual que la pantalla. "Hoy sin entregar" es de todas las fechas, según la Caja: baja cuando la agencia entrega el efectivo.';

        $display = [];
        if ($s['rows'] !== []) {
            $display[] = [
                'type' => 'table',
                'title' => "Efectivo de las agencias · {$period->label}",
                'columns' => [
                    ['key' => 'delivered', 'label' => 'Entregadas', 'format' => 'count'],
                    ['key' => 'usd', 'label' => 'Saldo $', 'format' => 'money'],
                    ['key' => 'ves', 'label' => 'Saldo Bs', 'format' => 'bs'],
                    ['key' => 'trips', 'label' => 'Carreras $', 'format' => 'money'],
                ],
                'rows' => $s['rows'],
            ];
            if (!empty($args['grafico'])) {
                $display[] = Present::chart('hbar', 'Saldo en dólares de cada agencia', 'money', [['key' => 'usd', 'label' => 'Saldo $']],
                    array_map(fn ($r) => ['label' => $r['name'], 'usd' => round((float) $r['values']['usd'], 2)], array_slice($s['rows'], 0, 12)));
            }
        }
        $display[] = ['type' => 'link', 'label' => 'Abrir Ganancias globales', 'to' => '/earnings'];

        return new ToolResult($data, $display);
    }

    /**
     * Lo que se dice y se ve, a partir de la liquidación, los euros de cada orden y las cuentas de efectivo de las
     * agencias en la Caja (PHP puro).
     *
     * @param array<int, array> $settlement la de EarningsService::calculateAgencySettlement
     * @param array<int, float> $euros order_id => dólares cobrados como "Euros efectivo"
     * @param array<int, array> $today las cuentas de efectivo de agencias de CashLedger::balances (agency_id, name, currency, balance)
     * @return array{agencies: array, total: array, rows: array, today: array}
     */
    public static function summarize(array $settlement, array $euros, array $today): array
    {
        $byAgency = [];
        foreach ($today as $a) {
            if (abs((float) ($a['balance'] ?? 0)) > 0.005) {
                $byAgency[(int) ($a['agency_id'] ?? 0)][(string) $a['currency']] = ($byAgency[(int) ($a['agency_id'] ?? 0)][(string) $a['currency']] ?? 0) + (float) $a['balance'];
            }
        }
        $todayText = fn (int $id) => isset($byAgency[$id]) ? self::currencies($byAgency[$id]) : null;

        usort($settlement, fn ($a, $b) => [(float) $b['balance_usd'], (float) $b['balance_ves']] <=> [(float) $a['balance_usd'], (float) $a['balance_ves']]);
        $agencies = [];
        $rows = [];
        $sum = ['delivered' => 0, 'usd' => 0.0, 'ves' => 0.0, 'cusd' => 0.0, 'cves' => 0.0, 'chusd' => 0.0, 'chves' => 0.0, 'trips' => 0, 'pay' => 0.0, 'eur' => 0.0];
        foreach ($settlement as $a) {
            $id = (int) $a['agency_id'];
            $eur = round(collect($a['order_details'] ?? [])->sum(fn ($d) => (float) ($euros[(int) $d['order_id']] ?? 0)), 2);
            $agencies[] = array_filter([
                'agencia' => $a['agency_name'],
                'entregadas' => (int) $a['count_delivered'],
                'efectivo_cobrado' => self::pair((float) $a['total_collected_usd'], (float) $a['total_collected_ves']),
                'vueltos_que_dio' => self::pair((float) $a['total_change_usd'], (float) $a['total_change_ves']),
                'saldo_del_periodo' => self::pair((float) $a['balance_usd'], (float) $a['balance_ves']),
                'euros_en_efectivo' => $eur > 0.005 ? Present::value($eur, 'money') . ' registrados como "Euros efectivo": la liquidación no los cuenta' : null,
                'carreras' => (int) ($a['count_trips'] ?? 0) ?: null,
                'se_le_paga_por_carreras' => (float) ($a['total_shipping_cost'] ?? 0) > 0 ? Present::value((float) $a['total_shipping_cost'], 'money') : null,
                'tiene_hoy_sin_entregar' => $todayText($id),
            ], fn ($v) => $v !== null);
            $rows[] = [
                'name' => $a['agency_name'],
                'group' => ($t = $todayText($id)) ? "Hoy tiene {$t}" : null,
                'values' => ['delivered' => (int) $a['count_delivered'], 'usd' => round((float) $a['balance_usd'], 2), 'ves' => round((float) $a['balance_ves'], 2),
                    'trips' => round((float) ($a['total_shipping_cost'] ?? 0), 2)],
                'low_data' => false,
            ];
            $sum['delivered'] += (int) $a['count_delivered'];
            $sum['usd'] += (float) $a['balance_usd'];
            $sum['ves'] += (float) $a['balance_ves'];
            $sum['cusd'] += (float) $a['total_collected_usd'];
            $sum['cves'] += (float) $a['total_collected_ves'];
            $sum['chusd'] += (float) $a['total_change_usd'];
            $sum['chves'] += (float) $a['total_change_ves'];
            $sum['trips'] += (int) ($a['count_trips'] ?? 0);
            $sum['pay'] += (float) ($a['total_shipping_cost'] ?? 0);
            $sum['eur'] += $eur;
        }

        $total = array_filter([
            'entregadas' => $sum['delivered'],
            'efectivo_cobrado' => self::pair($sum['cusd'], $sum['cves']),
            'vueltos_que_dieron' => self::pair($sum['chusd'], $sum['chves']),
            'saldo_del_periodo' => self::pair($sum['usd'], $sum['ves']),
            'euros_en_efectivo' => $sum['eur'] > 0.005 ? Present::value($sum['eur'], 'money') . ' registrados como "Euros efectivo" (no están en la liquidación ni en la Caja)' : null,
            'carreras' => $sum['trips'] ?: null,
            'se_les_paga_por_carreras' => $sum['pay'] > 0 ? Present::value($sum['pay'], 'money') : null,
        ], fn ($v) => $v !== null);

        // Hoy: todas las agencias juntas y, aparte, el efectivo de los repartidores propios (su cuenta no tiene agencia)
        $todayAll = [];
        foreach ($byAgency as $id => $currencies) {
            if ($id !== 0) {
                foreach ($currencies as $currency => $amount) {
                    $todayAll[$currency] = ($todayAll[$currency] ?? 0) + $amount;
                }
            }
        }

        return [
            'agencies' => $agencies,
            'total' => $total,
            'rows' => $rows,
            'today' => array_filter([
                'todas_las_agencias' => $todayAll === [] ? null : self::currencies($todayAll),
                'repartidores_propios' => isset($byAgency[0]) ? self::currencies($byAgency[0]) : null,
            ]),
        ];
    }

    /** order_id => lo cobrado como "Euros efectivo" (en dólares, como se guardan los pagos). */
    private static function eurosByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($orderIds)), 1000) as $chunk) {
            $out += DB::table('order_payments')->whereIn('order_id', $chunk)->where('method', 'EUROS_EFECTIVO')
                ->groupBy('order_id')->selectRaw('order_id, SUM(amount) as total')->pluck('total', 'order_id')
                ->map(fn ($v) => (float) $v)->all();
        }

        return $out;
    }

    /** "$120,00 y Bs 4.500,00". */
    private static function pair(float $usd, float $ves): string
    {
        return Present::value(round($usd, 2), 'money') . ' y ' . self::bs($ves);
    }

    /** Montos por moneda de la Caja: "$80,00 y Bs 1.200,00". */
    private static function currencies(array $byCurrency): string
    {
        ksort($byCurrency);

        return implode(' y ', array_map(fn ($c, $v) => match ($c) {
            'USD' => Present::value(round($v, 2), 'money'),
            'VES' => self::bs($v),
            default => number_format($v, 2, ',', '.') . " {$c}",
        }, array_keys($byCurrency), $byCurrency));
    }

    private static function bs(float $v): string
    {
        return 'Bs ' . number_format(round($v, 2), 2, ',', '.');
    }
}
