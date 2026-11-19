<?php

namespace App\Jobs;

use App\Models\StorefrontOrder;
use App\Services\Storefront\MetaCapi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** `.164`: manda a Meta la compra de un pedido del formulario de la tienda (API de conversiones), después de responderle al cliente. */
class SendStorefrontPurchase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $storefrontOrderId)
    {
    }

    public function handle(MetaCapi $capi): void
    {
        $front = StorefrontOrder::find($this->storefrontOrderId);
        if ($front && $capi->send($front) === 'failed' && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }
}
