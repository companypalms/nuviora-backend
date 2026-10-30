<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\Rules\CampaignRules;
use App\Services\Meta\Queue\QueueRules;

// Etapa 5, paso 4a: la cola de producción y la banca (las partes que no tocan la base).

describe('La numeración única', function () {
    it('B#### para el batch y C#### para cada pieza, con 4 cifras', function () {
        expect(QueueRules::batchCode(100))->toBe('B0100')->and(QueueRules::pieceCode(457))->toBe('C0457')
            ->and(QueueRules::batchCode(12345))->toBe('B12345');
    });

    it('entiende los números que ya hay en Meta, viejos y nuevos', function () {
        expect(array_map(fn ($c) => QueueRules::batchNumber($c), ['B0123', 'B004', 'B001.1', 'BX', null]))->toBe([123, 4, 1, null, null]);
        expect(array_map(fn ($c) => QueueRules::pieceNumber($c), ['C0457', 'CRE001', 'ITER2-CRE001', 'C005', 'CREATIVO6', null]))
            ->toBe([457, 1, 1, 5, null, null]);
    });

    it('sigue al más alto que existe y nunca empieza debajo de 100 (para no confundirse con BATCH 004 o C005)', function () {
        expect(QueueRules::next([]))->toBe(100)
            ->and(QueueRules::next([4, 5, null]))->toBe(100)
            ->and(QueueRules::next([100, 99]))->toBe(101)
            ->and(QueueRules::next([250, 120]))->toBe(251);
    });

    it('los nombres para Meta: el ad set y sus anuncios con el ángulo', function () {
        expect(QueueRules::metaNames(123, 'FASP', [457, 458, null]))->toBe(['adset' => 'B0123 FASP', 'ads' => ['C0457 FASP', 'C0458 FASP']]);
        expect(QueueRules::metaNames(123, null, [457])['adset'])->toBe('B0123')
            ->and(QueueRules::metaNames(null, 'FASP', [])['adset'])->toBeNull();
    });
});

describe('Los estados', function () {
    it('lo propuesto solo se aprueba (aparte) o se descarta', function () {
        expect(QueueRules::canMove('proposed', 'discarded', false))->toBeTrue()
            ->and(QueueRules::canMove('proposed', 'bank', false))->toBeFalse()
            ->and(QueueRules::canMove('proposed', 'approved', false))->toBeFalse();
    });

    it('lo aprobado se mueve libre por la cola, la banca y el test, pero no vuelve a propuesto', function () {
        expect(QueueRules::canMove('approved', 'production', true))->toBeTrue()
            ->and(QueueRules::canMove('review', 'bank', true))->toBeTrue()
            ->and(QueueRules::canMove('bank', 'testing', true))->toBeTrue()
            ->and(QueueRules::canMove('testing', 'tested', true))->toBeTrue()
            ->and(QueueRules::canMove('bank', 'proposed', true))->toBeFalse()
            ->and(QueueRules::canMove('bank', 'bank', true))->toBeFalse();
    });

    it('un descartado vuelve a donde estaba: a aprobar, o aprobado con sus números', function () {
        expect(QueueRules::canMove('discarded', 'proposed', false))->toBeTrue()
            ->and(QueueRules::canMove('discarded', 'approved', false))->toBeFalse()
            ->and(QueueRules::canMove('discarded', 'approved', true))->toBeTrue()
            ->and(QueueRules::canMove('discarded', 'proposed', true))->toBeFalse();
    });

    it('el test se cierra cuando todas las ciudades tienen un veredicto que dice algo', function () {
        $win = ['city' => 'Caracas', 'key' => 'proven', 'label' => 'PROVEN'];
        $lose = ['city' => 'Valencia', 'key' => 'loser', 'label' => 'Perdedor'];
        $wait = ['city' => 'Maracay', 'key' => 'evaluation', 'label' => 'En evaluación'];
        expect(QueueRules::outcome([]))->toBeNull()
            ->and(QueueRules::outcome([$win, $wait]))->toBeNull()
            ->and(QueueRules::outcome([['city' => null, 'key' => 'no_data', 'label' => 'Sin datos']]))->toBeNull()
            ->and(QueueRules::outcome([$win, $lose]))->toBe([$win, $lose]);
    });
});

