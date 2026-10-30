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

    it('las notas se ven en la tarjeta, pero no cuentan para la huella', function () {
        $a = new Proposal('T', 'S', [['label' => 'X', 'value' => '1']], ['x' => 1], [], ['Tiene 3 órdenes']);
        $b = new Proposal('T', 'S', [['label' => 'X', 'value' => '1']], ['x' => 1], [], ['Tiene 4 órdenes']);
        expect($a->fingerprint())->toBe($b->fingerprint());
        expect($a->card()['notes'])->toBe(['Tiene 3 órdenes']);
        expect((new Proposal('T', 'S', [], []))->card())->not->toHaveKey('notes');
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

    it('compras, ciudades, agencias y cuentas (paso 7)', function () use ($jueves) {
        expect(ExampleModel::change('Compré 10 Demo Sérum facial a Proveedor DEMO por 50 dólares desde Banco DEMO', $jueves()))
            ->toMatchArray(['name' => 'proponer_compra'])
            ->and(ExampleModel::change('Compré 10 Demo Sérum facial a Proveedor DEMO por 50 dólares desde Banco DEMO', $jueves())['arguments'])
            ->toMatchArray(['producto' => 'demo serum facial', 'cantidad' => 10, 'proveedor' => 'proveedor demo', 'total' => 50.0, 'pago' => 'contado', 'cuenta' => 'banco demo']);
        expect(ExampleModel::change('Compré 5 gorras a Juan por 20 dólares a crédito', $jueves())['arguments'])->toMatchArray(['pago' => 'credito', 'vence' => '2026-11-28']);
        expect(ExampleModel::change('Pedí 20 gorras a Juan por 80 dólares, llegan mañana', $jueves()))
            ->toMatchArray(['name' => 'proponer_compra_por_recibir']);
        expect(ExampleModel::change('Recibí la compra #12 desde Banco DEMO', $jueves())['arguments'])->toMatchArray(['compra' => '12', 'cuenta' => 'banco demo']);
        expect(ExampleModel::change('Apaga la ciudad Caracas', $jueves())['arguments'])->toBe(['ciudad' => 'caracas', 'trabajando' => false]);
        expect(ExampleModel::change('Prende la agencia Rápido de 8 a 17:30', $jueves())['arguments']['horario'])->toBe(['desde' => '08:00', 'hasta' => '17:30']);
        expect(ExampleModel::change('Crea a María Pérez como vendedora, maria@prueba.test', $jueves())['arguments'])
            ->toMatchArray(['nombre' => 'Maria', 'apellido' => 'Perez', 'correo' => 'maria@prueba.test', 'rol' => 'vendedora']);
        expect(ExampleModel::change('Desactiva a Carla porque renunció', $jueves())['arguments'])->toBe(['persona' => 'carla', 'motivo' => 'Renuncio']);
        expect(ExampleModel::change('Reactiva a Carla', $jueves())['name'])->toBe('proponer_reactivar_usuario');
        expect(ExampleModel::change('¿Cuánto compramos este mes?', $jueves()))->toBeNull();
        expect(ExampleModel::change('Ranking de vendedoras', $jueves()))->toBeNull();
    });
});
