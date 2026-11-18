<?php

use App\Models\EditorCommission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Calendar\Readers\ReminderFacts;
use App\Services\Calendar\Rules\ReminderRules;
use App\Services\Editors\CommissionFacts;
use App\Services\Editors\Commissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

// .153 (dueño, 2026-11-17): la comisión del líder de editores, por los productos de su grupo, del 1 al 15 (se paga el 15) y
// del 16 al último (se paga el último). Se congela el día de pago; lo que la quincena suma después pasa a la siguiente.
// En una base en memoria; la ganancia de cada producto (la de Reportes del negocio) es de mentira.

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
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    Schema::create('products', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('showable_name')->nullable();
        $t->decimal('price', 10, 2)->default(0);
        $t->timestamps();
    });
    Schema::create('inventories', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->integer('quantity')->default(0);
        $t->timestamps();
    });
    (require database_path('migrations/2026_10_29_300000_create_calendar_tables.php'))->up();

    $role = fn (string $d) => Role::forceCreate(['description' => $d])->id;
    [$leaderRole, $editorRole, $admin, $manager] = [$role('Editor líder'), $role('Editor de video'), $role('Admin'), $role('Gerente')];
    $user = fn (string $name, int $roleId) => User::forceCreate(['names' => $name, 'email' => "{$name}@test", 'password' => 'x', 'role_id' => $roleId]);
    $this->leader = $user('Leo', $leaderRole);
    $this->editor = $user('Ana', $editorRole);
    $this->owner = $user('Dueño', $admin);
    $this->manager = $user('Gerente', $manager);
    $this->leaderRole = $leaderRole;
    foreach (['Comprimax', 'FlexiStep', 'Ajeno'] as $i => $title) {
        DB::table('products')->insert(['id' => $i + 1, 'title' => $title]);
    }
    $this->migration = require database_path('migrations/2026_11_17_100000_add_editor_leader_commission.php');
    $this->migration->up();
    // "Ajeno" no es de su grupo
    DB::table('products')->where('id', 3)->update(['editor_leader_id' => null]);

    // La ganancia de cada producto por rango ("desde|hasta"), como la de Reportes del negocio
    $this->facts = new class extends CommissionFacts {
        public array $profits = [];

        public function __construct()
        {
        }

        public function profitByProduct(string $from, string $to, CarbonImmutable $now): array
        {
            return $this->profits["{$from}|{$to}"] ?? [];
        }
    };
    app()->instance(CommissionFacts::class, $this->facts);
    $this->nov = '2026-11-14|2026-11-30'; // .157: el primer pago, del 14 al 30
    $this->dec = '2026-12-01|2026-12-15';
});

describe('El grupo de editores de cada producto', function () {
    it('con un solo líder, la migración pone todos los productos en su grupo, y los nuevos también', function () {
        expect(DB::table('products')->whereIn('id', [1, 2])->pluck('editor_leader_id')->all())->toBe([$this->leader->id, $this->leader->id]);
        expect(Product::create(['title' => 'Releev', 'price' => 10])->editor_leader_id)->toBe($this->leader->id);
    });

    it('con dos líderes no se asigna nada solo: lo elige el dueño', function () {
        User::forceCreate(['names' => 'Otro', 'email' => 'otro@test', 'password' => 'x', 'role_id' => $this->leaderRole]);
        $this->migration->down();
        $this->migration->up();

        expect(DB::table('products')->whereNotNull('editor_leader_id')->count())->toBe(0)
            ->and(Product::create(['title' => 'Releev', 'price' => 10])->editor_leader_id)->toBeNull();
    });

    it('un líder desactivado no cuenta', function () {
        $this->leader->forceFill(['deactivated_at' => now()])->save();
        expect(Commissions::onlyLeader())->toBeNull();
    });

    it('solo el dueño lo cambia, y solo a un líder de editores activo', function () {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/products/3', ['editor_leader_id' => $this->editor->id])->assertStatus(422);
        $this->putJson('/api/products/3', ['editor_leader_id' => $this->leader->id])->assertOk();
        expect(Product::find(3)->editor_leader_id)->toBe($this->leader->id);
        $this->putJson('/api/products/3', ['editor_leader_id' => null])->assertOk();
        expect(Product::find(3)->editor_leader_id)->toBeNull();

        Sanctum::actingAs($this->manager);
        $this->putJson('/api/products/3', ['editor_leader_id' => $this->leader->id])->assertOk();
        expect(Product::find(3)->editor_leader_id)->toBeNull();
    });

    it('la lista de grupos para elegir: los líderes activos', function () {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/products/editor-leaders')->assertOk()->assertExactJson(['leaders' => [['id' => $this->leader->id, 'name' => 'Leo']]]);
    });
});

