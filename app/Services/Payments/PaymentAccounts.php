<?php

namespace App\Services\Payments;

use App\Constants\OrderStatus;
use App\Models\CompanyAccount;
use App\Models\Order;
use App\Models\OrderActivityLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Varias cuentas de pago móvil, una por orden (`.60`, pedido del dueño del 2026-10-31: el banco le pone problemas si todo
 * entra a una sola cuenta). Cuando la vendedora registra un pago por Pago Móvil, la orden recibe su cuenta
 * (PaymentAccountRules: la que menos Bs lleva hoy) y se queda con ella; el Admin la puede cambiar a mano. La plantilla
 * que copia la agencia y el cuadro de cuentas de la orden muestran esa.
 */
class PaymentAccounts
{
    /** El método de pago de la orden y el de la cuenta (Tasa y pagos › Cuentas). */
    public const METHOD = 'PAGOMOVIL';
    public const KIND = 'pago_movil';

    /** Las órdenes que ya no van a cobrar: no reciben cuenta y no cuentan en la carga del día. */
    public const CLOSED = [OrderStatus::ENTREGADO, OrderStatus::CANCELADO, OrderStatus::RECHAZADO];

    /** Las cuentas que pueden recibir órdenes nuevas: de pago móvil, activas y sin pausar. */
    public function candidates(): Collection
    {
        return CompanyAccount::where('method', self::KIND)->where('is_active', true)->where('takes_orders', true)->orderBy('id')->get();
    }

    /**
     * Lo que lleva hoy cada cuenta de pago móvil: las órdenes que la recibieron hoy (sin las canceladas ni rechazadas) y
     * lo que pagan por pago móvil, en Bs.
     * @return array<int, array{orders: int, bs: float}> por id de cuenta
     */
    public function today(): array
    {
        $rows = DB::table('orders')
            ->leftJoin('statuses', 'statuses.id', '=', 'orders.status_id')
            ->leftJoin('order_payments', function ($j) {
                $j->on('order_payments.order_id', '=', 'orders.id')->where('order_payments.method', self::METHOD);
            })
            ->whereNotNull('orders.payment_account_id')
            ->whereBetween('orders.payment_account_at', [now()->startOfDay(), now()->endOfDay()])
            ->where(fn ($q) => $q->whereNull('statuses.description')->orWhereNotIn('statuses.description', [OrderStatus::CANCELADO, OrderStatus::RECHAZADO]))
            ->groupBy('orders.payment_account_id')
            ->selectRaw('orders.payment_account_id AS id, COUNT(DISTINCT orders.id) AS orders,
                COALESCE(SUM(order_payments.amount * COALESCE(NULLIF(order_payments.rate, 0), order_payments.binance_usd_rate, 0)), 0) AS bs')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = ['orders' => (int) $r->orders, 'bs' => round((float) $r->bs, 2)];
        }

        return $out;
    }

    /** @return array<int, array{id: int, bs: float, orders: int, last_at: ?string}> */
    private function load(Collection $accounts): array
    {
        $today = $this->today();
        $last = DB::table('orders')->whereIn('payment_account_id', $accounts->pluck('id'))->groupBy('payment_account_id')
            ->selectRaw('payment_account_id AS id, MAX(payment_account_at) AS last_at')->pluck('last_at', 'id');

        return $accounts->map(fn (CompanyAccount $a) => [
            'id' => $a->id, 'bs' => $today[$a->id]['bs'] ?? 0.0, 'orders' => $today[$a->id]['orders'] ?? 0, 'last_at' => $last[$a->id] ?? null,
        ])->values()->all();
    }

    /** Si la orden necesita cuenta: tiene un pago por pago móvil, todavía no la tiene y no está cerrada. */
    public function needsAccount(Order $order): bool
    {
        return !$order->payment_account_id
            && $order->payments()->where('method', self::METHOD)->exists()
            && !in_array($order->status?->description, self::CLOSED, true);
    }

