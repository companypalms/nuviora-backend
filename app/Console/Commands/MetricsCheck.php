<?php

namespace App\Console\Commands;

use App\Services\Metrics\NumbersCheck;
use App\Services\Metrics\Period;
use App\Services\Metrics\Rules\CheckRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * La revisión de los números del panel del dueño (`.48a`), en texto, para pegarla en la consola. Solo lee: no cambia
 * nada en la base.
 */
class MetricsCheck extends Command
{
    protected $signature = 'metrics:check {--from= : desde (AAAA-MM-DD); sin esto, el mes pasado} {--to= : hasta (AAAA-MM-DD)}';

    protected $description = 'Revisa que los números del Panel del dueño sean correctos: los compara con un segundo cálculo y lista los datos que pueden hacerlos fallar (solo lectura)';

    public function handle(NumbersCheck $check): int
    {
        $now = CarbonImmutable::now();
        try {
            $period = $this->option('from') || $this->option('to')
                ? Period::fromPreset('custom', $this->option('from'), $this->option('to') ?: $now->toDateString(), $now)
                : Period::fromPreset('last_month', null, null, $now);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $r = $check->run($period);
        $money = fn (float $v) => ($v < 0 ? '-' : '') . '$' . number_format(abs($v), 2, ',', '.');
        $value = fn (array $c, $v) => $c['format'] === 'money' ? $money((float) $v) : number_format((int) $v, 0, ',', '.');

        $this->line('');
        $this->info("REVISIÓN DE LOS NÚMEROS · del {$r['period']['from']} al {$r['period']['to']} (pedidos creados esos días, sin filtros)");

        $o = $r['orders'];
        $this->line('');
        $this->line('1. Cada pedido, guardado contra calculado ahora');
        $this->line("   Revisados: {$o['checked']} · recalculándose: {$o['pending']} · con diferencias: {$o['different']}"
            . ($o['different'] ? ' (cambiaría la ganancia en ' . $money($o['profit_change']) . ')' : ''));
        foreach ($o['examples'] as $e) {
            $this->line("   ✗ {$e['order']}: " . implode(', ', $e['fields']) . ' (' . $money($e['profit_change']) . ')');
        }

        $this->line('');
        $this->line('2. Cada número del Panel, por un segundo camino');
        foreach ($r['comparisons'] as $c) {
            $this->line('   ' . ($c['ok'] ? '✔' : '✗') . ' ' . mb_str_pad($c['label'], 24) . ' Panel ' . mb_str_pad($value($c, $c['panel']), 14)
                . ' Segundo camino ' . mb_str_pad($value($c, $c['other']), 14) . ($c['ok'] ? '' : 'diferencia ' . $value($c, $c['diff'])));
        }

        $this->line('');
        $this->line('3. Datos que pueden hacer que un número salga mal');
        if ($r['problems'] === []) {
            $this->line('   Ninguno.');
        }
        $mark = [CheckRules::GRAVE => '✗ GRAVE', CheckRules::WARNING => '! aviso', CheckRules::INFO => '· info'];
        foreach ($r['problems'] as $p) {
            $this->line("   {$mark[$p['severity']]} · {$p['count']}" . ($p['amount'] !== null ? ' (' . $money($p['amount']) . ')' : '') . " · {$p['text']}");
            if ($p['examples']) {
                $this->line('       Ej.: ' . implode('; ', $p['examples']));
            }
        }

        $this->line('');
        $r['verdict']['ok'] ? $this->info('RESULTADO: ' . $r['verdict']['text']) : $this->warn('RESULTADO: ' . $r['verdict']['text']);

        return self::SUCCESS;
    }
}
