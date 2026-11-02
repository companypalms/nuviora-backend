<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\AnalysisWriter;
use App\Services\Meta\Analysis\CreativeCalls;

// .70 (dueño, 2026-11-01): "solo quiero que en base a los datos de las campañas la IA pueda decir si escalar, parar o
// mantener un creativo… y que le diga a mis editores qué creativos deben iterar". La decisión sale de SUS reglas.
// .80 (2026-11-02): "PARAR en Aragua" por ciudad, y al editor qué está bien, qué está flojo (el primer tramo del video),
// qué hacer y los números del video, nunca plata.

// Los tramos de ZenPulse CREATIVO 6 (2/11), con el medio flojo contra sus ganadores
$stages = [
    'hook' => ['label' => 'el hook', 'value' => 51.2, 'median' => 48.0, 'grade' => 'good'],
    'after_hook' => ['label' => 'justo después del hook', 'value' => 41.0, 'median' => 38.0, 'grade' => 'good'],
    'middle' => ['label' => 'el medio', 'value' => 37.0, 'median' => 52.0, 'grade' => 'weak'],
    'close' => ['label' => 'el cierre', 'value' => 65.2, 'median' => 60.0, 'grade' => 'good'],
    'click' => ['label' => 'el clic', 'value' => 6.8, 'median' => 6.0, 'grade' => 'good'],
];
$cr = fn (string $verdict, ?string $iterate = null, ?string $proposal = null, array $extra = []) => $extra + [
    'label' => 'C0106 FASP', 'type' => 'video', 'verdict' => ['key' => $verdict, 'proposal' => $proposal, 'causes' => []],
    'video' => $iterate === null && $verdict !== 'intermediate' ? null : ['iterate' => $iterate, 'window' => '3 días', 'stages' => [], 'watch_pct' => null],
    'metrics' => ['window' => '3 días', 'frequency' => 1.4],
    'cities' => [['campaign_meta_id' => '111', 'city' => 'Caracas', 'key' => 'proven', 'active' => true]],
];
$subir = ['111' => ['city' => 'Caracas', 'key' => 'scale', 'label' => 'Subir', 'proposal' => 'Subir $30,00 → $36,00']];
$pausar = ['111' => ['city' => 'Caracas', 'key' => 'pause_city', 'label' => 'Pausar la ciudad', 'proposal' => 'Pausar la ciudad.']];

