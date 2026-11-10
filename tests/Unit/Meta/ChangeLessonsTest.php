<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Changes\ChangeLessons;
use App\Services\Meta\Products\ProductMemoryRules;

// .132 (dueño, 2026-11-10): "y así el sistema de IA puede aprender automáticamente de lo que funciona y no funciona". Lo que
// deja el historial de cambios (.131) en el análisis de la mañana. FlexiStep (5) en Caracas (campaña 111) y en Carabobo (222).

beforeEach(function () {
    $row = fn (string $kind, string $campaign, string $city, string $date, string $result, string $what, ?string $text = null) => ['kind' => $kind, 'campaign' => $campaign,
        'city' => $city, 'product_id' => 5, 'date' => $date, 'what' => $what, 'result' => $result, 'result_text' => $text];
    // del más nuevo al más viejo
    $this->rows = [
        $row('raise', '111', 'Distrito Capital', '2026-11-08', 'not_met', 'De $36,00 a $43,00 por día (+19 %)', 'No se cumplió: del 09/11 al 09/11 pierde $3,00 por día (antes dejaba $12,00) y vende a $9,50 por compra.'),
        $row('raise', '111', 'Distrito Capital', '2026-11-05', 'not_met', 'De $30,00 a $36,00 por día (+20 %)', 'No se cumplió: …'),
        $row('ads_off', '111', 'Distrito Capital', '2026-11-04', 'met', 'Apagaste C0410 FLEX', 'Se cumplió: el 05/11 la ciudad vende a $6,00 por compra (antes $6,20).'),
        $row('raise', '111', 'Distrito Capital', '2026-11-02', 'met', 'De $25,00 a $30,00 por día (+20 %)', 'Se cumplió: …'),
        $row('raise', '111', 'Distrito Capital', '2026-10-28', 'not_met', 'De $20,00 a $25,00 por día (+25 %)', 'No se cumplió: …'),
        $row('raise', '222', 'Carabobo', '2026-11-06', 'met', 'De $15,00 a $18,00 por día (+20 %)', 'Se cumplió: del 07/11 al 09/11 deja $8,00 por día (antes dejaba $5,00) y vende a $5,10 por compra.'),
    ];
    $this->lessons = ChangeLessons::build($this->rows);
    $this->facts = [['product_id' => 5, 'product' => 'FlexiStep® Tobillera', 'cities' => [
        ['city' => 'Distrito Capital', 'campaign_meta_id' => '111', 'notes' => [], 'budget_notes' => [], 'price_option' => null,
            'action' => ['label' => 'Subir', 'data' => '3 días: …', 'reading' => 'Vende debajo de C.', 'proposal' => 'Subir $43,00 → $51,00']],
        ['city' => 'Carabobo', 'campaign_meta_id' => '222', 'notes' => [], 'budget_notes' => [], 'price_option' => null,
            'action' => ['label' => 'Subir', 'data' => '3 días: …', 'reading' => 'Vende debajo de C.', 'proposal' => null]],
    ]]];
});

