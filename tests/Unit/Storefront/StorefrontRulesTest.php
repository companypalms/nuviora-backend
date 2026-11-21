<?php

use App\Services\Orders\OrderSourceRules;
use App\Services\Storefront\StorefrontKpis;
use App\Services\Storefront\StorefrontRules;

// .164 (dueño, 2026-11-19): nuestro formulario de pedido en la landing, igual al de Releasit (pago al recibir), con los
// precios en Bs con su tasa de Shopify y lo que mandaba Releasit a Meta y a WhatsApp.

describe('.164: el formulario de la tienda', function () {
    it('el WhatsApp del cliente queda como los de Shopify: +58 y un celular', function () {
        foreach (['0414-123.45.67', '+58 414 1234567', '584141234567', '4141234567', '00584141234567', '(0414) 1234567'] as $raw) {
            expect(StorefrontRules::phone($raw))->toBe('+584141234567');
        }
        foreach (['0412', '0416', '0422', '0424', '0426'] as $p) {
            expect(StorefrontRules::phone("{$p}5551234"))->toBe('+58' . substr($p, 1) . '5551234');
        }
        // un fijo, uno corto o letras no son un WhatsApp
        expect(StorefrontRules::phone('0212-5551234'))->toBeNull()
            ->and(StorefrontRules::phone('0414123'))->toBeNull()
            ->and(StorefrontRules::phone('hola'))->toBeNull()
            ->and(StorefrontRules::last10('+584141234567'))->toBe('4141234567')
            ->and(StorefrontRules::splitName('  María José   Pérez '))->toBe(['María', 'José   Pérez']);
    });

    it('las ofertas como las de Releasit: Solo Brillo a $33 da $66,33 y $49,50', function () {
        expect(StorefrontRules::offerUsd(33, 3, 33))->toBe(66.33)
            ->and(StorefrontRules::offerUsd(33, 2, 25))->toBe(49.5)
            ->and(StorefrontRules::offerUsd(33, 1, 0))->toBe(33.0);
        $offers = StorefrontRules::offers(33.0, [
            ['id' => 1, 'units' => 3, 'discount_pct' => 33, 'title' => 'LLEVA 3 GALONES CON 33% DE DESCUENTO', 'badge' => '🎁 EL DESCUENTO MÁXIMO', 'is_default' => false, 'is_active' => true],
            ['id' => 2, 'units' => 2, 'discount_pct' => 25, 'title' => 'LLEVA 2 GALONES CON 25% DE DESCUENTO', 'badge' => '🏆 LA OPCIÓN FAVORITA', 'is_default' => false, 'is_active' => true],
            ['id' => 3, 'units' => 1, 'discount_pct' => 0, 'title' => 'LLEVA 1 GALÓN', 'badge' => null, 'is_default' => false, 'is_active' => true],
            ['id' => 4, 'units' => 5, 'discount_pct' => 40, 'title' => 'APAGADA', 'badge' => null, 'is_default' => false, 'is_active' => false],
        ], 250.0, 'SOLO BRILLO');

        // la apagada no sale; sin una marcada por el dueño, sale marcada la de 1 unidad
        expect(array_column($offers, 'id'))->toBe([1, 2, 3])
            ->and(array_column($offers, 'is_default'))->toBe([false, false, true])
            ->and($offers[0]['price_bs'])->toBe(16583)
            ->and($offers[0]['saving_bs'])->toBe(24750 - 16583)
            ->and($offers[2]['saving_bs'])->toBeNull();
        // sin ofertas: 1 unidad al precio, marcada; sin tasa, sin Bs
        $one = StorefrontRules::offers(33.0, [], null, 'SOLO BRILLO');
        expect($one)->toHaveCount(1)->and($one[0]['title'])->toBe('LLEVA 1 SOLO BRILLO')->and($one[0]['is_default'])->toBeTrue()->and($one[0]['price_bs'])->toBeNull();
    });

    it('los bolívares como los muestra su landing: dólares × su tasa, redondeado y con punto de miles', function () {
        expect(StorefrontRules::bs(33, 254.37))->toBe(8394)
            ->and(StorefrontRules::bsText(8394))->toBe('Bs 8.394')
            ->and(StorefrontRules::bsText(1234567))->toBe('Bs 1.234.567')
            // la de su página manda; si no llega bien (0 o vacío), la del sistema
            ->and(StorefrontRules::rate('254.37', 240.0))->toBe(254.37)
            ->and(StorefrontRules::rate('0', 240.0))->toBe(240.0)
            ->and(StorefrontRules::rate('', 0.0))->toBeNull();
    });

    it('se paga al recibir donde hay una agencia o un centro logístico activo, salvo donde el producto no se vende', function () {
        $cities = [['id' => 1, 'name' => 'Distrito Capital'], ['id' => 2, 'name' => 'Carabobo'], ['id' => 3, 'name' => 'Aragua'], ['id' => 4, 'name' => 'Lara']];

        expect(StorefrontRules::codCities($cities, [1, 2], [3], [2]))->toBe([['id' => 3, 'name' => 'Aragua'], ['id' => 1, 'name' => 'Distrito Capital']])
            ->and(StorefrontRules::codCities($cities, [], [], []))->toBe([]);
    });

    it('los nombres del pedido, su número para Meta y su Content ID', function () {
        expect(StorefrontRules::orderName(12))->toBe('WEB-12')
            ->and(StorefrontRules::orderId(12))->toBe(9_000_000_000_000_012)
            ->and(StorefrontRules::eventId(12))->toBe('nuv-web-12')
            ->and(StorefrontRules::contentId('shopify', 've', '7558816', '4521'))->toBe('shopify_VE_7558816_4521')
            ->and(StorefrontRules::contentId('product', 'VE', '7558816', '4521'))->toBe('7558816')
            ->and(StorefrontRules::contentId('variant', 'VE', '7558816', null))->toBe('7558816')
            ->and(StorefrontRules::samePedido('2026-11-19 10:00:00', '2026-11-19 10:29:59'))->toBeTrue()
            ->and(StorefrontRules::samePedido('2026-11-19 10:00:00', '2026-11-19 10:30:01'))->toBeFalse();
    });

    it('el mensaje de WhatsApp del dueño con los códigos de Releasit', function () {
        $msg = StorefrontRules::whatsappMessage(StorefrontRules::WHATSAPP_MESSAGE, ['first_name' => 'María', 'order_id' => 'WEB-12']);

        expect($msg)->toBe('*Es importante enviar este mensaje* ¡Hola! Soy María y he realizado el pedido WEB-12. Deseo pagarlo al momento de recibirlo. Muchas gracias!')
            ->and(StorefrontRules::whatsappMessage('{first_name} {desconocido}', []))->toBe('')
            ->and(StorefrontRules::waLink('+58 422-179 2891', 'Hola María'))->toBe('https://wa.me/584221792891?text=Hola%20Mar%C3%ADa')
            ->and(StorefrontRules::waLink('', 'x'))->toBeNull();
    });

    it('de dónde vino el pedido se guarda como uno de Shopify (anuncio, fbclid)', function () {
        $p = OrderSourceRules::parse(StorefrontRules::sourcePayload('https://tienda.com/products/x?utm_content=120210000000001&fbclid=abc', 'https://facebook.com/', 'https://tienda.com/products/x'));

        expect($p['ad_meta_id'])->toBe('120210000000001')->and($p['has_fbclid'])->toBeTrue()->and($p['source_name'])->toBe('nuviora_form');
    });

    it('el diseño: lo del producto sobre lo de siempre, y solo lo que es seguro', function () {
        $t = StorefrontRules::texts(['title' => 'PIDE TU LEGUP', 'button' => '  ', 'otra' => 'x']);
        $c = StorefrontRules::colors(['button' => '#ff0000', 'title' => 'red', 'otra' => '#000']);

        expect($t['title'])->toBe('PIDE TU LEGUP')->and($t['button'])->toBe('CONFIRMAR PEDIDO')->and($t)->not->toHaveKey('otra')
            ->and($c['button'])->toBe('#FF0000')->and($c['title'])->toBe(StorefrontRules::COLORS['title'])
            ->and(StorefrontRules::font('Poppins'))->toBe('Poppins')->and(StorefrontRules::font("x'; } body{"))->toBeNull()
            ->and(StorefrontRules::css('.nvf{color:red}</style><script>alert(1)</script>'))->not->toContain('</style')->not->toContain('<script');
    });

    it('los ajustes: lo que no se ve bien vuelve al de siempre', function () {
        $s = StorefrontRules::settings(['enabled' => true, 'shop_id' => '3', 'product_ids' => ['5', 5, 'x'], 'express_usd' => 99, 'whatsapp_number' => '0422 179 2891',
            'allowed_origins' => ['https://tienda.com/', 'http://malo.com', 'https://x.myshopify.com'], 'pixel_id' => 'abc', 'content_region' => 've', 'daily_max' => 1]);

        expect($s['shop_id'])->toBe(3)->and($s['product_ids'])->toBe([5])->and($s['express_usd'])->toBe(50.0)
            ->and($s['whatsapp_number'])->toBe('+584221792891')
            ->and($s['allowed_origins'])->toBe(['https://tienda.com', 'https://x.myshopify.com'])
            ->and($s['pixel_id'])->toBe('')->and($s['content_region'])->toBe('VE')->and($s['daily_max'])->toBe(10)
            ->and($s['whatsapp_message'])->toBe(StorefrontRules::WHATSAPP_MESSAGE);
    });
});

