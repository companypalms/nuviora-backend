<?php

use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Meta\Products\ProductQueueRules;

// .154 (dueño, 2026-11-17): "quiero tener una banca de productos por montar… siempre dándole prioridad a los batch de los
// productos que están activos actualmente, pero en cuanto tenga un chance, envía cada uno de esos productos por orden de cola".

describe('Cuándo sale el siguiente', function () {
    $in = fn (array $over = []) => $over + ['need_today' => [], 'late' => 0, 'building' => null, 'next' => 20, 'waiting' => 2];

    it('sin nada urgente, nada atrasado y nada en el taller: sale', function () use ($in) {
        expect(ProductQueueRules::missing($in()))->toBe([]);
    });

    it('dice qué falta, en palabras', function () use ($in) {
        expect(ProductQueueRules::missing($in(['building' => ['product' => 'Termafit', 'delivered' => 1, 'of' => 3],
            'need_today' => ['FlexiStep en Caracas', 'Comprimax en Aragua'], 'late' => 2])))->toBe([
                'El equipo está haciendo el testeo de Termafit: 1 de 3 en la banca.',
                'Hoy necesitan un batch: FlexiStep en Caracas y Comprimax en Aragua.',
                'Hay 2 encargos atrasados.',
            ])
            ->and(ProductQueueRules::missing($in(['late' => 1])))->toBe(['Hay 1 encargo atrasado.']);
    });

    it('si a los de la cola les falta algo, lo dice', function () use ($in) {
        expect(ProductQueueRules::missing($in(['next' => null, 'waiting' => 1])))->toBe(['Al producto de la cola le falta algo: complétalo para que salga.'])
            ->and(ProductQueueRules::missing($in(['next' => null, 'waiting' => 3])))->toBe(['A todos los de la cola les falta algo: complétalos para que salgan.'])
            ->and(ProductQueueRules::missing($in(['next' => null, 'waiting' => 0])))->toBe([]);
    });
});

describe('Cuál sale', function () {
    $q = [['product_id' => 20, 'missing' => ['Los ángulos de venta (del más fuerte al más débil).']], ['product_id' => 21, 'missing' => []], ['product_id' => 22, 'missing' => []]];

    it('el primero en orden al que no le falta nada', function () use ($q) {
        expect(ProductQueueRules::pick($q, null))->toBe(21)
            ->and(ProductQueueRules::pick([$q[0]], null))->toBeNull()
            ->and(ProductQueueRules::pick([], null))->toBeNull();
    });

    it('el de la misión semanal sale primero, si no le falta nada', function () use ($q) {
        expect(ProductQueueRules::pick($q, 22))->toBe(22)
            ->and(ProductQueueRules::pick($q, 20))->toBe(21)
            ->and(ProductQueueRules::pick($q, 99))->toBe(21);
    });

    it('lo que le falta a uno: la ficha, los ángulos o que esté parado', function () {
        expect(ProductQueueRules::notReady([], true, 'normal'))->toBe([])
            ->and(ProductQueueRules::notReady(['Qué es', 'al menos un buyer persona'], false, 'paused'))->toBe([
                'En la ficha: qué es y al menos un buyer persona.',
                'Los ángulos de venta (del más fuerte al más débil).',
                'Está en pausa: actívalo en «Pausar o activar productos» para que salga.',
            ])
            ->and(ProductQueueRules::notReady([], true, 'liquidation'))->toBe(['Está en liquidación: actívalo en «Pausar o activar productos» para que salga.']);
    });
});

describe('La cola', function () {
    it('subir y bajar un lugar; en las puntas no se mueve', function () {
        expect(ProductQueueRules::move([20, 21, 22], 22, 'up'))->toBe([20, 22, 21])
            ->and(ProductQueueRules::move([20, 21, 22], 20, 'down'))->toBe([21, 20, 22])
            ->and(ProductQueueRules::move([20, 21, 22], 20, 'up'))->toBe([20, 21, 22])
            ->and(ProductQueueRules::move([20, 21, 22], 22, 'down'))->toBe([20, 21, 22])
            ->and(ProductQueueRules::move([20, 21], 99, 'up'))->toBe([20, 21]);
    });

    it('en cuántos va el taller: los de la banca y los que ya salieron cuentan como entregados', function () {
        expect(ProductQueueRules::progress(['bank', 'production'], 3))->toBe(['delivered' => 1, 'of' => 3, 'done' => false])
            ->and(ProductQueueRules::progress(['bank', 'testing', 'tested'], 3))->toBe(['delivered' => 3, 'of' => 3, 'done' => true])
            ->and(ProductQueueRules::progress([], 0))->toBe(['delivered' => 0, 'of' => 0, 'done' => false]);
    });
});

describe('Por el asistente', function () {
    $args = fn (array $over = []) => $over + ['producto' => 'Termafit', 'que_es' => 'Faja térmica reductora', 'para_que_sirve' => 'Reducir cintura',
        'como_funciona' => 'Guarda el calor y hace sudar la zona', 'lo_que_no_es' => null, 'info_clave' => null, 'fotos' => null,
        'buyer_personas' => [['nombre' => 'Mujer posparto', 'quien_es' => 'Mujer de 25 a 40', 'que_le_duele' => 'No recupera su cintura', 'que_quiere' => null, 'que_lo_frena' => null]],
        'quitar_buyer_personas' => [], 'angulos' => [['codigo' => 'POSP', 'nombre' => 'Posparto', 'hipotesis' => null, 'buyer' => 'Mujer posparto']],
        'fase' => 'cola'];

    it('un producto nuevo entra a la cola, salvo que el dueño pida empezarlo ya', function () {
        expect(ProductProfileTool::newProductPhase(null))->toBe('cola')
            ->and(ProductProfileTool::newProductPhase('sin_cambio'))->toBe('cola')
            ->and(ProductProfileTool::newProductPhase('iterar'))->toBe('cola')
            ->and(ProductProfileTool::newProductPhase('testeo'))->toBe('testeo');
    });

    it('la tarjeta dice que entra a la cola y lo que pedirá el testeo cuando salga', function () use ($args) {
        $p = ProductProfileTool::proposal(0, 'Termafit', null, [], $args(), [], 'iterate', [], ['create' => true, 'shopify' => null]);
        $lines = collect($p->lines)->keyBy('label');
        expect($p->payload['phase'])->toBe('queued')
            ->and($lines['Producto nuevo']['value'])->toBe('Se crea al confirmar y entra a tu cola de productos por montar: sale solo al editor líder cuando el equipo tenga chance, uno a la vez.')
            ->and($lines['Fase']['value'])->toBe('En cola (por montar)')
            ->and($lines->has('Cuando salga de la cola, el testeo pide'))->toBeTrue();
    });

    it('en la cola sin ángulos, avisa que no sale hasta tenerlos', function () use ($args) {
        $p = ProductProfileTool::proposal(0, 'Termafit', null, [], $args(['angulos' => []]), [], 'iterate', [], ['create' => true, 'shopify' => null]);
        expect($p->warnings)->toContain('En la cola, pero sin ángulos: no sale de la cola hasta que la ficha tenga sus ángulos.');
    });
});
