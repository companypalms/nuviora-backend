<?php

namespace App\Services\Editors;

use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

/**
 * `.153` — la comisión del líder de editores. PHP puro, para probarlo sin la aplicación.
 *
 * Reglas del dueño (2026-11-17):
 * - "le pago una comisión en base a las ganancias… de la ganancia total descuento un 10 % (para prevenir gastos que no he
 *   colocado, márgenes de error, etc) y del resultado le pago 1 %": la ganancia neta de los productos de su grupo (la de
 *   Reportes del negocio), − 10 %, × 1 %. Los productos que pierden restan; una quincena negativa es $0 y no deja deuda.
 * - "no lo vamos a desarrollar por creativo, sino las ganancias que producen los productos que le son asignados a él y a
 *   su grupo": cada producto es de un solo grupo (products.editor_leader_id).
 * - "el 15 le paso del 1 al 15, y el 30 le pago del 15 al 30": las quincenas son del 1 al 15 y del 16 al último día; se
 *   pagan el 15 y el último (un domingo se adelanta al sábado, como todos los pagos del calendario).
 * - "solo su comisión… no se le explica de ninguna manera de dónde se obtiene ese número… todo como si fuera un juego": el
 *   líder ve el monto, una barra contra lo que cobró la quincena anterior y el día que cobra.
 *
 * El día de pago, de madrugada, la quincena se congela: lo que se paga ya no se mueve. Lo que esa quincena todavía suma
 * después (los pedidos que se entregan más tarde, lo del mismo día de pago) se le suma a la siguiente ("carry").
 *
 * `.157` (dueño, 2026-11-18: "yo al editor ya le pagué su comisión hasta el 13 de este mes. Para el último me toca pagarle
 * desde el 14 hasta el último"): se cuenta desde un día cualquiera ("since"). Si cae a mitad de una quincena, esos días se
 * suman a la quincena siguiente: el primer pago (30/11) cubre del 14 al 30.
 */
final class CommissionRules
{
    public const SETTING = 'finance.editor_leader_commission';

    /** `.157`: el 1–13/11 ya se lo pagó el dueño a mano: cuenta desde el 14/11 (el 14 y el 15 van con el pago del 30/11). */
    public const DEFAULTS = ['reserve_pct' => 10.0, 'pct' => 1.0, 'since' => '2026-11-14'];

    /** Lo que se le muestra al líder unos días después de cobrar. */
    public const PAID_NOTICE_DAYS = 3;

    /** Los ajustes guardados, completos y en su rango. */
    public static function settings(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $reserve = is_numeric($saved['reserve_pct'] ?? null) ? (float) $saved['reserve_pct'] : self::DEFAULTS['reserve_pct'];
        $pct = is_numeric($saved['pct'] ?? null) ? (float) $saved['pct'] : self::DEFAULTS['pct'];
        $since = is_string($saved['since'] ?? null) && strtotime($saved['since']) !== false ? $saved['since'] : self::DEFAULTS['since'];

        return [
            'reserve_pct' => round(min(90.0, max(0.0, $reserve)), 2),
            'pct' => round(min(100.0, max(0.0, $pct)), 2),
            // .157: desde ese día (si cae a mitad de una quincena, esos días van con la siguiente: first)
            'since' => CarbonImmutable::parse($since)->toDateString(),
        ];
    }

    /**
     * `.157`: el primer periodo que se paga con el sistema. Si "since" es el primer día de una quincena, es esa quincena; si
     * cae a mitad, va de "since" al final de la quincena siguiente (con su día de pago).
     *
     * @return array{start: string, end: string, payday: string}
     */
    public static function first(string $since): array
    {
        $q = self::quincena($since);

        return $q['start'] === $since ? $q : ['start' => $since] + self::next($q);
    }

    /**
     * `.157`: el periodo que se paga en la quincena de un día (por su último día), o null si esa quincena no se paga con el
     * sistema (antes de "since", o los días que se suman al primer pago).
     *
     * @return ?array{start: string, end: string, payday: string}
     */
    public static function period(string $date, string $since): ?array
    {
        $q = self::quincena($date);
        $first = self::first($since);

        return match (true) {
            $q['end'] < $first['end'] => null,
            $q['end'] === $first['end'] => $first,
            default => $q,
        };
    }

