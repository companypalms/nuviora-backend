<?php

use App\Services\Meta\Queue\DemandRules;
use App\Services\Meta\Queue\ForecastRules;

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

    it('un batch nuevo para hoy (uno solo para todas las ciudades) si alguna sube de C sin banca, está en el BE o se queda con 1 vivo', function () use ($bank, $copy) {
        $t = DemandRules::today(productoDemanda([
            ciudadDemanda('Carabobo', 'hold_prune', 3, []),
            ciudadDemanda('Caracas', 'hold_prune', 3, []),
            ciudadDemanda('Aragua', 'intervene', 2, [$copy('B0102')]),
            ciudadDemanda('Lara', 'scale', 1, []),
        ]));

        expect($t['today'])->toBe(1)
            ->and($t['cities'])->toBe(['Carabobo' => 'subió de su costo objetivo', 'Caracas' => 'subió de su costo objetivo',
                'Aragua' => 'está en su límite o encima', 'Lara' => 'le queda 1 solo batch vivo'])
            ->and($t['why'])->toBe('Carabobo y Caracas subieron de su costo objetivo; Aragua está en su límite o encima; a Lara le queda 1 solo batch vivo: '
                . 'hace falta un batch nuevo hoy para volver a bajar el costo por compra y seguir escalando.');
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

describe('.88: para cuándo y quién lo cubre', function () {
    it('sin nada pedido: uno para hoy y la reserva para dentro de 2 días', function () {
        expect(DemandRules::slots(1, 1, [], [], '2026-11-03', 2))->toBe(['advance' => [], 'covered' => [], 'new' => ['2026-11-03', '2026-11-05']]);
    });

    it('lo que viene en camino cuenta y se adelanta su fecha; lo de la banca que se lanza hoy ya no es reserva', function () {
        $open = [
            ['id' => 10, 'status' => 'bank', 'due_date' => null, 'code' => 'B0103'],
            ['id' => 11, 'status' => 'production', 'due_date' => '2026-11-06', 'code' => 'B0110'],
        ];
        expect(DemandRules::slots(1, 1, $open, ['B0103'], '2026-11-03', 2))
            ->toBe(['advance' => [11 => '2026-11-03'], 'covered' => [11 => '2026-11-03'], 'new' => ['2026-11-05']]);
    });

    it('lo listo en la banca cubre lo primero que hace falta; lo que el dueño descartó hoy no se vuelve a pedir', function () {
        $open = [['id' => 10, 'status' => 'bank', 'due_date' => null, 'code' => 'B0103'], ['id' => 12, 'status' => 'discarded', 'due_date' => '2026-11-03', 'code' => 'B0111']];
        expect(DemandRules::slots(1, 1, $open, [], '2026-11-03', 2))
            ->toBe(['advance' => [], 'covered' => [10 => '2026-11-03', 12 => '2026-11-05'], 'new' => []]);
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
