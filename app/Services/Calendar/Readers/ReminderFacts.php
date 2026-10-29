<?php

namespace App\Services\Calendar\Readers;

use App\Models\AgencyWeeklyClosure;
use App\Models\Currency;
use App\Models\Earning;
use App\Models\LeaderCommission;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationItem;
use App\Models\RecurringExpense;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\OtherPayments;
use App\Services\Metrics\GoalSettings;
use App\Services\Metrics\PersonName;
use App\Services\Payments\ApprovalQueues;
use App\Services\Reconciliation\DayBuilder;
use App\Services\SCM\SupplyChainEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Los datos del sistema que miran los recordatorios del calendario (Rules\ReminderRules y ExpenseRules). Solo lee: la tasa,
 * la conciliación, lo que hay por aprobar (las mismas colas de esa página), el inventario (lo mismo del cuadro del
 * Dashboard), las metas, lo que ganó cada vendedora, los cierres de las agencias y los gastos fijos de Finanzas.
 */
class ReminderFacts
{
    /** El análisis del inventario es pesado: se guarda unos minutos. */
    public const INVENTORY_CACHE_SECONDS = 300;

    public function __construct(
        private ApprovalQueues $approvals,
        private SupplyChainEngine $scm,
        private GoalSettings $goals,
        private DayBuilder $reconciliation,
        private OtherPayments $payments,
    ) {
    }

