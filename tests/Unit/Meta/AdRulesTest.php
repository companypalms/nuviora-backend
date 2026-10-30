<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\MetaMetrics;

// Etapa 5, paso 2a: las reglas del dueño (entrevista y manual corregido del 2026-10-30). C = 4 y BE = 5,70 son los del
// ejemplo de su manual.

function reglas(array $cambios = []): AdRules
{
    return new AdRules(array_merge(DecisionSettings::defaults(), $cambios));
}

function ventana(array $totals): array
{
    $totals = array_merge(array_fill_keys(MetaMetrics::SUMMABLE, null), $totals);

    return ['totals' => $totals, 'metrics' => MetaMetrics::compute($totals)];
}

describe('Los números', function () {
    it('empiezan con los del dueño y un número fuera de rango vuelve al inicial', function () {
        $d = DecisionSettings::defaults();
        expect($d['loser_spend_cpa'])->toBe(2.0)->and($d['proven_purchases'])->toBe(5.0)->and($d['ctr_good'])->toBe(3.0)
            ->and($d['auto_statuses'])->toBeTrue();
        $n = DecisionSettings::normalize(['loser_spend_cpa' => 3, 'proven_purchases' => -1, 'auto_statuses' => false]);
        expect($n['loser_spend_cpa'])->toBe(3.0)->and($n['proven_purchases'])->toBe(5.0)->and($n['auto_statuses'])->toBeFalse();
    });
});

describe('Estado de un anuncio o batch (C = 4, BE = 5,70)', function () {
    it('sigue las reglas del dueño', function (float $spend, float $purchases, string $key) {
        expect(reglas()->state($spend, $purchases, 4.0, 5.70)['key'])->toBe($key);
    })->with([
        'gastó menos de 1×C' => [3, 0, 'no_data'],
        'entre 1 y 2×C sin compras' => [6, 0, 'evaluation'],
        '2×C sin compras' => [8, 0, 'loser'],
        '2×C con una sola compra' => [8.5, 1, 'loser'],
        '2 compras con CPA debajo de C' => [8, 2, 'evaluation'],
        '3 compras con CPA debajo de C' => [9, 3, 'promising'],
        '5 compras con CPA debajo de C' => [15, 5, 'proven'],
        'CPA entre C y BE' => [10, 2, 'intermediate'],
        '2 compras en el BE, antes de 3×C' => [11.6, 2, 'evaluation'],
        '2 compras en el BE al llegar a 3×C' => [12, 2, 'loser'],
        'más de 3 compras encima del BE' => [24, 4, 'loser'],
    ]);

    it('sin CPA objetivo no hay veredicto', function () {
        expect(reglas()->state(10, 0, null, null)['key'])->toBe('no_target');
    });

    it('dice el dato, la lectura y la propuesta', function () {
        $v = reglas()->state(9.10, 0, 4.0, 5.70);
        expect($v['data'])->toContain('$9,10')->toContain('2,3×C')->toContain('0 compras')
            ->and($v['reading'])->toContain('Perdedor')->and($v['proposal'])->toBe('Apagar este anuncio.');
        expect(reglas()->state(9.10, 0, 4.0, 5.70, 'batch')['proposal'])->toBe('Apagar el batch.');
    });

    it('los números se cambian sin tocar código', function () {
        expect(reglas(['loser_spend_cpa' => 3])->state(8, 0, 4.0, 5.70)['key'])->toBe('evaluation');
    });
});

describe('Ganador que empezó a fallar', function () {
    it('tuvo historia buena si fue PROVEN o tuvo una semana ganadora', function () {
        $r = reglas();
        $loser = $r->state(40, 6, 4.0, 5.70);
        expect($loser['key'])->toBe('loser');
        $semana = ['2026-10-01' => ['spend' => 6, 'purchases' => 2], '2026-10-03' => ['spend' => 6, 'purchases' => 2],
            '2026-10-06' => ['spend' => 4, 'purchases' => 1], '2026-10-20' => ['spend' => 24, 'purchases' => 1]];
        expect($r->hadGoodHistory($loser, $semana, 4.0))->toBeTrue();
        $suelta = ['2026-10-01' => ['spend' => 6, 'purchases' => 2], '2026-10-12' => ['spend' => 6, 'purchases' => 2]];
        expect($r->hadGoodHistory($loser, $suelta, 4.0))->toBeFalse();
        expect($r->hadGoodHistory($r->state(20, 6, 4.0, 5.70), [], 4.0))->toBeTrue();
    });

    it('falla si en 3 días está en el BE o encima (o usa 7 días si en 3 casi no gastó)', function () {
        $r = reglas();
        expect($r->isFailing(true, ventana(['spend' => 12, 'purchases' => 2]), null, 4.0, 5.70))->toBeTrue()
            ->and($r->isFailing(true, ventana(['spend' => 9, 'purchases' => 3]), null, 4.0, 5.70))->toBeFalse()
            ->and($r->isFailing(false, ventana(['spend' => 12, 'purchases' => 2]), null, 4.0, 5.70))->toBeFalse()
            ->and($r->isFailing(true, ventana(['spend' => 2, 'purchases' => 0]), ventana(['spend' => 9, 'purchases' => 0]), 4.0, 5.70))->toBeTrue();
    });
});