describe('La decisión de cada creativo', function () use ($cr, $subir, $pausar, $stages) {
    it('ESCALAR: va ganando y la regla de su ciudad dice subir', function () use ($cr, $subir) {
        $c = CreativeCalls::call($cr('proven'), $subir);
        expect($c['label'])->toBe('ESCALAR')->and($c['tone'])->toBe('success')
            ->and($c['todo'])->toBe('Va ganando: no toques el anuncio, sube el presupuesto y pide iteraciones de él. En Caracas: Subir $30,00 → $36,00.')
            ->and($c['editor'])->toStartWith('Qué hacer: Este creativo funciona en todo: no lo toques. Haz hooks nuevos, uno distinto en cada pieza');
    });

    it('si va ganando pero su ciudad no está para subir, MANTENER (y avisa lo de la ciudad)', function () use ($cr, $pausar) {
        $c = CreativeCalls::call($cr('promising'), $pausar);
        expect($c['label'])->toBe('MANTENER')
            ->and($c['todo'])->toBe('Va ganando: no lo toques y pide iteraciones de él. Ojo, lo que dice su ciudad: En Caracas: Pausar la ciudad.')
            ->and($c['editor'])->not->toBeNull();
    });

    it('PARAR EN ARAGUA: va ganando, pero en Aragua pierde y sigue activo (FlexiStep CRE008, 2/11)', function () use ($cr) {
        $both = [['campaign_meta_id' => '111', 'city' => 'Distrito Capital', 'key' => 'proven', 'active' => true],
            ['campaign_meta_id' => '222', 'city' => 'Aragua', 'key' => 'loser', 'active' => true]];
        $c = CreativeCalls::call($cr('proven', null, null, ['cities' => $both]));
        expect($c['key'])->toBe('stop_city')->and($c['label'])->toBe('PARAR EN ARAGUA')->and($c['tone'])->toBe('error')
            ->and($c['todo'])->toBe('Apágalo en Aragua (ahí pierde). Donde gana, no lo toques y pide iteraciones de él.')
            ->and($c['stop_in'])->toBe(['Aragua'])->and($c['editor'])->not->toBeNull();
        // Si en Aragua ya está apagado, no hay nada que parar
        $off = [$both[0], ['active' => false] + $both[1]];
        expect(CreativeCalls::call($cr('proven', null, null, ['cities' => $off]))['label'])->toBe('MANTENER');
        // Uno que se está cansando y además pierde en Aragua
        $tired = CreativeCalls::call($cr('failing', null, null, ['cities' => $both, 'verdict' => ['key' => 'failing', 'proposal' => null, 'causes' => [['key' => 'fatigue']]]]));
        expect($tired['label'])->toBe('ITERAR · PARAR EN ARAGUA')->and($tired['todo'])->toStartWith('Apágalo en Aragua (ahí pierde). Se está cansando');
    });

    it('PARAR: perdedor, sin nada para el editor; para aprender, el tramo que falló', function () use ($cr, $subir) {
        $c = CreativeCalls::call($cr('loser', 'hook'), $subir);
        expect($c['label'])->toBe('PARAR')->and($c['todo'])->toBe('Apágalo. Para aprender: lo más flojo fue el hook.')->and($c['editor'])->toBeNull();
    });

    it('ITERAR: ganador que se cansó, o el primer tramo flojo del video', function () use ($cr) {
        $tired = $cr('failing', null, null, ['verdict' => ['key' => 'failing', 'proposal' => null, 'causes' => [['key' => 'fatigue']]]]);
        expect(CreativeCalls::call($tired)['editor'])->toStartWith('Qué hacer: La gente ya lo vio mucho: haz hooks nuevos con otras primeras imágenes')
            ->toContain('Está flojo: la gente ya lo vio mucho (frecuencia 1,4).');
        expect(CreativeCalls::call($cr('failing', 'after_hook'))['editor'])->toStartWith('Qué hacer: El hook funciona: no lo toques. La gente se va justo después');
        $mid = CreativeCalls::call($cr('intermediate', 'middle'));
        expect($mid['label'])->toBe('ITERAR')->and($mid['todo'])->toBe('Lo flojo es el medio: pide versiones que lo arreglen.')->and($mid['fix'])->toBe('middle');
        // Si lo que falla es la landing, no es trabajo del editor: se mantiene
        expect(CreativeCalls::call($cr('intermediate', 'landing', 'Mantener si aporta ventas; no escalar.'))['label'])->toBe('MANTENER');
        // Un ganador que falla porque la subasta está más cara en toda la ciudad: tampoco es el video
        $auction = $cr('failing', null, 'No es el creativo: la subasta está más cara en toda la ciudad. Vigilar sin apagar.',
            ['verdict' => ['key' => 'failing', 'proposal' => 'No es el creativo: la subasta está más cara en toda la ciudad. Vigilar sin apagar.', 'causes' => [['key' => 'auction']]]]);
        expect(CreativeCalls::call($auction))->toMatchArray(['key' => 'keep', 'editor' => null]);
    });

    it('MANTENER: en evaluación, con lo que dice la regla', function () use ($cr) {
        $c = CreativeCalls::call($cr('evaluation', null, 'Dejarlo correr hasta $12,00 (3×C).'));
        expect($c['label'])->toBe('MANTENER')->and($c['todo'])->toBe('Dejarlo correr hasta $12,00 (3×C).')->and($c['editor'])->toBeNull();
    });

    it('lo que lee el editor: qué hacer, qué está bien, qué está flojo y los números del video', function () use ($cr, $stages) {
        $c = $cr('proven', 'middle', null, ['video' => ['iterate' => 'middle', 'window' => '3 días', 'stages' => $stages, 'watch_pct' => 28.0]]);
        expect(CreativeCalls::call($c)['editor'])->toBe('Qué hacer: Este creativo está vendiendo: conserva todo lo que funciona. El inicio funciona: no lo toques. '
            . 'El medio pierde gente: hazlo más corto y con más ritmo (cortes más rápidos, menos explicación) y adelanta lo más fuerte (la demostración o el beneficio). '
            . 'Está bien (no lo toques): el hook (51,2 %), justo después del hook (41 %), el cierre (65,2 %), el clic (6,8 %). '
            . 'Está flojo: el medio (37 %; en tus ganadores, 52 %). '
            . 'Números del video (3 días): hook 51,2 % · justo después del hook (hold) 41 % · medio 37 % · cierre 65,2 % · CTR único 6,8 % · frecuencia 1,4 · visto en promedio 28 %.');
    });

    it('lo que lee el editor nunca trae plata', function () use ($cr, $subir, $stages) {
        foreach (['proven', 'promising', 'failing', 'intermediate'] as $v) {
            foreach ([null, 'hook', 'after_hook', 'middle', 'close', 'click', 'format'] as $it) {
                $c = $cr($v, $it, null, ['video' => ['iterate' => $it, 'window' => '3 días', 'stages' => $stages, 'watch_pct' => 28.0]]);
                expect((string) CreativeCalls::call($c, $subir)['editor'])->not->toMatch('/\$|USD|Bs/');
            }
        }
    });
});

