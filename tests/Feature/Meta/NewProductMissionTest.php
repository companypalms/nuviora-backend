<?php

use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Models\NewProductMission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Products\NewProductMissions;
use App\Services\Meta\Products\NewProductRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

// .118 (dueño, 2026-11-09): "quiero que sea una misión, no un aviso, porque escalar debe ser una obligación, no una opción… hay
// que buscar y analizar qué producto vamos a lanzar, luego crear la estrategia, luego los creativos, luego la landing, y luego
// publicar en Meta".

beforeEach(function () {
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description'), $t->timestamps()]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->string('surnames')->default('');
        $t->string('email')->nullable();
        $t->string('password')->nullable();
        $t->string('phone')->nullable();
        $t->string('address')->nullable();
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamps();
    });
    Schema::create('products', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->default(0), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('product_profiles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        foreach (['what_it_is', 'benefits', 'mechanism'] as $c) {
            $t->text($c)->nullable();
        }
        $t->string('phase', 12)->default('iterate');
        $t->unsignedTinyInteger('testing_round')->default(0);
        $t->timestamp('phase_at')->nullable();
        $t->json('testing_results')->nullable();
        $t->timestamps();
    });
    Schema::create('product_personas', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('name');
        $t->text('who')->nullable();
        $t->text('pain')->nullable();
        $t->unsignedInteger('position')->default(0);
        $t->timestamp('archived_at')->nullable();
        $t->timestamps();
    });
    Schema::create('ad_angles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('code');
        $t->string('name')->nullable();
        $t->text('hypothesis')->nullable();
        $t->unsignedBigInteger('persona_id')->nullable();
        $t->unsignedInteger('position')->nullable();
        $t->unsignedBigInteger('alias_of')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->string('status');
        $t->string('kind')->nullable();
        $t->string('angle_code')->nullable();
        $t->unsignedTinyInteger('testing_round')->nullable();
        $t->json('result')->nullable();
        $t->string('concept')->nullable();
        $t->string('style')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->timestamps()]);
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->json('value')->nullable(), $t->timestamps()]);
    Schema::create('meta_analyses', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedInteger('version')->default(1);
        $t->json('urgent')->nullable();
        $t->json('marks')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_09_120000_create_meta_new_product_missions_table.php'))->up();
    $this->today = now(config('app.timezone'))->toDateString();
    $this->missions = new NewProductMissions();
    $this->product = fn (int $id, string $name, int $level = 3) => [$id => ['product' => ['id' => $id, 'name' => $name, 'mode' => 'normal'], 'campaigns' => [], 'plan' => ['urgency' => ['level' => $level]]]];
    DB::table('products')->insert(['id' => 5, 'title' => 'FLEXISTEP', 'showable_name' => 'FlexiStep']);
});

/** Un producto nuevo con su ficha completa, en testeo desde ahora, con un ángulo (2 batches de inicio). */
function productoNuevoMision(int $id = 9): void
{
    DB::table('products')->insert(['id' => $id, 'title' => 'Termafit']);
    DB::table('product_profiles')->insert(['product_id' => $id, 'what_it_is' => 'a', 'benefits' => 'b', 'mechanism' => 'c', 'phase' => 'testing', 'testing_round' => 1, 'phase_at' => now()->addMinute(), 'created_at' => now()->addMinute()]);
    DB::table('product_personas')->insert(['product_id' => $id, 'name' => 'Ana', 'who' => 'x', 'pain' => 'y']);
    DB::table('ad_angles')->insert(['product_id' => $id, 'code' => 'POSP', 'name' => 'Posparto', 'position' => 1]);
}

function batchesMision(string $status, int $product = 9): void
{
    DB::table('creative_batches')->where('product_id', $product)->delete();
    foreach ([1, 2] as $n) {
        DB::table('creative_batches')->insert(['product_id' => $product, 'number' => 100 + $n, 'status' => $status, 'angle_code' => 'POSP', 'testing_round' => 1]);
    }
}

