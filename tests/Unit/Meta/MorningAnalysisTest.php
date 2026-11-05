<?php

use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\AnalysisWriter;
use App\Services\Meta\Analysis\DayStreak;
use App\Services\Meta\Analysis\MoneyCheck;
use App\Services\Meta\Analysis\UrgentList;

// Etapa 5, paso 3a: el análisis de la mañana. La lista urgente la arman las reglas; la IA solo redacta.

function producto(int $id, string $name, int $level, float $risk, array $cities, array $alarms = [], ?string $watch = null): array
{
    return ['product_id' => $id, 'product' => $name, 'mode' => 'normal', 'urgency' => ['level' => $level, 'label' => "U{$level}", 'batches' => '1', 'reasons' => []],
        'money_at_risk' => $risk, 'next_work' => [], 'price' => [], 'price_watch' => $watch, 'alarms' => $alarms, 'tracking' => null,
        'cities' => $cities, 'creatives' => [], 'batches' => []];
}

function ciudad(string $id, string $city, string $key, string $label, ?string $price = null): array
{
    return ['campaign_meta_id' => $id, 'campaign' => null, 'city' => $city, 'notes' => [], 'budget_notes' => [], 'price_option' => $price,
        'action' => ['key' => $key, 'label' => $label, 'tone' => 'info', 'data' => '', 'reading' => "lectura {$label}", 'proposal' => "propuesta {$label}", 'from' => null, 'to' => null]];
}

describe('La lista urgente', function () {
    it('primero lo que está en el break-even o encima, después escalar y después el resto', function () {
        // .87: con un anuncio que apagar (sin nada que hacer hoy serían avisos)
        $poda = fn (array $c) => $c + ['numbers' => ['spend3' => 30, 'purchases3' => 4, 'c' => 5, 'be' => 7, 'source' => null, 'budget' => 20, 'streak' => 0, 'prune' => ['AD 1']]];
        $facts = [
            producto(1, 'FlexiStep', 3, 0, [ciudad('c1', 'Caracas', 'scale', 'Subir $39,60 → $43,56', 'O probar bajar el precio $2,00'), ciudad('c2', 'Maracay', 'hold', 'Mantener')]),
            producto(2, 'Loli', 1, 60, [ciudad('c3', 'Caracas', 'drop', 'Dejar el producto aquí'), ciudad('c4', 'Valencia', 'decrease', 'Bajar $30,00 → $24,00'),
                $poda(ciudad('c5', 'Maracay', 'hold_prune', 'Mantener y podar'))]),
            producto(3, 'Cervimax', 2, 20, [$poda(ciudad('c6', 'Caracas', 'intervene', 'Intervenir'))], ['Tracking: Meta ve el 70 % de los pedidos reales…']),
        ];
        $list = UrgentList::build($facts);
        expect(array_map(fn ($i) => "{$i['product']} {$i['kind']}", $list))->toBe([
            // dentro del mismo grupo, el producto más urgente (Loli, U1) primero; y en un producto, la alarma de tracking antes de tocar campañas
            'Loli drop', 'Loli decrease', 'Cervimax tracking', 'Cervimax intervene', 'Loli hold_prune', 'FlexiStep scale', 'FlexiStep price',
        ]);
        expect(collect($list)->pluck('kind'))->not->toContain('hold');
    });

    it('entre iguales, el producto más urgente y con más dinero en riesgo primero', function () {
        $list = UrgentList::build([
            producto(1, 'A', 2, 10, [ciudad('a', 'Caracas', 'intervene', 'Intervenir')]),
            producto(2, 'B', 1, 5, [ciudad('b', 'Caracas', 'intervene', 'Intervenir')]),
            producto(3, 'C', 1, 50, [ciudad('c', 'Caracas', 'intervene', 'Intervenir')]),
        ]);
        expect(array_column($list, 'product'))->toBe(['C', 'B', 'A']);
    });

    it('las alarmas de stock y "no se vende aquí" son de lo más urgente; las claves no cambian entre versiones', function () {
        $facts = [producto(1, 'Solo Brillo', 4, 0, [ciudad('m', 'Maracay', 'none', 'No se vende aquí'), ciudad('x', 'Caracas', 'none', 'Nada')],
            ['Sin stock en Caracas y la campaña sigue activa.', 'Sin stock en Caracas de: S, M.'])];
        $list = UrgentList::build($facts);
        expect(array_column($list, 'kind'))->toBe(['stock_out', 'not_sold', 'alarm'])
            ->and(UrgentList::build($facts)[1]['key'])->toBe($list[1]['key']);
    });

    it('.101: los pedidos esperando stock son un aviso de arriba, con lo gastado en el porqué (Termafit, 4/11)', function () {
        $t = '55 pedidos esperando stock en Distrito Capital desde el 29/10: la campaña gastó $178,46 desde entonces. Manda stock; mientras tanto no se sube el presupuesto ahí.';
        $w = UrgentList::build([producto(1177, 'Termafit', 4, 0, [], [$t])])[0];
        expect($w['kind'])->toBe('stock_waiting')->and($w['group'])->toBe('aviso')->and($w['severity'])->toBe(1)->and($w['tone'])->toBe('error')
            ->and($w['title'])->toBe('55 pedidos esperando stock en Distrito Capital desde el 29/10')
            ->and($w['why'])->toBe('la campaña gastó $178,46 desde entonces. Manda stock; mientras tanto no se sube el presupuesto ahí.')
            ->and($w['detail'])->toBe([$t]);
    });
});

