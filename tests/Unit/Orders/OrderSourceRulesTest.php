<?php

use App\Services\Orders\OrderSourceRules;

// De dónde vino un pedido de Shopify (`.53`): lo que se lee de orders/create.

describe('la landing y los parámetros del anuncio', function () {
    it('con los parámetros que pone el dueño en Ads Manager, el pedido dice su anuncio', function () {
        $p = OrderSourceRules::parse([
            'landing_site' => '/products/Solo-Brillo/?utm_source=facebook&utm_medium=paid&utm_campaign=120200000000000001&utm_term=120200000000000002&utm_content=120200000000000003&fbclid=IwAR123',
            'referring_site' => 'https://l.facebook.com/l.php?u=algo-largo',
            'source_name' => 'web',
        ]);
        expect($p['landing_url'])->toBe('/products/solo-brillo');
        expect($p['landing_absolute'])->toBeFalse();
        expect($p['referring_site'])->toBe('https://l.facebook.com/l.php');
        expect($p['utm_source'])->toBe('facebook');
        expect($p['has_fbclid'])->toBeTrue();
        expect([$p['ad_meta_id'], $p['adset_meta_id'], $p['campaign_meta_id']])->toBe(['120200000000000003', '120200000000000002', '120200000000000001']);
    });

    it('con dominio, la landing queda como la guarda el sistema (sin utm, con su plantilla "view")', function () {
        $p = OrderSourceRules::parse(['landing_site' => 'https://www.SoloBrillo.com/collections/all/products/solo-brillo?view=basu&utm_source=fb']);
        expect($p['landing_url'])->toBe('https://solobrillo.com/products/solo-brillo?view=basu');
        expect($p['landing_absolute'])->toBeTrue();
    });

    it('si el parámetro no se reemplazó ("{{ad.id}}") o es un nombre, no es un anuncio', function () {
        expect(OrderSourceRules::parse(['landing_site' => '/products/x?utm_content={{ad.id}}'])['ad_meta_id'])->toBeNull();
        $p = OrderSourceRules::parse(['landing_site' => '/products/x?utm_content=C0457%20FASP']);
        expect($p['utm_content'])->toBe('C0457 FASP');
        expect($p['ad_meta_id'])->toBeNull();
    });

    it('sin landing ni parámetros, queda vacío (pero se guarda de dónde lo dice Shopify)', function () {
        $p = OrderSourceRules::parse(['landing_site' => null, 'referring_site' => '', 'source_name' => '1234567']);
        expect($p['landing_url'])->toBeNull();
        expect($p['utm_source'])->toBeNull();
        expect($p['has_fbclid'])->toBeFalse();
        expect($p['source_name'])->toBe('1234567');
    });
});

describe('los formularios contra entrega (note_attributes)', function () {
    $releasit = [
        ['name' => 'Nombre', 'value' => 'María Pérez'],
        ['name' => 'Teléfono', 'value' => '04141234567'],
        ['name' => 'utm_source', 'value' => 'facebook'],
        ['name' => 'UTM_CONTENT', 'value' => '120200000000000009'],
        ['name' => 'landing_page', 'value' => 'https://solobrillo.com/products/solo-brillo?fbclid=abc'],
    ];

    it('completan lo que falta con los parámetros, nunca con los datos del cliente', function () use ($releasit) {
        $p = OrderSourceRules::parse(['landing_site' => null, 'note_attributes' => $releasit]);
        expect($p['utm_source'])->toBe('facebook');
        expect($p['ad_meta_id'])->toBe('120200000000000009');
        expect($p['landing_url'])->toBe('https://solobrillo.com/products/solo-brillo');
        expect($p['has_fbclid'])->toBeTrue();
        expect(json_encode($p))->not->toContain('María')->not->toContain('0414');
    });

    it('lo de la URL manda sobre lo de note_attributes', function () use ($releasit) {
        $p = OrderSourceRules::parse(['landing_site' => '/products/x?utm_source=instagram', 'note_attributes' => $releasit]);
        expect($p['utm_source'])->toBe('instagram');
        expect($p['landing_url'])->toBe('/products/x');
    });
});

it('la ruta de una landing guardada', function () {
    expect(OrderSourceRules::pathOf('https://solobrillo.com/products/solo-brillo?view=basu'))->toBe('/products/solo-brillo?view=basu');
    expect(OrderSourceRules::pathOf('https://solobrillo.com/products/solo-brillo'))->toBe('/products/solo-brillo');
});
