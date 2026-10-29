<?php

namespace App\Services\Assistant\Tools;

use App\Models\Currency;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/** Las tasas del dólar de hoy (Tasa y pagos › Tasa del dólar) y las anteriores. */
class RatesTool implements Tool
{
    private const NAMES = ['bcv_usd' => 'BCV dólar', 'bcv_eur' => 'BCV euro', 'binance_usd' => 'Binance (dólar)'];

    public function name(): string
    {
        return 'tasas';
    }

    public function description(): string
    {
        return 'Las tasas de cambio que usa el sistema ahora (BCV dólar, BCV euro y Binance, en bolívares), cuándo se cambiaron por última vez y los valores anteriores de cada una.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false];
    }

    public function working(array $args): string
    {
        return 'Revisando las tasas…';
    }

    public function run(array $args): ToolResult
    {
        $bs = fn ($v) => $v !== null && $v !== '' ? 'Bs ' . number_format((float) $v, 2, ',', '.') : '—';
        $updated = Setting::get('rate_updated_at');
        $history = Currency::whereIn('description', array_keys(self::NAMES))->orderByDesc('id')->limit(60)->get(['description', 'value', 'created_at'])
            ->groupBy('description');

        $rates = [];
        foreach (self::NAMES as $key => $label) {
            $before = [];
            $last = null;
            foreach ($history[$key] ?? [] as $row) {
                // Cada vez que se guarda, se guardan las tres: solo cuentan las que cambiaron de valor
                if ($last !== null && (float) $row->value === $last) {
                    continue;
                }
                $last = (float) $row->value;
                $before[] = $bs($row->value) . ' (' . $row->created_at?->format('d/m/Y H:i') . ')';
            }
            $rates[] = ['tasa' => $label, 'ahora' => $bs(Setting::get("rate_{$key}")), 'antes' => array_slice($before, 1, 4)];
        }
        $at = $updated ? CarbonImmutable::parse($updated) : null;

        return new ToolResult([
            'tasas' => $rates,
            'ultimo_cambio' => $at ? $at->format('d/m/Y H:i') . ($at->isToday() ? ' (hoy)' : '') : 'sin dato',
        ], [
            ['type' => 'list', 'title' => 'Tasas de hoy', 'items' => array_map(fn ($r) => ['text' => "{$r['tasa']}: {$r['ahora']}"], $rates)],
            ['type' => 'link', 'label' => 'Abrir Tasa y pagos', 'to' => '/admin/tasa-y-pagos'],
        ]);
    }
}