describe('.164: los KPIs', function () {
    $web = fn (array $x = []) => $x + ['orders' => 100, 'visits' => 2000.0, 'meta_purchases' => 97.0, 'capi_sent' => 100, 'capi_failed' => 0, 'attributed' => 80,
        'delivered' => 50, 'closed' => 80, 'duplicates' => 2, 'express' => 25, 'fallback' => 0, 'days' => 14,
        'funnel' => ['view' => 900, 'start' => 300, 'submit' => 110, 'whatsapp' => 90], 'fields' => []];
    $base = ['orders' => 900, 'visits' => 20000.0, 'attributed' => 650, 'delivered' => 450, 'closed' => 760, 'duplicates' => 30, 'express' => 220, 'products' => 6];

    it('si conversión, Meta, anuncio identificado y efectividad salen bien, se pasa a más productos', function () use ($web, $base) {
        $r = StorefrontKpis::evaluate($web(), $base);
        $by = array_column($r['kpis'], null, 'key');

        expect($by['conversion']['value'])->toBe(5.0)->and($by['conversion']['base'])->toBe(4.5)->and($by['conversion']['state'])->toBe('ok')
            ->and($by['meta']['state'])->toBe('ok')->and($by['attribution']['state'])->toBe('ok')
            ->and($by['effectiveness']['value'])->toBe(62.5)->and($by['effectiveness']['state'])->toBe('ok')
            ->and($by['technical']['state'])->toBe('ok')
            ->and($r['verdict']['key'])->toBe('go');
    });

    it('con la conversión más de 10 % por debajo, se revisa el embudo; con pocos pedidos, se sigue midiendo', function () use ($web, $base) {
        expect(StorefrontKpis::evaluate($web(['orders' => 70, 'visits' => 2000.0]), $base)['verdict']['key'])->toBe('review')
            ->and(StorefrontKpis::evaluate($web(['orders' => 12, 'days' => 3, 'visits' => 200.0]), $base)['verdict']['key'])->toBe('measuring')
            ->and(StorefrontKpis::evaluate($web(['orders' => 0]), $base)['verdict']['key'])->toBe('empty')
            ->and(array_column(StorefrontKpis::evaluate($web(['fallback' => 1]), $base)['kpis'], 'state', 'key')['technical'])->toBe('bad');
    });
});

