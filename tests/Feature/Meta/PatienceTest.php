<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\Decisions\Rules\AdRules;
use App\Services\Meta\MetaMetrics;

// .127 (dueño, 2026-11-09): "sí acepto gastar 3C" cuando sus variables (hook rate, hold rate, etcétera) son buenas. Eligió
// "2 buenas y ninguna floja" de hook, hold y CTR único (con sus números de Reglas: bueno desde 40 %, 40 % y 3 %; flojo debajo
// de 30 %, 30 % y 2 %); en una imagen, el CTR bueno. Con tan pocas compras manda la suerte: a 2×C se apagan unos 4 de cada 10
// creativos que en realidad venden a C; a 3×C, unos 2 de cada 10. Aquí: ZenPulse en Caracas, C $4,44 y BE $7,61.

const PACIENCIA_CCS = '120000000000000131';

/** Una ventana con gasto, compras y las señales de video: hook y hold en %, CTR único en %. */
function pacienciaVentana(float $spend, float $purchases, ?float $hook = null, ?float $hold = null, ?float $ctr = null, int $impressions = 2000): array
{
    $plays = $hook !== null ? $impressions * $hook / 100 : null;
    $t = MetaMetrics::sum([array_filter(['spend' => $spend, 'purchases' => $purchases, 'impressions' => $impressions, 'reach' => 1800,
        'video_3s_plays' => $plays, 'thruplays' => $plays !== null && $hold !== null ? $plays * $hold / 100 : null,
        'unique_link_clicks' => $ctr !== null ? 1800 * $ctr / 100 : null], fn ($v) => $v !== null)]);

    return ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
}

function pacienciaReglas(array $cambios = []): AdRules
{
    return new AdRules(array_merge(DecisionSettings::defaults(), $cambios));
}

/** ZenPulse Caracas con un ganador (para que haya 2 batches vivos) y el creativo que se mira: [gasto, compras, hook, hold, CTR]. */
function pacienciaDecide(array $nuevo, string $type = 'video'): array
{
    $all = fn (array $w) => ['max' => $w, '7d' => $w, '3d' => $w, 'prev' => $w, 'yesterday' => $w];
    $ad = fn (string $id, string $set, string $name, int $creative, array $w, string $t = 'video') => ['id' => (int) substr($id, -3), 'meta_id' => $id, 'name' => $name,
        'campaign_meta_id' => PACIENCIA_CCS, 'adset_meta_id' => $set, 'creative_id' => $creative, 'creative_type' => $t, 'video_length' => null, 'angle_code' => null,
        'batch_code' => null, 'active' => true, 'created_time' => '2026-11-05 10:00:00', 'windows' => $all($w)];
    $set = fn (string $id, string $name) => ['meta_id' => $id, 'name' => $name, 'campaign_meta_id' => PACIENCIA_CCS, 'active' => true, 'missing' => false, 'batch_code' => null,
        'angle_code' => null, 'concept' => null, 'stage' => null, 'min_spend' => 0.0, 'created_time' => '2026-11-05 10:00:00', 'windows' => []];
    $creative = fn (int $id, string $label, string $adId, string $t = 'video') => ['id' => $id, 'label' => $label, 'type' => $t, 'status' => null, 'status_source' => null,
        'angle_code' => null, 'batch_code' => null, 'creative_code' => null, 'iteration_of' => null, 'ads' => [$adId]];
    $meta = ['product' => ['id' => 1149, 'name' => 'ZenPulse® Masajeador', 'mode' => 'normal'], 'dates' => AdFacts::dates(),
        'campaigns' => [PACIENCIA_CCS => ['meta_id' => PACIENCIA_CCS, 'name' => 'ZENPULSE | CCS | CBO | 27/10/2026', 'city_id' => 1, 'city' => 'Distrito Capital', 'active' => true,
            'daily_budget' => 39.6, 'target' => 4.44, 'break_even' => 7.61, 'target_source' => 'financial', 'target_no_cost' => false,
            'windows' => $all(pacienciaVentana(120.0, 30))]],
        'adsets' => ['p1' => $set('p1', 'B0101 ZEN'), 'p2' => $set('p2', 'B0118 ZEN')],
        'ads' => ['120000000000001311' => $ad('120000000000001311', 'p1', 'C0401 ZEN', 1, pacienciaVentana(90.0, 28, 44, 41, 3.2)),
            '120000000000001312' => $ad('120000000000001312', 'p2', 'C0457 ZEN', 2, pacienciaVentana(...$nuevo), $type)],
        'creatives' => [1 => $creative(1, 'C0401 ZEN', '120000000000001311'), 2 => $creative(2, 'C0457 ZEN', '120000000000001312', $type)]];
    $facts = Mockery::mock(AdFacts::class);
    $facts->allows('load')->andReturn([1149 => $meta]);
    $facts->allows('dailyHistory')->andReturn([]);
    $settings = Mockery::mock(DecisionSettings::class);
    $settings->allows('get')->andReturn(DecisionSettings::defaults());
    $ops = Mockery::mock(OpsFacts::class);
    $ops->allows('campaigns')->andReturn(['history_since' => '2026-10-01', 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(),
        'campaigns' => [PACIENCIA_CCS => ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0]]]);
    $ops->allows('products')->andReturn([1149 => ['price' => 25.0, 'price_change' => null, 'version' => null, 'bank' => ['codes' => [], 'ready' => [], 'launched' => []],
        'cities' => [1 => ['available' => true, 'stock' => 300, 'units_per_day' => 10.0, 'days_of_stock' => 30.0, 'sizes_out' => [], 'version' => null]]]]);

    return (new MetaDecisions($facts, $settings, $ops))->compute([1149])[1149];
}

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 00:30:00'));
});

