<?php

use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Naming;
use App\Services\Meta\Products\ShopifyLink;

// .89 (dueño, 2026-11-03): el flujo de su pizarra sin botones. Un producto nuevo entra por el asistente con su estrategia y
// el link de Shopify; se enlaza por su número de Shopify; el testeo se lanza cuando sus batches están en la banca.

describe('.89: la regla de nombres', function () {
    it('campaña: PRODUCTO | CIUDAD | CBO | fecha, con el código fijo de la ciudad', function () {
        expect(Naming::campaign('Termafit® Faja Térmica', 'Distrito Capital', '2026-11-03'))->toBe('TERMAFIT | CCS | CBO | 03/11/2026')
            ->and(Naming::campaign('Solo Brillo', 'Carabobo', '2026-11-03'))->toBe('SOLOBRILLO | CBOBO | CBO | 03/11/2026')
            ->and(Naming::city('Aragua'))->toBe('MCAY')->and(Naming::city('Lara'))->toBe('LARA')
            ->and(Naming::product('FlexiStep® Tobillera de compresión'))->toBe('FLEXISTEP');
    });

    it('batch y anuncios: B y C con su número único y el ángulo', function () {
        expect(Naming::batch(110, 'POSP', [457, 458]))->toBe(['adset' => 'B0110 POSP', 'ads' => ['C0457 POSP', 'C0458 POSP']]);
    });

    it('lanzar un batch de la banca dice el ad set y sus anuncios exactos', function () {
        $poda = ['campaign_meta_id' => 'c3', 'campaign' => 'SERUM | CBOBO', 'city' => 'Carabobo', 'notes' => [], 'budget_notes' => [], 'price_option' => null,
            'action' => ['key' => 'hold_prune', 'label' => 'Mantener y podar', 'tone' => 'info', 'data' => '', 'reading' => 'x', 'proposal' => null, 'from' => null, 'to' => null],
            'numbers' => ['spend3' => 30, 'purchases3' => 4, 'c' => 5, 'be' => 8, 'source' => null, 'budget' => 25, 'streak' => 0, 'prune' => [], 'live' => 2,
                'offers' => [['code' => 'B0108', 'how' => 'bank', 'adset' => 'B0108 MANCH', 'ads' => ['C0124 MANCH', 'C0125 MANCH', 'C0126 MANCH']]]]];
        $p = ['product_id' => 1, 'product' => 'Demo Sérum', 'mode' => 'normal', 'urgency' => ['level' => 2, 'label' => 'U2'], 'money_at_risk' => 0,
            'alarms' => [], 'price_watch' => null, 'cities' => [$poda]];
        expect(UrgentList::build([$p])[0]['steps'][1])
            ->toBe('Crea el ad set «B0108 MANCH» con los anuncios «C0124 MANCH», «C0125 MANCH» y «C0126 MANCH» (sus videos, aquí abajo).');
    });
});

