<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\WhatsApp\DeliveryPdfSender;
use Illuminate\Console\Command;

/**
 * `.106`: cada minuto, el PDF de los productos a los clientes cuyo pedido se entregó hace 10 minutos o más (ver
 * DeliveryPdfSender). Con --dry-run solo dice qué mandaría y quién espera por la ventana cerrada: no manda nada
 * (sirve como comando de solo lectura en producción). No muestra nombres ni teléfonos.
 */
class SendDeliveryPdfs extends Command
{
    protected $signature = 'whatsapp:delivery-pdfs {--dry-run : Solo mostrar lo que se mandaría, sin mandar nada}';

    protected $description = 'Manda por WhatsApp el PDF del producto al cliente 10 min después de Entregado (ventana de 24 h abierta, una vez por cliente y producto)';

    public function handle(DeliveryPdfSender $sender): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $sender->run($dry);
        if ($dry) {
            $products = Product::whereNotNull('delivery_pdf_path')->get();
            $this->line('== SOLO LECTURA: PDF AL ENTREGAR ==');
            foreach ($products as $p) {
                $sent = \App\Models\DeliveryPdfSend::where('product_id', $p->id)->where('status', 'sent');
                $this->line("- {$p->showable_name} ({$p->delivery_pdf_name}) | desde " . $p->delivery_pdf_since?->format('d/m H:i')
                    . ' | ya lo recibieron: ' . (clone $sent)->count() . ' clientes, hoy ' . (clone $sent)->whereDate('sent_at', today())->count());
            }
            if ($products->isEmpty()) {
                $this->line('Ningún producto tiene PDF cargado.');
            }
        }
        foreach ($result['items'] as $i) {
            $this->line("- orden {$i['order_id']} | producto {$i['product_id']} | {$i['result']}");
        }
        $this->line("Enviados: {$result['sent']} | fallidos: {$result['failed']} | esperando (ventana cerrada): {$result['waiting']}");

        return self::SUCCESS;
    }
}
