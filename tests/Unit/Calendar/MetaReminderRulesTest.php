<?php

use App\Services\Calendar\Rules\MetaReminderRules;
use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Calendar\Rules\Schedule;
use Carbon\CarbonImmutable;

// .67 (dueño, 2026-11-01): "que en el calendario se vea todo lo que tengo que revisar en sus respectivos tiempos, por
// ejemplo, revisar lo que tenga que ver con el flujo correcto de trabajo del editor de contenido".
// Noviembre de 2026: lunes 2, sábado 7. Hoy, en estas pruebas: lunes 2/11 a las 10:00.

$on = ReminderRules::normalizeSettings(null);
$dates = fn (string $key, array $settings, string $from, string $to) => array_column(
    Schedule::dates(ReminderRules::pattern($key, $settings), CarbonImmutable::parse($from), CarbonImmutable::parse($to)), 'date');

// Un encargo abierto, como lo lee MetaReminderFacts
$open = fn (array $o) => $o + ['code' => 'B0100', 'product' => 'Comprimax', 'landing' => false, 'status' => 'approved', 'editor_id' => null, 'editor' => null,
    'due_date' => null, 'approved_at' => null, 'status_at' => null, 'last_review' => null, 'last_reviewed_at' => null, 'pending_by' => null,
    'pending_editor' => null, 'pending_at' => null];
$leaders = [7 => 'Leo Líder'];
$work = fn (array $w) => $w + ['now' => '2026-11-02 10:00:00', 'proposed' => ['videos' => [], 'landings' => []], 'testing' => [], 'no_profile' => [],
    'open' => [], 'leaders' => [7 => 'Leo Líder'], 'landing_editor' => false, 'bank' => []];
$occ = fn (string $key, array $facts, string $due = '2026-11-02') => ReminderRules::occurrence($key, $due, '2026-11-02', $facts);

describe('Cuándo tocan', function () use ($on, $dates) {
    it('lo de Meta Ads va de lunes a sábado, en su propio bloque (con el análisis de campañas)', function () use ($on, $dates) {
        expect($dates('meta_flow', $on, '2026-11-01', '2026-11-08'))
            ->toBe(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);
        foreach (['meta_analysis', 'meta_queue', 'meta_deliveries', 'meta_flow', 'meta_bank'] as $key) {
            expect(ReminderRules::RULES[$key]['group'])->toBe('meta')->and(ReminderRules::RULES[$key]['accumulates'])->toBeFalse();
        }
    });

    it('el trabajo de los editores, los lunes; lo que aprendimos, los sábados (los dos se pueden mover)', function () use ($on, $dates) {
        expect($dates('editors_week', $on, '2026-11-01', '2026-11-14'))->toBe(['2026-11-02', '2026-11-09'])
            ->and($dates('learnings_week', $on, '2026-11-01', '2026-11-14'))->toBe(['2026-11-07', '2026-11-14']);
        $moved = ReminderRules::normalizeSettings(['editors_week' => ['weekday' => 2]]);
        expect($moved['editors_week'])->toBe(['on' => true, 'weekday' => 2])
            ->and($dates('editors_week', $moved, '2026-11-01', '2026-11-07'))->toBe(['2026-11-03']);
    });
});

