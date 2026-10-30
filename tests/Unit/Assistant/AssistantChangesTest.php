<?php

use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Actions\Tools\FinanceArgs;
use App\Services\Assistant\Ai\ExampleModel;
use Carbon\CarbonImmutable;

// El asistente del dueño (Etapa 4, paso 5): las partes de las tarjetas que no tocan la base ni la IA.

$jueves = fn () => CarbonImmutable::parse('2026-10-29 18:00:00');

describe('La tarjeta', function () {
    it('las líneas sin valor no se muestran; las que cambian algo llevan lo de antes', function () {
        expect(Proposal::lines([Proposal::line('Qué', 'Pagar'), Proposal::line('Hora', null), Proposal::line('Tasa', 'Bs 41', 'Bs 40')]))
            ->toBe([['label' => 'Qué', 'value' => 'Pagar'], ['label' => 'Tasa', 'value' => 'Bs 41', 'before' => 'Bs 40']]);
    });

    it('la huella cambia si cambia lo que se va a guardar o lo que se ve', function () {
        $a = new Proposal('T', 'S', [['label' => 'X', 'value' => '1']], ['x' => 1.0]);
        $same = new Proposal('Otro título', 'Otro resumen', [['label' => 'X', 'value' => '1']], ['x' => 1.0]);
        $other = new Proposal('T', 'S', [['label' => 'X', 'value' => '1', 'before' => '0']], ['x' => 1.0]);
        expect($a->fingerprint())->toBe($same->fingerprint());
        expect($a->fingerprint())->not->toBe($other->fingerprint());
        // 1.0 y 1 son lo mismo: al guardarse en la base, 1.0 vuelve como 1 (si no, al confirmar parecería que algo cambió)
        expect($a->fingerprint())->toBe((new Proposal('T', 'S', [['label' => 'X', 'value' => '1']], ['x' => 1]))->fingerprint());
    });

    it('los montos en palabras, con la tasa en bolívares', function () {
        expect(FinanceArgs::money(15.5, 'USD'))->toBe('$15,50');
        expect(FinanceArgs::money(1200, 'VES', 40))->toBe('Bs 1.200,00 (a Bs 40,00 por dólar: $30,00)');
        expect(FinanceArgs::money(3, 'USDT'))->toBe('3,00 USDT');
    });
});

describe('El modo ejemplo propone cambios con frases simples', function () use ($jueves) {
    it('tasas', function () use ($jueves) {
        expect(ExampleModel::change('Cambia la tasa Binance a 575,5', $jueves()))
            ->toBe(['name' => 'proponer_tasas', 'arguments' => ['bcv_usd' => null, 'bcv_eur' => null, 'binance_usd' => 575.5]]);
        expect(ExampleModel::change('La BCV del euro está en 44', $jueves())['arguments']['bcv_eur'])->toBe(44.0);
        expect(ExampleModel::change('¿Cuál es la tasa de hoy?', $jueves()))->toBeNull();
    });

    it('calendario: anotar y tildar', function () use ($jueves) {
        $c = ExampleModel::change('Anótame pagar el alquiler mañana', $jueves());
        expect($c['name'])->toBe('proponer_nota_calendario');
        expect($c['arguments'])->toMatchArray(['titulo' => 'Pagar el alquiler', 'fecha' => '2026-10-30', 'tipo' => 'pago', 'repetir' => 'no']);
        expect(ExampleModel::change('Marca como hecho revisar la tasa', $jueves())['arguments'])->toMatchArray(['texto' => 'revisar la tasa', 'fecha' => '2026-10-29', 'estado' => 'hecho']);
    });

    it('gastos, gastos fijos, pagos y bancos', function () use ($jueves) {
        expect(ExampleModel::change('Anota un gasto de 15 dólares de gasolina desde Banco DEMO', $jueves(), true)['arguments'])
            ->toMatchArray(['concepto' => 'Gasolina', 'monto' => 15.0, 'moneda' => 'USD', 'cuenta' => 'banco demo', 'comprobante' => 1, 'pago' => 'pagado']);
        expect(ExampleModel::change('Gasto fijo Hostinger de 12 dólares cada mes el día 3', $jueves())['arguments'])
            ->toMatchArray(['nombre' => 'Hostinger', 'frecuencia' => 'mensual', 'dia_de_pago' => 3, 'monto' => 12.0]);
        expect(ExampleModel::change('Pagué el alquiler desde Banco DEMO', $jueves())['arguments'])
            ->toMatchArray(['gasto_fijo' => 'alquiler', 'cuenta' => 'banco demo']);
        expect(ExampleModel::change('Añade el banco Banesco en bolívares', $jueves())['arguments'])
            ->toMatchArray(['nombre' => 'Banesco', 'moneda' => 'VES', 'cobro' => null]);
        expect(ExampleModel::change('¿Cuánto gastamos este mes?', $jueves()))->toBeNull();
        expect(ExampleModel::change('Ranking de vendedoras', $jueves()))->toBeNull();
    });
});
