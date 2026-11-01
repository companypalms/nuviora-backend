<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\Analysis\AnalysisWriter;
use App\Services\Meta\Analysis\CreativeCalls;

// .70 (dueño, 2026-11-01): "solo quiero que en base a los datos de las campañas la IA pueda decir si escalar, parar o
// mantener un creativo… y que le diga a mis editores qué creativos deben iterar". La decisión sale de SUS reglas.

$cr = fn (string $verdict, ?string $iterate = null, ?string $proposal = null, array $extra = []) => $extra + [
    'label' => 'C0106 FASP', 'type' => 'video', 'verdict' => ['key' => $verdict, 'proposal' => $proposal],
    'video' => $iterate === null && $verdict !== 'intermediate' ? null : ['iterate' => $iterate],
    'cities' => [['campaign_meta_id' => '111', 'city' => 'Caracas']],
];
$subir = ['111' => ['city' => 'Caracas', 'key' => 'scale', 'label' => 'Subir', 'proposal' => 'Subir $30,00 → $36,00']];
$pausar = ['111' => ['city' => 'Caracas', 'key' => 'pause_city', 'label' => 'Pausar la ciudad', 'proposal' => 'Pausar la ciudad.']];

describe('La decisión de cada creativo', function () use ($cr, $subir, $pausar) {
    it('ESCALAR: va ganando y la regla de su ciudad dice subir', function () use ($cr, $subir) {
        $c = CreativeCalls::call($cr('proven'), $subir);
        expect($c['label'])->toBe('ESCALAR')->and($c['tone'])->toBe('success')
            ->and($c['todo'])->toBe('Va ganando: no toques el anuncio, sube el presupuesto y pide iteraciones de él. En Caracas: Subir $30,00 → $36,00.')
            ->and($c['editor'])->toStartWith('Este creativo está funcionando: haz versiones nuevas que conserven el gancho y la promesa');
    });

    it('si va ganando pero su ciudad no está para subir, MANTENER (y avisa lo de la ciudad)', function () use ($cr, $pausar) {
        $c = CreativeCalls::call($cr('promising'), $pausar);
        expect($c['label'])->toBe('MANTENER')
            ->and($c['todo'])->toBe('Va ganando: no lo toques y pide iteraciones de él. Ojo, lo que dice su ciudad: En Caracas: Pausar la ciudad.')
            ->and($c['editor'])->not->toBeNull();
    });

    it('PARAR: perdedor, sin nada para el editor', function () use ($cr, $subir) {
        $c = CreativeCalls::call($cr('loser', 'hook'), $subir);
        expect($c['label'])->toBe('PARAR')->and($c['todo'])->toBe('Apágalo.')->and($c['editor'])->toBeNull();
    });

    it('ITERAR: ganador que se cansó, o intermedio con algo claro que arreglar en el video', function () use ($cr) {
        expect(CreativeCalls::call($cr('failing'))['editor'])->toStartWith('La gente ya lo vio mucho y se cansó')
            ->and(CreativeCalls::call($cr('failing', 'hook'))['editor'])->toBe('Cambia los primeros 3 segundos: el gancho no engancha. El resto del video funciona, no lo toques.');
        $mid = CreativeCalls::call($cr('intermediate', 'duration'));
        expect($mid['label'])->toBe('ITERAR')->and($mid['editor'])->toStartWith('Acorta el video');
        // Si lo que falla es la landing, no es trabajo del editor: se mantiene
        expect(CreativeCalls::call($cr('intermediate', 'landing', 'Mantener si aporta ventas; no escalar.'))['label'])->toBe('MANTENER');
    });

    it('MANTENER: en evaluación, con lo que dice la regla', function () use ($cr) {
        $c = CreativeCalls::call($cr('evaluation', null, 'Dejarlo correr hasta $12,00 (3×C).'));
        expect($c['label'])->toBe('MANTENER')->and($c['todo'])->toBe('Dejarlo correr hasta $12,00 (3×C).')->and($c['editor'])->toBeNull();
    });

    it('lo que lee el editor nunca trae porcentajes ni dinero', function () use ($cr, $subir) {
        foreach (['proven', 'promising', 'failing', 'intermediate'] as $v) {
            foreach ([null, 'hook', 'duration', 'cta', 'format'] as $it) {
                expect((string) CreativeCalls::call($cr($v, $it), $subir)['editor'])->not->toMatch('/%|\$|\d+[.,]\d/');
            }
        }
    });
});

describe('Sus números (para el dueño)', function () use ($cr) {
    it('los del video y los de la plata', function () use ($cr) {
        $video = $cr('proven', null, null, ['week' => ['spend' => 36.0, 'purchases' => 9.0, 'cpa' => 4.0], 'metrics' => ['window' => '3 días',
            'hook_rate' => 22.4, 'hold_rate' => 31.0, 'unique_ctr' => 1.5, 'frequency' => 1.2, 'cpm' => 4.0,
            'retention' => ['p25' => 48.0, 'p75' => 21.5, 'p100' => 12.0], 'avg_watch_time' => 7.5, 'length' => 30.0]]);
        expect(CreativeCalls::numbers($video))->toBe([
            'Hook rate 22,4 % · Hold rate 31 % · CTR 1,5 % · Frecuencia 1,2 · CPM $4,00 (3 días)',
            'Llegan al 25 %: 48 % · al 75 %: 21,5 % · al final: 12 %',
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
        'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [], 'batches' => [],
        'creatives' => [['id' => 9, 'label' => 'C0106 FASP', 'verdict' => 'PROVEN', 'verdict_key' => 'proven', 'tone' => 'success', 'reading' => 'Ganador',
            'proposal' => null, 'cross' => null, 'funnel' => null, 'week' => null, 'cities' => [], 'video' => null, 'status' => 'winner',
            'call' => ['key' => 'scale', 'label' => 'ESCALAR', 'tone' => 'success', 'todo' => 'Va ganando: sube el presupuesto.', 'editor' => 'Haz versiones nuevas.'],
            'numbers' => ['Hook rate 22,4 % · CTR 1,5 % (3 días)']]]]];

    it('la IA recibe la decisión, los números y lo que va para el editor', function () use ($facts) {
        expect(AnalysisFacts::input($facts))->toContain('  · decisión: ESCALAR — Va ganando: sube el presupuesto.')
            ->toContain('  · números: Hook rate 22,4 % · CTR 1,5 % (3 días)')->toContain('  · para el editor (en palabras): Haz versiones nuevas.');
    });

    it('sin IA, la plantilla también trae la decisión y el mensaje al editor en palabras', function () use ($facts) {
        $t = AnalysisWriter::example($facts, []);
        expect($t['productos'][0]['decisiones_creativas'][0])->toBe('P1 ESCALAR · C0106 FASP: PROVEN → Va ganando: sube el presupuesto.')
            ->and($t['productos'][0]['mensaje_editor'])->toContain('C0106 FASP: Haz versiones nuevas.');
    });
});
