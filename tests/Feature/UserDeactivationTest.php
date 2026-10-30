<?php

use App\Models\User;
use App\Models\UserAccountChange;
use App\Services\Agencies\AgencyRouter;
use App\Services\Agencies\WorkingHours;
use App\Services\Users\UserAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

// Desactivar y reactivar cuentas (Etapa 4, paso 6), en una base en memoria con solo las tablas que hacen falta
// (no toca la base local).

beforeEach(function () {
    Schema::create('roles', function ($t) {
        $t->id();
        $t->string('description');
    });
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('phone')->default('');
        $t->string('address')->default('');
        $t->string('email')->unique();
        $t->string('password');
        $t->rememberToken();
        $t->unsignedBigInteger('role_id')->nullable();
        $t->unsignedBigInteger('status_id')->nullable();
        $t->unsignedBigInteger('agency_id')->nullable();
        $t->boolean('agency_is_working')->nullable();
        $t->time('agency_works_from')->nullable();
        $t->time('agency_works_until')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->unsignedBigInteger('deactivated_by')->nullable();
        $t->string('deactivation_reason', 300)->nullable();
        $t->timestamps();
    });
    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });
    Schema::create('daily_agent_rosters', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedBigInteger('shop_id');
        $t->unsignedBigInteger('agent_id');
        $t->boolean('is_active')->default(true);
        $t->boolean('is_whatsapp_active')->default(false);
        $t->timestamps();
    });
    Schema::create('roster_changes', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedBigInteger('shop_id');
        $t->unsignedBigInteger('agent_id');
        $t->boolean('is_active');
        $t->string('reason')->nullable();
        $t->unsignedBigInteger('changed_by')->nullable();
        $t->string('changed_by_role')->nullable();
        $t->timestamps();
    });
    Schema::create('rider_profiles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('user_account_changes', function ($t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('action', 20);
        $t->string('reason', 300)->nullable();
        $t->json('details')->nullable();
        $t->unsignedBigInteger('changed_by')->nullable();
        $t->timestamps();
    });
    Schema::create('sales_groups', function ($t) {
        $t->id();
        $t->string('name');
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('sales_group_members', function ($t) {
        $t->id();
        $t->unsignedBigInteger('sales_group_id');
        $t->unsignedBigInteger('user_id');
        $t->string('role');
        $t->timestamp('ended_at')->nullable();
        $t->timestamps();
    });
    Schema::create('statuses', function ($t) {
        $t->id();
        $t->string('description');
    });
    Schema::create('orders', function ($t) {
        $t->id();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->unsignedBigInteger('agency_id')->nullable();
        $t->unsignedBigInteger('deliverer_id')->nullable();
        $t->unsignedBigInteger('status_id')->nullable();
        $t->timestamps();
    });
    Schema::create('whatsapp_conversations', function ($t) {
        $t->id();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->string('status')->default('open');
        $t->timestamps();
    });
    Schema::create('logistics_orders', function ($t) {
        $t->id();
        $t->unsignedBigInteger('rider_id')->nullable();
        $t->string('status');
        $t->timestamps();
    });
    Schema::create('rider_cash_movements', function ($t) {
        $t->id();
        $t->unsignedBigInteger('rider_id');
        $t->decimal('usd', 12, 2)->default(0);
        $t->decimal('ves', 14, 2)->default(0);
        $t->decimal('total_usd', 12, 2)->default(0);
        $t->timestamps();
    });
    Schema::create('logs', function ($t) {
        $t->id();
        $t->text('description');
        $t->string('impact')->nullable();
        $t->string('author')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->timestamps();
    });

    foreach (['Admin', 'Vendedor', 'Repartidor', 'Agencia', 'Master', 'Shopify'] as $i => $role) {
        DB::table('roles')->insert(['id' => $i + 1, 'description' => $role]);
    }
    $this->password = Str::random(16); // de mentira, solo para esta prueba
    $this->make = function (string $names, int $roleId) {
        return User::create(['names' => $names, 'email' => Str::lower($names) . '@prueba.test', 'password' => Hash::make($this->password), 'role_id' => $roleId])->load('role');
    };
    $this->admin = ($this->make)('Dueno', 1);
    $this->seller = ($this->make)('Ana', 2);
    $this->rider = ($this->make)('Beto', 3);
    $this->accounts = new UserAccounts();
});

