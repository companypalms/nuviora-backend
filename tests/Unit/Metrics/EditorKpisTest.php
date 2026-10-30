<?php

use App\Services\Metrics\Period;
use App\Services\Metrics\Team\EditorKpis;
use App\Services\Metrics\Team\TeamCatalog;
use Carbon\CarbonImmutable;

// Panel del dueño › Equipo › Editores de video (Etapa 5, paso 5b), con encargos inventados. La semana del 19 al 25/10/2026.

$hoy = CarbonImmutable::parse('2026-10-29 10:15:00', 'America/Caracas');
$semana = Period::fromPreset('custom', '2026-10-19', '2026-10-25', $hoy);

/** Un encargo como lo lee EditorFacts. */
function batchEditor(int $id, int $editor, array $extra = []): array
{
    return $extra + ['id' => $id, 'code' => 'B0' . (100 + $id), 'editor_id' => $editor, 'product' => 'FlexiStep', 'status' => 'bank', 'due_date' => '2026-10-21',
        'delivered_at' => null, 'testing_at' => null, 'tested_at' => null, 'verdicts' => [], 'versions' => 1, 'returned' => 0];
}

function hechosEditores(array $batches, array $reviews = [], array $rows = []): array
{
    return ['people' => [10 => ['name' => 'Leo Líder', 'group' => 'Líder'], 20 => ['name' => 'Eva Editora', 'group' => null]],
        'batches' => $batches, 'reviews' => $reviews, 'rows' => $rows];
}

$fila = fn (int $batch, float $spend, float $purchases, ?float $c = 3.0, ?float $be = 5.0, string $city = 'Caracas') => ['batch_id' => $batch, 'city' => $city,
    'spend' => $spend, 'purchases' => $purchases, 'life_spend' => $spend, 'life_purchases' => $purchases, 'c' => $c, 'be' => $be];

describe('Editores › entregas', function () use ($semana) {
    it('a tiempo cuenta la primera entrega hasta el final del día del plazo, y solo las del período', function () use ($semana) {
        $r = (new EditorKpis())->compute(hechosEditores([
            batchEditor(1, 20, ['delivered_at' => '2026-10-21 23:10:00']),            // el día del plazo: a tiempo
            batchEditor(2, 20, ['delivered_at' => '2026-10-22 08:00:00']),            // un día tarde
            batchEditor(3, 20, ['delivered_at' => '2026-10-15 10:00:00']),            // de otra semana
            batchEditor(4, 10, ['delivered_at' => '2026-10-20 10:00:00', 'due_date' => '2026-10-20']),
        ]), $semana);
        expect($r['people'][20]['values'])->toMatchArray(['delivered' => 2, 'on_time' => 50.0])
            ->and($r['people'][20]['details']['on_time'])->toBe('1 de 2 encargos')
            ->and($r['totals']['values'])->toMatchArray(['delivered' => 3, 'on_time' => 66.7]);
    });

    it('devuelto por calidad: de lo que se revisó en el período, lo que se devolvió', function () use ($semana) {
        $r = (new EditorKpis())->compute(hechosEditores([], [
            ['editor_id' => 20, 'review' => 'returned', 'reviewed_at' => '2026-10-20 10:00:00'],
            ['editor_id' => 20, 'review' => 'approved', 'reviewed_at' => '2026-10-21 10:00:00'],
            ['editor_id' => 20, 'review' => 'approved', 'reviewed_at' => '2026-10-22 10:00:00'],
            ['editor_id' => 20, 'review' => 'approved', 'reviewed_at' => '2026-10-23 10:00:00'],
        ]), $semana);
        expect($r['people'][20]['values']['returned'])->toBe(25.0)->and($r['people'][20]['details']['returned'])->toBe('1 de 4 entregas revisadas');
    });
});

