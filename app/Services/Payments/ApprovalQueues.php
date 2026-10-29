<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\ReceiptCheck;
use App\Models\SalesGroup;
use App\Services\Orders\ChangeRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las tres colas de "Por aprobar" en un solo lugar: comprobantes que la IA no pudo confirmar, vueltos de agencia por
 * validar y vueltos por pagar. Las usan sus páginas y el calendario del dueño (cuántos hay hoy), así los números son
 * siempre los mismos.
 */
class ApprovalQueues
{
    /** No cuadra, no se lee, dudoso o no se pudo revisar. */
    public const RECEIPT_STATUSES = [ReceiptCheck::FAIL, ReceiptCheck::UNREADABLE, ReceiptCheck::WARNING, ReceiptCheck::ERROR];

    /** Un vuelto por validar deja de esperar cuando la orden se cierra. */
    public const CHANGE_CLOSED = ['Entregado', 'Cancelado', 'Rechazado'];

    /** Comprobantes sin revisar de las últimas 2 semanas, de órdenes que no se cancelaron ni se rechazaron. */
    public function receipts(): Builder
    {
        return ReceiptCheck::whereIn('status', self::RECEIPT_STATUSES)
            ->whereNull('reviewed_at')
            ->where('created_at', '>=', now()->subDays(14))
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('status', fn ($s) => $s->whereIn('description', ['Cancelado', 'Rechazado'])));
    }

    /**
     * Las órdenes que pueden tener un vuelto de agencia por validar (pago mixto, la agencia da vuelto, orden abierta). Cuáles
     * lo esperan de verdad lo dice ChangeRules::needsApproval (si ya se validó para estos mismos pagos, no).
     */
    public function changesToValidate(): Builder
    {
        return Order::query()
            ->whereIn('change_covered_by', ['agency', 'partial'])
            ->where('change_amount_agency', '>', 0)
            ->whereHas('payments', fn ($q) => $q->whereIn('method', ChangeRules::CASH_METHODS))
            ->whereHas('payments', fn ($q) => $q->whereNotIn('method', ChangeRules::CASH_METHODS))
            ->whereDoesntHave('status', fn ($q) => $q->whereIn('description', self::CHANGE_CLOSED));
    }

    /**
     * Vueltos por pagar: órdenes entregadas con vuelto a cargo de la empresa que todavía no tienen el comprobante del vuelto.
     * Con un grupo, solo las de sus vendedoras (lo que ve la líder).
     */
    public function vueltosToPay(?SalesGroup $group = null): Builder
    {
        return Order::whereHas('status', fn ($q) => $q->where('description', 'Entregado'))
            ->whereIn('change_covered_by', ['company', 'partial'])
            ->where('change_amount_company', '>', 0)
            ->where(function ($q) {
                // No tiene extra o tiene extra pero sin recibo
                $q->whereDoesntHave('changeExtra')
                    ->orWhereHas('changeExtra', fn ($sq) => $sq->whereNull('change_receipt')->orWhere('change_receipt', ''));
            })
            ->when($group, fn ($q) => $q->whereIn('agent_id', $group->openMembers()->pluck('user_id')));
    }

    /** Cuántos hay en cada cola ahora (lo que ve administración). */
    public function counts(): array
    {
        return [
            'receipts' => $this->receipts()->count(),
            'to_validate' => $this->changesToValidate()->get()->filter(fn (Order $o) => ChangeRules::needsApproval($o))->count(),
            'to_pay' => $this->vueltosToPay()->count(),
        ];
    }
}
