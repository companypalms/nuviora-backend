<?php

namespace App\Console\Commands;

use App\Services\Products\ProductMerge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `.167`: une el producto repetido que creó Shopify solo con el nuestro (el de la ficha, el stock y las campañas). Sin
 * --aplicar es un ensayo: hace todo dentro de una transacción, dice qué movería y lo deshace. Ver ProductMerge.
 *
 *   php artisan products:unir 1183 1184            (ensayo)
 *   php artisan products:unir 1183 1184 --aplicar  (de verdad)
 */
class ProductsMerge extends Command
{
    protected $signature = 'products:unir {queda : el producto que se queda (el de la ficha, el stock y las campañas)} {repetido : el que creó Shopify solo}
        {--aplicar : hacerlo de verdad (sin esto es un ensayo que no cambia nada)}';

    protected $description = 'Une un producto repetido que creó Shopify con el nuestro (ensayo sin --aplicar)';

    public function handle(ProductMerge $merge): int
    {
        $apply = (bool) $this->option('aplicar');
        try {
            $r = $merge->run((int) $this->argument('queda'), (int) $this->argument('repetido'), $apply);
        } catch (\Throwable $e) {
            $this->error('No se hizo nada: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->line($apply ? 'HECHO.' : 'ENSAYO: no se cambió nada.');
        $this->line("Se queda: #{$r['keep']['id']} {$r['keep']['title']}. Se " . ($apply ? 'borró' : 'borraría') . ": #{$r['dup']['id']} {$r['dup']['title']}.");
        $this->line('Le ' . ($apply ? 'pasó' : 'pasaría') . ' al que se queda: ' . implode(', ', array_map(fn ($k, $v) => "{$k} = {$v}", array_keys($r['product']), $r['product'])) . '.');
        foreach ($r['moved'] as $where => $n) {
            $this->line("  {$where}: {$n}");
        }
        $this->line('Pedidos que ' . ($apply ? 'pasaron' : 'pasarían') . ': ' . count($r['orders']) . ". En \"Sin Stock\": {$r['waiting']}; con el stock del que se queda, les alcanza a {$r['could_leave']}.");
        if ($apply) {
            $this->line("Recalculados: {$r['rebuilt']}. Salieron de \"Sin Stock\": {$r['recovered']} de {$r['waiting']} (los demás esperan stock en su ciudad, que abra la tienda o el horario de su ciudad).");
            Log::info('products:unir', $r);
        }
        $this->line('FIN');

        return self::SUCCESS;
    }
}
