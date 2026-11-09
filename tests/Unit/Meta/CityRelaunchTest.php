<?php

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Rules\CampaignRules;

// .117 (dueño, 2026-11-09): "cuando una campaña se apaga, se debe esperar a tener 3 batch nuevos otra vez para reactivarla con
// esos 3 batchs, no se tiene que acelerar el proceso únicamente por esa ciudad, simplemente cuando hayan 3 batchs que no se
// hayan publicado en esa campaña, dice que se reactive y se coloquen esos 3 batchs".

function relaunchIn(array $over = []): array
{
    return $over + ['mode' => 'normal', 'c' => 5.0, 'be' => 8.0, 'city_free' => true, 'available' => true, 'dismissed' => false,
        'offers' => [['code' => 'B1', 'how' => 'bank'], ['code' => 'B2', 'how' => 'bank'], ['code' => 'B3', 'how' => 'bank']],
        'status_events' => [], 'new_ads' => [], 'daily' => [], 'today' => '2026-11-09'];
}

function relaunchRules(): CampaignRules
{
    return new CampaignRules(DecisionSettings::defaults());
}

$off = fn (string $date) => ['at' => "{$date} 10:00:00", 'date' => $date, 'from' => 'ACTIVE', 'to' => 'PAUSED', 'budget' => 25.0];
$on = fn (string $date) => ['at' => "{$date} 10:00:00", 'date' => $date, 'from' => 'PAUSED', 'to' => 'ACTIVE', 'budget' => 40.0];

describe('Cuándo se prende otra vez', function () use ($off, $on) {
    it('con 3 batches nunca publicados ahí: se prende con esos, con tu regla de arranque', function () {
        $a = relaunchRules()->relaunch(relaunchIn());
        expect($a['key'])->toBe('relaunch')->and($a['to'])->toBe(37.5)->and($a['from'])->toBe(45.0)
            ->and($a['proposal'])->toBe('Prenderla solo con esos 3 y un presupuesto de arranque entre $37,50 y $45,00 por día.');
    });

    it('con menos de 3 espera, sin importar cuánto tiempo pasó', function () {
        expect(relaunchRules()->relaunch(relaunchIn(['offers' => [['code' => 'B1', 'how' => 'bank'], ['code' => 'B2', 'how' => 'bank']]])))->toBeNull();
    });

    it('no vence: una campaña apagada hace meses también', function () use ($off) {
        expect(relaunchRules()->relaunch(relaunchIn(['status_events' => [$off('2026-06-01')]]))['key'])->toBe('relaunch');
    });

    it('nada si el producto no está activo, si no se vende ahí, si no tiene C o si la descartaste', function () {
        foreach ([['mode' => 'paused'], ['mode' => 'liquidation'], ['available' => false], ['c' => null], ['dismissed' => true], ['city_free' => false]] as $over) {
            expect(relaunchRules()->relaunch(relaunchIn($over)))->toBeNull();
        }
    });

    it('si se prendió otra vez con batches nuevos y volvió a perder: recomienda dejar el producto ahí', function () use ($off, $on) {
        $in = relaunchIn(['status_events' => [$off('2026-10-20'), $on('2026-11-01'), $off('2026-11-06')], 'new_ads' => ['2026-11-01'],
            'daily' => ['2026-11-02' => ['spend' => 30, 'purchases' => 1], '2026-11-03' => ['spend' => 30, 'purchases' => 2]]]);
        $a = relaunchRules()->relaunch($in);
        expect($a['key'])->toBe('left_city')->and($a['reading'])->toBe('La volviste a prender con batches nuevos el 01/11 y también perdió.')
            ->and($a['proposal'])->toBe('Recomendación: dejar el producto en esta ciudad. Lo decides tú.');
    });

    it('esa recomendación se calla a los 14 días, y no te vuelve a pedir prenderla', function () use ($off, $on) {
        $in = relaunchIn(['today' => '2026-11-30', 'status_events' => [$off('2026-10-20'), $on('2026-11-01'), $off('2026-11-06')], 'new_ads' => ['2026-11-01'],
            'daily' => ['2026-11-02' => ['spend' => 30, 'purchases' => 1]]]);
        expect(relaunchRules()->relaunch($in))->toBeNull();
    });

    it('no es perder si se prendió sin anuncios nuevos, si no llegó a gastar o si vendió debajo del BE', function () use ($off, $on) {
        $events = [$off('2026-10-20'), $on('2026-11-01'), $off('2026-11-06')];
        $spent = ['2026-11-02' => ['spend' => 30, 'purchases' => 1]];
        expect(relaunchRules()->relaunch(relaunchIn(['status_events' => $events, 'new_ads' => [], 'daily' => $spent]))['key'])->toBe('relaunch')
            ->and(relaunchRules()->relaunch(relaunchIn(['status_events' => $events, 'new_ads' => ['2026-11-01'], 'daily' => ['2026-11-02' => ['spend' => 2, 'purchases' => 0]]]))['key'])->toBe('relaunch')
            ->and(relaunchRules()->relaunch(relaunchIn(['status_events' => $events, 'new_ads' => ['2026-11-01'], 'daily' => ['2026-11-02' => ['spend' => 30, 'purchases' => 6]]]))['key'])->toBe('relaunch');
    });

    it('apagada una sola vez no es "volvió a perder"', function () use ($off) {
        expect(relaunchRules()->relaunch(relaunchIn(['status_events' => [$off('2026-11-06')], 'new_ads' => ['2026-11-06'], 'daily' => ['2026-11-05' => ['spend' => 30, 'purchases' => 0]]]))['key'])->toBe('relaunch');
    });
});

