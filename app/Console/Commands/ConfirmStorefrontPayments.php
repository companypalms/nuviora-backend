<?php

namespace App\Console\Commands;

use App\Services\Storefront\PrepaidMessages;
use Illuminate\Console\Command;

/**
 * `.168`: cada minuto, lo que le toca escribirle al cliente que pagó por adelantado en el formulario de la tienda (el aviso
 * de fuera de horario y el "ya confirmamos tu pago"; ver PrepaidMessages). Con --dry-run solo dice qué haría, sin mandar
 * nada ni cambiar nada. No muestra nombres ni teléfonos.
 */
class ConfirmStorefrontPayments extends Command
{
    protected $signature = 'tienda:confirmar-pagos {--dry-run : Solo mostrar lo que haría, sin mandar nada}';

    protected $description = 'Formulario de la tienda: aviso de fuera de horario y "ya confirmamos tu pago" a quien pagó por adelantado';

    public function handle(PrepaidMessages $messages): int
    {
        $r = $messages->run((bool) $this->option('dry-run'));
        foreach ($r['items'] as $line) {
            $this->line("- {$line}");
        }
        $this->line("Avisos fuera de horario: {$r['ack']} | confirmados: {$r['confirmed']} | para la vendedora: {$r['seller']} | esperando: {$r['waiting']}");

        return self::SUCCESS;
    }
}
