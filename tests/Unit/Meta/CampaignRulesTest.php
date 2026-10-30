<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\CampaignRules;

// Etapa 5, paso 2b: qué hacer con cada campaña. C = 4 y BE = 5,70 son los del manual del dueño; los montos, los de su
// manual ($40 → $48, $40 → $32) y los de sus análisis (Caracas $39,60 → $43,56; Carabobo $25 → $30).

function campana(array $cambios = []): CampaignRules
{
    return new CampaignRules(array_merge(DecisionSettings::defaults(), $cambios));
}

function accion(array $in): array
{
    return campana()->action(array_merge(['c' => 4.0, 'be' => 5.70, 'mode' => 'normal', 'budget' => 40.0, 'spend3' => 30.0, 'purchases3' => 12,
        'cpa7' => 3.0, 'freq3' => 1.3, 'stock_ok' => true], $in));
}

describe('Escalar', function () {
    it('+20 % si el CPA de 3 días está un 25 % o más debajo de C ($40 → $48, el ejemplo del manual)', function () {
        $a = accion(['spend3' => 30, 'purchases3' => 12]);
        expect($a['key'])->toBe('scale')->and($a['to'])->toBe(48.0)->and($a['label'])->toBe('Subir $40,00 → $48,00');
    });

    it('+10 % si está apenas debajo (Caracas $39,60 → $43,56)', function () {
        expect(accion(['budget' => 39.60, 'spend3' => 42, 'purchases3' => 12])['to'])->toBe(43.56);
    });

    it('Carabobo $25 → $30', function () {
        expect(accion(['budget' => 25.0, 'spend3' => 30.8, 'purchases3' => 11])['to'])->toBe(30.0);
    });

    it('no escala sin volumen, sin stock, con 7 días malos, saturada, si ya subió hoy o si entraron creativos hoy', function (array $in, string $why) {
        $a = accion($in);
        expect($a['key'])->toBe('hold')->and($a['reading'])->toContain($why);
    })->with([
        'Maracay: poco volumen' => [['purchases3' => 6, 'spend3' => 15], 'poco volumen'],
        'sin stock' => [['stock_ok' => false, 'stock_note' => 'quedan 5 unidades'], 'no hay stock'],
        '7 días encima del BE' => [['cpa7' => 6.0], 'Los 7 días no acompañan'],
        'saturada' => [['freq3' => 1.7], 'saturada'],
        'ya subió' => [['increased_24h' => true], 'últimas 24 horas'],
        'creativos de hoy' => [['new_ads_today' => true], 'creativos nuevos'],
        'liquidación' => [['mode' => 'liquidation'], 'liquidación'],
    ]);
});