describe('Quién se puede desactivar', function () {
    it('no el Admin, el Master, la cuenta de Shopify, uno mismo ni la líder de un grupo', function () {
        $master = ($this->make)('Miguel', 5);
        $shopify = ($this->make)('Shopify', 6);
        $otherAdmin = ($this->make)('Otro', 1);
        expect($this->accounts->problem($master, $this->admin))->toBe('Las cuentas de Master no se desactivan.');
        expect($this->accounts->problem($otherAdmin, $this->admin))->toBe('Las cuentas de Admin no se desactivan.');
        expect($this->accounts->problem($shopify, $this->admin))->toContain('Shopify');
        expect($this->accounts->problem($this->admin, $this->admin))->toBe('No puedes desactivar tu propia cuenta.');

        $group = DB::table('sales_groups')->insertGetId(['name' => 'Norte', 'is_active' => true]);
        DB::table('sales_group_members')->insert(['sales_group_id' => $group, 'user_id' => $this->seller->id, 'role' => 'leader']);
        expect($this->accounts->problem($this->seller, $this->admin))->toBe('Ana es la líder del grupo "Norte": primero cambia la líder en Reparto › Grupos y %.');
        expect($this->accounts->problem($this->rider, $this->admin))->toBeNull();
    });

    it('pide un motivo de al menos 5 letras', function () {
        expect(fn () => $this->accounts->deactivate($this->rider, $this->admin, ' no '))->toThrow(ValidationException::class);
        expect($this->rider->fresh()->isDeactivated())->toBeFalse();
    });
});

describe('Desactivar', function () {
    it('cierra sus sesiones, la saca del roster de hoy en adelante y deja el registro', function () {
        $this->seller->createToken('auth_token');
        $this->seller->createToken('auth_token');
        DB::table('daily_agent_rosters')->insert([
            ['date' => now()->subDay()->toDateString(), 'shop_id' => 1, 'agent_id' => $this->seller->id, 'is_active' => true],
            ['date' => now()->toDateString(), 'shop_id' => 1, 'agent_id' => $this->seller->id, 'is_active' => true],
            ['date' => now()->toDateString(), 'shop_id' => 2, 'agent_id' => $this->rider->id, 'is_active' => true],
        ]);

        $user = $this->accounts->deactivate($this->seller, $this->admin, 'Ya no trabaja con nosotros');

        expect($user->isDeactivated())->toBeTrue();
        expect([$user->deactivated_by, $user->deactivation_reason])->toBe([$this->admin->id, 'Ya no trabaja con nosotros']);
        expect(PersonalAccessToken::count())->toBe(0);
        expect(DB::table('daily_agent_rosters')->where('agent_id', $this->seller->id)->orderBy('date')->pluck('is_active')->map(fn ($v) => (bool) $v)->all())
            ->toBe([true, false]); // ayer queda como estaba (es historial)
        expect(DB::table('daily_agent_rosters')->where('agent_id', $this->rider->id)->value('is_active'))->toBe(1);
        expect(DB::table('roster_changes')->where('agent_id', $this->seller->id)->value('reason'))->toBe('Cuenta desactivada: Ya no trabaja con nosotros');

        $change = UserAccountChange::first();
        expect([$change->action, $change->changed_by, $change->details['role']])->toBe(['deactivated', $this->admin->id, 'Vendedor']);
        expect($change->details['pending'])->toContain('Está en el roster de hoy: sale al desactivarla.');
        expect($this->accounts->problem($user, $this->admin))->toBe('La cuenta de Ana ya está desactivada.');
    });

    it('un repartidor sale de las rondas de Logística propia y vuelve al reactivarlo', function () {
        DB::table('rider_profiles')->insert(['user_id' => $this->rider->id, 'is_active' => true]);
        $this->accounts->deactivate($this->rider, $this->admin, 'Se mudó de ciudad');
        expect(DB::table('rider_profiles')->value('is_active'))->toBe(0);

        $user = $this->accounts->reactivate($this->rider, $this->admin);
        expect($user->isDeactivated())->toBeFalse();
        expect([$user->deactivated_by, $user->deactivation_reason])->toBe([null, null]);
        expect(DB::table('rider_profiles')->value('is_active'))->toBe(1);
        expect(array_column($this->accounts->history($user), 'label'))->toBe(['Reactivada', 'Desactivada']);
    });

    it('si su perfil de rondas ya estaba apagado, al reactivarlo sigue apagado', function () {
        DB::table('rider_profiles')->insert(['user_id' => $this->rider->id, 'is_active' => false]);
        $this->accounts->deactivate($this->rider, $this->admin, 'Se mudó de ciudad');
        $this->accounts->reactivate($this->rider, $this->admin);
        expect(DB::table('rider_profiles')->value('is_active'))->toBe(0);
        expect(fn () => $this->accounts->reactivate($this->rider, $this->admin))->toThrow(ValidationException::class);
    });

    it('lo pendiente de una vendedora: sus órdenes, sus chats y su grupo', function () {
        $statuses = ['Llamado 1' => 1, 'En ruta' => 2];
        foreach ($statuses as $d => $id) {
            DB::table('statuses')->insert(['id' => $id, 'description' => $d]);
        }
        DB::table('orders')->insert([
            ['agent_id' => $this->seller->id, 'status_id' => 1],
            ['agent_id' => $this->seller->id, 'status_id' => 1],
            ['agent_id' => $this->seller->id, 'status_id' => 2], // ya salió: no está en sus manos
        ]);
        DB::table('whatsapp_conversations')->insert([['agent_id' => $this->seller->id, 'status' => 'open'], ['agent_id' => $this->seller->id, 'status' => 'closed']]);
        $group = DB::table('sales_groups')->insertGetId(['name' => 'Norte', 'is_active' => true]);
        DB::table('sales_group_members')->insert(['sales_group_id' => $group, 'user_id' => $this->seller->id, 'role' => 'seller']);

        $lines = collect($this->accounts->pending($this->seller))->keyBy('key');
        expect($lines['orders']['count'])->toBe(2);
        expect($lines['orders']['action'])->toBe('reassign_seller');
        expect($lines['orders']['text'])->toStartWith('Tiene 2 órdenes en sus manos');
        expect($lines['chats']['text'])->toStartWith('Tiene 1 chat de WhatsApp abierto:');
        expect($lines['group']['text'])->toContain('"Norte"');
        expect($lines->keys()->last())->toBe('session');
    });

    it('lo pendiente de un repartidor: el efectivo sin rendir y la orden de Logística propia que lleva', function () {
        DB::table('rider_cash_movements')->insert([['rider_id' => $this->rider->id, 'total_usd' => 30], ['rider_id' => $this->rider->id, 'total_usd' => -5.5]]);
        DB::table('logistics_orders')->insert(['rider_id' => $this->rider->id, 'status' => 'en_via']);
        DB::table('rider_profiles')->insert(['user_id' => $this->rider->id, 'is_active' => true]);

        $lines = collect($this->accounts->pending($this->rider))->keyBy('key');
        expect($lines['cash']['text'])->toBe('Tiene $24,50 en efectivo de entregas sin rendir.');
        expect($lines['logistics']['tone'])->toBe('warning');
        expect($lines->has('rider_profile'))->toBeTrue();
    });
});

