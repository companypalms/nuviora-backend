<?php

use App\Services\Meta\Queue\ProposalRules;
use App\Services\Meta\Queue\QueueWriter;

// Etapa 5, paso 4b: qué encargos proponen las reglas cada mañana (la IA solo los redacta).

/** Un producto como lo devuelve MetaDecisions::compute, con lo justo para proponer. */
function productoCola(int $id, string $name, int $level, string $batches, array $extra = []): array
{
    return array_replace_recursive([
        'product' => ['id' => $id, 'name' => $name, 'mode' => 'normal'],
        'campaigns' => [
            "c{$id}a" => ['city' => 'Caracas', 'city_id' => 1, 'active' => true, 'stock' => 50, 'purchases7' => 20, 'action' => ['key' => 'hold', 'label' => 'Mantener']],
            "c{$id}b" => ['city' => 'Valencia', 'city_id' => 2, 'active' => true, 'stock' => 30, 'purchases7' => 35, 'action' => ['key' => 'scale', 'label' => 'Subir']],
        ],
        'creatives' => [],
        'plan' => ['urgency' => ['level' => $level, 'label' => "U{$level}", 'batches' => $batches, 'reasons' => []], 'money_at_risk' => 0,
            'work' => ['winner' => null, 'main_angle' => 'FASP', 'failed_batches_in_angle' => 0, 'saturated' => []]],
    ], $extra);
}

describe('Cuántos y en qué orden', function () {
    it('primero lo que se puede salvar (U1), después escalar (U3) y el resto; hasta la capacidad de los editores', function () {
        $d = [
            1 => productoCola(1, 'Sano', 4, '0–1'),
            2 => productoCola(2, 'Escalando', 3, '1'),
            3 => productoCola(3, 'Urgente', 1, '2–3'),
        ];
        $plan = ProposalRules::plan($d, [], 3);
        expect(array_column($plan, 'product'))->toBe(['Urgente', 'Urgente', 'Escalando']);
        expect(array_column(ProposalRules::plan($d, [], 10), 'product'))->toBe(['Urgente', 'Urgente', 'Escalando', 'Urgente', 'Sano']);
    });

    it('dentro de la misma urgencia, más dinero en riesgo primero', function () {
        $d = [1 => productoCola(1, 'Poco riesgo', 1, '1', ['plan' => ['money_at_risk' => 10]]), 2 => productoCola(2, 'Mucho riesgo', 1, '1', ['plan' => ['money_at_risk' => 90]])];
        expect(array_column(ProposalRules::plan($d, [], 1), 'product'))->toBe(['Mucho riesgo']);
    });

    it('no repite lo que ya se propuso hoy', function () {
        $d = [3 => productoCola(3, 'Urgente', 1, '2–3')];
        expect(count(ProposalRules::plan($d, [3 => 2], 5)))->toBe(1)
            ->and(ProposalRules::plan($d, [3 => 3], 5))->toBe([]);
    });

    it('nunca un producto en liquidación, en pausa, sin trabajo o sin una ciudad con stock', function () {
        $sinStock = ['campaigns' => ['c1a' => ['stock' => 0], 'c1b' => ['stock' => 0]]];
        $d = [
            1 => productoCola(1, 'Sin stock', 1, '2–3', $sinStock),
            2 => productoCola(2, 'Liquidación', 1, '2–3', ['product' => ['mode' => 'liquidation']]),
            3 => productoCola(3, 'U5', 5, '0'),
            4 => productoCola(4, 'Banca llena', 2, '0'),
        ];
        expect(ProposalRules::plan($d, [], 10))->toBe([]);
    });

    it('la ciudad de aprendizaje: la que más vendió en 7 días con stock, sin las que se pausan', function () {
        $p = ProposalRules::plan([1 => productoCola(1, 'X', 2, '1')], [], 1)[0];
        expect($p['city'])->toBe('Valencia');
        $sinValencia = ['campaigns' => ['c1b' => ['action' => ['key' => 'pause_city', 'label' => 'Pausar la ciudad']]]];
        expect(ProposalRules::plan([1 => productoCola(1, 'X', 2, '1', $sinValencia)], [], 1)[0]['city'])->toBe('Caracas');
    });
});

