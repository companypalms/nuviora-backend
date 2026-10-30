<?php

use App\Services\Meta\Landings\LandingProposer;
use App\Services\Metrics\Period;
use App\Services\Metrics\Team\LandingEditorKpis;
use App\Services\Metrics\Team\TeamCatalog;
use Carbon\CarbonImmutable;

// Panel del dueño › Equipo › Editores de landings (Etapa 5, paso 6c), con encargos inventados. La semana del 19 al 25/10/2026.

$hoy = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$semana = Period::fromPreset('custom', '2026-10-19', '2026-10-25', $hoy);

/** Un test terminado: visitas y compras antes y después, y los clics de después. */
function testLanding(string $state, int $vb, int $pb, int $va, int $pa, int $clicks): array
{
    $side = fn (int $v, int $p, int $c) => ['totals' => ['link_clicks' => $c, 'visits' => $v, 'purchases' => $p],
        'metrics' => ['visit' => $c ? round($v / $c * 100, 1) : null, 'conversion' => $v ? round($p / $v * 100, 2) : null]];

    return ['state' => $state, 'change' => null, 'before' => $side($vb, $pb, (int) ($vb / 0.8)), 'after' => $side($va, $pa, $clicks)];
}

function encargoEditor(int $id, int $editor, array $extra = []): array
{
    return $extra + ['id' => $id, 'code' => 'L0' . (100 + $id), 'editor_id' => $editor, 'product' => 'FlexiStep', 'landing' => 'Base', 'status' => 'production',
        'due_date' => '2026-10-21', 'delivered_at' => null, 'published_at' => null, 'tested_at' => null, 'result' => null, 'versions' => 1, 'returned' => 0];
}

$hechos = fn (array $tasks, array $reviews = []) => ['people' => [1 => ['name' => 'Francisco', 'group' => 'Dueño'], 50 => ['name' => 'Lia Landing', 'group' => null]],
    'tasks' => $tasks, 'reviews' => $reviews, 'owners' => []];

describe('Editores de landings', function () use ($semana, $hechos) {
    it('entregas a tiempo: la primera entrega hasta el final del día del plazo; el dueño también se mide', function () use ($semana, $hechos) {
        $r = (new LandingEditorKpis())->compute($hechos([
            encargoEditor(1, 50, ['delivered_at' => '2026-10-21 22:00:00']),
            encargoEditor(2, 50, ['delivered_at' => '2026-10-23 09:00:00']),
            encargoEditor(3, 1, ['delivered_at' => '2026-10-20 09:00:00']),
            encargoEditor(4, 50, ['delivered_at' => '2026-10-10 09:00:00']),   // de otra semana
        ]), $semana);
        expect($r['people'][50]['values'])->toMatchArray(['delivered' => 2, 'on_time' => 50.0])
            ->and($r['people'][1]['values']['delivered'])->toBe(1)->and($r['people'][1]['group'])->toBe('Dueño')
            ->and($r['totals']['values']['on_time'])->toBe(66.7);
    });

    it('tests ganados, y la compra después contra antes sumando compras y visitas (no promediando)', function () use ($semana, $hechos) {
        $r = (new LandingEditorKpis())->compute($hechos([
            encargoEditor(1, 50, ['status' => 'tested', 'tested_at' => '2026-10-22 07:00:00', 'result' => testLanding('won', 500, 10, 500, 15, 600)]),
            encargoEditor(2, 50, ['status' => 'tested', 'tested_at' => '2026-10-24 07:00:00', 'result' => testLanding('tie', 1500, 45, 1500, 45, 2000)]),
            encargoEditor(3, 50, ['status' => 'tested', 'tested_at' => '2026-10-24 07:00:00', 'result' => testLanding('no_sample', 100, 2, 100, 3, 150)]),
            encargoEditor(4, 50, ['status' => 'testing', 'result' => testLanding('waiting', 500, 10, 100, 1, 120)]),
        ]), $semana);
        // antes 55 compras en 2.000 visitas (2,75 %), después 60 en 2.000 (3 %): +9,1 %; visita 2.000 de 2.600 clics
        expect($r['people'][50]['values'])->toMatchArray(['won' => 50.0, 'conversion_change' => 9.1, 'visit' => 76.9])
            ->and($r['people'][50]['details']['won'])->toBe('1 de 2 tests con muestra')
            ->and($r['people'][50]['details']['conversion_change'])->toBe('Compra 2,75 % antes → 3 % después');
    });

    it('devuelto por calidad y nada de dinero en lo que ven', function () use ($semana, $hechos) {
        $r = (new LandingEditorKpis())->compute($hechos([], [
            ['editor_id' => 50, 'review' => 'returned', 'reviewed_at' => '2026-10-20 10:00:00'],
            ['editor_id' => 50, 'review' => 'approved', 'reviewed_at' => '2026-10-21 10:00:00'],
        ]), $semana);
        expect($r['people'][50]['values']['returned'])->toBe(50.0)
            ->and(array_filter(array_column(LandingEditorKpis::METRICS, 'format'), fn ($f) => $f === 'money'))->toBe([]);
    });

    it('sus landings publicadas: en test arriba, después lo testeado más nuevo', function () use ($hechos) {
        $facts = $hechos([
            encargoEditor(1, 50, ['status' => 'tested', 'tested_at' => '2026-10-20 07:00:00', 'result' => testLanding('won', 500, 10, 500, 15, 600)]),
            encargoEditor(2, 50, ['status' => 'tested', 'tested_at' => '2026-10-24 07:00:00', 'result' => testLanding('lost', 500, 15, 500, 10, 600)]),
            encargoEditor(3, 50, ['status' => 'testing', 'published_at' => '2026-10-25 12:00:00']),
            encargoEditor(4, 1, ['status' => 'testing']),
            encargoEditor(5, 50, ['status' => 'production']),
        ]);
        $list = LandingEditorKpis::tasks($facts, [50]);
        expect(array_column($list, 'id'))->toBe([3, 2, 1])
            ->and($list[0])->toMatchArray(['state' => 'waiting', 'state_label' => 'En test'])
            ->and($list[1])->toMatchArray(['state' => 'lost', 'state_label' => 'Perdió'])
            ->and($list[2]['after'])->toBe(['conversion' => 3.0, 'visit' => 83.3]);
    });

    it('el panel ya mide al editor de landings', function () {
        expect(TeamCatalog::find('landing_editors'))->toBeInstanceOf(LandingEditorKpis::class)
            ->and(TeamCatalog::coverage(['Editor de landings'])['missing'])->toBe([]);
    });
});

describe('Las propuestas de las reglas', function () {
    it('lo más grave de una landing: sin píxel, lenta, no le habla a un ángulo, la del ángulo, otra compra mejor', function () {
        $f = fn (string ...$keys) => array_map(fn ($k) => ['key' => $k, 'level' => 'red', 'text' => $k], $keys);
        expect(LandingProposer::worst($f('beaten', 'angle_mismatch', 'slow'))['key'])->toBe('slow')
            ->and(LandingProposer::worst($f('beaten', 'angle_weak'))['key'])->toBe('angle_weak')
            ->and(LandingProposer::worst($f('no_pixel', 'slow'))['key'])->toBe('no_pixel')
            ->and(LandingProposer::worst($f('visit_low', 'best', 'little_data')))->toBeNull();
    });
});
