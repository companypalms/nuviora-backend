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
        '.101: 2 compras apenas encima del BE (5 %) al llegar a 3×C' => [12, 2, 'evaluation'],
        '2 compras 20 % encima del BE al llegar a 3×C' => [14, 2, 'loser'],
        'más de 3 compras 20 % encima del BE' => [28, 4, 'loser'],
        '.101: 20 compras apenas encima del BE (sin margen)' => [120, 20, 'loser'],
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
        $loser = $r->state(42, 6, 4.0, 5.70);
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

describe('.119: un ganador que falla y pierde dinero se apaga en esa ciudad (ZenPulse Caracas: C = 4,44, BE = 7,61)', function () {
    $falla = ['key' => 'failing', 'label' => 'Ganador que empezó a fallar', 'data' => 'su vida', 'reading' => 'Fue ganador.', 'proposal' => 'Revisar la landing.', 'causes' => [['key' => 'landing_offer']]];

    it('CREATIVO 5: $51,87 en 3 días con el CPA en $10,37: se apaga y sigue siendo ganador', function () use ($falla) {
        $v = reglas()->failingStop($falla, ['spend' => 51.87, 'purchases' => 5], 4.44, 7.61, ['spend' => 118.61, 'purchases' => 17]);
        expect($v['key'])->toBe('failing')->and($v['stop'])->toBeTrue()
            ->and($v['data'])->toBe('3 días: gastó $51,87 (11,7×C) · 5 compras · CPA $10,37 · C $4,44 · BE $7,61')
            ->and($v['reading'])->toBe('Pierde dinero: en los últimos 3 días gastó 11,7×C con el CPA 36 % encima del break-even. Fue ganador.')
            ->and($v['proposal'])->toBe('Apagarlo en esta ciudad e iterarlo en un batch nuevo: fue ganador.')
            ->and($v['causes'])->toBe($falla['causes']);
    });

    it('CREATIVO 4: 2 compras y el CPA en $13,10: también', function () use ($falla) {
        expect(reglas()->failingStop($falla, ['spend' => 26.19, 'purchases' => 2], 4.44, 7.61, ['spend' => 45.39, 'purchases' => 9])['stop'])->toBeTrue();
    });

    it('CREATIVO 8: en 3 días no llegó a 3×C y en 7 días su CPA está debajo del BE: sigue', function () use ($falla) {
        expect(reglas()->failingStop($falla, ['spend' => 9.22, 'purchases' => 1], 4.44, 7.61, ['spend' => 17.57, 'purchases' => 3]))->toBeNull();
    });

    it('el que gasta poco se juzga por la semana', function () use ($falla) {
        $v = reglas()->failingStop($falla, ['spend' => 9.22, 'purchases' => 1], 4.44, 7.61, ['spend' => 20.0, 'purchases' => 2]);
        expect($v['stop'])->toBeTrue()->and($v['data'])->toStartWith('7 días: gastó $20,00')->and($v['reading'])->toStartWith('Pierde dinero: en los últimos 7 días');
    });

    it('sin compras también, pero solo desde 3×C', function () use ($falla) {
        expect(reglas()->failingStop($falla, ['spend' => 14.0, 'purchases' => 0], 4.44, 7.61)['reading'])->toStartWith('Pierde dinero: en los últimos 3 días gastó 3,2×C sin compras.')
            ->and(reglas()->failingStop($falla, ['spend' => 13.0, 'purchases' => 0], 4.44, 7.61))->toBeNull();
    });

    it('el margen: 20 % encima del BE, o 20 compras en el BE o encima', function () use ($falla) {
        expect(reglas()->failingStop($falla, ['spend' => 17.9, 'purchases' => 2], 4.44, 7.61))->toBeNull()          // CPA 8,95: 18 % encima
            ->and(reglas()->failingStop($falla, ['spend' => 18.3, 'purchases' => 2], 4.44, 7.61)['stop'])->toBeTrue() // CPA 9,15: 20 % encima
            ->and(reglas()->failingStop($falla, ['spend' => 160.0, 'purchases' => 21], 4.44, 7.61)['stop'])->toBeTrue()
            ->and(reglas()->failingStop($falla, ['spend' => 150.0, 'purchases' => 21], 4.44, 7.61))->toBeNull();     // debajo del BE
    });

    it('la única razón para dejarlo prendido es el tracking', function () use ($falla) {
        $conCausa = fn (string $k) => ['causes' => [['key' => $k]]] + $falla;
        expect(reglas()->failingStop($conCausa('tracking'), ['spend' => 51.87, 'purchases' => 5], 4.44, 7.61))->toBeNull()
            ->and(reglas()->failingStop($conCausa('auction'), ['spend' => 51.87, 'purchases' => 5], 4.44, 7.61)['stop'])->toBeTrue()
            ->and(reglas()->failingStop($conCausa('fatigue'), ['spend' => 51.87, 'purchases' => 5], 4.44, 7.61)['stop'])->toBeTrue();
    });

    it('solo a los ganadores que fallan, y con su C', function () use ($falla) {
        expect(reglas()->failingStop(['key' => 'proven'] + $falla, ['spend' => 51.87, 'purchases' => 5], 4.44, 7.61))->toBeNull()
            ->and(reglas()->failingStop(['key' => 'intermediate'] + $falla, ['spend' => 51.87, 'purchases' => 5], 4.44, 7.61))->toBeNull()
            ->and(reglas()->failingStop($falla, ['spend' => 51.87, 'purchases' => 5], null, null))->toBeNull()
            ->and(reglas()->failingStop($falla, ['spend' => 51.87, 'purchases' => 5], 4.44, null))->toBeNull()
            ->and(reglas()->failingStop($falla, null, 4.44, 7.61))->toBeNull();
    });

    it('debajo del BE nunca pierde, aunque tenga 20 compras', function () {
        expect(reglas()->recentLoser(['spend' => 100.0, 'purchases' => 21], ['key' => 'intermediate', 'label' => 'Intermedio'], 4.0, 5.7))->toBeNull();
    });
});

