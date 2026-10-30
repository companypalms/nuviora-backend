<?php

use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Landings\LandingRules;
use App\Services\Meta\Landings\LandingTaskRules;
use App\Services\Meta\Landings\LandingTestRules;

// Los encargos de landing (Etapa 5, paso 6b): quién hace qué y el test antes/después con los mismos anuncios.

$dueno = ['id' => 1, 'role' => 'Admin'];
$lea = ['id' => 50, 'role' => 'Editor de landings'];
$otra = ['id' => 51, 'role' => 'Editor de landings'];
$video = ['id' => 45, 'role' => 'Editor de video'];
$tarea = fn (string $status, ?int $editor = 50, bool $pending = false) => ['status' => $status, 'editor_id' => $editor, 'pending' => $pending];

describe('Quién hace qué', function () use ($dueno, $lea, $otra, $video, $tarea) {
    it('el número L#### después del más alto, desde L0100', function () {
        expect(LandingTaskRules::code(101))->toBe('L0101')
            ->and(LandingTaskRules::next([]))->toBe(100)
            ->and(LandingTaskRules::next([100, 104, null]))->toBe(105);
    });

    it('el editor de landings: empieza, entrega y publica lo suyo; no aprueba, ni revisa, ni descarta', function () use ($lea, $otra, $tarea) {
        expect(LandingTaskRules::can('start', $lea, $tarea('approved')))->toBeTrue()
            ->and(LandingTaskRules::can('deliver', $lea, $tarea('production')))->toBeTrue()
            ->and(LandingTaskRules::can('deliver', $lea, $tarea('approved')))->toBeTrue()
            ->and(LandingTaskRules::can('publish', $lea, $tarea('ready')))->toBeTrue()
            ->and(LandingTaskRules::can('start', $otra, $tarea('approved')))->toBeFalse()
            ->and(LandingTaskRules::can('publish', $otra, $tarea('ready')))->toBeFalse();
        foreach (['approve', 'assign', 'review', 'discard'] as $a) {
            expect(LandingTaskRules::can($a, $lea, $tarea($a === 'review' ? 'review' : 'approved', 50, true)))->toBeFalse($a);
        }
    });

    it('el dueño puede todo, también lo del editor (por ahora las landings las hace él) y aprobar lo suyo', function () use ($dueno, $tarea) {
        expect(LandingTaskRules::can('approve', $dueno, $tarea('proposed', null)))->toBeTrue()
            ->and(LandingTaskRules::can('start', $dueno, $tarea('approved', 1)))->toBeTrue()
            ->and(LandingTaskRules::can('deliver', $dueno, $tarea('production', 50)))->toBeTrue()
            ->and(LandingTaskRules::can('review', $dueno, $tarea('review', 1, true)))->toBeTrue()
            ->and(LandingTaskRules::can('publish', $dueno, $tarea('ready', 1)))->toBeTrue()
            ->and(LandingTaskRules::can('discard', $dueno, $tarea('production')))->toBeTrue();
    });

    it('cada cosa en su estado: no se revisa sin entrega, no se publica sin aprobar, no se descarta un test', function () use ($dueno, $tarea) {
        expect(LandingTaskRules::can('review', $dueno, $tarea('review', 50, false)))->toBeFalse()
            ->and(LandingTaskRules::can('publish', $dueno, $tarea('review')))->toBeFalse()
            ->and(LandingTaskRules::can('start', $dueno, $tarea('production')))->toBeFalse()
            ->and(LandingTaskRules::can('approve', $dueno, $tarea('approved')))->toBeFalse()
            ->and(LandingTaskRules::can('discard', $dueno, $tarea('testing')))->toBeFalse()
            ->and(LandingTaskRules::can('discard', $dueno, $tarea('tested')))->toBeFalse();
    });

    it('lo que ve cada uno: el editor, lo suyo ya aprobado; los editores de video, nada', function () use ($dueno, $lea, $video, $tarea) {
        expect(LandingTaskRules::canSee($lea, $tarea('approved')))->toBeTrue()
            ->and(LandingTaskRules::canSee($lea, $tarea('proposed')))->toBeFalse()
            ->and(LandingTaskRules::canSee($lea, $tarea('approved', 51)))->toBeFalse()
            ->and(LandingTaskRules::canSee($video, $tarea('approved', 45)))->toBeFalse()
            ->and(LandingTaskRules::canSee($dueno, $tarea('proposed', null)))->toBeTrue();
    });

    it('lo pendiente del menú: por hacer, por publicar y, el dueño, por revisar', function () use ($dueno, $lea, $tarea) {
        $tareas = [$tarea('approved'), $tarea('production'), $tarea('ready'), $tarea('review', 50, true), $tarea('approved', 51), $tarea('testing')];
        expect(LandingTaskRules::pending($lea, $tareas))->toBe(['todo' => 2, 'to_publish' => 1, 'to_review' => 0, 'total' => 3])
            ->and(LandingTaskRules::pending($dueno, $tareas)['to_review'])->toBe(1);
    });

    it('la vista previa y la landing publicada: solo enlaces https', function () {
        expect(LandingTaskRules::validUrl('https://abc123.shopifypreview.com/products/flexistep'))->toBeTrue()
            ->and(LandingTaskRules::validUrl('https://tienda.com/products/flexistep?preview_theme_id=123'))->toBeTrue()
            ->and(LandingTaskRules::validUrl('http://tienda.com/products/flexistep'))->toBeFalse()
            ->and(LandingTaskRules::validUrl('tienda.com/products/flexistep'))->toBeFalse()
            ->and(LandingTaskRules::validUrl('la cambié ya'))->toBeFalse();
    });

    it('el tipo que sugiere lo que dicen los números', function () {
        expect(LandingTaskRules::kindFor(['slow']))->toBe('speed')
            ->and(LandingTaskRules::kindFor(['angle_mismatch', 'beaten']))->toBe('angle')
            ->and(LandingTaskRules::kindFor(['angle_weak']))->toBe('offer')
            ->and(LandingTaskRules::kindFor([]))->toBe('angle');
    });
});