describe('Sus números (para el dueño)', function () use ($cr, $stages) {
    it('los del video, por tramos, y los de la plata', function () use ($cr, $stages) {
        $video = $cr('proven', null, null, ['week' => ['spend' => 36.0, 'purchases' => 9.0, 'cpa' => 4.0], 'video' => ['iterate' => 'middle', 'stages' => $stages],
            'metrics' => ['window' => '3 días', 'hook_rate' => 22.4, 'hold_rate' => 31.0, 'unique_ctr' => 1.5, 'frequency' => 1.2, 'cpm' => 4.0,
                'retention' => ['p25' => 48.0, 'p50' => 30.0, 'p75' => 21.5, 'p100' => 12.0], 'avg_watch_time' => 7.5, 'length' => 30.0]]);
        expect(CreativeCalls::numbers($video))->toBe([
            'Hook rate 22,4 % · Hold rate 31 % · CTR 1,5 % · Frecuencia 1,2 · CPM $4,00 (3 días)',
            'De cada 100 que empiezan el video llegan: al 25 % 48 % · al 50 % 30 % · al 75 % 21,5 % · al final 12 %',
            'Por tramos: el hook 51,2 % (ganadores 48 %) · justo después del hook 41 % (ganadores 38 %) · el medio 37 % (ganadores 52 %) FLOJO · el cierre 65,2 % (ganadores 60 %) · el clic 6,8 % (ganadores 6 %)',
            'Tiempo promedio de visualización: 7,5 s de 30 s (25 %)',
            '7 días: gasto $36,00 · 9 compras · CPA $4,00',
        ]);
        $image = $cr('proven', null, null, ['type' => 'image', 'week' => null, 'metrics' => ['window' => '7 días', 'hook_rate' => null, 'hold_rate' => null,
            'unique_ctr' => 2.0, 'frequency' => 1.8, 'cpm' => 3.5, 'retention' => null, 'avg_watch_time' => null, 'length' => null]]);
        expect(CreativeCalls::numbers($image))->toBe(['CTR 2 % · Frecuencia 1,8 · CPM $3,50 (7 días)']);
    });
});

describe('En el análisis de la mañana', function () {
    $facts = [['product_id' => 1, 'product' => 'Comprimax', 'mode' => 'normal', 'urgency' => null, 'money_at_risk' => 0, 'next_work' => [], 'price' => [],
        'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [], 'batches' => [], 'stop_by_city' => ['Aragua' => ['CRE008', 'C0106 FASP']],
        'creatives' => [['id' => 9, 'label' => 'C0106 FASP', 'verdict' => 'PROVEN', 'verdict_key' => 'proven', 'tone' => 'success', 'reading' => 'Ganador',
            'proposal' => null, 'cross' => null, 'funnel' => null, 'week' => null, 'cities' => [], 'video' => null, 'status' => 'winner',
            'call' => ['key' => 'scale', 'label' => 'ESCALAR', 'tone' => 'success', 'todo' => 'Va ganando: sube el presupuesto.', 'editor' => 'Haz versiones nuevas.'],
            'numbers' => ['Hook rate 22,4 % · CTR 1,5 % (3 días)']]]]];

    it('la IA recibe qué apagar por ciudad, la decisión, los números y lo que va para el editor', function () use ($facts) {
        expect(AnalysisFacts::input($facts))->toContain('Apagar hoy en Aragua (ahí pierden): CRE008, C0106 FASP')
            ->toContain('  · decisión: ESCALAR — Va ganando: sube el presupuesto.')
            ->toContain('  · números: Hook rate 22,4 % · CTR 1,5 % (3 días)')->toContain('  · para el editor (en palabras): Haz versiones nuevas.');
    });

    it('sin IA, la plantilla también trae la decisión y el mensaje al editor en palabras', function () use ($facts) {
        $t = AnalysisWriter::example($facts, []);
        expect($t['productos'][0]['decisiones_creativas'][0])->toBe('P1 ESCALAR · C0106 FASP: PROVEN → Va ganando: sube el presupuesto.')
            ->and($t['productos'][0]['mensaje_editor'])->toContain('C0106 FASP: Haz versiones nuevas.');
    });
});
