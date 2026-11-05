<?php

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Products\ProductModes;
use App\Services\Meta\Queue\BatchTask;
use App\Services\Meta\Queue\DemandRules;
use App\Services\Meta\Queue\ForecastRules;
use App\Services\Meta\Queue\QueueRules;

// .88 (dueño, 2026-11-03): al editor líder le aparece todo lo que hay que hacer, para cuándo, sin tope de editores, y si
// alcanza el equipo (un flotante o un editor fijo).

/** Una ciudad como la deja MetaDecisions (action y numbers con los batches vivos y lo que puede lanzar). */
function ciudadDemanda(string $city, string $key, ?int $live, array $offers, bool $active = true): array
{
    return ['city' => $city, 'active' => $active, 'action' => ['key' => $key, 'label' => $key],
        'numbers' => ['spend3' => 30, 'purchases3' => 4, 'c' => 5, 'be' => 8, 'budget' => 25, 'streak' => 0, 'prune' => [], 'live' => $live, 'offers' => $offers]];
}

function productoDemanda(array $cities, string $mode = 'normal'): array
{
    return ['product' => ['id' => 1, 'name' => 'FlexiStep', 'mode' => $mode], 'campaigns' => $cities];
}

describe('.88: qué necesita cada producto para hoy', function () {
    $bank = fn (string $code) => ['code' => $code, 'how' => 'bank'];
    $copy = fn (string $code) => ['code' => $code, 'how' => 'copy', 'adset' => "{$code} FAS", 'campaign' => 'FLEXISTEP | ARAGUA', 'city' => 'Aragua', 'verdict' => 'Prometedor'];

    it('un batch nuevo para hoy (uno solo para todas las ciudades) si alguna sube de C, está en el BE o se queda con 1 vivo, sin nada que lanzar', function () use ($bank, $copy) {
        $t = DemandRules::today(productoDemanda([
            ciudadDemanda('Carabobo', 'hold_prune', 3, []),
            ciudadDemanda('Caracas', 'hold_prune', 3, []),
            ciudadDemanda('Aragua', 'intervene', 2, []),
            ciudadDemanda('Lara', 'scale', 1, []),
        ]));

        expect($t['today'])->toBe(1)
            ->and($t['cities'])->toBe(['Carabobo' => 'subió de su costo objetivo', 'Caracas' => 'subió de su costo objetivo',
                'Aragua' => 'está en su límite o encima', 'Lara' => 'le queda 1 solo batch vivo'])
            ->and($t['why'])->toBe('Carabobo y Caracas subieron de su costo objetivo; Aragua está en su límite o encima; a Lara le queda 1 solo batch vivo: '
                . 'hace falta un batch nuevo hoy para volver a bajar el costo por compra y seguir escalando.');
    });

    it('.100: en el BE pero con algo que lanzar (de la banca o de otra ciudad): se lanza eso y no se pide otro para hoy', function () use ($bank, $copy) {
        $t = DemandRules::today(productoDemanda([
            ciudadDemanda('Aragua', 'intervene', 2, [$copy('B0102')]),
            ciudadDemanda('Carabobo', 'intervene', 1, [$bank('B0103')]),
        ]));

        expect($t['today'])->toBe(0)->and($t['why'])->toBeNull()->and($t['consumed'])->toBe(['B0103']);
    });

    it('si su banca tiene algo, se lanza eso (y ya no queda de reserva); sin nada que pedir, nada para hoy', function () use ($bank) {
        $t = DemandRules::today(productoDemanda([
            ciudadDemanda('Carabobo', 'hold_prune', 3, [$bank('B0103')]),
            ciudadDemanda('Valencia', 'scale', 1, [$bank('B0104'), $bank('B0103')]),
            ciudadDemanda('Caracas', 'scale', 2, []),
            ciudadDemanda('Lara', 'hold_prune', 3, [], false), // apagada: no cuenta
        ]));

        expect($t['today'])->toBe(0)->and($t['why'])->toBeNull()
            ->and($t['consumed'])->toBe(['B0103', 'B0104']);
    });

    it('en liquidación no se pide nada', function () {
        expect(DemandRules::today(productoDemanda([ciudadDemanda('Carabobo', 'intervene', 0, [])], 'liquidation'))['today'])->toBe(0);
    });
});

