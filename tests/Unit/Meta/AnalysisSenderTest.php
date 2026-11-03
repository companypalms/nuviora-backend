<?php

use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Meta\Analysis\AnalysisSender;

// .71 (dueño, 2026-11-01): "no quiero copiar nada para pasárselo al editor líder, quiero que todo se haga a través del
// sistema". Lo que hay que iterar le llega solo al editor líder, como un encargo aprobado.

$creative = fn (int $id, string $key, ?string $editor, ?string $iterate = null) => ['id' => $id, 'label' => "C0{$id}", 'type' => 'video',
    'video' => ['iterate' => $iterate], 'call' => ['key' => $key, 'label' => strtoupper($key), 'tone' => 'info', 'todo' => '', 'editor' => $editor]];

describe('Qué se le manda al editor líder', function () use ($creative) {
    it('los que traen algo para el editor (ITERAR y los que van ganando), en el orden del análisis', function () use ($creative) {
        $facts = [
            ['product_id' => 1, 'mode' => 'normal', 'urgency' => ['level' => 1], 'creatives' => [
                $creative(10, 'stop', null), $creative(11, 'iterate', 'Cambia los primeros 3 segundos.', 'hook'), $creative(12, 'keep', null)]],
            ['product_id' => 2, 'mode' => 'liquidation', 'urgency' => null, 'creatives' => [$creative(20, 'scale', 'Haz versiones nuevas.')]],
            ['product_id' => 3, 'mode' => 'normal', 'urgency' => ['level' => 3], 'creatives' => [$creative(30, 'scale', 'Haz versiones nuevas.')]],
            // Sin trabajo (U5, por ejemplo sin stock): no se le manda nada al equipo
            ['product_id' => 4, 'mode' => 'normal', 'urgency' => ['level' => 5], 'creatives' => [$creative(40, 'iterate', 'Acorta el video.', 'duration')]],
        ];
        $c = AnalysisSender::candidates($facts);
        expect(array_map(fn ($x) => $x['creative']['id'], $c))->toBe([11, 30])
            ->and($c[0])->toMatchArray(['product_id' => 1, 'urgency' => 1]);
        // Los análisis viejos (sin decisiones) no mandan nada
        expect(AnalysisSender::candidates([['product_id' => 1, 'mode' => 'normal', 'creatives' => [['id' => 5, 'label' => 'C05']]]]))->toBe([]);
    });

    it('solo lo que cabe en el día de los editores', function () {
        expect(AnalysisSender::capacity(['video_editors' => 2, 'batches_per_editor' => 2, 'corrections_pct' => 30]))->toBe(3)
            ->and(AnalysisSender::capacity(['video_editors' => 0, 'batches_per_editor' => 2, 'corrections_pct' => 30]))->toBe(0);
    });
});

describe('Por turnos: uno por producto, el más importante (.78)', function () {
    // Dueño, 2026-11-02: "solo subimos un batch a la vez por producto… primero los ganadores, luego los que tienen un
    // rendimiento mediocre pero que se pueden mejorar cambiando algún aspecto como el hook, el hold". Los creativos ya
    // vienen de más a menos gasto.
    $cr = fn (int $id, string $verdict, string $key = 'keep') => ['id' => $id, 'label' => "C{$id}", 'verdict_key' => $verdict,
        'call' => ['key' => $key, 'label' => strtoupper($key), 'tone' => 'info', 'todo' => '', 'editor' => $key === 'stop' ? null : 'Haz versiones.']];

    it('de cada producto uno solo, del más urgente al menos (FlexiStep ya no se lleva los 3)', function () use ($cr) {
        $facts = [
            ['product_id' => 1, 'mode' => 'normal', 'urgency' => ['level' => 1], 'creatives' => [$cr(10, 'intermediate', 'iterate'), $cr(11, 'proven'), $cr(12, 'failing', 'iterate')]],
            ['product_id' => 2, 'mode' => 'normal', 'urgency' => ['level' => 1], 'creatives' => [$cr(20, 'intermediate', 'iterate')]],
            ['product_id' => 3, 'mode' => 'normal', 'urgency' => ['level' => 2], 'creatives' => [$cr(30, 'loser', 'stop')]],
        ];
        $p = AnalysisSender::picks($facts);
        expect(array_column($p, 'product_id'))->toBe([1, 2])
            ->and(array_map(fn ($x) => $x['creative']['id'], $p))->toBe([11, 20]); // el ganador, aunque gaste menos que el intermedio
    });

    it('primero los ganadores, después los que empezaron a fallar, después los mediocres; a igual nivel, el que más gastó', function () use ($cr) {
        $one = fn (array $creatives) => AnalysisSender::picks([['product_id' => 1, 'mode' => 'normal', 'urgency' => ['level' => 1], 'creatives' => $creatives]])[0]['creative']['id'];
        expect($one([$cr(1, 'intermediate', 'iterate'), $cr(2, 'failing', 'iterate')]))->toBe(2)
            ->and($one([$cr(1, 'failing', 'iterate'), $cr(2, 'promising'), $cr(3, 'proven')]))->toBe(2)
            ->and($one([$cr(1, 'intermediate', 'iterate'), $cr(2, 'intermediate', 'iterate')]))->toBe(1);
    });

    it('un producto sin nada para el editor no tiene turno', function () use ($cr) {
        expect(AnalysisSender::picks([['product_id' => 1, 'mode' => 'normal', 'urgency' => ['level' => 2], 'creatives' => [$cr(1, 'loser', 'stop')]]]))->toBe([]);
    });

    it('con "Tiempo de correcciones" en 0 %, 2 editores hacen 4 por día', function () {
        expect(AnalysisSender::capacity(['video_editors' => 2, 'batches_per_editor' => 2, 'corrections_pct' => 0]))->toBe(4);
    });
});