describe('Cuándo se abre', function () {
    it('con batch listo en cada producto y el equipo alcanza: se abre', function () {
        DB::table('creative_batches')->insert(['product_id' => 5, 'number' => 1, 'status' => 'bank']);
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'ok');
        expect(NewProductMission::count())->toBe(1)->and($this->missions->view()['state'])->toBe('open');
    });

    it('un batch que todavía se está haciendo no cuenta como listo: dice qué falta', function () {
        DB::table('creative_batches')->insert(['product_id' => 5, 'number' => 1, 'status' => 'production']);
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'ok');
        expect(NewProductMission::count())->toBe(0)
            ->and($this->missions->view())->toBe(['state' => 'waiting', 'missing' => ['Falta un batch listo en la banca de FlexiStep.']]);
    });

    it('si el equipo no alcanza, no se abre', function () {
        DB::table('creative_batches')->insert(['product_id' => 5, 'number' => 1, 'status' => 'bank']);
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'floater');
        expect(NewProductMission::count())->toBe(0)->and($this->missions->view()['missing'])->toBe(['Según el pronóstico, el equipo de editores no alcanza.']);
    });

    it('los productos en pausa o sin trabajo no cuentan', function () {
        $this->missions->evaluate(($this->product)(5, 'FlexiStep', 5), $this->today, 'ok');
        expect(NewProductMission::count())->toBe(1);
    });

    it('pasa 1 semana entre un producto nuevo y el siguiente, y nunca hay dos abiertas', function () {
        DB::table('creative_batches')->insert(['product_id' => 5, 'number' => 1, 'status' => 'bank']);
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'ok');
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'ok');
        expect(NewProductMission::count())->toBe(1);
        NewProductMission::query()->update(['status' => 'done', 'opened_on' => now()->subDays(3)->toDateString()]);
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'ok');
        expect(NewProductMission::count())->toBe(1);
        NewProductMission::query()->update(['opened_on' => now()->subDays(8)->toDateString()]);
        $this->missions->evaluate(($this->product)(5, 'FlexiStep'), $this->today, 'ok');
        expect(NewProductMission::count())->toBe(2);
    });
});

describe('Las etapas', function () {
    beforeEach(function () {
        $this->m = NewProductMission::create(['status' => 'open', 'opened_on' => $this->today]);
    });

    it('buscar y la landing las marca el dueño; las otras se marcan solas', function () {
        $this->missions->complete($this->m, 'search');
        $v = $this->missions->view();
        expect(array_column($v['stages'], 'done'))->toBe([true, false, false, false, false])->and($v['current'])->toBe('strategy')->and($v['day'])->toBe(1)->and($v['days'])->toBe(7);
        expect(fn () => $this->missions->complete($this->m, 'strategy'))->toThrow(ValidationException::class);
    });

    it('al crear el producto con su ficha, la estrategia queda hecha', function () {
        productoNuevoMision();
        $v = $this->missions->view();
        expect($v['product'])->toBe('Termafit')->and(array_column($v['stages'], 'done'))->toBe([true, true, false, false, false])->and($v['current'])->toBe('creatives');
    });

    it('un producto que ya tenía campañas no es el producto nuevo', function () {
        productoNuevoMision();
        DB::table('meta_campaigns')->insert(['product_id' => 9, 'created_at' => now()->subDays(30)]);
        expect($this->missions->view()['product'])->toBeNull();
    });

    it('los creativos, cuando todos los batches del testeo están en la banca', function () {
        productoNuevoMision();
        batchesMision('production');
        expect($this->missions->view()['stages'][2]['done'])->toBeFalse();
        batchesMision('bank');
        expect($this->missions->view()['stages'][2]['done'])->toBeTrue();
    });

    it('cuando Meta muestra los anuncios, la misión termina', function () {
        productoNuevoMision();
        batchesMision('testing');
        $v = $this->missions->view();
        expect($v['state'])->toBe('done')->and($v['product'])->toBe('Termafit')->and($this->m->fresh()->status)->toBe('done')->and($this->m->fresh()->landing_done_at)->not->toBeNull();
    });

    it('antes de lanzar el testeo te recuerda la landing', function () {
        productoNuevoMision();
        $this->missions->view();
        expect(NewProductMissions::landingPending(9))->toBeTrue();
        $this->missions->complete($this->m->fresh(), 'landing');
        expect(NewProductMissions::landingPending(9))->toBeFalse();
        $step = fn (bool $p) => UrgentList::testLaunch(['product_id' => 9, 'product' => 'Termafit', 'round' => 1, 'batches' => [['code' => 'B1', 'adset' => 'B1 POSP']], 'linked' => true, 'campaigns' => [], 'cities' => ['Caracas'],
            'target' => 5.0, 'min' => 2.5, 'max' => 3.0, 'date' => $this->today, 'landing_pending' => $p])['steps'];
        expect($step(true)[0])->toBe('Antes: termina la landing de Termafit y márcala en la misión de producto nuevo.')
            ->and(implode(' ', $step(false)))->not->toContain('landing');
    });
});