describe('.100: un batch a la vez (para cuándo y quién lo cubre)', function () {
    it('sin nada pedido: uno solo, para hoy si una ciudad lo necesita hoy; si no, para la reserva (dentro de 2 días)', function () {
        expect(DemandRules::slots(1, 1, [], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [], 'new' => ['2026-11-03']])
            ->and(DemandRules::slots(0, 1, [], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [], 'new' => ['2026-11-05']])
            // la reserva en 0: solo cuando una ciudad lo necesita
            ->and(DemandRules::slots(0, 0, [], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [], 'new' => []]);
    });

    it('lo que viene en camino cuenta y se adelanta su fecha; lo de la banca que se lanza hoy ya no cuenta', function () {
        $open = [
            ['id' => 10, 'status' => 'bank', 'due_date' => null, 'code' => 'B0103'],
            ['id' => 11, 'status' => 'production', 'due_date' => '2026-11-06', 'code' => 'B0110'],
        ];
        expect(DemandRules::slots(1, 1, $open, ['B0103'], '2026-11-03', 2))
            ->toBe(['advance' => [11 => '2026-11-03'], 'covered' => [11 => '2026-11-03'], 'new' => []]);
    });

    it('con uno listo en la banca o haciéndose no se pide otro; lo que el dueño descartó hoy no se vuelve a pedir', function () {
        $bank = ['id' => 10, 'status' => 'bank', 'due_date' => null, 'code' => 'B0103'];
        $made = ['id' => 11, 'status' => 'production', 'due_date' => '2026-11-04', 'code' => 'B0110'];
        $gone = ['id' => 12, 'status' => 'discarded', 'due_date' => '2026-11-03', 'code' => 'B0111'];
        expect(DemandRules::slots(1, 1, [$bank, $made], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [10 => '2026-11-03'], 'new' => []])
            ->and(DemandRules::slots(0, 1, [$made], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [11 => '2026-11-05'], 'new' => []])
            ->and(DemandRules::slots(1, 1, [$gone], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [12 => '2026-11-03'], 'new' => []]);
    });
});

describe('.88: ¿alcanza el equipo? (las próximas 2 semanas)', function () {
    $in = fn (array $over) => $over + ['due' => [], 'rate' => 0.0, 'new_rate' => 0.0, 'capacity' => 3.0, 'per_editor' => 1.5, 'reserve_days' => 2, 'start' => '2026-11-03'];

    it('alcanza: dice cuánto le sobra por semana', function () use ($in) {
        $r = ForecastRules::run($in(['due' => [0 => 2], 'rate' => 1.0, 'new_rate' => 0.4]));
        expect($r['verdict'])->toBe('ok')->and($r['text'])->toBe('Tu equipo alcanza: le sobran ~11 batches por semana.')
            ->and($r['can'])->toBe(42);
    });

    it('un pico de unos días: un flotante para esos batches', function () use ($in) {
        $r = ForecastRules::run($in(['due' => [0 => 6], 'rate' => 1.0, 'capacity' => 2.0, 'per_editor' => 1.0]));
        expect($r['verdict'])->toBe('floater')->and($r['extra'])->toBe(4)->and($r['short_days'])->toBe(['2026-11-03'])
            ->and($r['text'])->toBe('Un flotante para ~4 batches (el martes 3).');
    });

    it('2 semanas seguidas sin alcanzar: un editor fijo (cuántos, con lo que hace cada uno)', function () use ($in) {
        // 8 productos nuevos al mes × 3 = 0,8 por día; reponer 2,5 por día; el equipo hace 2 (1 por editor)
        $r = ForecastRules::run($in(['rate' => 2.5, 'new_rate' => 0.8, 'capacity' => 2.0, 'per_editor' => 1.0]));
        expect($r['verdict'])->toBe('fixed')->and($r['text'])->toStartWith('Contrata un editor fijo: faltan ~')
            ->and($r['text'])->toEndWith('batches por semana, 2 semanas seguidas.');
    });

    it('los días en palabras', function () {
        expect(ForecastRules::days(['2026-11-05', '2026-11-06']))->toBe('el jueves 5 y el viernes 6');
    });
});