describe('Las entregas que esperan por el dueño', function () use ($open, $leaders, $work, $occ) {
    it('las del líder, las de los editores si no hay otro líder, las de landings y las landings para publicar', function () use ($open, $leaders) {
        $list = [
            $open(['code' => 'B0101', 'status' => 'review', 'pending_by' => 7, 'pending_editor' => 'Leo Líder', 'pending_at' => '2026-11-01 09:00:00']),
            $open(['code' => 'B0102', 'status' => 'review', 'pending_by' => 8, 'pending_editor' => 'Eva Editora', 'pending_at' => '2026-10-31 09:00:00']),
            $open(['code' => 'L0100', 'landing' => true, 'status' => 'review', 'pending_by' => 9, 'pending_editor' => 'Lia', 'pending_at' => '2026-11-02 08:00:00']),
            $open(['code' => 'L0101', 'landing' => true, 'status' => 'ready', 'status_at' => '2026-10-30 12:00:00']),
        ];
        expect(array_column(MetaReminderRules::toReview($list, $leaders), 'code'))->toBe(['B0101', 'L0100', 'L0101']);
        // Sin líder, también la del editor
        expect(array_column(MetaReminderRules::toReview($list, []), 'code'))->toBe(['B0102', 'B0101', 'L0100', 'L0101']);
        // Con dos líderes, uno revisa al otro
        expect(array_column(MetaReminderRules::toReview($list, $leaders + [6 => 'Otro Líder']), 'code'))->toBe(['L0100', 'L0101']);
    });

    it('lo dice con el código, quién entregó y cuándo; sin nada, se tilda solo', function () use ($open, $work, $occ) {
        $o = $occ('meta_deliveries', ['meta_work' => $work(['open' => [
            $open(['code' => 'B0101', 'status' => 'review', 'pending_by' => 7, 'pending_editor' => 'Leo Líder', 'pending_at' => '2026-11-01 09:30:00']),
            $open(['code' => 'L0101', 'landing' => true, 'status' => 'ready', 'status_at' => '2026-10-30 12:00:00']),
        ]])]);
        expect($o['details'])->toBe('1 entrega de video por revisar, y 1 landing para publicar.')
            ->and($o['lines'][0])->toBe(['label' => 'B0101 · Comprimax', 'value' => 'Por revisar', 'note' => 'de Leo Líder (el líder: no puede aprobarse solo), entregó el domingo 1/11 a las 9:30'])
            ->and($o['lines'][1]['note'])->toBe('aprobada el viernes 30/10 a las 12:00: publícala en Shopify y toca "Publicada"')
            ->and($o['auto_done'])->toBeFalse();
        $none = $occ('meta_deliveries', ['meta_work' => $work([])]);
        expect($none['auto_done'])->toBeTrue()->and($none['details'])->toBe('No hay entregas esperando por ti.');
    });
});

describe('Que los editores vayan al día', function () use ($open, $leaders, $work, $occ) {
    $list = fn () => [
        $open(['code' => 'B0101', 'status' => 'production', 'editor_id' => 8, 'editor' => 'Eva Editora', 'due_date' => '2026-10-31']),
        $open(['code' => 'B0102', 'status' => 'approved', 'approved_at' => '2026-10-31 15:00:00']),
        $open(['code' => 'B0103', 'status' => 'approved', 'approved_at' => '2026-11-02 08:00:00']),               // hoy: todavía no
        $open(['code' => 'B0104', 'status' => 'review', 'editor_id' => 8, 'editor' => 'Eva Editora', 'pending_by' => 8, 'pending_editor' => 'Eva Editora', 'pending_at' => '2026-10-31 18:00:00']),
        $open(['code' => 'B0105', 'status' => 'review', 'pending_by' => 8, 'pending_at' => '2026-11-02 07:00:00']), // entregó hoy: todavía no
        $open(['code' => 'B0106', 'status' => 'production', 'editor_id' => 8, 'editor' => 'Eva Editora', 'last_review' => 'returned', 'last_reviewed_at' => '2026-10-30 11:00:00']),
        $open(['code' => 'B0107', 'status' => 'production', 'editor_id' => 8, 'last_review' => 'returned', 'last_reviewed_at' => '2026-11-01 11:00:00']), // hace 1 día: todavía no
        $open(['code' => 'L0100', 'landing' => true, 'status' => 'approved', 'approved_at' => '2026-10-29 10:00:00']),
    ];

    it('avisa lo atrasado, lo que lleva más de 1 día sin editor o esperando al líder y lo devuelto hace 2 días', function () use ($list, $leaders, $open) {
        $stuck = MetaReminderRules::stuck($list(), $leaders, false, '2026-11-02 10:00:00');
        expect(array_map(fn ($r) => $r['code'] . ' ' . $r['kind'], $stuck))->toBe(['B0101 late', 'B0102 unassigned', 'B0104 waiting', 'B0106 returned']);
        // La landing sin editor solo avisa si hay un editor de landings (si no, la hace el dueño)
        expect(array_column(MetaReminderRules::stuck($list(), $leaders, true, '2026-11-02 10:00:00'), 'code'))->toContain('L0100');
        // Lo atrasado gana: un devuelto que además pasó su fecha sale una vez, como atrasado
        $both = MetaReminderRules::stuck([$open(['code' => 'B0108', 'status' => 'production', 'due_date' => '2026-11-01', 'last_review' => 'returned', 'last_reviewed_at' => '2026-10-29 10:00:00'])], $leaders, false, '2026-11-02 10:00:00');
        expect(array_column($both, 'kind'))->toBe(['late']);
    });

    it('la frase dice cuántos y de qué, y cada encargo con quién y desde cuándo', function () use ($list, $work, $occ) {
        $o = $occ('meta_flow', ['meta_work' => $work(['open' => $list()])]);
        expect($o['details'])->toBe('4 encargos para mirar: 1 atrasado, 1 sin editor, 1 esperando al líder y 1 devuelto sin volver a entregarse.')
            ->and(array_column($o['lines'], 'note'))->toBe([
                'Eva Editora: se tenía que entregar el sábado 31/10',
                'aprobado el sábado 31/10: el líder todavía no se lo asigna a nadie',
                'Eva Editora entregó el sábado 31/10; lo revisa Leo Líder',
                'se le devolvió a Eva Editora el viernes 30/10 y todavía no lo vuelve a entregar',
            ]);
        $fine = $occ('meta_flow', ['meta_work' => $work([])]);
        expect($fine['auto_done'])->toBeTrue()->and($fine['details'])->toBe('Todo va al día: nada atrasado, nada sin editor y nada esperando.');
    });
});