describe('Entre C y BE, y en el break-even o encima', function () {
    it('entre C y BE: mantener y podar los perdedores', function () {
        $a = accion(['spend3' => 30, 'purchases3' => 6, 'prune' => ['C003 FASP']]);
        expect($a['key'])->toBe('hold_prune')->and($a['proposal'])->toContain('Apagar: C003 FASP');
    });

    it('en el BE o encima, sin intervención: intervenir, sin tocar el presupuesto', function () {
        $a = accion(['spend3' => 36, 'purchases3' => 6, 'streak_days' => 2, 'history_since' => '2026-10-30']);
        expect($a['key'])->toBe('intervene')->and($a['proposal'])->toContain('No tocar el presupuesto hoy');
        expect(accion(['spend3' => 36, 'purchases3' => 6, 'budget_changed_today' => true])['key'])->toBe('wait');
    });

    it('2 días cerrados después de intervenir: −20 % ($40 → $32, el ejemplo del manual)', function () {
        $a = accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-27', 'closed_since_intervention' => 2]);
        expect($a['key'])->toBe('decrease')->and($a['to'])->toBe(32.0);
        expect(accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-28', 'closed_since_intervention' => 1])['key'])->toBe('wait');
        expect(accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-26', 'closed_since_intervention' => 3, 'decreased_since_intervention' => true])['key'])->toBe('wait');
    });

    it('5 días cerrados: pausar la ciudad y el último test con 1,5×C por día', function () {
        $a = accion(['spend3' => 36, 'purchases3' => 6, 'intervention' => '2026-10-24', 'closed_since_intervention' => 5, 'decreased_since_intervention' => true]);
        expect($a['key'])->toBe('pause_city')->and($a['proposal'])->toContain('$6,00 por día durante 3 días');
    });

    it('el último test: esperar sus 3 días y, si falla, recomendar dejar el producto', function () {
        expect(accion(['spend3' => 18, 'purchases3' => 2, 'last_test' => ['days' => 1, 'cpa' => 9.0]])['key'])->toBe('wait')
            ->and(accion(['spend3' => 18, 'purchases3' => 2, 'last_test' => ['days' => 3, 'cpa' => 9.0]])['key'])->toBe('drop')
            ->and(accion(['spend3' => 18, 'purchases3' => 6, 'last_test' => ['days' => 3, 'cpa' => 3.0]])['key'])->not->toBe('drop');
    });

    it('producto en pausa, sin objetivo o sin gasto suficiente', function () {
        expect(accion(['mode' => 'paused'])['key'])->toBe('none')
            ->and(accion(['c' => null])['key'])->toBe('no_target')
            ->and(accion(['spend3' => 3, 'purchases3' => 0])['key'])->toBe('wait');
    });

    it('cuenta los días seguidos con el CPA de 3 días en el break-even o encima', function () {
        $daily = ['2026-10-24' => ['spend' => 10, 'purchases' => 4], '2026-10-25' => ['spend' => 10, 'purchases' => 4],
            '2026-10-26' => ['spend' => 10, 'purchases' => 2], '2026-10-27' => ['spend' => 12, 'purchases' => 1],
            '2026-10-28' => ['spend' => 12, 'purchases' => 1], '2026-10-29' => ['spend' => 12, 'purchases' => 2]];
        expect(CampaignRules::streak($daily, 5.70, '2026-10-29'))->toBe(['days' => 2, 'start' => '2026-10-28']);
    });
});

describe('Precio', function () {
    // FlexiStep, semana del 17 al 23-10: por entrega $23,14 + $4,26 de upsell, costos $7,37; 92 entregas por 195 compras de Meta
    $flexi = ['revenue' => 27.40 * 92, 'variable_costs' => 7.37 * 92, 'cogs' => 2.72 * 92, 'orders_delivered' => 92, 'purchases' => 195,
        'fixed_pct' => 10, 'target_type' => 'sales', 'target_pct' => 20];

    it('el precio mínimo con el CPA de hoy deja tu utilidad después de gastos fijos', function () use ($flexi) {
        expect(CampaignRules::minPrice($flexi, 23.14, 3.96))->toBe(18.26)
            ->and(CampaignRules::minPrice(['orders_delivered' => 0] + $flexi, 23.14, 3.96))->toBeNull();
    });

    it('se ofrece de a $2 junto al escalado, nunca debajo del mínimo', function () {
        $o = campana()->priceOption(23.14, 18.26, true);
        expect($o['to'])->toBe(21.14)->and($o['text'])->toContain('$23,14 → $21,14')->toContain('$18,26');
        expect(campana()->priceOption(23.14, 18.26, false))->toBeNull()
            ->and(campana()->priceOption(23.14, 22.0, true))->toBeNull();
    });

    it('después de bajar: vigilar, y volver si quedó debajo del mínimo', function () {
        expect(campana()->priceWatch(['from' => 23.14, 'to' => 21.14], 18.26))->toContain('vigilar')->not->toContain('volver')
            ->and(campana()->priceWatch(['from' => 23.14, 'to' => 17.99], 18.26))->toContain('volver al precio anterior')
            ->and(campana()->priceWatch(null, 18.26))->toBeNull();
    });
});

describe('Presupuesto, urgencia y trabajo siguiente', function () {
    it('los mínimos de los ad sets y el presupuesto para batches nuevos', function () {
        expect(campana()->budgetNotes(40, 24, 0, 4)[0])->toContain('demasiado')
            ->and(campana()->budgetNotes(40, 18, 0, 4)[0])->toContain('revisar')
            ->and(campana()->budgetNotes(15, 0, 2, 4.0)[0])->toContain('entre $20,00 y $24,00')
            ->and(campana()->budgetNotes(40, 12, 0, 4))->toBe([]);
    });

    it('U1 a U5', function () {
        $u = fn (array $p) => campana()->urgency($p + ['mode' => 'normal', 'healthy' => 1]);
        expect($u(['cities' => ['intervene', 'scale']])['level'])->toBe(1)
            ->and($u(['cities' => ['hold_prune']])['level'])->toBe(2)
            ->and($u(['cities' => ['hold'], 'failing' => 1])['level'])->toBe(2)
            ->and($u(['cities' => ['scale', 'hold']])['level'])->toBe(3)
            ->and($u(['cities' => ['hold']])['level'])->toBe(4)
            ->and(campana()->urgency(['mode' => 'normal', 'healthy' => 0, 'cities' => ['hold']])['level'])->toBe(1)
            ->and(campana()->urgency(['mode' => 'paused', 'cities' => ['intervene']])['level'])->toBe(5)
            ->and($u(['cities' => ['hold'], 'stock_all_out' => true])['level'])->toBe(5);
        expect(campana()->urgency(['mode' => 'liquidation', 'healthy' => 0, 'cities' => ['hold_prune']])['batches'])->toBe('0')
            ->and($u(['cities' => ['intervene']])['batches'])->toBe('2–3');
    });

    it('iteraciones de un ganador, un concepto nuevo o un ángulo nuevo, y creativos de arriba si está saturada', function () {
        $r = campana();
        expect($r->nextWork(['winner' => ['label' => 'C004 GINE', 'iterate' => 'el hook (los primeros segundos)']], '1')[0])->toContain('Iteraciones de C004 GINE')
            ->and($r->nextWork(['main_angle' => 'FASP', 'failed_batches_in_angle' => 2], '2–3')[0])->toContain('Un ángulo nuevo')
            ->and($r->nextWork(['main_angle' => 'FASP', 'failed_batches_in_angle' => 1], '1–2')[0])->toContain('Un concepto nuevo dentro de FASP')
            ->and($r->nextWork(['saturated' => ['Caracas']], '1'))->toHaveCount(2)
            ->and($r->nextWork(['mode' => 'liquidation'], '0')[0])->toContain('liquidación');
    });
});