// .82: cada punto, una cosa que hacer en Ads Manager, con su frase, su porqué y su grupo
function numeros(float $spend, float $purchases, float $c, ?float $be, ?float $budget = null, int $streak = 0, array $prune = [], ?string $bank = null): array
{
    return ['spend3' => $spend, 'purchases3' => $purchases, 'c' => $c, 'be' => $be, 'source' => null, 'budget' => $budget, 'streak' => $streak,
        'prune' => $prune, 'bank' => $bank];
}

describe('.82: tu día en Meta Ads', function () {
    it('cada punto dice qué hacer con los montos exactos, por qué y en qué grupo va', function () {
        $sube = ciudad('c1', 'Caracas', 'scale', 'Subir $51,60 → $61,92');
        $sube['action']['from'] = 51.6;
        $sube['action']['to'] = 61.92;
        $sube['numbers'] = numeros(35.4, 10, 5.39, 7.22, 51.6);
        $sube['campaign'] = 'FLEXISTEP CARACAS CBO';
        $interviene = ciudad('c2', 'Aragua', 'intervene', 'Intervenir');
        $interviene['numbers'] = numeros(72, 7, 5.39, 7.22, 30, 1, ['AD 1', 'AD 2']);
        $interviene['campaign'] = 'FLEXISTEP ARAGUA CBO';
        $poda = ciudad('c3', 'Carabobo', 'hold_prune', 'Mantener y podar');
        $poda['numbers'] = numeros(36.75, 5, 5.39, 7.22, 20, 0, ['AD 3'], 'B0104');
        $list = UrgentList::build([producto(1, 'FlexiStep® Tobillera de compresion profesional', 1, 72, [$sube, $interviene, $poda], ['Poco stock en Caracas: quedan 3 unidades.'])]);
        $by = collect($list)->keyBy('kind');

        // .83: dónde, qué (corto, con el verbo primero) y cómo (los pasos en Ads Manager con los nombres exactos)
        expect($by['scale']['where'])->toBe('FlexiStep · Caracas')
            ->and($by['scale']['title'])->toBe('Sube el presupuesto a $61,92 por día')
            ->and($by['scale']['steps'])->toBe(['Abre la campaña «FLEXISTEP CARACAS CBO».', 'Cambia el presupuesto diario de $51,60 a $61,92 (+20 %).'])
            ->and($by['scale']['why'])->toBe('+20 % · $3,54 por compra; tu objetivo es $5,39')
            ->and($by['scale']['group'])->toBe('crecer')
            ->and($by['intervene']['where'])->toBe('FlexiStep · Aragua')
            ->and($by['intervene']['title'])->toBe('Apaga 2 anuncios que pierden')
            // .87: sin nada en la banca, no pide "cuando llegue un batch": solo lo que se puede hacer hoy
            ->and($by['intervene']['steps'])->toBe(['Abre la campaña «FLEXISTEP ARAGUA CBO».', 'Apaga «AD 1» y «AD 2».', 'No toques el presupuesto hoy.'])
            ->and($by['intervene']['why'])->toBe('$10,29 por compra; tu límite es $7,22 · 1 día encima')
            ->and($by['intervene']['group'])->toBe('arreglar')
            ->and($by['intervene']['detail'][0])->toBe('En 3 días: gastaste $72,00 y Meta vio 7 compras = $10,29 por compra.')
            ->and($by['intervene']['detail'][1])->toBe('Tu objetivo (C) aquí es $5,39 y tu límite para no perder (BE) es $7,22.')
            ->and($by['hold_prune']['title'])->toBe('Apaga 1 anuncio caro y lanza B0104')
            ->and($by['hold_prune']['steps'])->toBe(['Abre la campaña de FlexiStep en Carabobo.', 'Apaga «AD 3».', 'Lanza el batch «B0104» de la banca (sus videos, aquí abajo).'])
            ->and($by['hold_prune']['why'])->toBe('$7,35 por compra: entre tu objetivo ($5,39) y tu límite ($7,22)')
            ->and($by['alarm']['group'])->toBe('aviso')
            ->and($by['alarm']['title'])->toBe('Poco stock en Caracas: quedan 3 unidades.')
            ->and($by['alarm']['where'])->toBe('FlexiStep');
        expect(UrgentList::short('Demo Crema corporal'))->toBe('Demo Crema corporal')
            ->and(UrgentList::short('ZenPulse - Masajeador'))->toBe('ZenPulse');
    });

    it('suma los creativos que hay que apagar: los perdedores en todas las ciudades y los demás solo donde pierden', function () {
        $interviene = ciudad('c2', 'Aragua', 'intervene', 'Intervenir');
        $interviene['numbers'] = numeros(72, 7, 5.39, 7.22, 30, 1, ['AD 1'], 'B0104');
        $interviene['campaign'] = 'FLEXISTEP ARAGUA CBO';
        $p = producto(1, 'FlexiStep', 1, 72, [$interviene, ciudad('c4', 'Carabobo', 'pause_city', 'Pausar la ciudad')]);
        $p['stop_all'] = ['C004'];
        $p['stop_by_city'] = ['Aragua' => ['CRE008', 'C004', 'CRE003'], 'Carabobo' => ['CRE009']];
        $p['creatives'] = [['id' => 8, 'label' => 'CRE008', 'reading' => 'Gana en Caracas', 'cities' => [['city' => 'Aragua', 'verdict' => 'Perdedor', 'data' => '3 días: gasto $20,00 · 0 compras']]]];
        $list = UrgentList::build([$p]);
        $stops = array_values(array_filter($list, fn ($i) => $i['kind'] === 'stop_creatives'));

        expect(array_column($stops, 'title'))->toBe(['Apaga C004 en todas las campañas', 'Apaga 2 creativos que aquí pierden'])
            ->and(array_column($stops, 'where'))->toBe(['FlexiStep · todas las ciudades', 'FlexiStep · Aragua'])
            ->and($stops[0]['steps'])->toBe(['En el buscador de Ads Manager, escribe «C004».', 'Apaga todos sus anuncios activos, en todas las campañas de FlexiStep.'])
            ->and($stops[1]['steps'][0])->toBe('Abre la campaña «FLEXISTEP ARAGUA CBO».')
            ->and($stops[1]['steps'][1])->toBe('Apaga los anuncios de «CRE008» y «CRE003» (búscalos por nombre).')
            ->and($stops[1]['why'])->toBe('CRE008, CRE003 · ahí pierden')
            ->and($stops[1]['detail'][0])->toBe('CRE008 en Aragua: 3 días: gasto $20,00 · 0 compras')
            ->and($stops[1]['group'])->toBe('frenar')
            // Carabobo se pausa entera: ahí no hace falta apagar creativos
            ->and(collect($stops)->pluck('city')->all())->not->toContain('Carabobo')
            // Aragua ya apaga sus creativos: intervenir solo lanza el batch de la banca
            ->and(collect($list)->firstWhere('kind', 'intervene')['title'])->toBe('Lanza B0104')
            ->and(collect($list)->firstWhere('kind', 'pause_city')['steps'])->toBe(['Abre la campaña de FlexiStep en Carabobo.', 'Apágala con el interruptor de la izquierda.'])
            ->and(UrgentList::build([$p])[1]['key'])->toBe($stops[1]['key']);
    });

    it('como un juego: cuánto presupuesto mueve cada misión, y "no estoy de acuerdo" también la resuelve', function () {
        $sube = ciudad('a', 'Caracas', 'scale', 'Subir $51,60 → $61,92');
        $sube['action']['from'] = 51.6;
        $sube['action']['to'] = 61.92;
        $baja = ciudad('b', 'Valencia', 'decrease', 'Bajar $30,00 → $24,00');
        $baja['action']['from'] = 30.0;
        $baja['action']['to'] = 24.0;
        $pausa = ciudad('c', 'Carabobo', 'pause_city', 'Pausar la ciudad');
        $pausa['action']['from'] = 25.0;
        $p = producto(1, 'FlexiStep', 1, 0, [$sube, $baja, $pausa], ['Poco stock en Caracas.']);
        $p['stop_all'] = ['C004'];
        $list = collect(UrgentList::build([$p]))->keyBy('kind');

        expect($list['scale']['money'])->toBe(['cut' => null, 'add' => 10.32])
            ->and($list['decrease']['money'])->toBe(['cut' => 6.0, 'add' => null])
            ->and($list['pause_city']['money'])->toBe(['cut' => 25.0, 'add' => null])
            // con CBO, apagar creativos no baja el gasto: Meta pasa esa plata a los otros anuncios
            ->and($list['stop_creatives']['money'])->toBeNull();

        $marks = [$list['scale']['key'] => ['status' => 'done'], $list['decrease']['key'] => ['status' => 'disagree'], $list['alarm']['key'] => ['status' => 'done']];
        expect(UrgentList::tally($list->values()->all(), $marks))->toBe(['tasks' => 4, 'resolved' => 2])
            // los análisis de antes de la .82 (sin grupo): los avisos tampoco cuentan
            ->and(UrgentList::tally([['key' => 'x', 'kind' => 'alarm'], ['key' => 'y', 'kind' => 'scale']], []))->toBe(['tasks' => 1, 'resolved' => 0]);
    });

    it('la racha: días seguidos con todo resuelto; un día sin misiones no la corta', function () {
        $days = [
            '2026-11-02' => ['tasks' => 5, 'resolved' => 1], // hoy: se suma en la pantalla al terminar
            '2026-11-01' => ['tasks' => 3, 'resolved' => 3],
            '2026-10-31' => ['tasks' => 0, 'resolved' => 0],
            '2026-10-30' => ['tasks' => 2, 'resolved' => 2],
            '2026-10-29' => ['tasks' => 4, 'resolved' => 3],
            '2026-10-28' => ['tasks' => 1, 'resolved' => 1],
        ];
        expect(DayStreak::count($days, '2026-11-02'))->toBe(2)
            ->and(DayStreak::count($days, '2026-10-30'))->toBe(0)
            ->and(DayStreak::count([], '2026-11-02'))->toBe(0);
    });

    it('los análisis sin números (de antes) siguen funcionando', function () {
        $list = UrgentList::build([producto(1, 'Loli', 1, 0, [ciudad('c', 'Valencia', 'decrease', 'Bajar $30,00 → $24,00')])]);
        expect($list[0]['title'])->toBe('Baja el presupuesto')->and($list[0]['where'])->toBe('Loli · Valencia')
            ->and($list[0]['why'])->toBe('lectura Bajar $30,00 → $24,00')
            ->and($list[0]['group'])->toBe('frenar');
    });
});