describe('Los encargos por aprobar y la banca', function () use ($work, $occ) {
    it('lo propuesto, los testeos por decidir y los productos sin ficha', function () use ($work, $occ) {
        $o = $occ('meta_queue', ['meta_work' => $work([
            'proposed' => ['videos' => ['Comprimax' => 2, 'FlexiStep' => 1], 'landings' => ['Comprimax' => 1]],
            'testing' => [['product' => 'Cervimax', 'round' => 1, 'can_next' => true, 'no_angles' => false]],
            'no_profile' => ['Solo Brillo'],
        ])]);
        expect($o['details'])->toBe('3 encargos de video y 1 de landing por aprobar. 1 testeo por decidir. 1 producto activo sin ficha: no recibe encargos.')
            ->and(array_column($o['lines'], 'value', 'label'))->toBe(['Comprimax' => '1 encargo', 'FlexiStep' => '1 encargo', 'Cervimax' => 'Decidir', 'Solo Brillo' => 'Sin ficha'])
            ->and($o['lines'][3]['note'])->toBe('la ronda 1 del testeo terminó sin ganador: prueba los siguientes ángulos o revisa la oferta o la landing')
            ->and($o['lines'][4]['note'])->toBe('la IA no le propone encargos hasta que llenes su ficha')
            ->and($o['auto_done'])->toBeFalse()
            ->and(array_column($o['actions'], 'link'))->toBe(['/meta-ads/cola', '/meta-ads/landings?tab=encargos']);
        $none = $occ('meta_queue', ['meta_work' => $work([])]);
        expect($none['auto_done'])->toBeTrue()->and($none['details'])->toBe('No hay encargos por aprobar ni testeos por decidir.');
    });

    it('.79: los productos nuevos sin CPA de referencia, con dónde ponerlo', function () use ($work, $occ) {
        $o = $occ('meta_queue', ['meta_work' => $work(['no_reference' => [['product' => 'Termafit', 'no_cost' => false], ['product' => 'Nuevo', 'no_cost' => true]]])]);
        expect($o['details'])->toBe('2 productos sin CPA de referencia: el análisis no los puede juzgar.')
            ->and(array_column($o['lines'], 'value', 'label'))->toBe(['Termafit' => 'Sin CPA', 'Nuevo' => 'Sin CPA'])
            ->and($o['lines'][0]['note'])->toBe('escribe "CPA $X | BE $Y" en el nombre de su campaña o ponlo en Meta Ads › Productos y objetivos')
            ->and($o['lines'][1]['note'])->toContain('registra la compra de inventario')
            ->and($o['auto_done'])->toBeFalse()
            ->and(array_column($o['actions'], 'link'))->toBe(['/meta-ads/cola', '/admin/meta-ads?tab=targets']);
    });

    it('otro día dice lo general (lo de ahora solo sirve para hoy)', function () use ($work) {
        $o = ReminderRules::occurrence('meta_queue', '2026-11-03', '2026-11-02', ['meta_work' => $work(['no_profile' => ['Solo Brillo']])]);
        expect($o['details'])->toStartWith('Después de las 7:00 la IA propone')->and($o['lines'])->toBe([])->and($o['auto_done'])->toBeFalse();
    });

    it('la banca: primero lo del testeo, después lo que más espera', function () use ($work, $occ) {
        $o = $occ('meta_bank', ['meta_work' => $work(['bank' => [
            ['code' => 'B0100', 'product' => 'Comprimax', 'since' => '2026-10-28', 'round' => null],
            ['code' => 'B0102', 'product' => 'Cervimax', 'since' => '2026-11-02', 'round' => 1],
        ]])]);
        expect(array_column($o['lines'], 'label'))->toBe(['B0102 · Cervimax', 'B0100 · Comprimax'])
            ->and(array_column($o['lines'], 'value'))->toBe(['Testeo · ronda 1', 'En banca'])
            ->and(array_column($o['lines'], 'note'))->toBe(['desde el lunes 2/11 (hoy)', 'desde el miércoles 28/10 (5 días)'])
            ->and($o['details'])->toStartWith('2 batches listos para lanzar (1 del testeo). El que más espera, desde el miércoles 28/10.');
        expect($occ('meta_bank', ['meta_work' => $work([])])['auto_done'])->toBeTrue();
    });
});

