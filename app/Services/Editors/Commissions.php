<?php

namespace App\Services\Editors;

use App\Models\CalendarMark;
use App\Models\EditorCommission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Metrics\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * `.153` — la comisión del líder de editores (las reglas, en CommissionRules): la quincena que va corriendo, el congelado
 * del día de pago y lo que lee el recordatorio "Pagar la comisión del líder de editores" del calendario del dueño.
 *
 * Pagada = el dueño tildó ese recordatorio (CalendarMark "hecho" del último día de la quincena).
 */
class Commissions
{
    /** La ganancia de la quincena es pesada (todos los pedidos): se guarda unos minutos. */
    public const CACHE_SECONDS = 600;

    /** El recordatorio del calendario del dueño (Calendar\Rules\ReminderRules). */
    public const REMINDER = 'editor_leader_pay';

    /** @var array<string, array<int, float>> la ganancia por producto de cada rango, mientras dura el pedido */
    private array $profits = [];

    public function __construct(private CommissionFacts $facts)
    {
    }

    public static function settings(): array
    {
        return CommissionRules::settings(Setting::get(CommissionRules::SETTING));
    }

    /** Guarda el descuento y el %; "desde cuándo" no se cambia. */
    public static function saveSettings(float $reservePct, float $pct): void
    {
        $s = self::settings();
        Setting::set(CommissionRules::SETTING, CommissionRules::settings(['reserve_pct' => $reservePct, 'pct' => $pct, 'since' => $s['since']]));
    }

