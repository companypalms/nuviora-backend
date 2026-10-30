<?php

use App\Services\Meta\MetaInsightMapper;

// Etapa 5, paso 1a: el CTR único llega de Meta como clics únicos en el enlace (necesita la config: la compra es el
// primer tipo de acción que traiga la fila).

it('mapea los clics únicos en el enlace', function () {
    expect(MetaInsightMapper::map(['spend' => '10', 'impressions' => '1000', 'reach' => '800', 'unique_inline_link_clicks' => '24'])['unique_link_clicks'])->toBe(24);
    expect(MetaInsightMapper::map(['spend' => '10'])['unique_link_clicks'])->toBeNull();
});
