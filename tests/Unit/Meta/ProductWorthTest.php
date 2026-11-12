<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\ProductWorth;

// .139 (dueño, 2026-11-12: "determinar si un producto es bueno, es regular y vale la pena mantenerlo y seguir renovando
// creativos, o por el contrario, si un producto es malo"). Con sus números reales de producción del 12/11 (efectividad de 4
// semanas sin los pedidos que esperaron stock): Cervimax 54 % (60 % en lo último), FlexiStep 51 % (46 %), ZenPulse 46 %
// (30 %), Comprimax 35 %. Eligió Bueno desde 55 % y Malo debajo de 45 % (Solo Brillo, la excepción, no cuenta).

function valeLaPena(int $orders, int $delivered, ?float $gain = null): array
{
    return ProductWorth::verdict(['orders' => $orders, 'delivered' => $delivered], $gain, DecisionSettings::defaults());
}

describe('.139: la etiqueta de cada producto', function () {
    it('con 50 pedidos o más: Bueno desde 55 %, Regular de 45 % a 54 %, Malo debajo de 45 %', function (int $orders, int $delivered, string $level) {
        expect(valeLaPena($orders, $delivered)['level'])->toBe($level);
    })->with([
        'Cervimax en 4 semanas (54 %)' => [100, 54, 'regular'],
        'Cervimax en lo último (60 %)' => [50, 30, 'good'],
        'justo 55 %' => [100, 55, 'good'],
        'FlexiStep en lo último (46 %)' => [50, 23, 'regular'],
        'justo 45 %' => [100, 45, 'regular'],
        'ZenPulse en lo último (30 %)' => [50, 15, 'bad'],
        'Comprimax (35 %)' => [100, 35, 'bad'],
    ]);

    it('dice la efectividad y con cuántos pedidos', function () {
        expect(valeLaPena(60, 21))->toMatchArray(['level' => 'bad', 'label' => 'Malo', 'effectiveness' => 35.0, 'orders' => 60, 'early' => false, 'missing' => 0, 'rescued' => false,
            'why' => 'Efectividad 35 % en sus últimos 60 pedidos.']);
    });

    it('"tiene que dejar una ganancia interesante como para marcarse regular": con $500 o más en 4 semanas, un Malo queda Regular', function () {
        expect(valeLaPena(100, 44, 500.0))->toMatchArray(['level' => 'regular', 'rescued' => true,
            'why' => 'Efectividad 44 % en sus últimos 100 pedidos: está debajo de 45 %, pero dejó $500,00 en 4 semanas.'])
            ->and(valeLaPena(100, 44, 499.99)['level'])->toBe('bad')
            ->and(valeLaPena(100, 44)['level'])->toBe('bad') // sin BE no se sabe lo que dejó: no se salva
            ->and(valeLaPena(100, 54, 900.0)['rescued'])->toBeFalse(); // un Regular no necesita salvarse
    });

    it('"en dos tiempos": con 30 a 49 pedidos solo es Malo si está claramente mal (debajo de 40 %)', function () {
        expect(valeLaPena(35, 15))->toMatchArray(['level' => 'regular', 'early' => true, 'missing' => 15, // 42,9 %: todavía no
            'why' => 'Efectividad 42,9 % en sus últimos 35 pedidos (primera lectura: faltan 15 pedidos para la completa).'])
            ->and(valeLaPena(30, 11)['level'])->toBe('bad') // 36,7 %
            ->and(valeLaPena(30, 17)['level'])->toBe('good') // 56,7 %
            ->and(valeLaPena(30, 11, 650.0)['level'])->toBe('regular');
    });

    it('con menos de 30 pedidos, todavía sin datos (Releev el 12/11)', function () {
        expect(valeLaPena(29, 5))->toMatchArray(['level' => 'no_data', 'label' => 'Todavía sin datos', 'missing' => 1, 'effectiveness' => 17.2,
            'why' => 'Tiene 29 pedidos que cuentan: falta 1 pedido para la primera lectura.'])
            ->and(ProductWorth::verdict(null, null, DecisionSettings::defaults()))->toMatchArray(['level' => 'no_data', 'orders' => 0, 'effectiveness' => null,
                'why' => 'Todavía no tiene pedidos que cuenten: faltan 30 pedidos para la primera lectura.']);
    });

    it('las líneas se cambian en Reglas', function () {
        $s = array_merge(DecisionSettings::defaults(), ['worth_good_pct' => 60.0, 'worth_bad_pct' => 50.0]);
        expect(ProductWorth::verdict(['orders' => 100, 'delivered' => 55], null, $s)['level'])->toBe('regular')
            ->and(ProductWorth::verdict(['orders' => 100, 'delivered' => 48], null, $s)['level'])->toBe('bad');
    });
});

describe('.139: lo que cambia con la etiqueta', function () {
    it('los encargos de los Buenos van antes que los de los Regulares (sin etiqueta, en el medio)', function () {
        expect(ProductWorth::rank('good'))->toBeLessThan(ProductWorth::rank(null))
            ->and(ProductWorth::rank(null))->toBe(ProductWorth::rank('no_data'))
            ->and(ProductWorth::rank('no_data'))->toBeLessThan(ProductWorth::rank('regular'));
    });

    it('un Malo frena sus encargos nuevos, salvo con "No estoy de acuerdo" de los últimos 14 días', function () {
        $bad = valeLaPena(100, 30);
        expect(ProductWorth::stops($bad, 'normal', [], 7, '2026-11-12'))->toBeTrue()
            ->and(ProductWorth::stops($bad, 'normal', ['7:worth_bad' => '2026-11-01'], 7, '2026-11-12'))->toBeFalse()
            ->and(ProductWorth::stops($bad, 'normal', ['7:worth_bad' => '2026-10-28'], 7, '2026-11-12'))->toBeTrue() // pasaron los 14 días
            ->and(ProductWorth::stops($bad, 'normal', ['8:worth_bad' => '2026-11-11'], 7, '2026-11-12'))->toBeTrue() // el de otro producto
            ->and(ProductWorth::stops($bad, 'liquidation', [], 7, '2026-11-12'))->toBeFalse() // en liquidación ya no recibe encargos
            ->and(ProductWorth::stops(valeLaPena(100, 50), 'normal', [], 7, '2026-11-12'))->toBeFalse();
    });
});
