<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Landings\LandingRules;
use App\Services\Meta\Landings\LandingUrl;

// Meta Ads › Landings (Etapa 5, paso 6a): qué landing es cada URL y lo que dicen sus números, con datos inventados.

/** Totales de una landing o de un ángulo: clics, visitas, compras e impresiones (gasto 1 por clic). */
function totalesLanding(float $clicks, float $visits, float $purchases, float $impressions = 0, ?float $c = 5.0): array
{
    $t = LandingRules::empty();
    LandingRules::add($t, ['spend' => $clicks, 'impressions' => $impressions ?: $clicks * 50, 'link_clicks' => $clicks,
        'landing_page_views' => $visits, 'purchases' => $purchases], $c);

    return $t;
}

function sumarLanding(array ...$parts): array
{
    $t = LandingRules::empty();
    foreach ($parts as $p) {
        foreach (LandingRules::SUMS as $k) {
            $t[$k] += $p[$k];
        }
    }

    return $t;
}

describe('Qué landing es cada URL', function () {
    it('sin UTM, fbclid, variante ni "www.", y con la ruta en minúsculas: la misma landing', function () {
        $base = LandingUrl::normalize('https://tienda.com/products/flexistep');
        expect($base)->toBe('https://tienda.com/products/flexistep')
            ->and(LandingUrl::normalize('https://www.Tienda.com/products/FlexiStep/?utm_source=fb&utm_campaign=x&fbclid=abc&variant=123'))->toBe($base)
            ->and(LandingUrl::normalize('tienda.com/collections/ofertas/products/flexistep#reseñas'))->toBe($base)
            ->and(LandingUrl::hash($base))->toBe(LandingUrl::hash(LandingUrl::normalize('http://tienda.com/products/flexistep?utm_medium=paid')));
    });

    it('la plantilla de Shopify (view) es otra landing, y su nombre corto la muestra', function () {
        $basu = LandingUrl::normalize('https://tienda.com/products/flexistep?view=BASU&utm_source=fb');
        expect($basu)->toBe('https://tienda.com/products/flexistep?view=basu')
            ->and(LandingUrl::label($basu))->toBe('flexistep · basu')
            ->and(LandingUrl::label('https://tienda.com/pages/oferta-cervimax'))->toBe('oferta-cervimax')
            ->and(LandingUrl::label('https://tienda.com'))->toBe('tienda.com');
    });

    it('WhatsApp, Messenger, Instagram o algo que no es una página no son landings', function () {
        foreach (['https://wa.me/584121234567', 'https://api.whatsapp.com/send?phone=58', 'https://m.me/tienda', 'https://www.instagram.com/tienda',
            'tel:+584121234567', '', null] as $url) {
            expect(LandingUrl::normalize($url))->toBeNull();
        }
    });
});

describe('Cada día con la landing que tenía ese día', function () {
    it('antes del primer cambio anotado vale la primera; después, la última hasta ese día', function () {
        $changes = [[1, '2026-10-20 15:00:00'], [2, '2026-10-25 09:00:00']];
        expect(LandingRules::at($changes, '2026-10-10'))->toBe(1)
            ->and(LandingRules::at($changes, '2026-10-20'))->toBe(1)
            ->and(LandingRules::at($changes, '2026-10-24'))->toBe(1)
            ->and(LandingRules::at($changes, '2026-10-25'))->toBe(2)
            ->and(LandingRules::at([], '2026-10-25'))->toBeNull();
    });

    it('las cuentas: visita, compra, CTR, CPA y CPA contra la C de cada ciudad', function () {
        $t = LandingRules::empty();
        LandingRules::add($t, ['spend' => 30, 'impressions' => 5000, 'link_clicks' => 100, 'landing_page_views' => 80, 'purchases' => 6], 5.0);  // a C: 30
        LandingRules::add($t, ['spend' => 60, 'impressions' => 5000, 'link_clicks' => 100, 'landing_page_views' => 60, 'purchases' => 4], 10.0); // a C: 40
        LandingRules::add($t, ['spend' => 10, 'impressions' => 0, 'link_clicks' => 0, 'landing_page_views' => 0, 'purchases' => 0], null);       // sin C
        expect(LandingRules::metrics($t))->toBe(['visit' => 70.0, 'conversion' => 7.14, 'ctr' => 2.0, 'cpa' => 10.0, 'cpa_vs_c' => 128.6]);
    });
});

