<?php

namespace App\Services\Finance;

use App\Models\Currency;
use App\Models\FinancialAuditLog;
use App\Models\Setting;
use App\Models\Status;
use App\Services\Calendar\Calendar;
use Illuminate\Support\Facades\DB;

/**
 * Las tasas del dólar (BCV en dólares y euros, y Binance): la pantalla "Tasa y pagos › Tasa del dólar" y el asistente
 * guardan con esto (salió de CurrencyController::create, igual que antes).
 * - Las tasas activas pasan a inactivas y se crean 3 filas nuevas en currencies (el historial de tasas).
 * - Se copian a los settings rate_* y rate_updated_at, que leen las órdenes, la caja y las comisiones.
 * - Desde el paso 5 del asistente: todo junto o nada (transacción), queda en el historial de Finanzas y el "Hoy" del
 *   calendario se entera enseguida (la tarea "Revisar la tasa" se tilda sola).
 */
class ExchangeRates
{
    public const KEYS = ['bcv_usd', 'bcv_eur', 'binance_usd'];

    public const LABELS = ['bcv_usd' => 'BCV dólar', 'bcv_eur' => 'BCV euro', 'binance_usd' => 'Binance'];

    /** Las tasas activas (null si no hay). @return array<string, ?Currency> */
    public function active(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = Currency::whereHas('status', fn ($s) => $s->where('description', 'Activo'))->where('description', $key)->first();
        }

        return $out;
    }

    /** Las tasas de ahora, como números (las activas; si falta alguna, la del setting). @return array<string, ?float> */
    public function current(): array
    {
        $out = [];
        foreach ($this->active() as $key => $row) {
            $value = $row?->value ?? Setting::get("rate_{$key}");
            $out[$key] = $value !== null && $value !== '' ? (float) $value : null;
        }

        return $out;
    }

    /**
     * Guarda las 3 tasas. $values: bcv_usd, bcv_eur y binance_usd (como llegaron: texto o número).
     * @return array{bcv_usd: Currency, bcv_eur: Currency, binance_usd: Currency} las filas nuevas
     */
    public function set(array $values, ?int $userId = null): array
    {
        return DB::transaction(function () use ($values, $userId) {
            $before = $this->current();
            $active = $this->active();
            $statusActive = Status::firstOrCreate(['description' => 'Activo']);
            $statusInactive = Status::firstOrCreate(['description' => 'Inactivo']);
            // Como siempre: las anteriores se desactivan solo si estaban las tres
            if ($active['bcv_usd'] && $active['bcv_eur'] && $active['binance_usd']) {
                foreach ($active as $row) {
                    $row->status()->associate($statusInactive);
                    $row->save();
                }
            }
            $created = [];
            foreach (self::KEYS as $key) {
                $created[$key] = Currency::create(['description' => $key, 'value' => $values[$key]]);
                $created[$key]->status()->associate($statusActive);
                $created[$key]->save();
            }

            // Para que las órdenes, la caja y las comisiones las vean
            foreach (self::KEYS as $key) {
                Setting::set("rate_{$key}", $values[$key]);
            }
            Setting::set('rate_updated_at', now()->toDateTimeString());

            FinancialAuditLog::record('rates_updated', 'rates', null, $before,
                array_map(fn ($v) => (float) $v, array_intersect_key($values, array_flip(self::KEYS))), null, $userId);
            Calendar::forgetToday();

            return $created;
        });
    }
}