describe('.86: el que se cae en los últimos 3 días (C = 4, BE = 5,70)', function () {
    $intermedio = ['key' => 'intermediate', 'label' => 'Intermedio'];

    it('un intermedio que en 3 días gastó 2×C sin compras se apaga en esa ciudad', function () use ($intermedio) {
        $v = reglas()->recentLoser(['spend' => 10.43, 'purchases' => 0], $intermedio, 4.0, 5.7);
        expect($v['key'])->toBe('loser')
            ->and($v['data'])->toBe('3 días: gastó $10,43 (2,6×C) · 0 compras · C $4,00 · BE $5,70')
            ->and($v['reading'])->toBe('Perdedor en los últimos 3 días: gastó 2,6×C sin compras (en toda su vida: intermedio).')
            ->and($v['proposal'])->toBe('Apagarlo en esta ciudad.');
    });

    it('con compras, solo si el CPA de los 3 días pasa el BE con el margen (.101)', function () use ($intermedio) {
        $v = reglas()->recentLoser(['spend' => 14.0, 'purchases' => 2], $intermedio, 4.0, 5.7);                       // CPA 7: 23 % encima
        expect($v['key'])->toBe('loser')
            ->and($v['reading'])->toBe('Perdedor en los últimos 3 días: gastó 3,5×C con el CPA 23 % encima del break-even (en toda su vida: intermedio).')
            ->and(reglas()->recentLoser(['spend' => 12.0, 'purchases' => 2], $intermedio, 4.0, 5.7))->toBeNull()          // CPA 6: 5 % encima
            ->and(reglas()->recentLoser(['spend' => 10.0, 'purchases' => 2], $intermedio, 4.0, 5.7))->toBeNull();         // CPA 5: todavía gana
    });

    it('no se apaga antes de 2×C, ni un ganador (tiene su regla), ni sin C', function () use ($intermedio) {
        expect(reglas()->recentLoser(['spend' => 7.9, 'purchases' => 0], $intermedio, 4.0, 5.7))->toBeNull()
            ->and(reglas()->recentLoser(['spend' => 20.0, 'purchases' => 0], ['key' => 'proven', 'label' => 'PROVEN'], 4.0, 5.7))->toBeNull()
            ->and(reglas()->recentLoser(['spend' => 20.0, 'purchases' => 0], ['key' => 'failing', 'label' => 'Ganador que falla'], 4.0, 5.7))->toBeNull()
            ->and(reglas()->recentLoser(['spend' => 20.0, 'purchases' => 0], $intermedio, null, null))->toBeNull()
            ->and(reglas()->recentLoser(null, $intermedio, 4.0, 5.7))->toBeNull()
            ->and(reglas()->recentLoser(['spend' => 9.0, 'purchases' => 0], ['key' => 'evaluation', 'label' => 'En evaluación'], 4.0, 5.7)['key'])->toBe('loser');
    });
});