describe('.89: el link de Shopify', function () {
    it('del panel sale el número; de la tienda, el nombre del link', function () {
        expect(ShopifyLink::parse('https://admin.shopify.com/store/mitienda/products/8812345678901'))->toBe(['id' => 8812345678901, 'handle' => null])
            ->and(ShopifyLink::parse('mitienda.myshopify.com/admin/products/8812345678901?tab=x'))->toBe(['id' => 8812345678901, 'handle' => null])
            ->and(ShopifyLink::parse('https://tienda.com/products/termafit-faja?variant=123'))->toBe(['id' => null, 'handle' => 'termafit-faja'])
            ->and(ShopifyLink::parse('https://tienda.com/es/collections/fajas/products/termafit'))->toBe(['id' => null, 'handle' => 'termafit'])
            ->and(ShopifyLink::parse('termafit'))->toBe(['id' => null, 'handle' => null]);
    });

    it('Shopify da el nombre exacto y el precio; si no responde, con el del panel queda el número igual', function () {
        $shopify = fn (?int $id, ?string $handle) => ['id' => 8812345678901, 'title' => 'Termafit® Faja Térmica', 'variants' => [['price' => '39.00']]];
        expect(ShopifyLink::resolve('https://tienda.com/products/termafit', $shopify))
            ->toBe(['id' => 8812345678901, 'title' => 'Termafit® Faja Térmica', 'price' => 39.0, 'problem' => null])
            ->and(ShopifyLink::resolve('https://admin.shopify.com/store/x/products/8812345678901', fn () => null))
            ->toBe(['id' => 8812345678901, 'title' => null, 'price' => null, 'problem' => null]);
    });

    it('un link que no es de un producto, o uno de la tienda que no existe, dice qué link pasar', function () {
        expect(ShopifyLink::resolve('termafit', fn () => null)['problem'])->toStartWith('Ese link no es de un producto de Shopify')
            ->and(ShopifyLink::resolve('https://tienda.com/products/no-existe', fn () => null)['problem'])->toStartWith('No encontré ese producto en tu tienda Shopify');
    });
});

describe('.89: un producto nuevo por el asistente', function () {
    $args = fn (array $over = []) => $over + ['producto' => 'Termafit', 'que_es' => 'Faja térmica reductora', 'para_que_sirve' => 'Reducir cintura',
        'como_funciona' => 'Guarda el calor y hace sudar la zona', 'lo_que_no_es' => null, 'info_clave' => null, 'fotos' => null,
        'buyer_personas' => [['nombre' => 'Mujer posparto', 'quien_es' => 'Mujer de 25 a 40', 'que_le_duele' => 'No recupera su cintura', 'que_quiere' => null, 'que_lo_frena' => null]],
        'quitar_buyer_personas' => [], 'angulos' => [['codigo' => 'POSP', 'nombre' => 'Posparto', 'hipotesis' => null, 'buyer' => 'Mujer posparto']],
        'fase' => 'testeo'];
    $link = ['id' => 8812345678901, 'title' => 'Termafit® Faja Térmica', 'price' => 39.0, 'problem' => null];

    it('se crea al confirmar, en testeo, con el nombre exacto y el número de Shopify', function () use ($args, $link) {
        $p = ProductProfileTool::proposal(0, 'Termafit', null, [], $args(), [], 'iterate', [], ['create' => true, 'shopify' => $link]);
        expect($p->title)->toBe('Crear Termafit y llenar su ficha')
            ->and(array_column($p->lines, 'label'))->toContain('Producto nuevo')->toContain('Shopify')
            ->and(collect($p->lines)->firstWhere('label', 'Shopify')['value'])->toBe('«Termafit® Faja Térmica» · número 8812345678901: sus pedidos se unen a este producto.')
            ->and($p->payload['create'])->toBe(['name' => 'Termafit', 'title' => 'Termafit® Faja Térmica', 'price' => 39.0, 'shopify_id' => 8812345678901])
            ->and($p->payload['phase'])->toBe('testing')
            ->and($p->warnings)->toBe([]);
    });

    it('sin el link, avisa que lo pase antes de lanzar el testeo', function () use ($args) {
        $p = ProductProfileTool::proposal(0, 'Termafit', null, [], $args(), [], 'iterate', [], ['create' => true, 'shopify' => null]);
        expect($p->payload['create']['shopify_id'])->toBe(0)
            ->and($p->warnings)->toContain('Todavía sin el link de Shopify: pásamelo cuando el producto esté en tu tienda (antes de lanzar el testeo), para que sus pedidos se unan a él.');
    });

    it('a un producto que ya existe se le puede pasar solo el link', function () use ($args, $link) {
        $ficha = ['what_it_is' => 'Faja térmica reductora', 'benefits' => 'Reducir cintura', 'mechanism' => 'Guarda el calor y hace sudar la zona',
            'not_this' => null, 'key_info' => null, 'photos_url' => null];
        $persona = ['id' => 3, 'name' => 'Mujer posparto', 'who' => 'Mujer de 25 a 40', 'pain' => 'No recupera su cintura', 'wants' => null, 'objections' => null];
        $p = ProductProfileTool::proposal(9, 'Termafit', $ficha, [$persona], $args(['que_es' => null, 'para_que_sirve' => null, 'como_funciona' => null,
            'buyer_personas' => [], 'angulos' => [], 'fase' => 'sin_cambio']), [], 'iterate', [], ['create' => false, 'shopify' => $link]);
        expect($p->title)->toBe('Cambiar la ficha de Termafit')->and($p->summary)->toBe('Cambiar la ficha de Termafit: el enlace con Shopify')
            ->and($p->payload['shopify'])->toBe(['id' => 8812345678901])->and($p->payload['create'])->toBeNull();
    });
});

