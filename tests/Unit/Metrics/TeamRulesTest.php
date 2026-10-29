<?php

use App\Services\Metrics\Rules\TeamRules;
use App\Services\Metrics\Team\OperatorKpis;
use App\Services\Metrics\Team\SellerKpis;
use App\Services\Metrics\Team\TeamCatalog;

// Panel del dueño › Equipo: lo común a todos los roles (ranking, "pocos datos", marcas, comparación y catálogo).

$person = fn (string $name, int $attended, ?float $eff) => ['name' => $name, 'group' => null,
    'values' => ['attended' => $attended, 'effectiveness' => $eff, 'secret' => 1], 'details' => []];

describe('Equipo › ranking y tabla', function () use ($person) {
    it('ordena por el KPI del rol: lo mejor primero, los de pocos datos y los sin valor al final', function () use ($person) {
        $rows = TeamRules::rows(new SellerKpis(), [
            1 => $person('Ana', 40, 60.0),
            2 => $person('Bea', 3, 100.0),   // pocos datos: 2 de 2 no es lo mejor
            3 => $person('Caro', 25, 80.0),
            4 => $person('Dani', 12, null),
        ], [1 => $person('Ana', 30, 55.0)]);
        expect(array_column($rows, 'name'))->toBe(['Caro', 'Ana', 'Dani', 'Bea']);
        expect($rows[3]['low_data'])->toBeTrue();
        expect($rows[1]['previous']['effectiveness'])->toBe(55.0);
        expect($rows[0]['previous'])->toBeNull();
        // Solo los KPIs del rol
        expect(array_key_exists('secret', $rows[0]['values']))->toBeFalse();
    });

    it('si bajar es mejor, ordena de menor a mayor', function () {
        $rows = TeamRules::sort([
            ['name' => 'A', 'low_data' => false, 'values' => ['t' => 3.0]],
            ['name' => 'B', 'low_data' => false, 'values' => ['t' => 1.0]],
        ], 't', 'lower');
        expect(array_column($rows, 'name'))->toBe(['B', 'A']);
    });

    it('marca a quien tiene el doble del promedio o más en un KPI con "flag"', function () {
        $op = fn (string $name, int $manual) => ['name' => $name, 'group' => null, 'values' => ['dispatched' => 30, 'manual' => $manual], 'details' => []];
        $rows = TeamRules::rows(new OperatorKpis(), [1 => $op('Olga', 1), 2 => $op('Rosa', 9), 3 => $op('Ana', 2)], []);
        $flags = array_column($rows, 'flags', 'name');
        expect($flags['Rosa'])->toBe(['manual']);
        expect($flags['Olga'])->toBe([]);
    });

    it('lo de "ahora mismo" no se compara con el período anterior', function () {
        $role = new \App\Services\Metrics\Team\AgencyKpis();
        $p = fn (float $cash) => ['name' => 'X', 'group' => null, 'values' => ['received' => 20, 'cash' => $cash], 'details' => []];
        $built = TeamRules::build($role, ['people' => [1 => $p(100)], 'totals' => ['values' => ['cash' => 100], 'details' => []]],
            ['people' => [1 => $p(50)], 'totals' => ['values' => ['cash' => 50], 'details' => []]], false);
        $cash = array_values(array_filter($built['cards'], fn ($c) => $c['key'] === 'cash'))[0];
        expect($cash['compare'])->toBeFalse();
        expect($cash['change'])->toBeNull();
        expect($built['rows'][0]['previous']['cash'])->toBeNull();
    });
});

describe('Equipo › catálogo de roles', function () {
    it('cada rol tiene su clave, sus KPIs con explicación y un KPI de ranking que existe', function () {
        foreach (TeamCatalog::roles() as $key => $role) {
            expect($role->key())->toBe($key);
            expect(array_key_exists($role->rankBy(), $role->metrics()))->toBeTrue();
            expect(array_key_exists($role->base()['key'], $role->metrics()))->toBeTrue();
            foreach ($role->metrics() as $m) {
                expect($m['help'])->not->toBe('');
                expect(in_array($m['format'], ['count', 'money', 'percent', 'hours', 'hours_total'], true))->toBeTrue();
                expect(in_array($m['better'], ['higher', 'lower', 'none'], true))->toBeTrue();
            }
        }
    });

    it('avisa de un rol nuevo sin KPIs, y no de los que el dueño decidió no medir', function () {
        $c = TeamCatalog::coverage(['Admin', 'Vendedor', 'Agencia', 'Repartidor', 'Operador logístico', 'Gerente', 'Master', 'Shopify', 'Editor']);
        expect($c['missing'])->toBe(['Editor']);
        expect(array_column($c['unmeasured'], 'role'))->toBe(['Gerente', 'Master']);
    });
});
