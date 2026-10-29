<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Present;
use App\Services\EarningsService;
use App\Services\Metrics\PersonName;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Las comisiones de un período, como Ganancias globales (EarningsService): lo que ganó cada vendedora (con sus
 * upsells), cada líder, cada repartidor y lo que se les debe a las agencias por sus envíos. Por la fecha de la comisión.
 */
class CommissionsTool implements Tool
{
    private const MAX_PEOPLE = 30;

    public function __construct(private EarningsService $earnings)
    {
    }

    public function name(): string
    {
        return 'comisiones';
    }

    public function description(): string
    {
        return 'Las comisiones de un período (lo mismo de Ganancias globales): lo que ganó cada vendedora (incluye sus upsells), cada líder por su grupo, cada repartidor, y lo que se les paga a las agencias por los envíos; con el total de cada rol y el total general. Es lo que se le paga al equipo, no la ganancia del negocio (para eso, dinero).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => PeriodArgs::schema() + ['grafico' => ['type' => 'boolean', 'description' => 'true para mostrar una gráfica de lo que ganó cada persona.']],
            'required' => ['periodo', 'desde', 'hasta', 'grafico'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando las comisiones…';
    }

    public function run(array $args): ToolResult
    {
        $period = PeriodArgs::period($args, CarbonImmutable::now());
        // EarningsService cambia las fechas que recibe: se le pasan nuevas
        $d = $this->earnings->summary(Carbon::parse($period->fromDate()), Carbon::parse($period->toDate()));
        $people = fn ($rows, bool $upsells = false) => collect($rows)->sortByDesc('amount_usd')->take(self::MAX_PEOPLE)->map(fn ($r) => array_filter([
            'nombre' => PersonName::of($r['names'] ?? '', $r['surnames'] ?? ''),
            'gano' => Present::value((float) $r['amount_usd'], 'money'),
            'pedidos' => $r['orders_count'] ?? null,
            'upsells' => $upsells ? ($r['upsells_count'] ?? null) : null,
        ], fn ($v) => $v !== null))->values()->all();
        $t = $d['totals'];

        $data = [
            'periodo' => Present::period($period),
            'total_a_pagar' => Present::value((float) $t['all_usd'], 'money'),
            'por_rol' => [
                'vendedoras (con upsells)' => Present::value((float) $t['vendors_usd'], 'money'),
                'lideres' => Present::value((float) $t['leaders_usd'], 'money'),
                'repartidores' => Present::value((float) $t['deliverers_usd'], 'money'),
                'agencias (envios)' => Present::value((float) $t['agencies_usd'], 'money'),
            ],
            'vendedoras' => $people($d['vendors'], true),
            'lideres' => $people($d['leaders']),
            'repartidores' => $people($d['deliverers']),
            'agencias' => $people($d['agencies']),
        ];
        if ((float) $t['managers_usd'] > 0) {
            $data['por_rol']['gerentes'] = Present::value((float) $t['managers_usd'], 'money');
        }

        $everyone = collect($d['global_users'])->sortByDesc('amount_usd')->values();
        $display = [[
            'type' => 'table',
            'title' => "Comisiones · {$period->label}",
            'columns' => [['key' => 'amount', 'label' => 'Gana', 'format' => 'money'], ['key' => 'orders', 'label' => 'Pedidos', 'format' => 'count']],
            'rows' => $everyone->take(60)->map(fn ($u) => ['name' => PersonName::of($u['names'] ?? '', $u['surnames'] ?? ''), 'group' => $u['role_name'] ?? null,
                'values' => ['amount' => (float) $u['amount_usd'], 'orders' => $u['orders_count'] ?? null], 'low_data' => false])->all(),
        ]];
        if (!empty($args['grafico']) && $everyone->isNotEmpty()) {
            $display[] = Present::chart('hbar', 'Lo que ganó cada persona', 'money', [['key' => 'amount', 'label' => 'Gana']],
                $everyone->take(12)->map(fn ($u) => ['label' => PersonName::of($u['names'] ?? '', $u['surnames'] ?? ''), 'amount' => round((float) $u['amount_usd'], 2)])->all());
        }

        return new ToolResult($data, $display);
    }
}
