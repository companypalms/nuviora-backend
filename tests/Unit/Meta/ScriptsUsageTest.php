<?php

use App\Services\Meta\Analysis\AnalysisFacts;
use App\Services\Meta\AngleProposer;
use App\Services\Meta\Queue\QueueWriter;
use App\Services\Meta\Videos\Scripts;

// `.56`: la IA usa lo que dicen los videos de los ganadores (transcritos en la `.55`).

$script = ['hook' => '¿Te duelen las rodillas? Mira esto.', 'text' => '¿Te duelen las rodillas? Mira esto. FlexiStep te ayuda a caminar. Pídelo hoy con pago al recibir.'];

it('en una línea: el hook y el guion, recortado si es largo', function () use ($script) {
    expect(Scripts::line($script))->toBe('hook «¿Te duelen las rodillas? Mira esto.» · guion «¿Te duelen las rodillas? Mira esto. FlexiStep te ayuda a caminar. Pídelo hoy con pago al recibir.»');
    expect(Scripts::line(['hook' => null, 'text' => str_repeat('palabra ', 200)], 40))->toEndWith('…»')->not->toContain('hook');
    expect(Scripts::line(['hook' => null, 'text' => null]))->toBe('');
});

it('el análisis de la mañana: el ganador lleva lo que dice su video; los demás no', function () use ($script) {
    $creative = fn (int $id, string $label) => ['id' => $id, 'label' => $label, 'verdict' => 'PROVEN', 'verdict_key' => 'proven', 'tone' => 'success', 'reading' => 'Gana.',
        'proposal' => null, 'cross' => null, 'funnel' => null, 'week' => null, 'cities' => [], 'video' => null, 'status' => 'winner'];
    $facts = [['product_id' => 1, 'product' => 'FlexiStep', 'mode' => 'normal', 'urgency' => null, 'money_at_risk' => 0, 'next_work' => [], 'price' => [],
        'price_watch' => null, 'alarms' => [], 'tracking' => null, 'cities' => [], 'batches' => [],
        'creatives' => [$creative(7, 'C007 FASP'), $creative(8, 'C008 FASP')]]];
    $facts = AnalysisFacts::withScripts($facts, [7 => $script]);
    expect($facts[0]['creatives'][0]['script'])->toBe($script)->and($facts[0]['creatives'][1]['script'])->toBeNull();
    $in = AnalysisFacts::input($facts);
    expect($in)->toContain('Creativo #1 C007 FASP: PROVEN')->toContain('  · lo que dice su video: hook «¿Te duelen las rodillas? Mira esto.»');
    expect(substr_count($in, 'lo que dice su video'))->toBe(1);
});

it('el encargo a los editores: la referencia lleva lo que dice su video', function () use ($script) {
    $p = ['key' => 'E1', 'product' => 'FlexiStep', 'urgency' => 1, 'kind' => 'iteration', 'pieces' => 3, 'reason' => 'U1.', 'iterate' => 'el hook',
        'angle_code' => 'FASP', 'reference' => 'C007 FASP', 'reference_type' => 'video', 'city' => 'Caracas',
        'context' => ['proof' => null, 'price' => null, 'angles' => [], 'reference_texts' => [], 'reference_video' => [], 'reference_script' => $script]];
    expect(QueueWriter::input([$p], []))->toContain('Referencia: C007 FASP (video)')->toContain('  · lo que dice su video: hook «¿Te duelen las rodillas?');
    $p['context']['reference_script'] = null;
    expect(QueueWriter::input([$p], []))->not->toContain('lo que dice su video');
});

it('las propuestas de ángulos: los videos de los ganadores de cada ángulo', function () {
    $input = AngleProposer::input('FlexiStep', ['FASP' => ['aliases' => [], 'names' => ['C007 FASP'], 'texts' => ['Camina sin dolor'],
        'videos' => ['C007 FASP: hook «¿Te duelen las rodillas?»']]], ['FASP']);
    expect($input)->toContain('- Video de un ganador: C007 FASP: hook «¿Te duelen las rodillas?»');
});