describe('Qué cambió', function () {
    $antes = ventana(['spend' => 30, 'impressions' => 10000, 'reach' => 8000, 'link_clicks' => 300, 'unique_link_clicks' => 280,
        'landing_page_views' => 240, 'purchases' => 8, 'video_3s_plays' => 4000]);

    it('fatiga: sube la frecuencia y baja el CTR único', function () use ($antes) {
        $ahora = ventana(['spend' => 30, 'impressions' => 10000, 'reach' => 6000, 'link_clicks' => 200, 'unique_link_clicks' => 150,
            'landing_page_views' => 160, 'purchases' => 4, 'video_3s_plays' => 3000]);
        $c = reglas()->whatChanged($ahora, $antes, null);
        expect(array_column($c, 'key'))->toBe(['fatigue'])->and($c[0]['evidence'])->toContain('frecuencia');
    });

    it('subasta: el CPM de la ciudad sube y el CTR sigue igual', function () use ($antes) {
        expect(array_column(reglas()->whatChanged($antes, $antes, null, 25.0), 'key'))->toBe(['auction']);
    });

    it('landing u oferta: engancha igual pero compra menos', function () use ($antes) {
        $ahora = ventana(['spend' => 30, 'impressions' => 10000, 'reach' => 8000, 'link_clicks' => 300, 'unique_link_clicks' => 280,
            'landing_page_views' => 240, 'purchases' => 4, 'video_3s_plays' => 4000]);
        expect(array_column(reglas()->whatChanged($ahora, $antes, null), 'key'))->toBe(['landing_offer']);
    });

    it('landing lenta y tracking', function () use ($antes) {
        $ahora = ventana(['spend' => 30, 'impressions' => 10000, 'reach' => 8000, 'link_clicks' => 300, 'unique_link_clicks' => 280,
            'landing_page_views' => 150, 'purchases' => 5, 'video_3s_plays' => 4000]);
        $keys = array_column(reglas()->whatChanged($ahora, $antes, null, null, 70.0), 'key');
        expect($keys)->toContain('landing_speed')->toContain('tracking');
    });

    it('el veredicto dice qué hacer según la causa', function () use ($antes) {
        $r = reglas();
        $v = $r->failingVerdict($r->state(40, 6, 4.0, 5.70), [['key' => 'fatigue', 'label' => 'Fatiga', 'evidence' => 'frecuencia 1,4 → 1,8']]);
        expect($v['key'])->toBe('failing')->and($v['reading'])->toContain('Fatiga')->and($v['proposal'])->toContain('iteraciones');
        expect($r->failingVerdict($r->state(40, 6, 4.0, 5.70), [['key' => 'auction', 'label' => 'Subasta', 'evidence' => '']])['proposal'])->toContain('subasta');
    });
});

describe('Entre ciudades, embudo y saturación', function () {
    it('ganador transversal, sensible a ciudad o perdedor en todas', function () {
        $r = reglas();
        expect($r->crossCity(['Caracas' => 'proven', 'Valencia' => 'promising', 'Maracay' => 'evaluation'])['key'])->toBe('transversal')
            ->and($r->crossCity(['Caracas' => 'proven', 'Maracay' => 'loser'])['key'])->toBe('city_sensitive')
            ->and($r->crossCity(['Caracas' => 'loser', 'Maracay' => 'loser', 'Valencia' => 'no_data'])['key'])->toBe('loser_all')
            ->and($r->crossCity(['Caracas' => 'evaluation']))->toBeNull()
            // en una sola ciudad no es "perdedor en todas"
            ->and($r->crossCity(['Caracas' => 'loser']))->toBeNull();
        expect($r->crossCity(['Caracas' => 'proven', 'Maracay' => 'loser'])['detail'])->toBe('gana en Caracas · pierde en Maracay');
    });

    it('la frecuencia del creativo dice la parte del embudo (~1 arriba, 1,3–1,5 medio, ~2 abajo)', function () {
        $r = reglas();
        expect($r->funnel(1.1))->toBe('top')->and($r->funnel(1.4))->toBe('middle')->and($r->funnel(1.9))->toBe('bottom')->and($r->funnel(null))->toBeNull();
        expect($r->saturated(1.65))->toBeTrue()->and($r->saturated(1.5))->toBeFalse();
    });
});