describe('.114: si en 3 días no llegó a 2×C, se mira la semana (FlexiStep en Carabobo: C $6,38, BE $9,78)', function () {
    $intermedio = ['key' => 'intermediate', 'label' => 'Intermedio'];

    it('CRE003-FLEX-FAS-BATCH003: $11,93 en 3 días y $26,43 en 7, sin compras: perdedor por la semana', function () use ($intermedio) {
        $v = reglas()->recentLoser(['spend' => 11.93, 'purchases' => 0], $intermedio, 6.38, 9.78, ['spend' => 26.43, 'purchases' => 0]);
        expect($v['key'])->toBe('loser')
            ->and($v['data'])->toBe('7 días: gastó $26,43 (4,1×C) · 0 compras · C $6,38 · BE $9,78')
            ->and($v['reading'])->toBe('Perdedor en los últimos 7 días: gastó 4,1×C sin compras (en toda su vida: intermedio).')
            ->and(reglas()->recentLoser(['spend' => 11.93, 'purchases' => 0], $intermedio, 6.38, 9.78))->toBeNull();   // sin la semana, como antes
    });

    it('CRE007-FEX-FASP-REF007: $12,68 en 3 días sin compras, pero 5 compras en la semana con CPA debajo de C: sigue', function () use ($intermedio) {
        expect(reglas()->recentLoser(['spend' => 12.68, 'purchases' => 0], $intermedio, 6.38, 9.78, ['spend' => 30.54, 'purchases' => 5]))->toBeNull();
    });

    it('si en 3 días ya llegó a 2×C, mandan los 3 días', function () use ($intermedio) {
        $v = reglas()->recentLoser(['spend' => 13.0, 'purchases' => 0], $intermedio, 6.38, 9.78, ['spend' => 30.54, 'purchases' => 5]);
        expect($v['reading'])->toBe('Perdedor en los últimos 3 días: gastó 2×C sin compras (en toda su vida: intermedio).');
    });
});

