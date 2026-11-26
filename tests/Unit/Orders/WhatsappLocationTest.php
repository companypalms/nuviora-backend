<?php

use App\Services\Orders\WhatsappLocation;

it('.182: arma el link de Maps de las coordenadas que manda WhatsApp (y nada si no son coordenadas)', function () {
    expect(WhatsappLocation::link('10.4806', -66.9036))->toBe('https://www.google.com/maps?q=10.4806,-66.9036')
        ->and(WhatsappLocation::link('', '-66.9'))->toBeNull()
        ->and(WhatsappLocation::link(91, 0))->toBeNull();
});

it('.182: la pone si el pedido no tenía; si tenía otra, la vendedora la revisa; en una encomienda, nunca', function () {
    $link = 'https://www.google.com/maps?q=10.4806,-66.9036';

    expect(WhatsappLocation::decide(null, $link, false))->toBe('set')
        ->and(WhatsappLocation::decide('  ', $link, false))->toBe('set')
        ->and(WhatsappLocation::decide($link, $link, false))->toBe('same')
        ->and(WhatsappLocation::decide('https://maps.app.goo.gl/abc', $link, false))->toBe('other')
        ->and(WhatsappLocation::decide(null, $link, true))->toBe('skip')
        ->and(WhatsappLocation::decide(null, null, false))->toBe('skip');
});