    /**
     * Le da su cuenta a la orden si la necesita (se llama al guardar los pagos). De a una a la vez, para que dos órdenes
     * al mismo tiempo no lean la misma carga.
     */
    public function ensure(Order $order): ?CompanyAccount
    {
        $order->loadMissing('status');
        if (!$this->needsAccount($order)) {
            return $order->payment_account_id ? CompanyAccount::find($order->payment_account_id) : null;
        }

        return Cache::lock('payment-account-pick', 10)->block(5, function () use ($order) {
            $order->refresh();
            if ($order->payment_account_id) {
                return CompanyAccount::find($order->payment_account_id);
            }
            $accounts = $this->candidates();
            $id = PaymentAccountRules::pick($this->load($accounts));
            if ($id === null) {
                return null;
            }
            $account = $accounts->firstWhere('id', $id);
            $this->assign($order, $account, null);

            return $account;
        });
    }

    /** El Admin cambia la cuenta de una orden a mano (queda en el historial). */
    public function change(Order $order, CompanyAccount $account, ?int $userId): void
    {
        $this->assign($order, $account, $userId, $order->payment_account_id ? CompanyAccount::find($order->payment_account_id) : null);
    }

    /**
     * El comando de una vez: las órdenes abiertas con pago móvil que no tienen cuenta (o la tienen desactivada) reciben
     * una, repartidas igual que las nuevas.
     * @return array<int, array{order: Order, from: ?CompanyAccount, to: ?CompanyAccount}>
     */
    public function backfill(int $days, bool $write): array
    {
        $accounts = $this->candidates();
        $inactive = CompanyAccount::where('method', self::KIND)->where('is_active', false)->pluck('id');
        $orders = Order::with('payments', 'status')
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->whereHas('payments', fn ($q) => $q->where('method', self::METHOD))
            ->whereDoesntHave('status', fn ($q) => $q->whereIn('description', self::CLOSED))
            ->where(fn ($q) => $q->whereNull('payment_account_id')->orWhereIn('payment_account_id', $inactive))
            ->orderBy('id')->get();

        $bs = $orders->mapWithKeys(fn (Order $o) => [$o->id => PaymentAccountRules::orderBs($o->payments)])->all();
        $picked = PaymentAccountRules::spread($this->load($accounts), $bs, now()->toDateTimeString());

        $out = [];
        foreach ($orders as $order) {
            $from = $order->payment_account_id ? CompanyAccount::find($order->payment_account_id) : null;
            $to = $picked[$order->id] ? $accounts->firstWhere('id', $picked[$order->id]) : null;
            if ($write && $to) {
                $this->assign($order, $to, null, $from);
            }
            $out[] = ['order' => $order, 'from' => $from, 'to' => $to];
        }

        return $out;
    }

    private function assign(Order $order, CompanyAccount $account, ?int $userId, ?CompanyAccount $from = null): void
    {
        $order->forceFill(['payment_account_id' => $account->id, 'payment_account_at' => now()])->saveQuietly();
        OrderActivityLog::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'action' => 'payment_account',
            'description' => $from
                ? "Cambió la cuenta de pago móvil de «{$from->name}» a «{$account->name}»"
                : "Pago móvil: se asignó la cuenta «{$account->name}»",
            'properties' => ['from' => $from?->id, 'to' => $account->id],
        ]);
    }

    /** Lo que ve el panel de la cuenta de una orden (la plantilla de la agencia y el cuadro de cuentas). */
    public static function present(?CompanyAccount $account): ?array
    {
        return $account ? [
            'id' => $account->id,
            'name' => $account->name,
            'details' => array_values(array_filter((array) $account->details, fn ($d) => is_array($d) && trim((string) ($d['value'] ?? '')) !== '')),
            'is_active' => (bool) $account->is_active,
        ] : null;
    }
}