describe('El congelado de cada quincena', function () {
    it('el día de pago se congela lo de sus productos: (ganancia − 10 %) × 1 %, una sola vez', function () {
        // Comprimax gana, FlexiStep pierde (resta) y "Ajeno" no es de su grupo
        $this->facts->profits[$this->nov] = [1 => 3000.0, 2 => -500.0, 3 => 9999.0];
        $this->travelTo(CarbonImmutable::parse('2026-11-30 00:50:00'));

        Artisan::call('editors:comision');
        Artisan::call('editors:comision');

        expect(EditorCommission::count())->toBe(1);
        expect(EditorCommission::first()->only(['period_start', 'period_end', 'payday', 'profit_usd', 'reserve_pct', 'pct', 'own_usd', 'carry_usd', 'amount_usd']))
            ->toEqual(['period_start' => CarbonImmutable::parse('2026-11-14'), 'period_end' => CarbonImmutable::parse('2026-11-30'), 'payday' => CarbonImmutable::parse('2026-11-30'),
                'profit_usd' => 2500.0, 'reserve_pct' => 10.0, 'pct' => 1.0, 'own_usd' => 22.5, 'carry_usd' => 0.0, 'amount_usd' => 22.5]);
    });

    it('el 14/11 (el pago del 1 al 15) no se congela nada: el 14 y el 15 van con el pago del 30 (.157)', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        expect(app(Commissions::class)->freeze(CarbonImmutable::now()))->toBe(0)
            ->and(EditorCommission::count())->toBe(0);
    });

    it('lo que la quincena suma después de pagarla (entregas tardías) se le suma a la siguiente', function () {
        $this->facts->profits[$this->nov] = [1 => 3000.0, 2 => -500.0];
        $this->travelTo(CarbonImmutable::parse('2026-11-30 00:50:00'));
        app(Commissions::class)->freeze(CarbonImmutable::now());

        // se entregaron más pedidos de la segunda quincena de noviembre
        $this->facts->profits[$this->nov] = [1 => 3500.0, 2 => -500.0];
        $this->facts->profits[$this->dec] = [1 => 1000.0];
        $this->travelTo(CarbonImmutable::parse('2026-12-15 00:50:00'));
        app(Commissions::class)->freeze(CarbonImmutable::now());

        $dec = EditorCommission::whereDate('period_start', '2026-12-01')->first();
        expect($dec->own_usd)->toBe(9.0)
            ->and($dec->carry_usd)->toBe(4.5) // (3000 × 0,9 × 1 %) − 22,50
            ->and($dec->amount_usd)->toBe(13.5);
    });

    it('si se pasó un día de pago (el comando no corrió), se congelan las que faltan', function () {
        $this->facts->profits[$this->nov] = [1 => 1000.0];
        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
        expect(app(Commissions::class)->freeze(CarbonImmutable::now()))->toBe(2)
            ->and(EditorCommission::orderBy('period_start')->get()->map(fn ($c) => $c->period_start->toDateString())->all())->toBe(['2026-11-14', '2026-12-01']);
    });

    it('un cambio del % vale para la quincena que corre; la congelada guarda los suyos', function () {
        $this->facts->profits[$this->nov] = [1 => 1000.0];
        $this->travelTo(CarbonImmutable::parse('2026-11-30 00:50:00'));
        app(Commissions::class)->freeze(CarbonImmutable::now());
        Commissions::saveSettings(10, 2);

        expect(EditorCommission::first()->amount_usd)->toBe(9.0)
            ->and(Commissions::settings())->toBe(['reserve_pct' => 10.0, 'pct' => 2.0, 'since' => '2026-11-14']);
    });
});