describe('.132: lo que deja el historial', function () {
    it('por ciudad: la última vez de cada cambio y cuántas seguidas no se cumplieron; por producto: cuántas se cumplieron', function () {
        expect($this->lessons['campaigns']['111']['raise']['streak'])->toBe(2)
            ->and($this->lessons['campaigns']['111']['raise']['last']['date'])->toBe('2026-11-08')
            ->and($this->lessons['campaigns']['111']['ads_off']['streak'])->toBe(0)
            ->and($this->lessons['products'][5])->toBe(['raise' => ['met' => 2, 'not_met' => 3], 'ads_off' => ['met' => 1, 'not_met' => 0]])
            ->and(ChangeLessons::line($this->lessons['campaigns']['222']['raise']['last']))
            ->toBe('La última vez (06/11): de $15,00 a $18,00 por día (+20 %). Se cumplió: del 07/11 al 09/11 deja $8,00 por día (antes dejaba $5,00) y vende a $5,10 por compra.')
            ->and(ChangeLessons::warning('raise', 2))->toBeNull()
            ->and(ChangeLessons::warning('raise', 3))->toBe('Ojo: las últimas 3 veces que subiste el presupuesto aquí no se cumplió.');
    });

    it('cada misión de una ciudad trae la última vez que se hizo lo mismo ahí', function () {
        $urgent = [
            ['key' => '5:111:scale', 'product_id' => 5, 'city' => 'Distrito Capital', 'kind' => 'scale'],
            // la de apagar creativos no trae la campaña en su clave: se busca por la ciudad
            ['key' => '5:stop:abc', 'product_id' => 5, 'city' => 'Distrito Capital', 'kind' => 'stop_creatives'],
            ['key' => '5:222:min_spend', 'product_id' => 5, 'city' => 'Carabobo', 'kind' => 'min_spend'],
            ['key' => 'stale:2026-11-10', 'product_id' => 0, 'city' => null, 'kind' => 'stale'],
        ];
        $out = ChangeLessons::missions($urgent, $this->facts, $this->lessons);

        expect($out[0]['last'])->toMatchArray(['result' => 'not_met', 'warn' => null, 'kind' => 'raise', 'city' => 'Distrito Capital'])
            ->and($out[0]['last']['text'])->toStartWith('La última vez (08/11): de $36,00 a $43,00 por día (+19 %). No se cumplió')
            ->and($out[1]['last']['text'])->toBe('La última vez (04/11): apagaste C0410 FLEX. Se cumplió: el 05/11 la ciudad vende a $6,00 por compra (antes $6,20).')
            // sin historial de ese cambio en esa ciudad, nada
            ->and($out[2])->not->toHaveKey('last')
            ->and($out[3])->not->toHaveKey('last');
    });

    it('el aviso: el mismo cambio que no se cumplió 3 veces seguidas en una ciudad, en los últimos 30 días', function () {
        $rows = $this->rows;
        array_unshift($rows, ['kind' => 'raise', 'campaign' => '111', 'city' => 'Distrito Capital', 'product_id' => 5, 'date' => '2026-11-09',
            'what' => 'De $43,00 a $50,00 por día (+16 %)', 'result' => 'not_met', 'result_text' => 'No se cumplió: …']);
        $lessons = ChangeLessons::build($rows);
        $notices = ChangeLessons::notices($lessons, [5 => 'FlexiStep® Tobillera'], '2026-11-10');

        expect($notices)->toHaveCount(1)
            ->and($notices[0])->toMatchArray(['key' => '5:111:lesson:raise', 'kind' => 'lesson', 'group' => 'aviso', 'tone' => 'warning', 'city' => 'Distrito Capital',
                'where' => 'FlexiStep · Distrito Capital', 'title' => 'Las últimas 3 subidas de presupuesto en Distrito Capital no se cumplieron',
                'why' => 'Es solo un aviso: tus reglas siguen igual'])
            ->and($notices[0]['detail'][1])->toContain('El sistema no cambia sus decisiones por esto')
            // la misión de subir trae el "ojo"
            ->and(ChangeLessons::missions([['key' => '5:111:scale', 'product_id' => 5, 'city' => 'Distrito Capital', 'kind' => 'scale']], $this->facts, $lessons)[0]['last']['warn'])
            ->toBe('Ojo: las últimas 3 veces que subiste el presupuesto aquí no se cumplió.')
            // pasados 30 días del último, ya no; ni de un producto que no está en el análisis
            ->and(ChangeLessons::notices($lessons, [5 => 'FlexiStep® Tobillera'], '2026-12-20'))->toBe([])
            ->and(ChangeLessons::notices($lessons, [], '2026-11-10'))->toBe([]);
    });

    it('la IA lo recibe: la última vez en cada ciudad y lo que se cumplió del producto', function () {
        $facts = ChangeLessons::intoFacts($this->facts, $this->lessons);

        expect($facts[0]['changes'])->toBe('Subir el presupuesto: se cumplió 2 de 5; Apagar anuncios: se cumplió 1 de 1')
            ->and($facts[0]['cities'][1]['lessons'])->toBe(['Subir el presupuesto: La última vez (06/11): de $15,00 a $18,00 por día (+20 %). Se cumplió: del 07/11 al 09/11 deja $8,00 por día (antes dejaba $5,00) y vende a $5,10 por compra.']);

        $input = AnalysisFacts::input([$facts[0] + ['mode' => 'normal', 'urgency' => null, 'creatives' => [], 'next_work' => [], 'price' => [], 'alarms' => [], 'batches' => [],
            'price_watch' => null, 'tracking' => null]]);
        expect($input)->toContain('  · historial: Subir el presupuesto: La última vez (08/11)')
            ->toContain('Tus cambios en Meta (60 días): Subir el presupuesto: se cumplió 2 de 5; Apagar anuncios: se cumplió 1 de 1');
    });
});

describe('.132: en "Lo que sabemos"', function () {
    it('cuántas veces se cumplió cada cambio y cuántas variaciones mejoraron lo que buscaban', function () {
        $changes = ['kinds' => [['kind' => 'raise', 'label' => 'Subir el presupuesto', 'met' => 2, 'not_met' => 3]],
            'metrics' => [['key' => 'hook_rate', 'label' => 'Hook rate', 'better' => 2, 'total' => 3]]];
        $f = ProductMemoryRules::facts([], [], [], [], [], $changes);

        expect(ProductMemoryRules::changeLines($f))->toBe(['Subir el presupuesto: se cumplió 2 de 5', 'Variaciones que buscaban subir el Hook rate: lo subieron 2 de 3'])
            ->and(ProductMemoryRules::input('FlexiStep', $f))->toContain("CAMBIOS EN META (si se cumplió la hipótesis de cada cambio):\n- Subir el presupuesto: se cumplió 2 de 5")
            ->and(ProductMemoryRules::forWriter($f, null))->toContain('  · Cambios en Meta, Variaciones que buscaban subir el Hook rate: lo subieron 2 de 3');
    });

    it('sin cambios en Meta, la huella no cambia (los resúmenes de antes no se vuelven a escribir)', function () {
        $old = hash('sha256', json_encode([[], [], ProductMemoryRules::facts([], [], [], [])['untested'], []], JSON_UNESCAPED_UNICODE));

        expect(ProductMemoryRules::hash(ProductMemoryRules::facts([], [], [], [])))->toBe($old)
            ->and(ProductMemoryRules::hash(ProductMemoryRules::facts([], [], [], [], [], ['kinds' => [['kind' => 'raise', 'label' => 'x', 'met' => 1, 'not_met' => 0]]])))->not->toBe($old)
            ->and(ProductMemoryRules::forWriter(ProductMemoryRules::facts([], [], [], []), null))->toBe('');
    });
});