describe('Qué tipo de encargo', function () {
    it('con un ganador: iteraciones de ese ganador, cambiando lo que dice su video', function () {
        $winner = ['id' => 7, 'label' => 'C004 GINE', 'iterate' => 'el hook (los primeros segundos)', 'angle' => 'GINE', 'type' => 'video'];
        $p = ProposalRules::plan([1 => productoCola(1, 'X', 3, '1', ['plan' => ['work' => ['winner' => $winner]]])], [], 1)[0];
        expect($p)->toMatchArray(['kind' => 'iteration', 'reference_id' => 7, 'reference' => 'C004 GINE', 'angle_code' => 'GINE', 'iterate' => 'el hook (los primeros segundos)']);
    });

    it('una imagen ganadora pasa a video', function () {
        $winner = ['id' => 7, 'label' => 'C009 IMG', 'iterate' => null, 'angle' => 'FASP', 'type' => 'image'];
        $p = ProposalRules::plan([1 => productoCola(1, 'X', 3, '1', ['plan' => ['work' => ['winner' => $winner]]])], [], 1)[0];
        expect($p['iterate'])->toBe('el formato: la imagen ganadora pasa a video')->and($p['format'])->toBe('video');
    });

    it('sin ganador: concepto nuevo; ángulo nuevo solo si el ángulo ya falló en 2 batches; arriba del embudo si se satura', function () {
        expect(ProposalRules::kind(['winner' => null, 'main_angle' => 'FASP', 'failed_batches_in_angle' => 1], 0))->toBe('new_concept')
            ->and(ProposalRules::kind(['winner' => null, 'main_angle' => 'FASP', 'failed_batches_in_angle' => 2], 0))->toBe('new_angle')
            ->and(ProposalRules::kind(['winner' => ['id' => 1], 'saturated' => ['Caracas']], 1))->toBe('top_funnel')
            ->and(ProposalRules::kind(['winner' => ['id' => 1], 'saturated' => []], 1))->toBe('new_concept');
    });

    it('la referencia de un concepto nuevo: el creativo más consistente entre ciudades del ángulo principal', function () {
        $creatives = [
            1 => ['id' => 1, 'label' => 'C001 FASP', 'active' => true, 'angle_code' => 'FASP', 'type' => 'video', 'verdict' => ['key' => 'proven'],
                'cross' => ['key' => 'city_sensitive'], 'week' => ['purchases' => 30, 'cpa' => 3.0]],
            2 => ['id' => 2, 'label' => 'C002 FASP', 'active' => true, 'angle_code' => 'FASP', 'type' => 'video', 'verdict' => ['key' => 'promising'],
                'cross' => ['key' => 'transversal'], 'week' => ['purchases' => 9, 'cpa' => 3.8]],
            3 => ['id' => 3, 'label' => 'C003 GINE', 'active' => true, 'angle_code' => 'GINE', 'type' => 'video', 'verdict' => ['key' => 'proven'],
                'cross' => ['key' => 'transversal'], 'week' => ['purchases' => 40, 'cpa' => 2.0]],
        ];
        $p = ProposalRules::plan([1 => productoCola(1, 'X', 2, '1', ['creatives' => $creatives])], [], 1)[0];
        expect($p)->toMatchArray(['kind' => 'new_concept', 'reference' => 'C002 FASP', 'angle_code' => 'FASP']);
    });
});

describe('Lo que lee la IA', function () {
    it('lleva los avales reales del producto tal cual, y avisa cuando no hay', function () {
        $base = ['key' => 'E1', 'product' => 'FlexiStep', 'urgency' => 1, 'kind' => 'iteration', 'pieces' => 3, 'reason' => 'U1.', 'iterate' => 'el hook',
            'angle_code' => 'FASP', 'reference' => 'C004 FASP', 'reference_type' => 'video', 'city' => 'Caracas',
            'context' => ['proof' => 'Registro sanitario 1234', 'price' => '$23,14', 'angles' => ['FASP' => 'Dolor de tobillo'],
                'reference_texts' => ['Camina sin dolor — Tobillera'], 'reference_video' => ['hook bajo']]];
        $input = QueueWriter::input([$base, ['key' => 'E2', 'product' => 'Loli'] + array_replace($base, ['context' => ['proof' => null, 'price' => null, 'angles' => []]])], []);
        expect($input)->toContain('Avales y pruebas reales: Registro sanitario 1234')
            ->toContain('Avales y pruebas reales: ninguno (no inventes)')
            ->toContain('== ENCARGO E1 · FlexiStep · U1 · iteración · 3 piezas')
            ->toContain('texto del anuncio: Camina sin dolor');
    });

    it('sin IA, la plantilla respeta la cantidad de piezas', function () {
        $p = ['key' => 'E1', 'kind' => 'iteration', 'reference' => 'C004 FASP', 'iterate' => 'el hook', 'pieces' => 3, 'format' => 'video'];
        $t = QueueWriter::example([$p], [])['encargos'][0];
        expect($t['clave'])->toBe('E1')->and($t['piezas'])->toHaveCount(3)->and($t['entregables'])->toBe('3 videos 9:16');
    });
});