// .168 (dueño, 2026-11-21): el pago anticipado, las encomiendas, el horario para el cliente y el Purchase al entregar.
describe('.168: pago anticipado, encomiendas y Purchase al entregar', function () {
    it('la cédula de quien retira queda limpia (V o E y los números); algo raro no vale', function () {
        expect(StorefrontRules::idNumber('v 12.345.678'))->toBe('V-12345678')
            ->and(StorefrontRules::idNumber('12345678'))->toBe('V-12345678')
            ->and(StorefrontRules::idNumber('E-8.123.456'))->toBe('E-8123456')
            ->and(StorefrontRules::idNumber('J-1'))->toBeNull()
            ->and(StorefrontRules::idNumber('12345'))->toBeNull()
            ->and(StorefrontRules::idNumber(null))->toBeNull();
    });

    it('una campaña marcada manda el Purchase al entregar desde su día; al desmarcarla, los pedidos nuevos vuelven a "al pedir"', function () {
        $ranges = [['campaign' => '111', 'from' => '2026-11-21', 'to' => null], ['campaign' => '222', 'from' => '2026-11-10', 'to' => '2026-11-20']];

        expect(StorefrontRules::purchaseMode('111', $ranges, '2026-11-21'))->toBe('delivery')
            ->and(StorefrontRules::purchaseMode('111', $ranges, '2026-11-20'))->toBe('order')
            ->and(StorefrontRules::purchaseMode('222', $ranges, '2026-11-15'))->toBe('order') // ya desmarcada
            ->and(StorefrontRules::purchaseMode(null, $ranges, '2026-11-21'))->toBe('order')
            // el sistema la mide con el "pedido hecho" en todos sus días marcados (el último incluido)
            ->and(StorefrontRules::markedOn('222', '2026-11-20', $ranges))->toBeTrue()
            ->and(StorefrontRules::markedOn('222', '2026-11-21', $ranges))->toBeFalse()
            ->and(StorefrontRules::markedOn('111', '2026-11-30', $ranges))->toBeTrue();
    });

    it('el Purchase sale al pedir salvo en una campaña "al entregar" sin pago adelantado', function () {
        expect(StorefrontRules::purchaseNow('order', 'cod'))->toBeTrue()
            ->and(StorefrontRules::purchaseNow('delivery', 'cod'))->toBeFalse()
            ->and(StorefrontRules::purchaseNow('delivery', 'asesora'))->toBeFalse()
            ->and(StorefrontRules::purchaseNow('delivery', 'pago_movil'))->toBeTrue()
            ->and(StorefrontRules::placedEventId(12))->toBe('nuv-pedido-12');
    });

    it('al entregar, la hora del pedido si fue hace 6 días o menos (Meta acepta 7); si no, la de ahora', function () {
        $t = 1_800_000_000;

        expect(StorefrontRules::deliveredTime($t, $t + 2 * 86400))->toBe($t)
            ->and(StorefrontRules::deliveredTime($t, $t + 6 * 86400))->toBe($t)
            ->and(StorefrontRules::deliveredTime($t, $t + 6 * 86400 + 1))->toBe($t + 6 * 86400 + 1);
    });

    it('"ya confirmamos tu pago" sale entre 5 y 15 minutos después', function () {
        expect(StorefrontRules::confirmDue(1000, 0.0))->toBe(1300)
            ->and(StorefrontRules::confirmDue(1000, 1.0))->toBe(1900)
            ->and(StorefrontRules::confirmDue(1000, 0.5))->toBe(1600);
    });

    it('el horario para el cliente, como lo diría una persona', function () {
        expect(StorefrontRules::hoursText('08:00:00', '19:00:00', [1, 2, 3, 4, 5, 6]))->toBe('de lunes a sábado, de 8:00 a. m. a 7:00 p. m.')
            ->and(StorefrontRules::hoursText('09:00', '18:30', [1, 2, 3, 4, 5]))->toBe('de lunes a viernes, de 9:00 a. m. a 6:30 p. m.')
            ->and(StorefrontRules::hoursText('09:00', '18:00', [1, 3, 6]))->toBe('los lunes, los miércoles y los sábados, de 9:00 a. m. a 6:00 p. m.')
            ->and(StorefrontRules::hoursText('09:00', '18:00', null))->toBe('de lunes a domingo, de 9:00 a. m. a 6:00 p. m.')
            ->and(StorefrontRules::hoursText(null, '18:00', null))->toBeNull()
            ->and(StorefrontRules::niceHour('12:15'))->toBe('12:15 p. m.')
            ->and(StorefrontRules::niceHour('00:30'))->toBe('12:30 a. m.');
    });

    it('cuándo vuelve a atender: hoy, mañana o el lunes (el domingo no se trabaja)', function () {
        $days = [1, 2, 3, 4, 5, 6];
        $at = fn (string $s) => new DateTimeImmutable($s);
        // viernes 20/11/2026 a las 6 a. m. → hoy a las 8; a las 10 p. m. → mañana; sábado en la noche → el lunes
        $when = fn (string $now) => StorefrontRules::whenText($at($now), StorefrontRules::nextOpening($at($now), '08:00', $days));

        expect($when('2026-11-20 06:00'))->toBe('Hoy a partir de las 8:00 a. m.')
            ->and($when('2026-11-20 22:00'))->toBe('Mañana a partir de las 8:00 a. m.')
            ->and($when('2026-11-21 21:00'))->toBe('El lunes a partir de las 8:00 a. m.')
            ->and(StorefrontRules::whenText($at('2026-11-20 06:00'), null))->toBe('Apenas abramos');
    });

    it('nada de lo que ve o recibe el cliente nombra a una IA (dueño: "la persona debe creer que es un ser humano")', function () {
        $customer = [...array_values(StorefrontRules::TEXTS), StorefrontRules::WHATSAPP_PREPAID, StorefrontRules::WHATSAPP_ADVISOR, StorefrontRules::WHATSAPP_COURIER,
            StorefrontRules::CONFIRM_MESSAGE, StorefrontRules::CONFIRM_COURIER, StorefrontRules::ACK_MESSAGE];
        foreach ($customer as $text) {
            expect(preg_match('/\b(IA|AI|inteligencia artificial|autom[aá]tic|robot|bot)\b/iu', $text))->toBe(0, $text);
        }
        expect(StorefrontRules::ACK_MESSAGE)->toContain('una asesora validará tu pago y procederá con tu envío');
    });

    it('los ajustes nuevos: sin Tealca, las encomiendas con su ciudad de origen y los mensajes de siempre si quedan vacíos', function () {
        $s = StorefrontRules::settings(['courier_companies' => ['MRW', 'Tealca'], 'courier_origin_city_id' => '2', 'courier_dropoff' => ['MRW' => ' https://maps.app/x ', 'Tealca' => 'y'],
            'confirm_message' => '  ', 'prepaid_enabled' => 1]);

        expect($s['courier_companies'])->toBe(['MRW'])
            ->and($s['courier_origin_city_id'])->toBe(2)
            ->and($s['courier_dropoff'])->toBe(['MRW' => 'https://maps.app/x', 'Zoom' => ''])
            ->and($s['confirm_message'])->toBe(StorefrontRules::CONFIRM_MESSAGE)
            ->and($s['prepaid_enabled'])->toBeTrue()
            ->and(StorefrontRules::settings([])['courier_companies'])->toBe(['MRW', 'Zoom']);
    });

});