describe('El test antes y después con los mismos anuncios', function () {
    $s = DecisionSettings::defaults(); // 7 días antes; 3 a 14 después; 10 compras y 300 visitas; 15 %
    $lado = function (float $visits, float $purchases, float $clicks = 0) {
        $t = LandingRules::empty();
        LandingRules::add($t, ['spend' => $purchases * 10, 'impressions' => 0, 'link_clicks' => $clicks ?: $visits / 0.8, 'landing_page_views' => $visits, 'purchases' => $purchases], null);

        return $t;
    };

    it('las ventanas: 7 días antes, después desde el día siguiente, sin el día de publicar', function () use ($s) {
        expect(LandingTestRules::windows('2026-10-20', '2026-10-25', $s))
            ->toBe(['before' => ['2026-10-13', '2026-10-19'], 'after' => ['2026-10-21', '2026-10-25'], 'after_days' => 5])
            ->and(LandingTestRules::windows('2026-10-20', '2026-10-20', $s)['after_days'])->toBe(0)
            ->and(LandingTestRules::windows('2026-10-01', '2026-10-30', $s)['after'])->toBe(['2026-10-02', '2026-10-15']);
    });

    it('gana si la compra sube 15 % o más, pierde si baja, y si no es empate', function () use ($s, $lado) {
        $antes = $lado(500, 15);                                                  // 3 %
        expect(LandingTestRules::evaluate($antes, $lado(500, 18), 5, $s))->toMatchArray(['state' => 'won', 'done' => true, 'change' => 20.0])
            ->and(LandingTestRules::evaluate($antes, $lado(500, 12), 5, $s))->toMatchArray(['state' => 'lost', 'done' => true, 'change' => -20.0])
            ->and(LandingTestRules::evaluate($antes, $lado(500, 16), 5, $s))->toMatchArray(['state' => 'tie', 'done' => true]);
        expect(LandingTestRules::evaluate($antes, $lado(500, 12), 5, $s)['text'])->toContain('Volver a la anterior')->toContain('3 % antes → 2,4 % después (-20 %)');
    });

    it('espera la muestra y los días mínimos; a los 14 días sin muestra, termina "sin muestra"', function () use ($s, $lado) {
        $antes = $lado(500, 15);
        expect(LandingTestRules::evaluate($antes, $lado(500, 20), 2, $s))->toMatchArray(['state' => 'waiting', 'done' => false])
            ->and(LandingTestRules::evaluate($antes, $lado(200, 9), 6, $s))->toMatchArray(['state' => 'waiting', 'done' => false])
            ->and(LandingTestRules::evaluate($antes, $lado(200, 9), 6, $s)['text'])->toContain('9 de 10 compras y 200 de 300 visitas')
            ->and(LandingTestRules::evaluate($antes, $lado(200, 9), 14, $s))->toMatchArray(['state' => 'no_sample', 'done' => true]);
    });

    it('sin muestra antes no se puede comparar: lo dice', function () use ($s, $lado) {
        $r = LandingTestRules::evaluate($lado(100, 3), $lado(500, 20), 3, $s);
        expect($r)->toMatchArray(['state' => 'no_sample', 'done' => true])->and($r['text'])->toContain('antes no había suficientes');
    });

    it('la visita también se compara (una landing más liviana la sube)', function () use ($s, $lado) {
        $r = LandingTestRules::evaluate($lado(300, 12, 600), $lado(450, 18, 600), 5, $s);
        expect($r['visit_change'])->toBe(25.0)->and($r['text'])->toContain('visita 50 % → 75 %');
    });
});