describe('.89: lanzar el testeo y los atrasados en correcciones', function () {
    $test = fn (array $over = []) => $over + ['product_id' => 9, 'product' => 'Termafit® Faja Térmica', 'round' => 1,
        'batches' => [['code' => 'B0110', 'adset' => 'B0110 POSP', 'ads' => ['C0457 POSP', 'C0458 POSP']], ['code' => 'B0111', 'adset' => 'B0111 POSP', 'ads' => ['C0459 POSP']],
            ['code' => 'B0112', 'adset' => 'B0112 GYM', 'ads' => ['C0460 GYM']]],
        'linked' => true, 'campaigns' => [], 'cities' => ['Aragua', 'Carabobo', 'Distrito Capital'], 'target' => 4.0, 'min' => 2.5, 'max' => 3.0, 'date' => '2026-11-03'];

    it('un producto nuevo: una campaña en cada ciudad con los 3 ad sets y el presupuesto de arranque', function () use ($test) {
        $i = UrgentList::testLaunch($test());
        expect($i['title'])->toBe('Lanza el testeo de Termafit: B0110, B0111 y B0112')->and($i['group'])->toBe('crecer')
            ->and($i['steps'])->toBe([
                'Crea una campaña CBO en cada ciudad, con estos nombres: «TERMAFIT | MCAY | CBO | 03/11/2026», «TERMAFIT | CBOBO | CBO | 03/11/2026» y «TERMAFIT | CCS | CBO | 03/11/2026».',
                'En cada una, crea los 3 ad sets: «B0110 POSP» con los anuncios «C0457 POSP» y «C0458 POSP»; «B0111 POSP» con el anuncio «C0459 POSP»; '
                    . '«B0112 GYM» con el anuncio «C0460 GYM» (sus videos, aquí abajo).',
                'Presupuesto de arranque por campaña: entre $30,00 y $36,00 por día (tu regla: C × 3 batches × 2,5 a 3).',
                // .124: el gasto mínimo de cada ad set y los parámetros de URL de sus anuncios
                'Ponle a cada ad set nuevo un gasto mínimo diario de $4,00 (tu CPA de referencia). Va solo mientras se prueba.',
                'En cada anuncio nuevo, abre «Seguimiento» y pega los parámetros de URL (botón «Copiar parámetros», aquí abajo).',
            ])
            ->and($i['copy']['text'])->toBe('utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}')
            ->and($i['batches'])->toBe(['B0110', 'B0111', 'B0112']); // .102: los videos de los 3, en la misión
    });

    it('sin el link de Shopify ni su C, primero eso', function () use ($test) {
        $i = UrgentList::testLaunch($test(['linked' => false, 'target' => null]));
        expect($i['steps'][0])->toBe('Antes: pásale a tu asistente el link de Termafit en Shopify (así sus pedidos se suman a Termafit).')
            ->and($i['steps'][1])->toStartWith('Antes: pon el CPA de referencia de Termafit')
            ->and($i['steps'])->toHaveCount(6)
            ->and($i['steps'][4])->toBe('Ponle a cada ad set nuevo un gasto mínimo diario igual al CPA del nombre de su campaña (o a tu C, si no lo trae). Va solo mientras se prueba.');
    });

    it('un producto que ya vende: los ángulos nuevos en todas sus campañas', function () use ($test) {
        $i = UrgentList::testLaunch($test(['round' => 2, 'campaigns' => [['name' => 'TERMAFIT | CCS', 'city' => 'Distrito Capital'], ['name' => 'TERMAFIT | MCAY', 'city' => 'Aragua']]]));
        expect($i['title'])->toBe('Lanza los ángulos nuevos de Termafit: B0110, B0111 y B0112')
            ->and($i['steps'][0])->toStartWith('En cada campaña de Termafit («TERMAFIT | CCS» y «TERMAFIT | MCAY»), crea los 3 ad sets: «B0110 POSP» con los anuncios')
            // .124: sus campañas no traen el CPA en el nombre: el mínimo es su C
            ->and($i['steps'][2])->toBe('Ponle a cada ad set nuevo un gasto mínimo diario de $4,00 (tu C). Va solo mientras se prueba.');
        $named = UrgentList::testLaunch($test(['round' => 2, 'campaigns' => [['name' => 'TERMAFIT | MCAY | CBO | HV | CPA $4| BE $5.6| 29/10/2026', 'city' => 'Aragua']]]));
        expect($named['steps'][2])->toBe('Ponle a cada ad set nuevo un gasto mínimo diario de $4,00 (el CPA del nombre de la campaña). Va solo mientras se prueba.');
    });

    it('entregó a tiempo pero las correcciones pasaron de la fecha "lista": presiona al líder', function () {
        $late = ['id' => 41, 'product_id' => 9, 'product' => 'Termafit', 'code' => 'B0111', 'due' => '2026-11-01', 'ready' => '2026-11-02',
            'stage' => 'corrections', 'returned' => false, 'days' => 1, 'editor' => 'Ana', 'urgent' => false, 'testing' => true, 'cities' => []];
        $i = UrgentList::late([$late], 'Luis')[0];
        expect($i['steps'][0])->toBe('Escríbele a Luis: «B0111» de Termafit ya se entregó, pero sus correcciones tenían que terminar el lunes 2; es del testeo de Termafit.')
            ->and($i['steps'][1])->toBe('Que lo apruebe o lo devuelva hoy (lo hizo Ana).')
            ->and($i['why'])->toBe('1 día atrasado en correcciones · lo hizo Ana')
            ->and(UrgentList::late([['returned' => true] + $late], 'Luis')[0]['steps'][1])->toBe('Lo devolvió para corregir: que Ana entregue la corrección hoy.');
    });
});

describe('.89: lo que el sistema no reconoce por su nombre', function () {
    it('un aviso por campaña, con qué nombre ponerle a cada cosa', function () {
        $i = UrgentList::names(['campaign_meta_id' => '120', 'campaign' => 'Termafit nueva', 'product_id' => null, 'product' => null,
            'unclassified' => true, 'sets' => ['Conjunto 1'], 'ads' => ['Nuevo anuncio', 'Video 2']]);
        expect($i['group'])->toBe('aviso')->and($i['where'])->toBe('«Termafit nueva»')
            ->and($i['title'])->toBe('Renombra en Meta: no se reconoce la campaña: nómbrala «PRODUCTO | CIUDAD | CBO | fecha» (como «TERMAFIT | CCS | CBO | 03/11/2026») '
                . 'o clasifícala en Meta Ads › Configuración; un ad set sin su número B («Conjunto 1»): ponle el nombre de su batch, «B#### ÁNGULO» (está en Cola y banca); '
                . '2 anuncios sin su código C («Nuevo anuncio» y «Video 2»): ponle a cada uno el nombre de su pieza, «C#### ÁNGULO» (está en Cola y banca).')
            ->and(UrgentList::tally([$i], [])['tasks'])->toBe(0);
    });
});