describe('Lo que dicen los números', function () {
    $s = DecisionSettings::defaults(); // visita: fuga < 55 %, normal desde 70 %; 20 % debajo; 100 clics y 300 visitas

    it('lenta (visita < 55 %), un poco baja, sin píxel; con pocos clics no se opina', function () use ($s) {
        $l = fn (float $clicks, float $visits) => ['label' => 'x', 'angle_code' => null, 'totals' => totalesLanding($clicks, $visits, 0), 'angles' => []];
        $f = LandingRules::findings([1 => $l(400, 180), 2 => $l(400, 260), 3 => $l(400, 0), 4 => $l(80, 20), 5 => $l(400, 320)],
            totalesLanding(1680, 780, 0), $s);
        expect(array_column($f[1], 'key'))->toContain('slow')->and($f[1][0]['text'])->toContain('llegan 45 a la página')
            ->and(array_column($f[2], 'key'))->toContain('visit_low')
            ->and(array_column($f[3], 'key'))->toBe(['no_pixel'])
            ->and(array_column($f[4], 'key'))->not->toContain('slow')
            ->and(array_column($f[5], 'key'))->not->toContain('slow')->not->toContain('visit_low')
            ->and(LandingRules::level($f[1]))->toBe('red');
    });

    it('un ángulo que hace clic como el producto pero compra 20 % menos: la landing no habla de ese ángulo', function () use ($s) {
        $fasp = totalesLanding(1000, 800, 32);   // compra 4 %
        $nerv = totalesLanding(1000, 800, 12);   // compra 1,5 %, mismo CTR
        $gine = totalesLanding(200, 160, 1, 40000); // compra poco, pero hace poco clic (CTR 0,5 %): es del creativo
        $base = ['label' => 'Base', 'angle_code' => null, 'totals' => sumarLanding($fasp, $nerv, $gine), 'angles' => ['FASP' => $fasp, 'NERV' => $nerv, 'GINE' => $gine]];
        $f = LandingRules::findings([1 => $base], $base['totals'], $s, ['NERV' => 'Nervios']);
        $mismatch = array_values(array_filter($f[1], fn ($x) => $x['key'] === 'angle_mismatch'));
        expect($mismatch)->toHaveCount(1)
            // el producto: 45 compras en 1.760 visitas = 2,56 %
            ->and($mismatch[0]['text'])->toContain('NERV (Nervios)')->toContain('compran 41,4 % menos')->toContain('1,5 % contra 2,6 %');
    });

    it('si es la landing de ese ángulo, el problema es la oferta o lo que dice la página', function () use ($s) {
        $fasp = totalesLanding(1000, 800, 32);
        $nerv = totalesLanding(1000, 800, 12);
        $landings = [
            1 => ['label' => 'Base', 'angle_code' => null, 'totals' => $fasp, 'angles' => ['FASP' => $fasp]],
            2 => ['label' => 'Nervios', 'angle_code' => 'NERV', 'totals' => $nerv, 'angles' => ['NERV' => $nerv]],
        ];
        $f = LandingRules::findings($landings, sumarLanding($fasp, $nerv), $s);
        expect(array_column($f[2], 'key'))->toBe(['angle_weak'])->and($f[1])->toBe([]);
    });

    it('el mismo ángulo en dos landings: la que compra 20 % menos que la mejor pierde contra ella', function () use ($s) {
        $enBase = totalesLanding(500, 400, 8);    // 2 %
        $enBasu = totalesLanding(500, 400, 14);   // 3,5 %
        $enOtra = totalesLanding(500, 400, 13);   // 3,25 %: menos de 20 % debajo, no pierde
        $landings = [
            1 => ['label' => 'Base', 'angle_code' => null, 'totals' => $enBase, 'angles' => ['FASP' => $enBase]],
            2 => ['label' => 'BASU', 'angle_code' => 'FASP', 'totals' => $enBasu, 'angles' => ['FASP' => $enBasu]],
            3 => ['label' => 'Otra', 'angle_code' => null, 'totals' => $enOtra, 'angles' => ['FASP' => $enOtra]],
        ];
        $f = LandingRules::findings($landings, sumarLanding($enBase, $enBasu, $enOtra), $s);
        expect(array_column($f[1], 'key'))->toContain('beaten')
            ->and(collect($f[1])->firstWhere('key', 'beaten')['text'])->toContain('«BASU» compra mejor: 3,5 % contra 2 %')
            ->and(array_column($f[2], 'key'))->toBe(['best'])
            ->and(array_column($f[3], 'key'))->not->toContain('beaten');
    });

    it('con pocas visitas no se compara la compra, y lo dice', function () use ($s) {
        $poco = totalesLanding(150, 120, 0);
        $f = LandingRules::findings([1 => ['label' => 'Base', 'angle_code' => null, 'totals' => $poco, 'angles' => ['FASP' => $poco]]], $poco, $s);
        expect(array_column($f[1], 'key'))->toBe(['little_data'])->and($f[1][0]['text'])->toContain('120 de 300')
            ->and(LandingRules::level($f[1]))->toBe('info');
    });
});
