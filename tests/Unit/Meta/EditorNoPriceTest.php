<?php

use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Queue\QueueRules;
use App\Services\Meta\Queue\QueueWriter;

// .69 (dueño, 2026-11-01): el editor "no debería ver el precio del producto".

describe('Las frases con precio', function () {
    it('quita cada frase que habla de un precio o de un monto, y deja lo demás', function () {
        expect(QueueRules::withoutPrices('Precio $23,14'))->toBeNull()
            ->and(QueueRules::withoutPrices('Chaleco de neopreno. Cuesta 25 dólares. Viene en tallas S a XL.'))->toBe('Chaleco de neopreno. Viene en tallas S a XL.')
            ->and(QueueRules::withoutPrices("2x1 en todas las tallas\nAhora a solo 40$"))->toBe('2x1 en todas las tallas')
            ->and(QueueRules::withoutPrices('Envío gratis; pago en Bs 900 al recibir'))->toBe('Envío gratis;')
            ->and(QueueRules::withoutPrices('El precio especial es por hoy'))->toBeNull()
            ->and(QueueRules::withoutPrices('Rebaja hasta 3 tallas en 30 días.'))->toBe('Rebaja hasta 3 tallas en 30 días.')
            ->and(QueueRules::withoutPrices('Talla 2.5 cm de grosor'))->toBe('Talla 2.5 cm de grosor')
            ->and(QueueRules::withoutPrices(null))->toBeNull();
    });

    it('en listas y textos de la referencia', function () {
        expect(QueueRules::withoutPricesIn(['Quema grasa mientras caminas', 'Solo $25, paga al recibir']))->toBe(['Quema grasa mientras caminas'])
            ->and(QueueRules::withoutPricesIn(['hook' => '¿Te sobra barriga?', 'text' => 'Hoy a 30 dólares. Pídelo ya.']))->toBe(['hook' => '¿Te sobra barriga?', 'text' => 'Pídelo ya.']);
    });
});

describe('Lo que ve el editor', function () {
    it('la ficha y el buyer persona sin precios', function () {
        $info = ProductProfileRules::forEditor(['what_it_is' => 'Chaleco térmico para rebajar.', 'key_info' => 'Precio: $30. Tallas S a XL.', 'benefits' => null]);
        expect($info)->toBe(['what_it_is' => 'Chaleco térmico para rebajar.', 'key_info' => 'Tallas S a XL.']);
        expect(ProductProfileRules::personaForEditor(['name' => 'Hombre con barriga', 'objections' => 'Cree que $30 es caro. Duda que funcione.']))
            ->toBe(['name' => 'Hombre con barriga', 'objections' => 'Duda que funcione.']);
    });

    it('el texto del encargo no trae el precio en ningún lado', function () {
        $text = QueueRules::brief(['code' => 'B0200', 'product' => 'Comprimax', 'kind' => 'new_concept', 'reference' => null, 'angle_code' => null, 'angle_name' => null,
            'product_info' => ['what_it_is' => 'Chaleco térmico. Cuesta 30$.', 'key_info' => 'Precio $30'],
            'persona' => ['name' => 'Hombre con barriga', 'who' => 'Hombre de 35 a 50 años'],
            'goal' => 'Que se note el sudor. Mostrar que vale 30 dólares.', 'offer' => 'Precio $30,00', 'format' => 'video', 'style' => null,
            'pieces' => [['code' => 'C0900', 'change' => 'Abrir con el precio: solo $30', 'format' => null]], 'deliverables' => '3 videos 9:16', 'due_date' => null]);
        expect($text)->toContain('- Qué es: Chaleco térmico.')->toContain('Lo que buscamos: Que se note el sudor.')
            ->not->toContain('30')->not->toContain('Oferta')->not->toContain('Información clave');
    });

    it('la IA de los encargos ya no recibe el precio', function () {
        $plan = [['key' => 'E1', 'product' => 'Comprimax', 'urgency' => 3, 'kind' => 'new_concept', 'pieces' => 3, 'reason' => 'U3.', 'iterate' => 'un concepto nuevo',
            'angle_code' => null, 'reference' => null, 'reference_type' => null, 'city' => null, 'format' => 'video',
            'context' => ['price' => '$30,00', 'proof' => null, 'angles' => [], 'profile' => '', 'personas' => []]]];
        expect(QueueWriter::input($plan, []))->not->toContain('Precio')->not->toContain('30,00');
    });
});