describe('La banca', function () {
    it('lo que ya está aprobado sin testear se resta de lo que hay que pedir', function () {
        expect(QueueRules::minusBank('2–3', 0))->toBe('2–3')
            ->and(QueueRules::minusBank('2–3', 1))->toBe('1–2')
            ->and(QueueRules::minusBank('1–2', 1))->toBe('0–1')
            ->and(QueueRules::minusBank('2–3', 3))->toBe('0')
            ->and(QueueRules::minusBank('1', 2))->toBe('0');
    });

    it('si la banca alcanza, el trabajo siguiente lo dice; si no se pedía nada, no', function () {
        $rules = new CampaignRules(DecisionSettings::defaults());
        expect($rules->nextWork(['mode' => 'normal', 'bank' => ['B0100'], 'needed' => '1'], '0'))->toBe(['La banca alcanza: B0100 está aprobado sin testear.'])
            ->and($rules->nextWork(['mode' => 'normal', 'bank' => ['B0100', 'B0101'], 'needed' => '2–3'], '0')[0])->toContain('B0100, B0101 están aprobados')
            ->and($rules->nextWork(['mode' => 'normal', 'bank' => ['B0100'], 'needed' => '0'], '0'))->toBe(['Sin trabajo para este producto.'])
            ->and($rules->nextWork(['mode' => 'liquidation', 'bank' => ['B0100'], 'needed' => '0'], '0'))->toBe(['En liquidación: sin creativos nuevos.']);
    });

    it('al intervenir una ciudad, propone lanzar el batch que está en banca', function () {
        $rules = new CampaignRules(DecisionSettings::defaults());
        $in = ['c' => 4.0, 'be' => 5.70, 'mode' => 'normal', 'budget' => 40.0, 'spend3' => 36, 'purchases3' => 6, 'cpa7' => 6.0, 'freq3' => 1.3,
            'stock_ok' => true, 'streak_days' => 2, 'history_since' => '2026-10-30'];
        expect($rules->action($in + ['bank_ready' => ['B0123', 'B0124']])['proposal'])->toContain('podar y lanzar B0123 de la banca')
            ->and($rules->action($in)['proposal'])->toContain('podar y lanzar un batch nuevo');
    });
});

describe('El texto del encargo para el editor', function () {
    it('lleva el número, las piezas con su C#### y los nombres para Meta', function () {
        $text = QueueRules::brief([
            'code' => 'B0123', 'product' => 'FlexiStep', 'urgency' => 1, 'kind' => 'iteration', 'reference' => 'C0400 FASP', 'angle_code' => 'FASP',
            'angle_name' => 'Dolor de tobillo', 'concept' => 'Antes y después', 'hypothesis' => 'Un hook con la pregunta del dolor sube el CTR.',
            'audience' => 'Mujeres de 40+ con dolor al caminar', 'city' => 'Caracas', 'offer' => 'Precio $23,14', 'keep' => 'El cuerpo y el cierre',
            'format' => 'video', 'deliverables' => '3 videos 9:16', 'due_date' => '2026-10-31',
            'pieces' => [['code' => 'C0457', 'change' => 'Hook 1: la pregunta', 'format' => null], ['code' => 'C0458', 'change' => 'Hook 2: el dato', 'format' => 'image']],
            'meta_names' => ['adset' => 'B0123 FASP', 'ads' => ['C0457 FASP', 'C0458 FASP']],
        ]);
        expect($text)->toStartWith('ENCARGO B0123 · FlexiStep · U1')
            ->toContain('Tipo: Iteración (referencia: C0400 FASP)')
            ->toContain('Ángulo: FASP (Dolor de tobillo)')
            ->toContain("1. C0457 — Hook 1: la pregunta\n2. C0458 — Hook 2: el dato (imagen)")
            ->toContain('Plazo: sáb 31/10')
            ->toContain('Nombres en Meta: ad set "B0123 FASP"; anuncios "C0457 FASP", "C0458 FASP".');
    });

    it('sin aprobar todavía no tiene números', function () {
        $text = QueueRules::brief(['code' => null, 'product' => 'Loli', 'urgency' => null, 'kind' => 'new_angle', 'reference' => null, 'angle_code' => null,
            'angle_name' => null, 'format' => null, 'due_date' => null, 'pieces' => [['code' => null, 'change' => '', 'format' => null]],
            'meta_names' => ['adset' => null, 'ads' => []]]);
        expect($text)->toBe("ENCARGO (sin aprobar) · Loli\nTipo: Ángulo nuevo\nPiezas:\n1. sin detalle");
    });
});