describe('Lo que dice el encargo', function () {
    it('.80 iterar: el primer tramo flojo, qué conservar y qué cambiar', function () {
        $b = AnalysisSender::brief(['key' => 'iterate', 'editor' => 'Qué hacer: …', 'fix' => 'after_hook'], 'after_hook', 3);
        expect($b)->toBe([
            'test_idea' => 'Probamos si arreglando justo después del hook, este creativo vuelve a vender.',
            'goal' => '3 versiones que arreglen justo después del hook.',
            'keep' => 'El hook (los primeros 3 segundos): funciona.',
            'improve' => 'Qué hacer: …',
        ]);
        $tired = AnalysisSender::brief(['key' => 'iterate', 'editor' => 'Qué hacer: …', 'fix' => 'fatigue'], null, 3);
        expect($tired['keep'])->toBe('Todo lo que viene después de los primeros 3 segundos: déjalo igual (en este encargo solo cambia el hook).')
            ->and($tired['goal'])->toBe('3 versiones: un hook distinto en cada una, con otras primeras imágenes.');
    });

    it('.80 un ganador sano: hooks nuevos con el mismo cuerpo; con un tramo flojo, mejorar ese tramo', function () {
        $b = AnalysisSender::brief(['key' => 'scale', 'editor' => 'Qué hacer: …', 'fix' => null], null, 1);
        expect($b['goal'])->toBe('Una versión nueva con otro hook, con el mismo cuerpo.')->and($b['keep'])->toBe('Todo lo que viene después del hook: funciona.')
            ->and($b['improve'])->toBe('Qué hacer: …');
        $mid = AnalysisSender::brief(['key' => 'keep', 'editor' => 'x', 'fix' => 'middle'], 'middle', 3);
        expect($mid['test_idea'])->toBe('Probamos si mejorando el medio, este creativo que ya vende vende todavía más.')
            ->and($mid['keep'])->toBe('El inicio (el hook y lo que sigue): funciona.');
    });
});

describe('La rutina en el calendario', function () {
    it('dice lo de hoy: lo urgente, las decisiones y lo que le llegó al editor líder', function () {
        expect(ReminderRules::routineText(['ready' => true, 'urgent' => 3, 'calls' => ['ESCALAR' => 1, 'PARAR' => 2, 'ITERAR' => 2], 'sent' => 2, 'waiting' => 1]))
            ->toBe('Lo urgente: 3 cosas. Creativos: 1 ESCALAR · 2 PARAR · 2 ITERAR. Al editor líder le llegaron 2 encargos (1 espera cupo para mañana).');
        expect(ReminderRules::routineText(['ready' => true, 'urgent' => 0, 'calls' => ['MANTENER' => 4], 'sent' => 0, 'waiting' => 0]))
            ->toBe('Nada urgente en tus campañas. Creativos: 4 MANTENER.');
        expect(ReminderRules::routineText(['ready' => false, 'urgent' => 0, 'calls' => [], 'sent' => 0, 'waiting' => 0]))->toStartWith('El análisis de hoy sale a las 12:30 a. m.');
        $o = ReminderRules::occurrence('meta_analysis', '2026-11-02', '2026-11-02', ['meta_routine' => ['ready' => true, 'urgent' => 1, 'calls' => [], 'sent' => 1, 'waiting' => 0]]);
        expect($o['details'])->toBe('Lo urgente: 1 cosa. Al editor líder le llegó 1 encargo.');
    });
});