describe('Diagnóstico del video', function () {
    $video = fn (array $t) => ventana(array_merge(['spend' => 20, 'impressions' => 10000, 'reach' => 9000, 'link_clicks' => 350,
        'unique_link_clicks' => 320, 'landing_page_views' => 280, 'purchases' => 5, 'video_plays' => 5000, 'video_3s_plays' => 4500,
        'thruplays' => 2000, 'video_p25' => 3000, 'video_p75' => 1500, 'video_p100' => 1000, 'video_watch_time' => 5000 * 9], $t));

    it('hook flojo y hold bueno: iterar los primeros segundos', function () use ($video) {
        $v = reglas()->video($video(['video_3s_plays' => 2500, 'thruplays' => 1200]), true, 30);
        expect($v['grades']['hook'])->toBe('weak')->and($v['grades']['hold'])->toBe('good')->and($v['iterate'])->toBe('hook')
            ->and($v['diagnosis'][0])->toContain('primeros segundos');
    });

    it('hook bueno y hold flojo: acortar', function () use ($video) {
        $v = reglas()->video($video(['thruplays' => 1000]), true, 30);
        expect($v['grades']['hook'])->toBe('good')->and($v['grades']['hold'])->toBe('weak')->and($v['iterate'])->toBe('duration');
    });

    it('dónde se va la gente y el tiempo medio contra la duración', function () use ($video) {
        expect(reglas()->video($video(['video_p25' => 1500]), true, 30)['leaves'])->toBe('opening')
            ->and(reglas()->video($video(['video_p75' => 900]), true, 30)['leaves'])->toBe('body')
            ->and(reglas()->video($video(['video_p100' => 500]), true, 30)['leaves'])->toBe('end');
        $v = reglas()->video($video([]), true, 30);
        expect($v['leaves'])->toBeNull()->and($v['watch_pct'])->toBe(30.0)->and($v['grades']['watch'])->toBe('good');
    });

    it('engancha y hace clic pero no compra (contra los ganadores): la landing', function () use ($video) {
        $v = reglas()->video($video(['purchases' => 2]), true, 30, ['conversion_rate' => 2.0]);
        expect($v['grades']['conversion'])->toBe('weak')->and($v['iterate'])->toBe('landing');
    });

    it('debajo de la mediana de los ganadores también es flojo', function () use ($video) {
        expect(reglas()->video($video([]), true, 30, ['hook_rate' => 60.0])['grades']['hook'])->toBe('weak');
    });

    it('una imagen no se mide por hook ni retención', function () use ($video) {
        $v = reglas()->video($video([]), false, null);
        expect($v['grades']['hook'])->toBeNull()->and($v['leaves'])->toBeNull()->and($v['grades']['ctr'])->toBe('good');
    });

    it('la mediana', function () {
        expect(AdRules::median([3, null, 1, 2]))->toBe(2.0)->and(AdRules::median([1, 2, 3, 4]))->toBe(2.5)->and(AdRules::median([]))->toBeNull();
    });
});

describe('Estado automático del creativo', function () {
    $cr = fn (string $key, array $causes = [], bool $active = true) => ['active' => $active,
        'verdict' => ['key' => $key, 'reading' => "lectura {$key}", 'causes' => array_map(fn ($c) => ['key' => $c], $causes)]];
    $s = DecisionSettings::defaults();

    it('Winner, Fatigued, Loser, Testing o Archived', function () use ($cr, $s) {
        expect(MetaDecisions::autoStatus($cr('proven'), '2026-10-29', $s, '2026-10-30')[0])->toBe('winner')
            ->and(MetaDecisions::autoStatus($cr('failing', ['fatigue']), '2026-10-29', $s, '2026-10-30')[0])->toBe('fatigued')
            ->and(MetaDecisions::autoStatus($cr('failing', ['auction']), '2026-10-29', $s, '2026-10-30')[0])->toBe('winner')
            ->and(MetaDecisions::autoStatus($cr('loser'), '2026-10-29', $s, '2026-10-30')[0])->toBe('loser')
            ->and(MetaDecisions::autoStatus($cr('intermediate'), '2026-10-29', $s, '2026-10-30')[0])->toBe('testing')
            ->and(MetaDecisions::autoStatus($cr('proven', [], false), '2026-10-10', $s, '2026-10-30')[0])->toBe('archived')
            ->and(MetaDecisions::autoStatus($cr('proven', [], false), '2026-10-25', $s, '2026-10-30')[0])->toBe('winner');
    });
});