describe('.89: el flujo sin botones', function () {
    $late = fn (bool $urgent, ?string $editor = 'Ana Pérez', int $days = 1) => ['id' => 40, 'product_id' => 1, 'product' => 'FlexiStep® Tobillera',
        'code' => 'B0108', 'due' => '2026-11-02', 'days' => $days, 'editor' => $editor, 'urgent' => $urgent, 'cities' => $urgent ? ['Valencia'] : []];

    it('un encargo atrasado que se necesita hoy: presiona al editor líder, con qué decirle', function () use ($late) {
        $i = UrgentList::build([], [$late(true)], 'Luis')[0];
        expect($i['kind'])->toBe('late_urgent')->and($i['group'])->toBe('arreglar')
            ->and($i['title'])->toBe('Presiona al editor líder: B0108 se necesita con urgencia')
            ->and($i['where'])->toBe('FlexiStep · B0108')
            ->and($i['steps'][0])->toBe('Escríbele a Luis: «B0108» de FlexiStep era para el lunes 2 y Valencia lo necesita hoy para volver a bajar el costo por compra.')
            ->and($i['steps'][1])->toBe('Lo tiene Ana Pérez.')
            ->and($i['why'])->toBe('1 día atrasado · lo tiene Ana Pérez')
            ->and(UrgentList::tally([$i], [])['tasks'])->toBe(1);
    });

    it('si es la reserva, solo preguntarle; sin editor, que lo asigne hoy', function () use ($late) {
        $i = UrgentList::build([], [$late(false, null, 3)], null)[0];
        expect($i['kind'])->toBe('late_batch')
            ->and($i['title'])->toBe('Pregúntale al editor líder por B0108: está atrasado')
            ->and($i['steps'][0])->toBe('Escríbele al editor líder: «B0108» de FlexiStep era para el lunes 2; es la reserva para seguir escalando.')
            ->and($i['steps'][1])->toBe('Todavía no tiene editor: que lo asigne hoy.')
            ->and($i['why'])->toBe('3 días atrasado · sin editor');
    });

    it('lo que llega a la banca y una ciudad necesita: una misión para lanzarlo, con los pasos', function () {
        $i = UrgentList::launchReady(1, 'Demo Sérum facial', 'B0108', [['city' => 'Valencia', 'campaign' => 'SERUM | VLC',
            'offer' => ['code' => 'B0108', 'how' => 'bank'], 'why' => 'subió de su costo objetivo']], '15:20');
        expect($i['title'])->toBe('Lanza B0108: ya llegó a la banca')->and($i['group'])->toBe('arreglar')
            ->and($i['where'])->toBe('Demo Sérum facial · Valencia')
            ->and($i['steps'])->toBe(['Abre la campaña «SERUM | VLC».', 'Lanza el batch «B0108» de la banca (sus videos, aquí abajo).'])
            ->and($i['why'])->toBe('Llegó a la banca a las 15:20 · Valencia subió de su costo objetivo')
            ->and($i['batches'])->toBe(['B0108']); // .102: sus videos se bajan en la misión
    });

    it('por qué una ciudad necesita un batch, en palabras', function () {
        expect(DemandRules::reason('intervene', 3))->toBe('está en su límite o encima')
            ->and(DemandRules::reason('hold_prune', 3))->toBe('subió de su costo objetivo')
            ->and(DemandRules::reason('scale', 1))->toBe('le queda 1 solo batch vivo')
            ->and(DemandRules::reason('scale', 2))->toBeNull();
    });
});

