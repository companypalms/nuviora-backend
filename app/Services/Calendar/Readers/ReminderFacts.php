<?php

namespace App\Services\Calendar\Readers;

use App\Models\Currency;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationItem;
use App\Models\Setting;
use App\Services\Metrics\GoalSettings;
use App\Services\Payments\ApprovalQueues;
use App\Services\Reconciliation\DayBuilder;
use App\Services\SCM\SupplyChainEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Los datos del sistema que miran los recordatorios del calendario (Rules\ReminderRules). Solo lee: la tasa, la
 * conciliación, lo que hay por aprobar (las mismas colas de esa página), el inventario (lo mismo del cuadro del
 * Dashboard) y las metas.
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