describe('Si se pasa de la fecha', function () {
    it('una etapa suya vencida es una misión de hoy: cuenta para la racha y no se puede descartar', function () {
        $m = NewProductMission::create(['status' => 'open', 'opened_on' => now()->subDays(3)->toDateString()]);
        $i = $this->missions->lateItem($this->today);
        expect($i)->toMatchArray(['key' => "new_product:{$m->id}:search", 'kind' => 'new_product_late', 'group' => 'crecer', 'no_disagree' => true, 'no_done' => false, 'title' => 'Vas tarde con el producto nuevo: buscar y analizar el producto'])
            ->and(UrgentList::tally([$i], [])['tasks'])->toBe(1);
    });

    it('dentro del plazo no es misión del día', function () {
        NewProductMission::create(['status' => 'open', 'opened_on' => $this->today]);
        expect($this->missions->lateItem($this->today))->toBeNull();
    });

    it('si solo se atrasa el equipo con los creativos, no te corta la racha', function () {
        NewProductMission::create(['status' => 'open', 'opened_on' => now()->subDays(6)->toDateString(), 'search_done_at' => now(), 'strategy_done_at' => now(), 'landing_done_at' => now()]);
        $rules = NewProductRules::stages(now()->subDays(6)->toDateString(), ['search' => true, 'strategy' => true, 'landing' => true], now()->toDateString());
        expect($rules[2]['late'])->toBeTrue();
        expect(NewProductRules::firstLate($rules))->toBeNull();
    });

    it('una etapa que se marca sola no tiene "Hecho"', function () {
        NewProductMission::create(['status' => 'open', 'opened_on' => now()->subDays(5)->toDateString(), 'search_done_at' => now()]);
        expect($this->missions->lateItem($this->today))->toMatchArray(['no_done' => true]);
    });

    it('al terminarla se resuelve en la lista de hoy, y por la pantalla "No estoy de acuerdo" no se deja', function () {
        Sanctum::actingAs(User::forceCreate(['names' => 'Dueño', 'email' => 'd@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Admin'])->id]));
        $m = NewProductMission::create(['status' => 'open', 'opened_on' => now()->subDays(3)->toDateString()]);
        $i = $this->missions->lateItem($this->today);
        $a = MetaAnalysis::forceCreate(['date' => $this->today, 'urgent' => [$i]]);
        $url = "/api/meta/analysis/{$a->id}/marks";
        $this->putJson($url, ['key' => $i['key'], 'status' => 'disagree'])->assertStatus(422);
        $this->postJson("/api/meta/new-product-mission/{$m->id}/done", ['stage' => 'search'])->assertOk()->assertJsonPath('view.state', 'open');
        expect($m->fresh()->search_done_at)->not->toBeNull()->and($a->fresh()->marks[$i['key']]['status'])->toBe('done');
        expect(CreativeBatch::count())->toBe(0)->and(Setting::get(NewProductMissions::CHECK))->toBeNull();
    });
});