describe('.171: Purchase según cómo paga, por producto (sin duplicar campañas)', function () {
    it('un producto prendido manda según cómo paga en cualquier campaña (o sin campaña); apagado, al pedir', function () {
        $ranges = [['campaign' => null, 'product' => 9, 'from' => '2026-11-21', 'to' => null], ['campaign' => '555', 'product' => 9, 'from' => '2026-11-21', 'to' => null],
            ['campaign' => null, 'product' => 8, 'from' => '2026-11-10', 'to' => '2026-11-15']];

        expect(StorefrontRules::purchaseMode(null, $ranges, '2026-11-21', 9))->toBe('delivery')
            ->and(StorefrontRules::purchaseMode('999', $ranges, '2026-11-22', 9))->toBe('delivery')
            ->and(StorefrontRules::purchaseMode(null, $ranges, '2026-11-20', 9))->toBe('order') // antes de prenderlo
            ->and(StorefrontRules::purchaseMode(null, $ranges, '2026-11-21', 8))->toBe('order') // ya apagado
            ->and(StorefrontRules::purchaseMode(null, $ranges, '2026-11-21', 7))->toBe('order') // otro producto
            // el sistema mide por pedidos sus campañas de esos días (la fila del producto sola no es una campaña)
            ->and(StorefrontRules::markedOn('555', '2026-11-21', $ranges))->toBeTrue()
            ->and(StorefrontRules::markedOn('999', '2026-11-21', $ranges))->toBeFalse();
    });

    it('cada lado del experimento: la efectividad, cuánto de lo vendido se va en publicidad y el costo por entregado', function () {
        expect(StorefrontKpis::arm(300.0, 60, 40, 50, 1200.0, 12.344))->toBe(['spend' => 300.0, 'orders' => 60, 'delivered' => 40, 'closed' => 50,
            'revenue' => 1200.0, 'effectiveness' => 80.0, 'ad_share' => 25.0, 'cost_per_delivered' => 7.5, 'profit_per_day' => 12.34])
            ->and(StorefrontKpis::arm(0.0, 0, 0, 0, 0.0, null))->toMatchArray(['effectiveness' => null, 'ad_share' => null, 'cost_per_delivered' => null]);
    });

    it('el veredicto: con 14 días y 30 entregados, va mejor si la publicidad se come 15 % menos de lo vendido que antes', function () {
        $arm = fn (int $delivered, ?float $share, ?float $eff = 60.0) => ['delivered' => $delivered, 'ad_share' => $share, 'effectiveness' => $eff];
        $before = $arm(40, 30.0);

        expect(StorefrontKpis::experimentVerdict($before, $arm(35, 25.5), 14)['key'])->toBe('go')
            ->and(StorefrontKpis::experimentVerdict($before, $arm(35, 25.5), 14)['text'])
            ->toBe('Va mejor: la publicidad se come el 26 % de lo vendido (antes 30 %) y la efectividad es 60 % (antes 60 %). Puedes prenderlo en otro producto.')
            // casi igual de publicidad pero 5 puntos más de efectividad: también va mejor
            ->and(StorefrontKpis::experimentVerdict($before, $arm(35, 29.0, 65.0), 14)['key'])->toBe('go')
            ->and(StorefrontKpis::experimentVerdict($before, $arm(35, 33.0), 14)['key'])->toBe('back')
            ->and(StorefrontKpis::experimentVerdict($before, $arm(35, 33.0, 70.0), 14)['key'])->toBe('tie') // más publicidad pero más efectividad
            ->and(StorefrontKpis::experimentVerdict($before, $arm(35, 28.0), 14)['key'])->toBe('tie')
            ->and(StorefrontKpis::experimentVerdict($before, $arm(20, 10.0), 14)['key'])->toBe('measuring')
            ->and(StorefrontKpis::experimentVerdict($before, $arm(35, 10.0), 9)['key'])->toBe('measuring')
            ->and(StorefrontKpis::experimentVerdict(null, $arm(35, 10.0), 14)['text'])->toBe('No hay con qué comparar: faltan las ventas de antes o el gasto de Meta.');
    });
});