    /** Lo que sirve para cualquier fecha: qué días se guardó una tasa y de qué meses hay metas. */
    public function forRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'rate_days' => $this->rateDays($from, $to),
            'goal_months' => array_keys($this->goals->get()['months'] ?? []),
        ];
    }

    /**
     * Los pagos de cada semana que piden los recordatorios (por el lunes de la semana): lo que ganó cada vendedora y los
     * cierres de las agencias.
     *
     * @param list<string> $sellerWeeks lunes "Y-m-d"
     * @param list<string> $closureWeeks lunes "Y-m-d"
     */
    public function weeks(array $sellerWeeks, array $closureWeeks): array
    {
        $facts = ['sellers' => [], 'closures' => []];
        foreach (array_unique($sellerWeeks) as $monday) {
            $facts['sellers'][$monday] = $this->sellerWeek($monday);
        }
        if ($closureWeeks !== []) {
            $closures = AgencyWeeklyClosure::with('agency:id,names,surnames')
                ->whereIn('period_start', array_unique($closureWeeks))
                ->get()
                ->sortBy(fn (AgencyWeeklyClosure $c) => PersonName::of($c->agency?->names, $c->agency?->surnames));
            foreach (array_unique($closureWeeks) as $monday) {
                $facts['closures'][$monday] = $closures
                    ->filter(fn (AgencyWeeklyClosure $c) => $c->period_start->toDateString() === $monday)
                    ->map(fn (AgencyWeeklyClosure $c) => [
                        'agency' => PersonName::of($c->agency?->names, $c->agency?->surnames),
                        'status' => $c->status,
                        'status_label' => AgencyWeeklyClosure::STATUS_LABEL[$c->status] ?? $c->status,
                        // Lo aprobado; si todavía no se aprobó, lo que cobra la agencia o, si no, lo que calculó el sistema
                        'amount' => $c->final_approved_total ?? $c->reported_total ?? $c->expected_total,
                        'paid' => $c->status === AgencyWeeklyClosure::PAID,
                    ])
                    ->values()
                    ->all();
            }
        }

        return $facts;
    }

    /**
     * Los gastos fijos de Finanzas que están activos, cada uno con sus vencimientos hasta esa fecha (desde el arranque de
     * la caja, con si ya se pagaron: OtherPayments::dueDates, lo mismo que la ventana de pago).
     *
     * @return list<array{id: int, name: string, category: ?string, frequency: string, schedule: string, dues: list<array>}>
     */
    public function expenses(CarbonImmutable $until): array
    {
        return RecurringExpense::with(['category:id,name', 'versions'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (RecurringExpense $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'category' => $r->category?->name,
                'frequency' => $r->frequency,
                'schedule' => $r->scheduleLabel(),
                'dues' => $this->payments->dueDates($r, $until->toDateString()),
            ])
            ->all();
    }

    /**
     * Lo que ganó cada vendedora esa semana (de lunes a domingo, por la fecha de cada comisión), igual que su pantalla de
     * Ganancias (EarningsService::forUser): comisiones por pedido, upsells y, si es líder, su parte del grupo.
     *
     * @return list<array{id: int, name: string, orders: float, upsells: float, leadership: float, total: float}>
     */
    private function sellerWeek(string $monday): array
    {
        $from = $monday;
        $to = CarbonImmutable::parse($monday)->addDays(6)->toDateString();
        $people = [];
        $earnings = Earning::whereIn('role_type', ['vendedor', 'upsell'])->whereBetween('earning_date', [$from, $to])
            ->selectRaw('user_id, role_type, SUM(amount_usd) AS usd')->groupBy('user_id', 'role_type')->get();
        foreach ($earnings as $e) {
            $people[$e->user_id][$e->role_type === 'upsell' ? 'upsells' : 'orders'] = (float) $e->usd;
        }
        $leaders = LeaderCommission::whereBetween('earning_date', [$from, $to])
            ->selectRaw('leader_id, SUM(amount_usd) AS usd')->groupBy('leader_id')->get();
        foreach ($leaders as $l) {
            $people[$l->leader_id]['leadership'] = (float) $l->usd;
        }
        $names = User::whereIn('id', array_keys($people))->get(['id', 'names', 'surnames'])->keyBy('id');

        $rows = [];
        foreach ($people as $id => $p) {
            $row = ['id' => (int) $id, 'name' => PersonName::of($names->get($id)?->names, $names->get($id)?->surnames) ?: "Usuario {$id}",
                'orders' => round($p['orders'] ?? 0, 2), 'upsells' => round($p['upsells'] ?? 0, 2), 'leadership' => round($p['leadership'] ?? 0, 2)];
            $row['total'] = round($row['orders'] + $row['upsells'] + $row['leadership'], 2);
            if ($row['total'] > 0) {
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** Lo de ahora mismo, para los recordatorios de hoy. Si algo falla, ese recordatorio se muestra sin el dato. */
    public function now(CarbonImmutable $now): array
    {
        $facts = ['rates' => $this->rates()];
        $facts['reconciliation'] = $this->pendingReconciliation($now);
        $facts['approvals'] = $this->approvals->counts();
        $inventory = $this->inventory();
        if ($inventory !== null) {
            $facts['inventory'] = $inventory;
        }

        return $facts;
    }

    /**
     * Día => la hora de la última tasa guardada ese día ("9:12"). Cada vez que se guarda la tasa nacen filas nuevas en
     * currencies; además, por si se guardó solo en settings, cuenta "rate_updated_at".
     */
    private function rateDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $rows = Currency::where('description', 'binance_usd')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('created_at')
            ->pluck('created_at');
        foreach ($rows as $at) {
            $days[$at->toDateString()] = $at->format('G:i');
        }
        $setting = Setting::get('rate_updated_at');
        if (is_string($setting) && $setting !== '') {
            $at = CarbonImmutable::parse($setting);
            if ($at->betweenIncluded($from->startOfDay(), $to->endOfDay())) {
                $days[$at->toDateString()] = $at->format('G:i');
            }
        }

        return $days;
    }

    /** Las tasas que hay guardadas (las activas), y cuándo se guardaron. */
    private function rates(): array
    {
        $active = Currency::whereHas('status', fn ($s) => $s->where('description', 'Activo'))
            ->whereIn('description', ['bcv_usd', 'bcv_eur', 'binance_usd'])
            ->get(['description', 'value', 'created_at'])
            ->keyBy('description');
        if ($active->isEmpty()) {
            return [];
        }

        return [
            'bcv_usd' => $active->get('bcv_usd')?->value,
            'bcv_eur' => $active->get('bcv_eur')?->value,
            'binance_usd' => $active->get('binance_usd')?->value,
            'updated_at' => $active->max('created_at')?->toDateTimeString(),
        ];
    }

    /**
     * Los días anteriores a hoy que todavía no están conciliados, con lo que les falta: extractos por subir y pagos por
     * revisar (lo mismo que muestra la página de Conciliación).
     *
     * @return list<array{date: string, missing_statements: int, incidents: int}>
     */
    private function pendingReconciliation(CarbonImmutable $now): array
    {
        return ReconciliationDay::where('status', '!=', ReconciliationDay::COMPLETED)
            ->where('date', '<', $now->toDateString())
            ->orderBy('date')
            ->get()
            ->map(function (ReconciliationDay $day) {
                $uploaded = $day->statements()->current()->pluck('statement_source_id')->map(fn ($id) => (int) $id);

                return [
                    'date' => $day->date->toDateString(),
                    'missing_statements' => $this->reconciliation->neededSources($day, true)->diff($uploaded)->count(),
                    'incidents' => $day->items()->whereIn('status', [ReconciliationItem::NOT_FOUND, ReconciliationItem::REVIEW])->count(),
                ];
            })
            ->values()
            ->all();
    }

    /** Cuántos productos están en rojo y en naranja (el mismo análisis del cuadro de inventario del Dashboard). */
    private function inventory(): ?array
    {
        try {
            return Cache::remember('calendar.inventory', self::INVENTORY_CACHE_SECONDS, function () {
                $rows = $this->scm->analyze();

                return [
                    'red' => count(array_filter($rows, fn ($r) => $r['priority'] === 'red')),
                    'orange' => count(array_filter($rows, fn ($r) => $r['priority'] === 'orange')),
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