    /**
     * La quincena de un día: del 1 al 15 o del 16 al último. "payday" es el día que se paga (el 15 o el último; un
     * domingo pasa al sábado). El calendario marca el pago con el último día de la quincena ("end").
     *
     * @return array{start: string, end: string, payday: string}
     */
    public static function quincena(string $date): array
    {
        $d = CarbonImmutable::parse($date)->startOfDay();
        $month = $d->startOfMonth();
        [$start, $end] = $d->day <= 15
            ? [$month, $month->addDays(14)]
            : [$month->addDays(15), $month->endOfMonth()->startOfDay()];

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'payday' => Schedule::shift($end, 'twice_monthly', 'payment')->toDateString(),
        ];
    }

    /** @return array{start: string, end: string, payday: string} */
    public static function next(array $q): array
    {
        return self::quincena(CarbonImmutable::parse($q['end'])->addDay()->toDateString());
    }

    /** @return array{start: string, end: string, payday: string} */
    public static function previous(array $q): array
    {
        return self::quincena(CarbonImmutable::parse($q['start'])->subDay()->toDateString());
    }

    /** La comisión de una ganancia: − el descuento, × el %. Negativa, $0. */
    public static function own(float $profit, float $reservePct, float $pct): float
    {
        return round(max(0.0, $profit * (1 - $reservePct / 100)) * $pct / 100, 2);
    }

    /** Lo que se paga: la comisión de la quincena más lo que creció la anterior. Nunca negativo (no queda deuda). */
    public static function amount(float $own, float $carry): float
    {
        return round(max(0.0, $own + $carry), 2);
    }

    /**
     * La barra: se llena contra lo que cobró la quincena anterior y está "beat" al pasarla. Sin quincena anterior (o si
     * fue $0), se llena con los días que ya pasaron de la quincena.
     *
     * @return array{fill: float, beat: bool, target: string, days_left: int}
     */
    public static function bar(float $amount, ?float $previous, array $q, string $today): array
    {
        $start = CarbonImmutable::parse($q['start']);
        $payday = CarbonImmutable::parse($q['payday']);
        $daysLeft = max(0, (int) round(CarbonImmutable::parse($today)->diffInDays($payday, false)));
        if ($previous !== null && $previous > 0) {
            return ['fill' => round(min(1.0, $amount / $previous), 4), 'beat' => $amount > $previous, 'target' => 'previous', 'days_left' => $daysLeft];
        }
        $total = max(1, (int) round($start->diffInDays($payday)) + 1);
        $gone = min($total, max(0, (int) round($start->diffInDays(CarbonImmutable::parse($today))) + 1));

        return ['fill' => round($gone / $total, 4), 'beat' => false, 'target' => 'days', 'days_left' => $daysLeft];
    }

    /**
     * La línea verde de arriba (una sola): hoy cobra, ya cobró (unos días) o ya pasó la quincena anterior.
     *
     * @param ?array{amount: float, payday: string, paid: bool} $last la última quincena congelada
     * @return ?array{tone: string, text: string}
     */
    public static function notice(?array $last, bool $beat, string $today): ?array
    {
        if ($last !== null && $last['paid'] && $last['payday'] <= $today
            && CarbonImmutable::parse($last['payday'])->addDays(self::PAID_NOTICE_DAYS)->toDateString() >= $today) {
            return ['tone' => 'green', 'text' => '¡Cobrada! ' . self::money($last['amount'])];
        }
        if ($last !== null && $last['payday'] === $today) {
            return ['tone' => 'green', 'text' => 'Hoy cobras ' . self::money($last['amount'])];
        }

        return $beat ? ['tone' => 'green', 'text' => '¡Ya pasaste la quincena anterior!'] : null;
    }

    /** "lun 30/11". */
    public static function dayLabel(string $ymd): string
    {
        $d = CarbonImmutable::parse($ymd);

        return mb_substr(Schedule::WEEKDAYS[$d->dayOfWeekIso], 0, 3) . ' ' . $d->format('j/n');
    }

    /** "Del 16/11 al 30/11". */
    public static function periodLabel(array $q): string
    {
        return 'Del ' . CarbonImmutable::parse($q['start'])->format('j/n') . ' al ' . CarbonImmutable::parse($q['end'])->format('j/n');
    }

    /** "$1.234,50". */
    public static function money(float $value): string
    {
        return '$' . number_format($value, 2, ',', '.');
    }
}