describe('Editores › resultados en Meta', function () use ($semana, $fila) {
    it('ganador: prometedor o PROVEN en al menos una ciudad, de lo que terminó el test en el período', function () use ($semana) {
        $v = fn (string ...$keys) => array_map(fn ($k) => ['city' => 'Caracas', 'key' => $k, 'label' => $k], $keys);
        $r = (new EditorKpis())->compute(hechosEditores([
            batchEditor(1, 20, ['status' => 'tested', 'tested_at' => '2026-10-22 06:45:00', 'verdicts' => $v('loser', 'promising')]),
            batchEditor(2, 20, ['status' => 'tested', 'tested_at' => '2026-10-23 06:45:00', 'verdicts' => $v('loser', 'intermediate')]),
            batchEditor(3, 20, ['status' => 'tested', 'tested_at' => '2026-10-24 06:45:00', 'verdicts' => $v('proven')]),
            batchEditor(4, 20, ['status' => 'testing', 'testing_at' => '2026-10-24 06:45:00']),            // todavía en test: no cuenta
            batchEditor(5, 20, ['status' => 'tested', 'tested_at' => '2026-10-10 06:45:00', 'verdicts' => $v('proven')]), // otro período
        ]), $semana);
        expect($r['people'][20]['values']['winners'])->toBe(66.7)->and($r['people'][20]['details']['winners'])->toBe('2 de 3 batches testeados');
    });

    it('CPA contra C con la C de cada ciudad, compras y ganancia estimada (compras × BE − gasto)', function () use ($semana, $fila) {
        $r = (new EditorKpis())->compute(hechosEditores([batchEditor(1, 20), batchEditor(2, 20)], [], [
            $fila(1, 30.0, 10, 3.0, 5.0, 'Caracas'),     // a C se podían gastar 30: justo
            $fila(2, 60.0, 10, 9.0, 12.0, 'Valencia'),   // a C se podían gastar 90: un tercio debajo
        ]), $semana);
        // 90 gastados contra 120 permitidos = 75 %; ganancia 10×5 − 30 + 10×12 − 60 = 80
        expect($r['people'][20]['values'])->toMatchArray(['cpa_vs_c' => 75.0, 'purchases' => 20, 'spend' => 90.0, 'profit' => 80.0])
            ->and($r['people'][20]['details']['cpa_vs_c'])->toBe('Con 20 compras');
    });

    it('sin C en una ciudad no se compara; sin compras no hay CPA', function () use ($semana, $fila) {
        $r = (new EditorKpis())->compute(hechosEditores([batchEditor(1, 20), batchEditor(2, 10)], [], [
            $fila(1, 30.0, 10, null, null),
            $fila(2, 25.0, 0),
        ]), $semana);
        expect($r['people'][20]['values'])->toMatchArray(['cpa_vs_c' => null, 'purchases' => 10, 'profit' => null])
            ->and($r['people'][10]['values'])->toMatchArray(['cpa_vs_c' => null, 'profit' => -25.0])
            ->and($r['people'][10]['details']['cpa_vs_c'])->toBe('Todavía sin compras');
    });
});

describe('Editores › lo que ven ellos', function () use ($semana, $fila) {
    it('sin dólares: ni el gasto ni la ganancia, y los textos no traen montos', function () use ($semana, $fila) {
        expect(array_keys(EditorKpis::withoutMoney(EditorKpis::METRICS)))->toBe(['delivered', 'on_time', 'returned', 'winners', 'cpa_vs_c', 'purchases']);
        $r = (new EditorKpis())->compute(hechosEditores([batchEditor(1, 20, ['delivered_at' => '2026-10-20 10:00:00'])], [], [$fila(1, 33.33, 7)]), $semana);
        expect(json_encode($r['people'][20]['details']))->not->toContain('$')->not->toContain('33');
    });

    it('sus batches: en test arriba, después lo testeado más nuevo; con el CPA de toda su vida contra C y si fue a tiempo', function () use ($fila) {
        $facts = hechosEditores([
            batchEditor(1, 20, ['status' => 'tested', 'tested_at' => '2026-10-20 06:45:00', 'delivered_at' => '2026-10-20 10:00:00',
                'verdicts' => [['city' => 'Caracas', 'key' => 'proven', 'label' => 'PROVEN']], 'versions' => 2, 'returned' => 1]),
            batchEditor(2, 20, ['status' => 'tested', 'tested_at' => '2026-10-24 06:45:00', 'delivered_at' => '2026-10-23 10:00:00',
                'verdicts' => [['city' => 'Caracas', 'key' => 'loser', 'label' => 'Perdedor']]]),
            batchEditor(3, 20, ['status' => 'testing', 'testing_at' => '2026-10-25 06:45:00']),
            batchEditor(4, 10, ['status' => 'testing']),
            batchEditor(5, 20, ['status' => 'production']),
        ], [], [$fila(1, 24.0, 10), $fila(2, 40.0, 4)]);
        $list = EditorKpis::batches($facts, [20]);
        expect(array_column($list, 'id'))->toBe([3, 2, 1])
            ->and($list[2])->toMatchArray(['winner' => true, 'purchases' => 10, 'cpa_vs_c' => 80.0, 'on_time' => true, 'versions' => 2, 'returned' => 1])
            ->and($list[1])->toMatchArray(['winner' => false, 'cpa_vs_c' => 333.3, 'on_time' => false])
            ->and($list[0])->toMatchArray(['winner' => null, 'purchases' => 0, 'cpa_vs_c' => null])
            ->and(array_keys($list[0]))->not->toContain('spend');
        expect(array_column(EditorKpis::batches($facts), 'id'))->toContain(4);
    });
});

it('el panel ya mide a los editores: los dos roles quedan cubiertos', function () {
    expect(TeamCatalog::find('editors'))->toBeInstanceOf(EditorKpis::class)
        ->and(TeamCatalog::coverage(['Editor líder', 'Editor de video'])['missing'])->toBe([]);
});