    /** @return Collection<int, User> los "Editor líder" activos */
    public static function leaders(): Collection
    {
        return User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->where('description', EditorRules::LEADER))
            ->orderBy('id')->get(['id', 'names', 'surnames']);
    }

    /** El único líder de editores, si hay uno solo (a su grupo van los productos nuevos). */
    public static function onlyLeader(): ?int
    {
        if (!Schema::hasTable('roles') || !Schema::hasColumn('users', 'deactivated_at')) {
            return null;
        }
        $ids = self::leaders()->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** @return list<int> los productos de su grupo */
    public static function productIds(int $leaderId): array
    {
        return Product::where('editor_leader_id', $leaderId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Congela cada quincena cuyo día de pago ya llegó (desde "since"), de cada líder. Lo hace `editors:comision` de
     * madrugada y, por si no corrió, la primera lectura. No vuelve a congelar una que ya está.
     *
     * @return int cuántas congeló
     */
    public function freeze(CarbonImmutable $now): int
    {
        $s = self::settings();
        $today = $now->toDateString();
        $n = 0;
        foreach (self::leaders() as $leader) {
            $last = $this->lastFrozen($leader->id);
            $q = $last ? CommissionRules::next(self::range($last)) : CommissionRules::first($s['since']);
            for (; $q['payday'] <= $today; $q = CommissionRules::next($q)) {
                $this->freezeOne($leader->id, $q, $s, $now);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Lo que ve el líder: su comisión de la quincena que va corriendo (con lo que creció la anterior), la barra contra la
     * anterior, el día que cobra y la línea verde. Sin la ganancia ni el %.
     */
    public function current(User $leader, CarbonImmutable $now): array
    {
        $this->freeze($now);
        $today = $now->toDateString();
        $last = $this->lastFrozen($leader->id);
        $q = $last ? CommissionRules::next(self::range($last)) : CommissionRules::first(self::settings()['since']);
        $amount = $q['start'] > $today ? 0.0 : $this->live($leader->id, $q, $last, $now);
        $previous = $last?->amount_usd;
        $bar = CommissionRules::bar($amount, $previous, $q, $today);
        $lastRow = $last ? ['amount' => $last->amount_usd, 'payday' => $last->payday->toDateString(), 'paid' => $this->paid($last->period_end->toDateString())] : null;

        return [
            'amount' => $amount,
            'start' => $q['start'],
            'end' => $q['end'],
            'payday' => $q['payday'],
            'payday_label' => CommissionRules::dayLabel($q['payday']),
            'previous' => $previous,
            'notice' => CommissionRules::notice($lastRow, $bar['beat'], $today),
        ] + $bar;
    }

    /**
     * Lo que dice el recordatorio del dueño de una quincena (por su último día): cada líder con su monto (congelado, o lo
     * de hasta ahora) y el periodo que se paga (`.157`: el primero puede empezar a mitad de la quincena anterior), o null si
     * esa quincena no se paga con el sistema.
     *
     * @return ?list<array{id: int, name: string, amount: ?float, frozen: bool, start: string, end: string}>
     */
    public function forReminder(string $due, CarbonImmutable $now): ?array
    {
        $since = self::settings()['since'];
        $q = CommissionRules::period($due, $since);
        if ($q === null) {
            return null;
        }
        $this->freeze($now);
        $rows = [];
        foreach (self::leaders() as $leader) {
            $name = PersonName::of($leader->names, $leader->surnames) ?: "Usuario {$leader->id}";
            $frozen = EditorCommission::where('user_id', $leader->id)->whereDate('period_start', $q['start'])->first();
            if ($frozen) {
                $rows[] = ['id' => $leader->id, 'name' => $name, 'amount' => $frozen->amount_usd, 'frozen' => true, 'start' => $q['start'], 'end' => $q['end']];
                continue;
            }
            $last = $this->lastFrozen($leader->id);
            $isNext = $last ? CommissionRules::next(self::range($last))['start'] === $q['start'] : $q['start'] === CommissionRules::first($since)['start'];
            $amount = $q['start'] <= $now->toDateString() && $isNext ? $this->live($leader->id, $q, $last, $now) : null;
            $rows[] = ['id' => $leader->id, 'name' => $name, 'amount' => $amount, 'frozen' => false, 'start' => $q['start'], 'end' => $q['end']];
        }

        return $rows;
    }

    /** Si el dueño ya tildó el pago de esa quincena (por su último día). */
    public function paid(string $end): bool
    {
        return CalendarMark::where('item_key', 'rule:' . self::REMINDER)->whereDate('occurs_on', $end)->where('status', 'done')->exists();
    }

    /** La comisión de hasta ahora de una quincena que no se congeló: la suya más lo que creció la anterior. */
    private function live(int $leaderId, array $q, ?EditorCommission $last, CarbonImmutable $now): float
    {
        $s = self::settings();
        $ids = self::productIds($leaderId);
        $key = 'editor-commission:' . implode(':', [$leaderId, $q['start'], $last?->id ?? 0, $s['reserve_pct'], $s['pct'], md5(implode(',', $ids))]);

        return (float) Cache::remember($key, self::CACHE_SECONDS, function () use ($ids, $q, $last, $s, $now) {
            $own = CommissionRules::own($this->profit($ids, $q, $now), $s['reserve_pct'], $s['pct']);

            return CommissionRules::amount($own, $last ? $this->carry($last, $ids, $now) : 0.0);
        });
    }

    private function freezeOne(int $leaderId, array $q, array $s, CarbonImmutable $now): void
    {
        $ids = self::productIds($leaderId);
        $profit = $this->profit($ids, $q, $now);
        $own = CommissionRules::own($profit, $s['reserve_pct'], $s['pct']);
        // .157: la anterior es la que terminó el día antes (la primera puede haber empezado a mitad de una quincena)
        $prev = EditorCommission::where('user_id', $leaderId)->whereDate('period_end', CarbonImmutable::parse($q['start'])->subDay()->toDateString())->first();
        $carry = $prev ? $this->carry($prev, $ids, $now) : 0.0;
        EditorCommission::create([
            'user_id' => $leaderId, 'period_start' => $q['start'], 'period_end' => $q['end'], 'payday' => $q['payday'],
            'profit_usd' => $profit, 'reserve_pct' => $s['reserve_pct'], 'pct' => $s['pct'],
            'own_usd' => $own, 'carry_usd' => $carry, 'amount_usd' => CommissionRules::amount($own, $carry), 'frozen_at' => $now,
        ]);
    }

    /** Lo que creció una quincena congelada desde que se congeló (con su descuento y su % de ese día). */
    private function carry(EditorCommission $prev, array $ids, CarbonImmutable $now): float
    {
        $final = CommissionRules::own($this->profit($ids, self::range($prev), $now), $prev->reserve_pct, $prev->pct);

        return round($final - $prev->own_usd, 2);
    }

    private function profit(array $ids, array $q, CarbonImmutable $now): float
    {
        if ($ids === []) {
            return 0.0;
        }
        $key = $q['start'] . '|' . $q['end'];
        $this->profits[$key] ??= $this->facts->profitByProduct($q['start'], $q['end'], $now);

        return round(array_sum(array_intersect_key($this->profits[$key], array_flip($ids))), 2);
    }

    private function lastFrozen(int $leaderId): ?EditorCommission
    {
        return EditorCommission::where('user_id', $leaderId)->orderByDesc('period_start')->first();
    }

    /** @return array{start: string, end: string, payday: string} */
    private static function range(EditorCommission $c): array
    {
        return ['start' => $c->period_start->toDateString(), 'end' => $c->period_end->toDateString(), 'payday' => $c->payday->toDateString()];
    }
}