describe('.172: solo donde hay stock', function () {
    it('cada ciudad vale lo de su almacén que más tiene; por talla, el menor entre el producto y la talla en ese almacén', function () {
        $s = StorefrontRules::stockByCity([1 => [10, 11], 2 => [12], 3 => []], [10 => 3, 11 => 7, 12 => 2], [10 => [70 => 3, 71 => 1], 11 => [71 => 9], 12 => [70 => 5]], [70, 71]);

        expect($s)->toBe([1 => ['all' => 7, 'sizes' => [70 => 3, 71 => 7]], 2 => ['all' => 2, 'sizes' => [70 => 2, 71 => 0]], 3 => ['all' => 0, 'sizes' => [70 => 0, 71 => 0]]]);
    });

    it('si alcanza: de esa talla, de alguna talla si no eligió, o del producto si no tiene tallas', function () {
        $sizes = ['all' => 5, 'sizes' => [70 => 0, 71 => 2]];
        $plain = ['all' => 2, 'sizes' => []];

        expect(StorefrontRules::canDeliver($sizes, 2, 71))->toBeTrue()
            ->and(StorefrontRules::canDeliver($sizes, 1, 70))->toBeFalse()
            ->and(StorefrontRules::canDeliver($sizes, 2))->toBeTrue()
            ->and(StorefrontRules::canDeliver($sizes, 3))->toBeFalse() // el producto tiene 5, pero sin repartir por talla no se vende
            ->and(StorefrontRules::canDeliver($plain, 2))->toBeTrue()
            ->and(StorefrontRules::canDeliver($plain, 3))->toBeFalse()
            ->and(StorefrontRules::canDeliver(null, 1))->toBeFalse()
            ->and(json_encode(StorefrontRules::stockShown(['all' => 40, 'sizes' => [70 => 1, 71 => 9]], 3)))->toBe('{"all":3,"sizes":{"70":1,"71":3}}');
    });
});
