<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentAccounts;
use Illuminate\Console\Command;

/**
 * Varias cuentas de pago móvil (`.60`): las órdenes abiertas con pago móvil que no tienen cuenta (las de antes de
 * publicar) o que la tienen desactivada (el banco la bloqueó) reciben una, repartidas igual que las nuevas (la que menos
 * Bs lleva hoy). Con --dry solo muestra lo que haría.
 */
class AssignPaymentAccounts extends Command
{
    protected $signature = 'orders:assign-payment-accounts {--days=30 : las órdenes creadas en estos últimos días} {--dry : solo mostrar, no cambiar nada}';

    protected $description = 'Da su cuenta de pago móvil a las órdenes abiertas que no la tienen o la tienen desactivada (con --dry solo muestra)';

    public function handle(PaymentAccounts $accounts): int
    {
        $dry = (bool) $this->option('dry');
        $days = max(1, (int) $this->option('days'));
        $candidates = $accounts->candidates();
        if ($candidates->isEmpty()) {
            $this->warn('No hay cuentas de pago móvil activas que reciban órdenes (Tasa y pagos › Cuentas). No se hizo nada.');

            return self::SUCCESS;
        }

        $rows = $accounts->backfill($days, !$dry);
        $this->info(($dry ? 'SIMULACRO (no cambia nada) · ' : '') . 'CUENTAS DE PAGO MÓVIL · órdenes abiertas de los últimos ' . $days . ' días: ' . count($rows));
        foreach ($rows as $r) {
            $this->line(str_pad((string) $r['order']->name, 10) . ' ' . str_pad((string) $r['order']->status?->description, 26)
                . ($r['from'] ? "«{$r['from']->name}» (desactivada) → " : '') . '«' . ($r['to']?->name ?? '—') . '»');
        }
        $by = collect($rows)->groupBy(fn ($r) => $r['to']?->name ?? '—')->map->count();
        foreach ($by as $name => $n) {
            $this->line("  {$name}: {$n}");
        }
        if ($dry && $rows) {
            $this->line('Para hacerlo de verdad, el mismo comando sin --dry.');
        }

        return self::SUCCESS;
    }
}