describe('.127: qué son buenas señales (PHP puro)', function () {
    it('de hook, hold y CTR: 2 buenas y ninguna floja', function (array $s, bool $good) {
        expect(pacienciaReglas()->signals(pacienciaVentana(10, 1, ...$s), true)['good'])->toBe($good);
    })->with([
        'las 3 buenas' => [[45, 42, 3.1], true],
        'hook y hold buenos, CTR regular' => [[45, 42, 2.5], true],
        'hook y CTR buenos, hold regular' => [[45, 35, 3.1], true],
        'una sola buena' => [[45, 35, 2.5], false],
        'dos buenas pero el CTR flojo' => [[45, 42, 1.5], false],
        'dos buenas pero el hook flojo' => [[25, 42, 3.1], false],
        'todo regular' => [[35, 35, 2.5], false],
    ]);

    it('dice los números; en una imagen cuenta el CTR; sin muestra no se opina', function () {
        $r = pacienciaReglas();
        expect($r->signals(pacienciaVentana(10, 1, 45, 42, 3.1), true)['text'])->toBe('hook 45 % · hold 42 % · CTR 3,1 %')
            ->and($r->signals(pacienciaVentana(10, 1, null, null, 3.2), false))->toBe(['good' => true, 'text' => 'CTR 3,2 %'])
            ->and($r->signals(pacienciaVentana(10, 1, null, null, 2.5), false)['good'])->toBeFalse()
            // con menos de 1.000 impresiones no se leen el hook ni el CTR
            ->and($r->signals(pacienciaVentana(10, 1, 45, 42, 3.1, 600), true)['good'])->toBeFalse()
            ->and($r->signals(null, true))->toBeNull();
        // con tus números de Reglas: si subes "hook bueno desde" a 50 %, 45 % ya no es bueno
        expect(pacienciaReglas(['hook_good' => 50.0, 'hold_good' => 50.0])->signals(pacienciaVentana(10, 1, 45, 42, 3.1), true)['good'])->toBeFalse();
    });
});

describe('.127: la paciencia (PHP puro)', function () {
    $buenas = ['good' => true, 'text' => 'hook 45 % · hold 42 % · CTR 3,1 %'];

    it('perdedor por 0 o 1 compra con buenas señales: en evaluación hasta 3×C', function () use ($buenas) {
        $r = pacienciaReglas();
        $loser = $r->state(10.0, 1, 4.44, 7.61, 'creativo');
        $v = $r->patient($loser, 10.0, 1, 4.44, $buenas);

        expect($loser['key'])->toBe('loser')
            ->and($v['key'])->toBe('evaluation')->and($v['patient'])->toBeTrue()
            ->and($v['reading'])->toBe('En evaluación, con paciencia: gastó 2,3×C con una sola compra, pero sus señales son buenas.')
            ->and($v['proposal'])->toBe('Tiene buenas señales (hook 45 % · hold 42 % · CTR 3,1 %): darle hasta $13,32 (3×C); si llega ahí con 0 o 1 compra, apagarlo.')
            ->and($r->patient($r->state(9.0, 0, 4.44, 7.61), 9.0, 0, 4.44, $buenas)['reading'])->toContain('con ninguna compra');
    });

    it('a 3×C se apaga como siempre; sin buenas señales, a 2×C como siempre', function () use ($buenas) {
        $r = pacienciaReglas();
        expect($r->patient($r->state(13.4, 1, 4.44, 7.61), 13.4, 1, 4.44, $buenas))->toBeNull()
            ->and($r->patient($r->state(10.0, 1, 4.44, 7.61), 10.0, 1, 4.44, ['good' => false, 'text' => 'hook 35 %']))->toBeNull()
            ->and($r->patient($r->state(10.0, 1, 4.44, 7.61), 10.0, 1, 4.44, null))->toBeNull();
    });

    it('solo es para el que perdió por pocas compras: uno con 2 o más compras en el BE no entra', function () use ($buenas) {
        $r = pacienciaReglas();
        // 3×C con 2 compras y el CPA 20 % o más encima del BE: perdedor por el BE, no por la suerte
        $byBe = $r->state(20.0, 2, 4.44, 7.61);
        expect($byBe['key'])->toBe('loser')->and($r->patient($byBe, 20.0, 2, 4.44, $buenas))->toBeNull()
            // y uno que no es perdedor no cambia
            ->and($r->patient($r->state(6.0, 1, 4.44, 7.61), 6.0, 1, 4.44, $buenas))->toBeNull();
    });

    it('en Reglas se cambia el 3×C (con 2×C, no hay paciencia)', function () use ($buenas) {
        $r = pacienciaReglas(['patience_cpa' => 2.0]);
        expect(DecisionSettings::defaults()['patience_cpa'])->toBe(3.0)
            ->and($r->patient($r->state(10.0, 1, 4.44, 7.61), 10.0, 1, 4.44, $buenas))->toBeNull();
    });
});