describe('Cada semana', function () use ($occ) {
    it('el trabajo de cada editor la semana pasada, con el Panel en Equipo y esas fechas', function () use ($occ) {
        $rows = [
            ['name' => 'Eva Editora', 'landing' => false, 'delivered' => 3, 'on_time' => 2, 'reviewed' => 4, 'returned' => 1, 'won' => 1, 'lost' => 1, 'tie' => 0],
            ['name' => 'Lia', 'landing' => true, 'delivered' => 1, 'on_time' => 1, 'reviewed' => 1, 'returned' => 0, 'won' => 0, 'lost' => 0, 'tie' => 0],
        ];
        $o = $occ('editors_week', ['editor_weeks' => ['2026-10-26' => $rows]]);
        expect($o['details'])->toBe('Del lunes 26/10 al domingo 1/11: 4 encargos entregados (3 a tiempo), 1 devuelto de 5 revisados; de lo que terminó su test, ganó 1 y perdió 1.')
            ->and($o['lines'])->toBe([
                ['label' => 'Eva Editora', 'value' => '3 entregados', 'note' => '2 a tiempo · 1 devuelto de 4 revisados · ganó 1 y perdió 1'],
                ['label' => 'Lia (landings)', 'value' => '1 entregado', 'note' => '1 a tiempo · nada devuelto'],
            ])
            ->and($o['link'])->toBe('/panel-dueno?desde=2026-10-26&hasta=2026-11-01&equipo=editors')
            ->and($o['auto_done'])->toBeFalse();
        $empty = $occ('editors_week', ['editor_weeks' => ['2026-10-26' => []]]);
        expect($empty['auto_done'])->toBeTrue()->and($empty['details'])->toBe('Del lunes 26/10 al domingo 1/11: ningún editor entregó ni terminó nada esa semana.');
    });

    it('lo que aprendimos: los batches que terminaron su test en los 7 días anteriores', function () {
        $o = ReminderRules::occurrence('learnings_week', '2026-11-07', '2026-11-07', ['learnings' => ['2026-11-07' => [
            ['code' => 'B0105', 'product' => 'Comprimax', 'result' => 'Ganó', 'learning' => 'El testimonio de un hombre real engancha.'],
            ['code' => 'B0104', 'product' => 'Comprimax', 'result' => 'Perdió', 'learning' => null],
        ]]]);
        expect($o['details'])->toBe('Del sábado 31/10 al viernes 6/11: 2 batches terminaron su test (ganó 1 y perdió 1). Revisa lo que aprendimos de cada uno ("Corregir" si está mal): la IA lo usa en los encargos siguientes.')
            ->and(array_column($o['lines'], 'note'))->toBe(['El testimonio de un hombre real engancha.', 'todavía sin aprendizaje: lo escribe la IA a la mañana siguiente'])
            ->and($o['link'])->toBe('/meta-ads/lo-que-sabemos');
        $none = ReminderRules::occurrence('learnings_week', '2026-11-07', '2026-11-07', ['learnings' => ['2026-11-07' => []]]);
        expect($none['auto_done'])->toBeTrue();
    });
});
