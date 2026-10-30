<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancialCpa;
use Illuminate\Console\Command;

/** CPA objetivo y break-even reales con datos maduros: cada día a las 06:30, antes del análisis de campañas (2026-10-30). */
class FinanceCpa extends Command
{
    protected $signature = 'finance:cpa';

    protected $description = 'Calcula el CPA objetivo y el break-even reales (con gastos fijos) por producto y ciudad';

    public function handle(FinancialCpa $cpa): int
    {
        [$from, $to] = FinancialCpa::window();
        [$longFrom] = FinancialCpa::window(null, FinancialCpa::longDays());
        $this->info("Cohortes maduras {$from->toDateString()} y {$longFrom->toDateString()} a {$to->toDateString()}: " . $cpa->compute() . ' versiones nuevas.');

        return self::SUCCESS;
    }
}