describe('.127: en el análisis', function () {
    it('con buenas señales no sale para apagar: ni el creativo, ni su anuncio, ni su batch', function () {
        // $10,00 con 1 compra (2,3×C), hook 45 %, hold 42 %, CTR 3,1 %
        $d = pacienciaDecide([10.0, 1, 45, 42, 3.1]);

        expect($d['creatives'][2]['verdict']['key'])->toBe('evaluation')
            ->and($d['creatives'][2]['cities'][0]['reading'])->toBe('En evaluación, con paciencia: gastó 2,3×C con una sola compra, pero sus señales son buenas.')
            ->and($d['creatives'][2]['cities'][0]['proposal'])->toBe('Tiene buenas señales (hook 45 % · hold 42 % · CTR 3,1 %): darle hasta $13,32 (3×C); si llega ahí con 0 o 1 compra, apagarlo.')
            ->and($d['ads']['120000000000001312']['key'])->toBe('evaluation')
            ->and($d['adsets']['p2']['key'])->not->toBe('loser')
            ->and($d['campaigns'][PACIENCIA_CCS]['numbers']['prune'])->toBe([]);

        $facts = AnalysisFacts::build([1149 => $d], ['campaigns' => [PACIENCIA_CCS => 'ZENPULSE | CCS | CBO | 27/10/2026']]);
        // en la lista de creativos del análisis se lee el porqué
        expect(collect($facts[0]['creatives'])->firstWhere('label', 'C0457 ZEN')['call'])->toMatchArray(['key' => 'keep',
            'todo' => 'Tiene buenas señales (hook 45 % · hold 42 % · CTR 3,1 %): darle hasta $13,32 (3×C); si llega ahí con 0 o 1 compra, apagarlo.']);
        expect(array_column(UrgentList::build($facts), 'kind'))->not->toContain('stop_creatives');
    });

    it('con señales regulares, o al llegar a 3×C, sale para apagar como siempre', function () {
        $regular = pacienciaDecide([10.0, 1, 35, 35, 2.5]);
        $tope = pacienciaDecide([13.5, 1, 45, 42, 3.1]);

        expect($regular['creatives'][2]['verdict']['key'])->toBe('loser')
            ->and($tope['creatives'][2]['verdict']['key'])->toBe('loser');
        $list = UrgentList::build(AnalysisFacts::build([1149 => $tope], ['campaigns' => [PACIENCIA_CCS => 'ZENPULSE | CCS | CBO | 27/10/2026']]));
        expect(collect($list)->firstWhere('kind', 'stop_creatives')['title'])->toBe('Apaga C0457 ZEN en todas las campañas');
    });

    it('una imagen con el CTR bueno también tiene paciencia', function () {
        $d = pacienciaDecide([10.0, 0, null, null, 3.4], 'image');

        expect($d['creatives'][2]['verdict']['key'])->toBe('evaluation')
            ->and($d['creatives'][2]['cities'][0]['reading'])->toContain('con ninguna compra, pero sus señales son buenas')
            ->and($d['creatives'][2]['cities'][0]['proposal'])->toStartWith('Tiene buenas señales (CTR 3,4 %): darle hasta $13,32');
    });
});