describe('.99: qué hay que hacer en cada encargo', function () {
    $b = fn (array $over) => $over + ['kind' => 'new_concept', 'angle_code' => null, 'angle_name' => null, 'iterates' => null, 'imported' => false, 'testing' => false,
        'pieces' => [['test' => 'new_concept', 'style' => null, 'format' => null], ['test' => 'new_concept', 'style' => null, 'format' => null], ['test' => 'new_concept', 'style' => null, 'format' => null]]];
    $pieces = fn (array ...$p) => array_map(fn ($x) => $x + ['style' => null, 'format' => null], $p);

    it('piezas nuevas de un ángulo, de un ángulo nuevo o de arriba del embudo', function () use ($b) {
        expect(BatchTask::of($b(['angle_code' => 'NV', 'angle_name' => 'Nervio vago']))['title'])->toBe('3 piezas nuevas del ángulo NV · Nervio vago')
            ->and(BatchTask::of($b([]))['title'])->toBe('3 piezas nuevas (concepto nuevo)')
            ->and(BatchTask::of($b(['kind' => 'new_angle', 'angle_code' => 'DOL', 'angle_name' => 'Dolor de espalda']))['title'])->toBe('3 piezas nuevas de un ángulo nuevo: DOL · Dolor de espalda')
            ->and(BatchTask::of($b(['kind' => 'new_angle', 'angle_code' => 'POSP', 'testing' => true]))['title'])->toBe('3 piezas para probar el ángulo POSP')
            ->and(BatchTask::of($b(['kind' => 'top_funnel', 'angle_code' => 'POSP']))['title'])->toBe('3 piezas nuevas para gente que todavía no conoce el problema (ángulo POSP)')
            // una iteración pedida a mano sin decir de qué creativo
            ->and(BatchTask::of($b(['kind' => 'iteration', 'angle_code' => 'FASP']))['title'])->toBe('3 iteraciones del ángulo FASP')
            // un batch ya hecho que se cargó: nada que hacer
            ->and(BatchTask::of($b(['imported' => true])))->toBeNull();
    });

    it('las iteraciones dicen de qué creativo y qué se cambia: el hook, el formato o un tramo', function () use ($b, $pieces) {
        $it = fn (array $p) => BatchTask::of($b(['kind' => 'iteration', 'iterates' => 'C0146 POSP', 'pieces' => $p]));
        expect($it($pieces(['test' => 'winner_hook'], ['test' => 'hook'], ['test' => 'fatigue'])))
            ->toBe(['title' => '3 iteraciones del hook de C0146 POSP', 'detail' => 'Solo cambia el hook (los primeros 3 segundos): el resto del video queda igual.'])
            ->and($it($pieces(['test' => 'format', 'style' => 'ugc'], ['test' => 'format', 'style' => 'testimonio'])))
            ->toBe(['title' => '2 iteraciones de formato de C0146 POSP', 'detail' => 'El mismo mensaje en UGC y testimonio.'])
            ->and($it($pieces(['test' => 'winner_hook'], ['test' => 'format', 'style' => 'ugc'], ['test' => 'format', 'style' => 'imagen', 'format' => 'image'])))
            ->toBe(['title' => '3 iteraciones de C0146 POSP', 'detail' => '1 de hook (hook nuevo, el resto igual) y 2 de formato (UGC e imagen).'])
            ->and($it($pieces(['test' => 'middle'], ['test' => 'middle']))['title'])->toBe('2 iteraciones de C0146 POSP: arreglar el medio del video')
            // la imagen ganadora que pasa a video
            ->and($it($pieces(['test' => 'format', 'format' => 'video']))['title'])->toBe('1 iteración de formato de C0146 POSP')
            // .99: un batch ya hecho que son variaciones de un creativo dice qué es (sin estilo: "otro formato")
            ->and(BatchTask::of($b(['kind' => 'iteration', 'iterates' => 'CRE002-FLEX-BATCH001', 'imported' => true,
                'pieces' => $pieces(['test' => 'format'], ['test' => 'format'])])))
            ->toBe(['title' => '2 iteraciones de formato de CRE002-FLEX-BATCH001', 'detail' => 'El mismo mensaje en otro formato.']);
    });

    it('el texto del encargo empieza por lo que hay que hacer', function () {
        $brief = QueueRules::brief(['code' => 'B0101', 'product' => 'FlexiStep', 'kind' => 'iteration', 'reference' => 'C0146', 'angle_code' => null, 'angle_name' => null,
            'format' => 'video', 'style' => null, 'pieces' => [], 'deliverables' => null, 'due_date' => null, 'editor' => null,
            'task' => ['title' => '3 iteraciones del hook de C0146', 'detail' => 'Solo cambia el hook.']]);
        expect($brief)->toContain("ENCARGO B0101 · FlexiStep\nQué hay que hacer: 3 iteraciones del hook de C0146. Solo cambia el hook.\nTipo: Iteración");
    });
});

describe('.99: la banca frena los pedidos', function () {
    it('con un batch esperando en la banca (que hoy no se lanza) no se pide nada nuevo de ese producto', function () {
        $open = [['id' => 10, 'status' => 'bank', 'due_date' => null, 'code' => 'B0103']];
        expect(DemandRules::slots(1, 1, $open, [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [10 => '2026-11-03'], 'new' => []])
            // .100: cuando se lanza, se pide el siguiente (uno solo, para la reserva)
            ->and(DemandRules::slots(0, 1, $open, ['B0103'], '2026-11-03', 2)['new'])->toBe(['2026-11-05']);
    });

    it('la banca del líder, por producto: primero los activos con menos en la banca; los parados, al final y con lo suyo en espera', function () {
        $rows = ProductModes::bankRows([5 => 'Comprimax', 6 => 'FlexiStep', 7 => 'Termafit'],
            [5 => ['bank' => 1, 'production' => 1], 6 => ['bank' => 2, 'approved' => 1, 'testing' => 1]], [5 => 'paused']);
        expect(array_column($rows, 'name'))->toBe(['Termafit', 'FlexiStep', 'Comprimax'])
            ->and($rows[1])->toMatchArray(['bank' => 2, 'coming' => 1, 'meta' => 1, 'held' => 0, 'stopped' => false, 'mode_label' => 'Activo'])
            ->and($rows[2])->toMatchArray(['bank' => 1, 'coming' => 0, 'held' => 1, 'stopped' => true, 'mode_label' => 'En pausa']);
    });
});
