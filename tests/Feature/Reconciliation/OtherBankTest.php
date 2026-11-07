<?php

use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Reconciliation\DayBuilder;
use App\Services\Reconciliation\StatementMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// `.115` (el dueño, 2026-11-07): "cuando un comprobante no lo valide un banco, que mire en el otro a ver si está ahí".
// En producción hay dos bancos en bolívares: Banesco (pago móvil y transferencia) y Bancamiga (pago móvil). En una base
// en memoria, con las tablas de la conciliación.

beforeEach(function () {
    Schema::create('roles', function ($t) {
        $t->id();
        $t->string('description');
        $t->timestamps();
    });
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('phone')->default('');
        $t->string('address')->default('');
        $t->string('email')->unique();
        $t->string('password');
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamps();
    });
    Schema::create('orders', function ($t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
    Schema::create('payment_receipts', function ($t) {
        $t->id();
        $t->unsignedBigInteger('order_id')->nullable();
        $t->timestamps();
    });
    Schema::create('statement_sources', function ($t) {
        $t->id();
        $t->string('name', 60)->unique();
        $t->string('currency', 8);
        $t->json('format')->nullable();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('company_accounts', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('icon')->nullable();
        $t->string('method', 24)->nullable();
        $t->string('currency', 8)->nullable();
        $t->unsignedBigInteger('statement_source_id')->nullable();
        $t->unsignedBigInteger('financial_account_id')->nullable();
        $t->json('details')->nullable();
        $t->boolean('is_active')->default(true);
        $t->boolean('takes_orders')->default(true);
        $t->timestamps();
    });
    Schema::create('receipt_checks', function ($t) {
        $t->id();
        $t->unsignedBigInteger('company_account_id')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_10_07_120000_create_reconciliation_tables.php'))->up();

    $this->banesco = DB::table('statement_sources')->insertGetId(['name' => 'Banesco', 'currency' => 'VES']);
    $this->bancamiga = DB::table('statement_sources')->insertGetId(['name' => 'Bancamiga', 'currency' => 'VES']);
    $this->binance = DB::table('statement_sources')->insertGetId(['name' => 'Binance', 'currency' => 'USDT']);
    $account = fn (string $name, string $method, ?int $source) => DB::table('company_accounts')->insertGetId([
        'name' => $name, 'method' => $method, 'currency' => $method === 'binance' ? 'USDT' : 'VES', 'statement_source_id' => $source, 'is_active' => true,
    ]);
    $this->pmBanesco = $account('Pagomovil Banesco', 'pago_movil', $this->banesco);
    $this->trBanesco = $account('Transferencia', 'transferencia', $this->banesco);
    $this->pmBancamiga = $account('Pago móvil Bancamiga', 'pago_movil', $this->bancamiga);
    $this->binanceAccount = $account('BINANCE', 'binance', $this->binance);
    $this->day = ReconciliationDay::create(['date' => '2026-11-06', 'status' => ReconciliationDay::PENDING]);
});

/** Un pago del día, con la cuenta que dice su comprobante. */
function otroBancoPago(object $t, string $order, ?int $account, string $reference, float $amount, string $method = 'pago_movil'): ReconciliationItem
{
    $check = DB::table('receipt_checks')->insertGetId(['company_account_id' => $account]);
    $source = $account ? DB::table('company_accounts')->where('id', $account)->value('statement_source_id') : null;

    return $t->day->items()->create([
        'receipt_check_id' => $check, 'order_name' => $order, 'method' => $method, 'currency' => $method === 'binance' ? 'USDT' : 'VES',
        'amount' => $amount, 'reference' => $reference, 'reference_norm' => BankStatementRow::normalizeReference($reference),
        'paid_on' => '2026-11-06', 'company_account_id' => $account, 'statement_source_id' => $source,
    ]);
}

/** Sube el extracto de un banco con sus ingresos: [referencia, monto]. */
function otroBancoExtracto(object $t, int $source, array $rows): BankStatement
{
    $statement = BankStatement::forceCreate([
        'reconciliation_day_id' => $t->day->id, 'statement_source_id' => $source, 'path' => 'x', 'original_name' => "extracto-{$source}.xlsx",
        'sha256' => str_repeat('0', 64), 'rows_read' => count($rows), 'rows_credit' => count($rows),
    ]);
    foreach ($rows as $i => [$reference, $amount]) {
        BankStatementRow::create(['bank_statement_id' => $statement->id, 'line' => $i + 2, 'date' => '2026-11-06', 'reference' => $reference,
            'reference_norm' => BankStatementRow::normalizeReference($reference), 'amount' => $amount]);
    }

    return $statement;
}

describe('.115: un pago en bolívares que no está en su banco se busca en el otro', function () {
    it('lo concilia en el otro banco y el pago pasa a esa cuenta', function () {
        $normal = otroBancoPago($this, '#1001', $this->pmBanesco, '0001234111111', 100);
        $crossed = otroBancoPago($this, '#1002', $this->pmBanesco, '0001234222222', 200);
        $own = otroBancoPago($this, '#1003', $this->pmBancamiga, '333333', 300);
        $transfer = otroBancoPago($this, '#1004', $this->trBanesco, '7777888899', 450, 'transferencia');
        otroBancoExtracto($this, $this->banesco, [['1234111111', 100]]);
        otroBancoExtracto($this, $this->bancamiga, [['1234222222', 200], ['333333', 300], ['7777888899', 450]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect($normal->fresh()->status)->toBe(ReconciliationItem::MATCHED)
            ->and($normal->fresh()->match_note)->toBe('Misma referencia y mismo monto en la fila 2 del extracto.')
            ->and($normal->fresh()->company_account_id)->toBe($this->pmBanesco);
        expect($crossed->fresh()->status)->toBe(ReconciliationItem::MATCHED)
            ->and($crossed->fresh()->match_note)->toBe('Misma referencia y mismo monto en la fila 2 del extracto de Bancamiga, no en el de Banesco: el cliente pagó a la otra cuenta.')
            ->and($crossed->fresh()->company_account_id)->toBe($this->pmBancamiga)
            ->and($crossed->fresh()->statement_source_id)->toBe($this->bancamiga);
        expect($own->fresh()->status)->toBe(ReconciliationItem::MATCHED);
        // Una transferencia a Banesco que llegó a Bancamiga (allí no hay cuenta de transferencia): queda en su pago móvil
        expect($transfer->fresh()->status)->toBe(ReconciliationItem::MATCHED)
            ->and($transfer->fresh()->company_account_id)->toBe($this->pmBancamiga);
    });

    it('al buscar otra vez sigue conciliado y la nota sigue diciendo que llegó a la otra cuenta', function () {
        $crossed = otroBancoPago($this, '#1002', $this->pmBanesco, '1234222222', 200);
        otroBancoExtracto($this, $this->banesco, []);
        otroBancoExtracto($this, $this->bancamiga, [['1234222222', 200]]);

        app(StatementMatcher::class)->matchDay($this->day);
        app(StatementMatcher::class)->matchDay($this->day->fresh());

        expect($crossed->fresh()->status)->toBe(ReconciliationItem::MATCHED)
            ->and($crossed->fresh()->match_note)->toContain('no en el de Banesco: el cliente pagó a la otra cuenta')
            ->and($crossed->fresh()->company_account_id)->toBe($this->pmBancamiga);
    });

    it('primero cada banco se queda con sus pagos: un movimiento ya usado no sirve para el pago del otro banco', function () {
        // El pago de Banesco va primero en la lista, pero el movimiento de Bancamiga es de su propio pago
        $banesco = otroBancoPago($this, '#2001', $this->pmBanesco, '555555', 500);
        $bancamiga = otroBancoPago($this, '#2002', $this->pmBancamiga, '555555', 500);
        otroBancoExtracto($this, $this->banesco, []);
        otroBancoExtracto($this, $this->bancamiga, [['555555', 500]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect($bancamiga->fresh()->status)->toBe(ReconciliationItem::MATCHED);
        expect($banesco->fresh()->status)->toBe(ReconciliationItem::REVIEW)
            ->and($banesco->fresh()->match_note)->toBe('Misma referencia en el extracto de Bancamiga; ese movimiento ya es de la orden #2002.')
            ->and($banesco->fresh()->company_account_id)->toBe($this->pmBanesco);
    });

    it('si no está en ninguno lo dice, y en el otro banco el mismo monto solo no es una posible coincidencia', function () {
        $missing = otroBancoPago($this, '#3001', $this->pmBanesco, '444444', 400);
        otroBancoExtracto($this, $this->banesco, []);
        otroBancoExtracto($this, $this->bancamiga, [['999999', 400]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect($missing->fresh()->status)->toBe(ReconciliationItem::NOT_FOUND)
            ->and($missing->fresh()->match_note)->toBe('No está en el extracto de Banesco ni en el de Bancamiga.');
    });

    it('si el comprobante no dice la cuenta, se concilia en el banco donde está', function () {
        $unknown = otroBancoPago($this, '#4001', null, '121212', 120);
        otroBancoExtracto($this, $this->banesco, []);
        otroBancoExtracto($this, $this->bancamiga, [['121212', 120]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect($unknown->fresh()->status)->toBe(ReconciliationItem::MATCHED)
            ->and($unknown->fresh()->match_note)->toBe('Misma referencia y mismo monto en la fila 2 del extracto de Bancamiga. El comprobante no deja ver a qué cuenta fue: llegó a Bancamiga.')
            ->and($unknown->fresh()->company_account_id)->toBe($this->pmBancamiga);
    });

    it('con solo el extracto de Banesco: concilia lo de Bancamiga que llegó a Banesco, espera lo demás y pide el de Bancamiga', function () {
        $toBanesco = otroBancoPago($this, '#5001', $this->pmBancamiga, '666666', 600);
        $waiting = otroBancoPago($this, '#5002', $this->pmBancamiga, '656565', 650);
        $missing = otroBancoPago($this, '#5003', $this->pmBanesco, '444444', 400);
        otroBancoExtracto($this, $this->banesco, [['666666', 600]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect($toBanesco->fresh()->status)->toBe(ReconciliationItem::MATCHED)
            ->and($toBanesco->fresh()->company_account_id)->toBe($this->pmBanesco);
        expect($waiting->fresh()->status)->toBe(ReconciliationItem::PENDING);
        expect($missing->fresh()->status)->toBe(ReconciliationItem::NOT_FOUND)
            ->and($missing->fresh()->match_note)->toBe('No está en el extracto de Banesco. Falta el extracto de Bancamiga para buscarlo ahí.');
        expect(app(DayBuilder::class)->neededSources($this->day, true)->sort()->values()->all())->toBe([$this->banesco, $this->bancamiga]);
    });

    it('si todo apareció en su banco, no pide el extracto del otro', function () {
        otroBancoPago($this, '#6001', $this->pmBanesco, '111111', 100);
        otroBancoExtracto($this, $this->banesco, [['111111', 100]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect(app(DayBuilder::class)->neededSources($this->day)->all())->toBe([$this->banesco]);
    });

    it('lo que no es en bolívares no se busca en los bancos', function () {
        $binance = otroBancoPago($this, '#7001', $this->binanceAccount, '777777', 10, 'binance');
        otroBancoExtracto($this, $this->binance, []);
        otroBancoExtracto($this, $this->banesco, [['777777', 10]]);

        app(StatementMatcher::class)->matchDay($this->day);

        expect($binance->fresh()->status)->toBe(ReconciliationItem::NOT_FOUND)
            ->and($binance->fresh()->match_note)->toBe('No está en el extracto de Binance.');
    });
});

describe('.115: la pantalla de la conciliación', function () {
    beforeEach(function () {
        $role = Role::forceCreate(['description' => 'Admin']);
        Sanctum::actingAs(User::forceCreate(['names' => 'Admin', 'email' => 'admin@test', 'password' => 'x', 'role_id' => $role->id]));
    });

    it('avisa de la cuenta sin extracto y la tarjeta del otro banco dice para qué se necesita', function () {
        // Como en producción el 7/11: la cuenta de Bancamiga sin extracto asignado
        DB::table('company_accounts')->where('id', $this->pmBancamiga)->update(['statement_source_id' => null]);
        $noSource = otroBancoPago($this, '#8001', $this->pmBancamiga, '808080', 800);
        otroBancoPago($this, '#8002', $this->pmBanesco, '444444', 400);
        otroBancoExtracto($this, $this->banesco, []);
        // Otra cuenta en bolívares con su extracto: como dos pagos no aparecieron, se pide para buscarlos ahí
        $bnc = DB::table('statement_sources')->insertGetId(['name' => 'BNC', 'currency' => 'VES']);
        DB::table('company_accounts')->insert(['name' => 'Pago móvil BNC', 'method' => 'pago_movil', 'currency' => 'VES', 'statement_source_id' => $bnc, 'is_active' => true]);

        $data = $this->getJson('/api/reconciliations/2026-11-06')->assertOk()->json();

        expect($data['unlinked'])->toBe([['id' => $this->pmBancamiga, 'name' => 'Pago móvil Bancamiga', 'payments' => 1]]);
        $cards = collect($data['sources'])->keyBy('name');
        expect($cards->keys()->sort()->values()->all())->toBe(['BNC', 'Banesco'])
            ->and($cards['BNC']['payments'])->toBe(0)
            ->and($cards['BNC']['searching'])->toBe(2);
        expect($noSource->fresh()->status)->toBe(ReconciliationItem::NOT_FOUND);
    });

    it('vincular a mano con un movimiento del otro banco pasa el pago a esa cuenta', function () {
        $item = otroBancoPago($this, '#9001', $this->pmBanesco, '909090', 900);
        otroBancoExtracto($this, $this->banesco, []);
        $other = otroBancoExtracto($this, $this->bancamiga, [['111', 900]]);
        app(StatementMatcher::class)->matchDay($this->day);
        expect($item->fresh()->status)->toBe(ReconciliationItem::NOT_FOUND);
        $row = $other->rows()->first();

        $this->getJson("/api/reconciliation-items/{$item->id}/rows")->assertOk()->assertJsonPath('rows.0.id', $row->id);
        $this->postJson("/api/reconciliation-items/{$item->id}/resolve", ['action' => 'link', 'row_id' => $row->id])->assertOk();

        expect($item->fresh()->status)->toBe(ReconciliationItem::LINKED)
            ->and($item->fresh()->company_account_id)->toBe($this->pmBancamiga);
    });
});
