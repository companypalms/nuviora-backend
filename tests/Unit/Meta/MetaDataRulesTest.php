<?php

use App\Services\Meta\MetaHierarchySync;
use App\Services\Meta\MetaInsightMapper;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\MetaSnapshots;

// Etapa 5, paso 1a (2026-10-30): los datos de Meta que necesita la IA para analizar como el dueño.

describe('Lo que dice el anuncio y a dónde lleva', function () {
    it('lee un anuncio de enlace (link_data)', function () {
        $d = MetaHierarchySync::creativeDetails(['object_story_spec' => ['link_data' => [
            'message' => 'Alivia la fascitis plantar desde el primer día', 'name' => 'FlexiStep', 'picture' => 'https://img/1.jpg',
            'link' => 'https://flexistep.shop/products/tobillera',
        ]], 'url_tags' => 'utm_source=facebook&utm_content={{ad.id}}']);
        expect($d)->toBe(['ad_body' => 'Alivia la fascitis plantar desde el primer día', 'ad_title' => 'FlexiStep', 'image_url' => 'https://img/1.jpg',
            'url_tags' => 'utm_source=facebook&utm_content={{ad.id}}', 'landing_url' => 'https://flexistep.shop/products/tobillera']);
    });

    it('lee un anuncio de video (video_data con su botón)', function () {
        $d = MetaHierarchySync::creativeDetails(['object_story_spec' => ['video_data' => [
            'message' => 'Mira cómo cambia', 'title' => 'Comprimax', 'image_url' => 'https://img/v.jpg',
            'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://comprimax.shop/']],
        ]]]);
        expect($d['ad_body'])->toBe('Mira cómo cambia')->and($d['landing_url'])->toBe('https://comprimax.shop/')
            ->and($d['url_tags'])->toBeNull();
    });

    it('lee un anuncio dinámico (asset_feed_spec) y prefiere lo del creativo', function () {
        $d = MetaHierarchySync::creativeDetails(['body' => 'Texto del creativo', 'asset_feed_spec' => [
            'bodies' => [['text' => 'Texto 1'], ['text' => 'Texto 2']], 'titles' => [['text' => 'Título 1']],
            'link_urls' => [['website_url' => 'https://solobrillo.shop/basu']],
        ]]);
        expect($d['ad_body'])->toBe('Texto del creativo')->and($d['ad_title'])->toBe('Título 1')
            ->and($d['landing_url'])->toBe('https://solobrillo.shop/basu');
    });

    it('sin datos, todo vacío (no inventa)', function () {
        expect(array_filter(MetaHierarchySync::creativeDetails(['id' => '1', 'body' => '  '])))->toBe([]);
    });
});

describe('El historial de presupuestos y estados', function () {
    it('guarda los montos en dólares (Meta los manda en centavos) y solo lo de cada nivel', function () {
        expect(MetaSnapshots::state('campaign', ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '3960', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP']))
            ->toBe(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => 39.6, 'lifetime_budget' => null,
                'min_spend' => null, 'spend_cap' => null, 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP']);
        expect(MetaSnapshots::state('adset', ['status' => 'ACTIVE', 'daily_budget' => '0', 'daily_min_spend_target' => '400', 'bid_strategy' => 'X']))
            ->toMatchArray(['daily_budget' => null, 'min_spend' => 4.0, 'bid_strategy' => null]);
        expect(MetaSnapshots::state('ad', ['status' => 'PAUSED', 'daily_budget' => '999']))->toMatchArray(['status' => 'PAUSED', 'daily_budget' => null]);
    });

    it('solo hay foto nueva si algo cambió', function () {
        $a = MetaSnapshots::state('campaign', ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '3000']);
        expect(MetaSnapshots::changed(null, $a))->toBeTrue();
        // Lo que vuelve de la base trae los montos como texto
        expect(MetaSnapshots::changed(['daily_budget' => '30.00'] + $a, $a))->toBeFalse();
        expect(MetaSnapshots::changed($a, MetaSnapshots::state('campaign', ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '3300'])))->toBeTrue();
        expect(MetaSnapshots::changed($a, ['status' => 'PAUSED'] + $a))->toBeTrue();
        expect(MetaSnapshots::changed($a, ['daily_budget' => null] + $a))->toBeTrue();
    });
});

describe('El CTR único', function () {
    it('se le pide a Meta', function () {
        expect(MetaInsightMapper::FIELDS)->toContain('unique_inline_link_clicks');
    });

    it('es clics únicos ÷ alcance; sin alcance, N/A', function () {
        expect(MetaMetrics::compute(['spend' => 10, 'impressions' => 1000, 'reach' => 800, 'unique_link_clicks' => 24])['unique_ctr'])->toBe(3.0);
        expect(MetaMetrics::compute(['spend' => 10, 'impressions' => 1000, 'reach' => null, 'unique_link_clicks' => 24])['unique_ctr'])->toBeNull();
        expect(MetaMetrics::sum([['unique_link_clicks' => 10], ['unique_link_clicks' => 5]])['unique_link_clicks'])->toBe(15.0);
    });
});