describe('Cuál campaña de la ciudad', function () use ($off) {
    $c = fn (bool $active, ?int $city, array $extra = []) => ['active' => $active, 'city_id' => $city] + $extra;

    it('la última que se apagó, si no hay otra prendida ahí y sigue en Meta', function () use ($c, $off) {
        $list = ['1' => $c(false, 3), '2' => $c(false, 3), '3' => $c(false, 7), '4' => $c(true, 7), '5' => $c(false, 9, ['missing' => true]), '6' => $c(false, null)];
        $h = ['1' => ['status_events' => [$off('2026-11-05')]], '2' => ['status_events' => [$off('2026-10-01')]]];
        expect(MetaDecisions::toRelaunch($list, $h))->toBe([3 => '1']);
    });
});

describe('La misión', function () {
    $base = ['product_id' => 1, 'product' => 'FlexiStep® Tobillera', 'mode' => 'normal', 'urgency' => null, 'money_at_risk' => 0, 'next_work' => [], 'price' => [], 'price_watch' => null,
        'alarms' => [], 'tracking' => null, 'creatives' => [], 'batches' => [], 'stop_by_city' => [], 'stop_all' => [], 'no_city' => null];
    $numbers = ['spend3' => 0.0, 'purchases3' => 0.0, 'c' => 5.0, 'be' => 8.0, 'source' => null, 'budget' => 25.0, 'streak' => 0, 'prune' => [], 'live' => 0,
        'offers' => [['code' => 'B1', 'how' => 'bank', 'adset' => 'B1 POSP', 'ads' => ['C1 POSP']], ['code' => 'B2', 'how' => 'bank'], ['code' => 'B3', 'how' => 'copy', 'adset' => 'B3 POSP', 'campaign' => 'FLEXI | CCS', 'city' => 'Caracas', 'verdict' => 'Prometedor']]];
    $city = fn (string $key, string $label, array $n, ?float $from = null, ?float $to = null) => ['campaign_meta_id' => '9', 'campaign' => 'FLEXI | ARAGUA', 'city' => 'Aragua',
        'action' => ['key' => $key, 'label' => $label, 'tone' => 'success', 'data' => '', 'reading' => 'Hay 3 batches que nunca se publicaron en esta ciudad.', 'proposal' => 'Prenderla.', 'from' => $from, 'to' => $to],
        'notes' => [], 'budget_notes' => [], 'price_option' => null, 'numbers' => $n];

    it('dice qué lanzar, que lo anterior se queda apagado y cuánto presupuesto', function () use ($base, $city, $numbers) {
        $i = UrgentList::build([$base + ['cities' => [$city('relaunch', 'Prender otra vez', $numbers, 45.0, 37.5)]]])[0];
        expect($i)->toMatchArray(['kind' => 'relaunch', 'group' => 'crecer', 'title' => 'Prende esta campaña con B1, B2 y B3', 'where' => 'FlexiStep · Aragua', 'why' => 'Hay 3 batches que nunca se publicaron aquí',
            'batches' => ['B1', 'B2'], 'money' => ['cut' => null, 'add' => 37.5]])
            ->and($i['steps'][0])->toBe('Abre la campaña «FLEXI | ARAGUA».')
            ->and($i['steps'][1])->toBe('Deja apagados los ad sets y los anuncios que ya estaban: esta vez solo van estos 3.')
            ->and($i['steps'][2])->toContain('Crea el ad set «B1 POSP»')
            ->and($i['steps'][4])->toContain('Duplica el ad set «B3 POSP» de la campaña «FLEXI | CCS»')
            // .124: el gasto mínimo de cada ad set nuevo y los parámetros de URL de sus anuncios
            ->and($i['steps'][5])->toBe('Ponle a cada ad set nuevo un gasto mínimo diario de $5,00 (tu C aquí). Va solo mientras se prueba.')
            ->and($i['steps'][6])->toBe('En cada anuncio nuevo, abre «Seguimiento» y pega los parámetros de URL (botón «Copiar parámetros», aquí abajo).')
            ->and($i['copy'])->toBe(['label' => 'Copiar parámetros', 'text' => 'utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}'])
            ->and($i['steps'][7])->toBe('Pon el presupuesto de arranque entre $37,50 y $45,00 por día (tu regla: C × 3 batches × 2,5 a 3).')
            ->and($i['steps'][8])->toBe('Prende la campaña.')
            ->and(UrgentList::tally([$i], [])['tasks'])->toBe(1);
    });

    it('la recomendación de dejarla es un aviso, sin pasos', function () use ($base, $city, $numbers) {
        $i = UrgentList::build([$base + ['cities' => [$city('left_city', 'Dejar el producto aquí', $numbers)]]])[0];
        expect($i)->toMatchArray(['kind' => 'left_city', 'group' => 'aviso', 'steps' => []])->and($i['title'])->toContain('volvió a perder con batches nuevos');
    });

    it('sin ninguna ciudad prendida: una misión para decidir', function () use ($base) {
        $i = UrgentList::build([array_replace($base, ['cities' => [], 'no_city' => ['have' => 1, 'need' => 3]])])[0];
        expect($i)->toMatchArray(['kind' => 'no_city', 'group' => 'arreglar', 'key' => '1:no_city', 'title' => 'Se quedó sin ninguna ciudad prendida: decide qué hacer', 'where' => 'FlexiStep'])
            ->and($i['why'])->toContain('Tiene 1 de los 3 batches')
            ->and($i['steps'][1])->toContain('«pon FlexiStep en testeo»');
    });

    it('prender una ciudad no pide batches ni la vuelve urgente', function () {
        $r = relaunchRules()->urgency(['mode' => 'normal', 'cities' => ['scale'], 'healthy' => 1, 'failing' => 0]);
        expect($r['level'])->toBe(3)->and(UrgentList::need('relaunch', ['live' => 0], 'normal'))->toBe(0);
    });
});