describe('Ya no entra', function () {
    it('el login la rechaza con un mensaje claro; activa entra como siempre', function () {
        $this->postJson('/api/login', ['email' => 'beto@prueba.test', 'password' => $this->password])->assertOk();
        $this->accounts->deactivate($this->rider, $this->admin, 'Se mudó de ciudad');

        $this->postJson('/api/login', ['email' => 'beto@prueba.test', 'password' => $this->password])
            ->assertStatus(403)
            ->assertJson(['status' => false, 'message' => 'Tu cuenta está desactivada. Habla con el administrador.']);
        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('una sesión vieja de una cuenta desactivada no sirve', function () {
        $token = PersonalAccessToken::findToken($this->seller->createToken('auth_token')->plainTextToken);
        $check = Sanctum::$accessTokenAuthenticationCallback;
        expect($check($token, true))->toBeTrue();
        DB::table('users')->where('id', $this->seller->id)->update(['deactivated_at' => now()]); // como si quedara una sesión
        expect($check(PersonalAccessToken::find($token->id), true))->toBeFalse();
        expect($check($token->fresh(), false))->toBeFalse();
    });

    it('una agencia desactivada no recibe órdenes de su ciudad', function () {
        $hours = new WorkingHours(new AgencyRouter());
        $agency = (new User())->forceFill(['names' => 'Rápido', 'agency_is_working' => true]);
        expect($hours->agencyStop($agency))->toBeNull();
        $agency->deactivated_at = now();
        expect($hours->agencyStop($agency))->toBe('está desactivada');
        expect($hours->agencyWorks($agency))->toBeFalse();
    });
});