describe('.101: margen antes de apagar por el BE (dueño, 2026-11-04)', function () {
    it('el CRE002 de Termafit en Aragua (CPA $4,78, 10 compras, BE $4,39) sigue en evaluación', function () {
        $v = reglas()->state(47.80, 10, 2.91, 4.39, 'creativo');
        expect($v['key'])->toBe('evaluation')
            ->and($v['reading'])->toBe('En evaluación: CPA 9 % encima del break-even con 10 compras. Con menos de 20 compras se apaga solo si pasa el 20 %.')
            ->and($v['proposal'])->toBe('Mantener; se vuelve a mirar cada día.');
    });

    it('con el CPA 20 % encima, o con 20 compras, es perdedor', function () {
        expect(reglas()->state(53.0, 10, 2.91, 4.39, 'creativo')['key'])->toBe('loser')    // CPA 5,30: 21 % encima
            ->and(reglas()->state(95.6, 20, 2.91, 4.39, 'creativo')['key'])->toBe('loser');  // CPA 4,78 con 20 compras
    });

    it('el margen se cambia en Reglas (0 = como antes)', function () {
        expect(reglas(['loser_be_margin_pct' => 0])->state(47.80, 10, 2.91, 4.39)['key'])->toBe('loser')
            ->and(reglas(['loser_be_margin_purchases' => 10])->state(47.80, 10, 2.91, 4.39)['key'])->toBe('loser');
    });

    it('con 0 o 1 compra no hay margen: sigue siendo perdedor a 2×C', function () {
        expect(reglas()->state(8.5, 1, 4.0, 5.70)['key'])->toBe('loser');
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
    // .80 (dueño, 2026-11-02): el video por tramos, cada uno sobre la gente que llega a él; el editor recibe solo el primero
    // flojo. Base: hook 45 %, después del hook (hold) 44 %, medio 50 %, cierre 67 %, CTR único 3,6 %: todo bien.
    $video = fn (array $t) => ventana(array_merge(['spend' => 20, 'impressions' => 10000, 'reach' => 9000, 'link_clicks' => 350,
        'unique_link_clicks' => 320, 'landing_page_views' => 280, 'purchases' => 5, 'video_plays' => 5000, 'video_3s_plays' => 4500,
        'thruplays' => 2000, 'video_p25' => 3000, 'video_p75' => 1500, 'video_p100' => 1000, 'video_watch_time' => 5000 * 9], $t));

    it('el hook flojo es el primer tramo: hooks nuevos', function () use ($video) {
        $v = reglas()->video($video(['video_3s_plays' => 2500, 'thruplays' => 1200]), true, 30);
        expect($v['grades']['hook'])->toBe('weak')->and($v['grades']['hold'])->toBe('good')->and($v['iterate'])->toBe('hook')
            ->and($v['diagnosis'][0])->toBe('El hook está flojo: solo el 25 % de los que lo ven se queda 3 segundos.');
    });

    it('hook bueno y se van justo después: el hold rate (del segundo 3 al 15)', function () use ($video) {
        $v = reglas()->video($video(['thruplays' => 1000]), true, 30);
        expect($v['grades']['hook'])->toBe('good')->and($v['grades']['hold'])->toBe('weak')->and($v['iterate'])->toBe('after_hook')
            ->and($v['leaves'])->toBe('after_hook');
    });

    it('el medio y el cierre, sobre los que llegaron a ese tramo; el tiempo medio contra la duración', function () use ($video) {
        $mid = reglas()->video($video(['video_p75' => 900]), true, 30);
        expect($mid['iterate'])->toBe('middle')->and($mid['leaves'])->toBe('body')->and($mid['stages']['middle']['value'])->toBe(30.0);
        $end = reglas()->video($video(['video_p100' => 500]), true, 30);
        expect($end['iterate'])->toBe('close')->and($end['leaves'])->toBe('end');
        $v = reglas()->video($video([]), true, 30);
        expect($v['iterate'])->toBeNull()->and($v['diagnosis'])->toBe([])->and($v['watch_pct'])->toBe(30.0)->and($v['grades']['watch'])->toBe('good')
            ->and(array_keys($v['stages']))->toBe(['hook', 'after_hook', 'middle', 'close', 'click']);
    });

    it('si no pasan el hook, el cierre no importa: decide el primer tramo flojo', function () use ($video) {
        $v = reglas()->video($video(['video_3s_plays' => 2500, 'thruplays' => 1200, 'video_p100' => 500]), true, 30);
        expect($v['iterate'])->toBe('hook')->and($v['diagnosis'])->toHaveCount(2);
    });

    it('contra los ganadores del producto: flojo si está bastante debajo de su mediana', function () use ($video) {
        $v = reglas()->video($video([]), true, 30, ['hook_rate' => 60.0, 'retention_middle' => 70.0]);
        expect($v['grades']['hook'])->toBe('weak')->and($v['grades']['middle'])->toBe('weak')
            ->and($v['diagnosis'][0])->toBe('El hook está flojo: solo el 45 % de los que lo ven se queda 3 segundos (tus ganadores: 60 %).');
    });

    it('ZenPulse CREATIVO 6 (2/11): con hook de 51 % ya no dice que el gancho no engancha', function () use ($video) {
        // hook 51,2 %, hold 41 %, de cada 100 que empiezan: 33,7 al 25 %, 15,8 al 75 %, 10,3 al final; CTR único 6,8 %
        $v = reglas()->video($video(['video_plays' => 10000, 'video_3s_plays' => 5120, 'thruplays' => 2099, 'video_p25' => 3370, 'video_p75' => 1580,
            'video_p100' => 1030, 'unique_link_clicks' => 612]), true, 30);
        expect($v['grades']['hook'])->toBe('good')->and($v['iterate'])->toBeNull()->and($v['diagnosis'])->toBe([]);
    });

    it('engancha y hace clic pero no compra: la landing, solo si está caro (CPA encima de C)', function () use ($video) {
        $caro = reglas()->video($video(['purchases' => 2]), true, 30, ['conversion_rate' => 2.0], null, 10.0, 4.0);
        expect($caro['grades']['conversion'])->toBe('weak')->and($caro['iterate'])->toBe('landing');
        $barato = reglas()->video($video(['purchases' => 2]), true, 30, ['conversion_rate' => 2.0], null, 3.0, 4.0);
        expect($barato['iterate'])->toBeNull()->and($barato['diagnosis'])->toBe([]);
    });

    it('CPM alto: probar en imagen, solo si está caro', function () use ($video) {
        expect(reglas()->video($video([]), true, 30, [], 1.0, 6.0, 4.0)['iterate'])->toBe('format')
            ->and(reglas()->video($video([]), true, 30, [], 1.0, 3.0, 4.0)['diagnosis'])->toBe([]);
    });

    it('una imagen no se mide por hook ni retención', function () use ($video) {
        $v = reglas()->video($video([]), false, null);
        expect($v['grades']['hook'])->toBeNull()->and($v['leaves'])->toBeNull()->and($v['grades']['ctr'])->toBe('good')
            ->and(array_keys($v['stages']))->toBe(['click']);
    });

    it('con pocos datos un tramo no se juzga', function () use ($video) {
        $v = reglas()->video($video(['video_p75' => 20, 'video_p100' => 5]), true, 30);
        expect($v['stages'])->not->toHaveKey('close');
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
