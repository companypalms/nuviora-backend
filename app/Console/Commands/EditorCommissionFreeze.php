<?php

namespace App\Console\Commands;

use App\Services\Editors\Commissions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `.153`: congela la comisión del líder de editores el día de pago (el 15 y el último; un domingo, el sábado), a las 00:50,
 * con el día anterior ya cerrado. Lo que se congela es lo que el dueño paga y el líder ve; lo que esa quincena todavía
 * suma después pasa a la siguiente. Solo escribe en editor_commissions; no vuelve a congelar una quincena.
 */
class EditorCommissionFreeze extends Command
{
    protected $signature = 'editors:comision';

    protected $description = 'Congela la comisión del líder de editores el día de pago';

    public function handle(Commissions $commissions): int
    {
        $n = $commissions->freeze(CarbonImmutable::now());
        $this->info("Quincenas congeladas: {$n}");

        return self::SUCCESS;
    }
}
