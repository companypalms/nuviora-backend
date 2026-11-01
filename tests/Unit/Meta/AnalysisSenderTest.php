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

describe('Lo que dice el encargo', function () {
    it('iterar: qué conservar y qué cambiar, en palabras', function () {
        $b = AnalysisSender::brief(['key' => 'iterate', 'editor' => 'Cambia los primeros 3 segundos: el gancho no engancha.'], 'hook', 3);
        expect($b)->toBe([
            'test_idea' => 'Probamos si arreglando lo que falla, este creativo vuelve a vender.',
            'goal' => '3 versiones bien distintas entre sí que arreglen lo que falla.',
            'keep' => 'Todo lo que viene después de los primeros segundos: funciona.',
            'improve' => 'Cambia los primeros 3 segundos: el gancho no engancha.',
        ]);
        $tired = AnalysisSender::brief(['key' => 'iterate', 'editor' => 'La gente ya lo vio mucho.'], null, 3);
        expect($tired['keep'])->toBe('La promesa.')->and($tired['goal'])->toBe('3 versiones bien distintas entre sí, con otra apertura y otras imágenes.');
    });

    it('un ganador: conservar el gancho y la promesa, con aperturas nuevas', function () {
        $b = AnalysisSender::brief(['key' => 'scale', 'editor' => 'Haz versiones nuevas.'], null, 1);
        expect($b['keep'])->toBe('El gancho y la promesa: funcionan, no los cambies.')->and($b['goal'])->toBe('Una versión nueva, con aperturas nuevas.')
            ->and($b['improve'])->toBeNull();
        expect(AnalysisSender::brief(['key' => 'scale', 'editor' => 'x'], 'duration', 3)['improve'])->toStartWith('Acorta el video');
    });
});

describe('La rutina en el calendario', function () {
    it('dice lo de hoy: lo urgente, las decisiones y lo que le llegó al editor líder', function () {
        expect(ReminderRules::routineText(['ready' => true, 'urgent' => 3, 'calls' => ['ESCALAR' => 1, 'PARAR' => 2, 'ITERAR' => 2], 'sent' => 2, 'waiting' => 1]))
            ->toBe('Lo urgente: 3 cosas. Creativos: 1 ESCALAR · 2 PARAR · 2 ITERAR. Al editor líder le llegaron 2 encargos (1 espera cupo para mañana).');
        expect(ReminderRules::routineText(['ready' => true, 'urgent' => 0, 'calls' => ['MANTENER' => 4], 'sent' => 0, 'waiting' => 0]))
            ->toBe('Nada urgente en tus campañas. Creativos: 4 MANTENER.');
        expect(ReminderRules::routineText(['ready' => false, 'urgent' => 0, 'calls' => [], 'sent' => 0, 'waiting' => 0]))->toStartWith('El análisis de hoy sale a las 7:00');
        $o = ReminderRules::occurrence('meta_analysis', '2026-11-02', '2026-11-02', ['meta_routine' => ['ready' => true, 'urgent' => 1, 'calls' => [], 'sent' => 1, 'waiting' => 0]]);
        expect($o['details'])->toBe('Lo urgente: 1 cosa. Al editor líder le llegó 1 encargo.');
    });
});