describe('Lo que ve el líder', function () {
    it('su comisión de la quincena, la barra y el día que cobra; sin la ganancia ni el %', function () {
        $this->facts->profits['2026-11-14|2026-11-30'] = [1 => 2000.0];
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
        Sanctum::actingAs($this->leader);

        $r = $this->getJson('/api/editor/commission')->assertOk()->json();

        expect($r)->toMatchArray(['amount' => 18, 'payday' => '2026-11-30', 'payday_label' => 'lun 30/11', 'previous' => null, 'target' => 'days', 'beat' => false, 'notice' => null])
            ->and(array_values(array_intersect(array_keys($r), ['profit_usd', 'pct', 'reserve_pct', 'profit', 'own_usd', 'carry_usd'])))->toBe([]);
    });

    it('el editor de video no la ve', function () {
        Sanctum::actingAs($this->editor);
        $this->getJson('/api/editor/commission')->assertForbidden();
    });

    it('el día de pago: "Hoy cobras"; cuando el dueño lo tilda, "¡Cobrada!"; y la barra contra la anterior', function () {
        $this->facts->profits[$this->nov] = [1 => 2500.0];
        $this->facts->profits['2026-12-01|2026-12-15'] = [1 => 3000.0];
        $this->travelTo(CarbonImmutable::parse('2026-11-30 08:00:00'));

        $r = app(Commissions::class)->current($this->leader, CarbonImmutable::now());
        expect($r['notice'])->toBe(['tone' => 'green', 'text' => 'Hoy cobras $22,50'])
            ->and($r['start'])->toBe('2026-12-01')
            ->and($r['previous'])->toBe(22.5);

        DB::table('calendar_marks')->insert(['item_key' => 'rule:editor_leader_pay', 'occurs_on' => '2026-11-30', 'status' => 'done', 'source' => 'owner']);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 08:00:00'));
        Cache::flush();
        $r = app(Commissions::class)->current($this->leader, CarbonImmutable::now());
        expect($r['notice'])->toBe(['tone' => 'green', 'text' => '¡Cobrada! $22,50'])
            // 3000 × 0,9 × 1 % = 27 > 22,50
            ->and($r)->toMatchArray(['amount' => 27.0, 'beat' => true, 'fill' => 1.0, 'target' => 'previous']);
    });
});

describe('El recordatorio del dueño', function () {
    it('el 30/11 dice cuánto le paga a cada líder, del 14 al 30; el 15/11 no sale (.157)', function () {
        $this->facts->profits[$this->nov] = [1 => 2500.0];
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00'));
        $facts = app(ReminderFacts::class)->editorCommissions(['2026-11-15', '2026-11-30'], CarbonImmutable::now());
        $today = '2026-11-25';

        expect(ReminderRules::occurrence('editor_leader_pay', '2026-11-15', $today, $facts)['hidden'] ?? false)->toBeTrue();
        $o = ReminderRules::occurrence('editor_leader_pay', '2026-11-30', $today, $facts);
        expect($o['details'])->toBe('Del 14/11 al 30/11: lo de hasta ahora. Se congela la madrugada del día de pago.')
            ->and($o['lines'])->toBe([['label' => 'Leo', 'value' => '$22,50', 'note' => 'lo de hasta ahora']]);

        $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00:00'));
        $facts = app(ReminderFacts::class)->editorCommissions(['2026-11-30'], CarbonImmutable::now());
        $o = ReminderRules::occurrence('editor_leader_pay', '2026-11-30', '2026-11-30', $facts);
        expect($o['details'])->toBe('Del 14/11 al 30/11: ya está congelada; es lo que le pagas.')
            ->and($o['lines'][0])->toBe(['label' => 'Leo', 'value' => '$22,50', 'note' => null]);
    });

    it('sin líder de editores no sale', function () {
        $this->leader->forceFill(['deactivated_at' => now()])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00'));
        $facts = app(ReminderFacts::class)->editorCommissions(['2026-11-30'], CarbonImmutable::now());

        expect(ReminderRules::occurrence('editor_leader_pay', '2026-11-30', '2026-11-25', $facts)['hidden'] ?? false)->toBeTrue();
    });
});