// .87: lo que puede lanzar una ciudad: de la banca o duplicado de otra ciudad
function oferta(string $code, ?string $from = null): array
{
    return $from === null ? ['code' => $code, 'how' => 'bank']
        : ['code' => $code, 'how' => 'copy', 'adset' => "{$code} FASP", 'campaign' => "FLEXISTEP | {$from}", 'city' => $from, 'verdict' => 'Prometedor'];
}

describe('.87: los batches por ciudad', function () {
    it('una misión que no se puede hacer hoy es un aviso: no cuenta para la racha', function () {
        $poda = ciudad('c3', 'Carabobo', 'hold_prune', 'Mantener y podar');
        $poda['numbers'] = numeros(37.4, 5, 5.77, 10, 25) + ['live' => 3, 'offers' => []];
        $poda['campaign'] = 'SOLOBRILLO | CBOBO';
        $interviene = ciudad('c2', 'Aragua', 'intervene', 'Intervenir');
        $interviene['numbers'] = numeros(72, 7, 5.39, 7.22, 30, 1) + ['live' => 2, 'offers' => []];
        $list = UrgentList::build([producto(1, 'Solo Brillo', 2, 0, [$poda, $interviene])]);
        $by = collect($list)->keyBy('kind');

        expect($by['hold_prune']['group'])->toBe('aviso')
            ->and($by['hold_prune']['title'])->toBe('Espera un batch nuevo para meter creativos: no hay en la banca. Cuando haya uno, te sale como misión.')
            ->and($by['hold_prune']['steps'])->toBe([])
            ->and($by['intervene']['group'])->toBe('aviso')
            ->and($by['intervene']['title'])->toStartWith('No toques el presupuesto y espera un batch nuevo')
            ->and(UrgentList::tally($list, []))->toBe(['tasks' => 0, 'resolved' => 0])
            // con los avisos, no entre lo más urgente
            ->and($by['intervene']['severity'])->toBe(UrgentList::SEVERITY['alarm']);
    });

    it('con algo que apagar sigue siendo misión, solo con lo de hoy', function () {
        $poda = ciudad('c3', 'Carabobo', 'hold_prune', 'Mantener y podar');
        $poda['numbers'] = numeros(37.4, 5, 5.77, 10, 25, 0, ['AD 3']) + ['live' => 3, 'offers' => []];
        $poda['campaign'] = 'SOLOBRILLO | CBOBO';
        $item = UrgentList::build([producto(1, 'Solo Brillo', 2, 0, [$poda])])[0];

        expect($item['title'])->toBe('Apaga 1 anuncio caro')
            ->and($item['group'])->toBe('arreglar')
            ->and($item['steps'])->toBe(['Abre la campaña «SOLOBRILLO | CBOBO».', 'Apaga «AD 3».']);
    });

    it('un batch que salió en otra ciudad se duplica aquí; con menos de 2 vivos se lanzan los que falten', function () {
        $poda = ciudad('c3', 'Carabobo', 'hold_prune', 'Mantener y podar');
        $poda['campaign'] = 'FLEXISTEP | CBOBO';
        $poda['numbers'] = numeros(36.75, 5, 5.36, 8.17, 25) + ['live' => 2, 'offers' => [oferta('B0102', 'Aragua'), oferta('B0103')]];
        $uno = UrgentList::build([producto(1, 'FlexiStep', 2, 0, [$poda])])[0];
        $poda['numbers']['live'] = 0;
        $dos = UrgentList::build([producto(1, 'FlexiStep', 2, 0, [$poda])])[0];

        // con 2 vivos, pide 1 (el que ya gana en Aragua va primero); con 0 vivos, 2
        expect($uno['title'])->toBe('Lanza B0102')
            ->and($uno['steps'])->toBe(['Abre la campaña «FLEXISTEP | CBOBO».',
                'Duplica el ad set «B0102 FASP» de la campaña «FLEXISTEP | Aragua» en esta campaña, con el mismo nombre (sin « - Copia») (en Aragua: prometedor).'])
            ->and($dos['title'])->toBe('Lanza B0102 y B0103')
            ->and($dos['steps'][2])->toBe('Lanza el batch «B0103» de la banca (sus videos, aquí abajo).')
            ->and($dos['detail'])->toContain('No queda ningún batch vivo aquí (ad sets prendidos con algún anuncio prendido, sin contar lo que apagas hoy). Tu regla: nunca menos de 2.')
            ->and(collect([$uno, $dos])->pluck('kind')->unique()->all())->toBe(['hold_prune'])
            // .102: los videos que se bajan en la misión: solo los de la banca (el que se duplica ya está en Meta)
            ->and($uno['batches'])->toBe([])->and($dos['batches'])->toBe(['B0103']);
    });

    it('una ciudad que sube o se mantiene con menos de 2 batches vivos: misión de lanzar lo que falta, o un aviso', function () {
        $sube = ciudad('c1', 'Aragua', 'scale', 'Subir $25,00 → $30,00');
        $sube['campaign'] = 'FLEXISTEP | ARAGUA';
        $sube['numbers'] = numeros(15, 5, 5.36, 8.17, 25) + ['live' => 1, 'offers' => [oferta('B0103'), oferta('B0104')]];
        $mantiene = ciudad('c2', 'Caracas', 'hold', 'Mantener');
        $mantiene['numbers'] = numeros(30, 6, 5.36, 8.17, 40) + ['live' => 1, 'offers' => []];
        $bien = ciudad('c4', 'Carabobo', 'hold', 'Mantener');
        $bien['numbers'] = numeros(30, 6, 5.36, 8.17, 40) + ['live' => 2, 'offers' => [oferta('B0103')]];
        $pausa = ciudad('c5', 'Lara', 'pause_city', 'Pausar la ciudad');
        $pausa['numbers'] = numeros(30, 0, 5.36, 8.17, 20) + ['live' => 0, 'offers' => [oferta('B0103')]];
        $list = UrgentList::build([producto(1, 'FlexiStep', 2, 0, [$sube, $mantiene, $bien, $pausa])]);
        $few = collect($list)->where('kind', 'few_batches')->keyBy('city');

        expect($few->keys()->sort()->values()->all())->toBe(['Aragua', 'Caracas'])
            ->and($few['Aragua']['title'])->toBe('Lanza 1 batch más: B0103')
            ->and($few['Aragua']['group'])->toBe('arreglar')
            ->and($few['Aragua']['steps'])->toBe(['Abre la campaña «FLEXISTEP | ARAGUA».', 'Lanza el batch «B0103» de la banca (sus videos, aquí abajo).'])
            ->and($few['Aragua']['why'])->toBe('Solo queda 1 batch vivo: $25,00 por día cae todo en él; con 2, Meta reparte')
            ->and($few['Caracas']['title'])->toBe('Solo queda 1 batch vivo y no hay más en la banca')
            ->and($few['Caracas']['group'])->toBe('aviso')
            ->and($few['Aragua']['batches'])->toBe(['B0103'])->and($few['Caracas']['batches'])->toBe([])
            // subir sigue siendo su propia misión
            ->and(collect($list)->where('kind', 'scale')->count())->toBe(1)
            ->and(UrgentList::tally($list, [])['tasks'])->toBe(3); // subir, lanzar en Aragua y pausar Lara
    });

    it('en liquidación no entran creativos; los análisis de antes siguen con su banca', function () {
        $poda = ciudad('c3', 'Carabobo', 'hold_prune', 'Mantener y podar');
        $poda['numbers'] = numeros(37.4, 5, 5.77, 10, 25) + ['live' => 0, 'offers' => [oferta('B0103')]];
        $p = producto(1, 'Solo Brillo', 2, 0, [$poda]);
        $p['mode'] = 'liquidation';
        $viejo = ciudad('c3', 'Carabobo', 'hold_prune', 'Mantener y podar');
        $viejo['numbers'] = numeros(37.4, 5, 5.77, 10, 25, 0, [], 'B0104');

        expect(UrgentList::build([$p]))->toBe([])
            ->and(UrgentList::build([producto(1, 'Solo Brillo', 2, 0, [$viejo])])[0]['title'])->toBe('Lanza B0104');
    });
});

describe('Que la IA no invente montos', function () {
    it('lee los montos como se escriben en español', function () {
        expect(MoneyCheck::amounts('subir $39,60 → $43,56; gastó $1.412,06 y $30; o $2.5'))->toBe([39.6, 43.56, 1412.06, 30.0, 2.5]);
    });

    it('avisa de los que no estaban en los datos', function () {
        $datos = 'Caracas: Subir $39,60 → $43,56 · C $4,00';
        expect(MoneyCheck::unknown('Sube Caracas de $39,60 a $43,56 (C de $4).', $datos))->toBe([])
            ->and(MoneyCheck::unknown('Sube Caracas a $50,00.', $datos))->toBe(['$50,00']);
    });
});

describe('Lo que recibe la IA y la plantilla sin IA', function () {
    $facts = [producto(2, 'Loli', 1, 60, [ciudad('c4', 'Valencia', 'decrease', 'Bajar $30,00 → $24,00')], ['Tracking: Meta ve el 70 %…'])];
    $facts[0]['creatives'] = [['id' => 9, 'label' => 'C004 NERV', 'verdict' => 'Perdedor', 'verdict_key' => 'loser', 'tone' => 'error', 'reading' => 'Perdedor: gastó 2,5×C',
        'proposal' => 'Apagarlo en Valencia.', 'cross' => null, 'funnel' => 'Arriba del embudo', 'week' => ['spend' => 10.0, 'purchases' => 0.0, 'cpa' => null],
        'cities' => [], 'video' => ['window' => '3 días', 'diagnosis' => ['Hook flojo y hold bueno: iterar los primeros segundos.'], 'iterate' => 'hook'], 'status' => 'loser']];

    it('un bloque por producto con lo que dijeron las reglas', function () use ($facts) {
        $in = AnalysisFacts::input($facts);
        expect($in)->toContain('== PRODUCTO: Loli')->toContain('Ciudad Valencia: Bajar $30,00 → $24,00')->toContain('Creativo #1 C004 NERV: Perdedor')
            ->toContain('video: Hook flojo')->toContain('Alarma: Tracking');
    });

    it('sin IA arma el mismo formato con las reglas', function () use ($facts) {
        $t = AnalysisWriter::example($facts, UrgentList::build($facts));
        expect($t['resumen'])->toContain('Loli en Valencia: Bajar $30,00 → $24,00')
            ->and($t['productos'][0]['ciudades'][0]['ciudad'])->toBe('Valencia')
            ->and($t['productos'][0]['decisiones_creativas'][0])->toStartWith('P1 C004 NERV: Perdedor');
    });
});

describe('La tarea del calendario', function () {
    it('se tilda sola al abrir el análisis de ese día', function () {
        $o = ReminderRules::occurrence('meta_analysis', '2026-11-02', '2026-11-02', ['meta_analysis_days' => ['2026-11-02' => '7:12']]);
        expect($o['auto_done'])->toBeTrue()->and($o['auto_note'])->toBe('Lo abriste a las 7:12.')->and($o['link'])->toBe('/meta-ads/analisis');
        expect(ReminderRules::occurrence('meta_analysis', '2026-11-03', '2026-11-03', [])['auto_done'])->toBeFalse();
    });
});
