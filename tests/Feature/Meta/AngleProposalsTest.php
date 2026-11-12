<?php

use App\Models\AdAngle;
use App\Models\AngleProposal;
use App\Models\Role;
use App\Models\User;
use App\Services\Meta\Decisions\DecisionSettings;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Decisions\Readers\AdFacts;
use App\Services\Meta\Decisions\Readers\OpsFacts;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\Products\AngleProposals;
use App\Services\Meta\Products\TestingPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

// .116 (dueño, 2026-11-09): "lo de que la IA propone un nuevo ángulo no me gusta tanto. Me gustaría que me lo propusiera a mí
// solamente (en mi calendario y en mis tareas por hacer en el día) y yo veo si le digo que hagamos ese ángulo, otro que yo
// recomiende, o uno de los que ya está".

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
        $t->string('phase', 12)->default('iterate');
        $t->unsignedTinyInteger('testing_round')->default(0);
        $t->timestamp('phase_at')->nullable();
        $t->json('testing_results')->nullable();
        $t->timestamps();
    });
    Schema::create('ad_angles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('code');
        $t->string('name')->nullable();
        $t->text('hypothesis')->nullable();
        $t->string('status')->nullable();
        $t->string('proposed_by')->nullable();
        $t->unsignedBigInteger('confirmed_by')->nullable();
        $t->timestamp('confirmed_at')->nullable();
        $t->unsignedBigInteger('persona_id')->nullable();
        $t->unsignedInteger('position')->nullable();
        $t->unsignedBigInteger('alias_of')->nullable();
        $t->timestamps();
    });
    Schema::create('product_personas', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->string('name'), $t->timestamp('archived_at')->nullable(), $t->timestamps()]);
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('status');
        $t->string('angle_code')->nullable();
        $t->timestamps();
    });
    (require database_path('migrations/2026_11_09_100000_create_meta_angle_proposals_table.php'))->up();

    DB::table('products')->insert(['id' => 5, 'title' => 'FLEXISTEP RODILLERA', 'showable_name' => 'FlexiStep']);
    DB::table('product_profiles')->insert(['product_id' => 5, 'testing_results' => json_encode([['lost' => ['ESPA']]])]);
    foreach (['RODI', 'ESPA'] as $i => $code) {
        DB::table('ad_angles')->insert(['product_id' => 5, 'code' => $code, 'name' => ucfirst(strtolower($code)), 'position' => $i + 1]);
    }
    $this->owner = User::forceCreate(['names' => 'Dueño', 'email' => 'd@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Admin'])->id]);
    $this->proposal = AngleProposal::create(['product_id' => 5, 'code' => 'OFIC', 'name' => 'Dolor en la oficina', 'about' => 'Le habla al que pasa sentado.', 'why' => 'No se ha probado.', 'status' => 'pending']);
    $this->service = new AngleProposals();
});

describe('Lo que decide el dueño', function () {
    it('hacer ese ángulo: entra al final de la ficha y es el del siguiente encargo', function () {
        expect($this->service->decide($this->proposal, ['choice' => 'accepted'], $this->owner))->toBe('OFIC');
        $a = AdAngle::where('code', 'OFIC')->first();
        expect($a->position)->toBe(3)->and($a->name)->toBe('Dolor en la oficina')->and($a->status)->toBe('confirmed')->and($a->hypothesis)->toBe('Le habla al que pasa sentado.')
            ->and($this->proposal->fresh()->status)->toBe('accepted')
            ->and(TestingPhase::profileAngles(5)['chosen'])->toBe('OFIC');
    });

    it('otro que él recomienda: con su nombre, y el código sale solo', function () {
        expect($this->service->decide($this->proposal, ['choice' => 'other', 'name' => 'Dolor al subir escaleras', 'about' => null], $this->owner))->toBe('DOLSUB');
        expect(AdAngle::where('code', 'DOLSUB')->first()->position)->toBe(3)->and(AdAngle::where('code', 'OFIC')->exists())->toBeFalse();
    });

    it('uno de los que ya están: su cuenta empieza de cero, aunque haya perdido en un testeo', function () {
        expect(TestingPhase::profileAngles(5)['lost'])->toBe(['ESPA']);
        expect($this->service->decide($this->proposal, ['choice' => 'existing', 'code' => 'ESPA'], $this->owner))->toBe('ESPA');
        $pa = TestingPhase::profileAngles(5);
        expect($pa['chosen'])->toBe('ESPA')->and($pa['lost'])->toBe([])->and($pa['floors'])->toBe(['ESPA' => now(config('app.timezone'))->toDateString()]);
    });

    it('no acepta un ángulo que no está en la ficha ni decidir dos veces', function () {
        expect(fn () => $this->service->decide($this->proposal, ['choice' => 'existing', 'code' => 'NOPE'], $this->owner))->toThrow(ValidationException::class);
        $this->service->decide($this->proposal, ['choice' => 'accepted'], $this->owner);
        expect(fn () => $this->service->decide($this->proposal->fresh(), ['choice' => 'accepted'], $this->owner))->toThrow(ValidationException::class);
    });

    it('cuando ya salió su encargo, deja de ser "el siguiente"', function () {
        $this->service->decide($this->proposal, ['choice' => 'accepted'], $this->owner);
        DB::table('creative_batches')->insert(['product_id' => 5, 'status' => 'approved', 'angle_code' => 'OFIC', 'created_at' => now()->addMinute(), 'updated_at' => now()]);
        expect(TestingPhase::profileAngles(5)['chosen'])->toBeNull();
    });

    it('por la pantalla: solo el dueño, y dice qué ángulo sigue', function () {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/meta/angle-proposals/{$this->proposal->id}/decide", ['choice' => 'other'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson("/api/meta/angle-proposals/{$this->proposal->id}/decide", ['choice' => 'accepted'])->assertOk()->assertJson(['message' => 'Listo: el siguiente encargo será del ángulo OFIC.']);
        $editor = User::forceCreate(['names' => 'Edi', 'email' => 'e@test', 'password' => 'x', 'role_id' => Role::forceCreate(['description' => 'Editor'])->id]);
        Sanctum::actingAs($editor);
        $this->postJson("/api/meta/angle-proposals/{$this->proposal->id}/decide", ['choice' => 'accepted'])->assertForbidden();
    });
});

describe('La propuesta de la IA', function () {
    beforeEach(fn () => AngleProposal::query()->delete());

    it('sin IA en el servidor igual queda la tarea, para que elija él', function () {
        $this->service->ensure(5);
        $p = AngleProposal::first();
        expect($p->status)->toBe('pending')->and($p->name)->toBeNull()->and($p->why)->toBe('La IA no está configurada en este servidor.');
    });

    it('un producto no tiene dos propuestas abiertas', function () {
        $this->service->ensure(5);
        $this->service->ensure(5);
        expect(AngleProposal::count())->toBe(1);
    });
});

describe('Los ángulos en descanso, con Meta', function () {
    /** Un batch perdedor en Meta: $20 gastados sin vender. */
    function perdedorDescanso(array &$f, string $set, string $code, string $angle, string $day, float $spend = 20, float $buys = 0): void
    {
        $t = MetaMetrics::sum([['spend' => $spend, 'purchases' => $buys, 'impressions' => 5000]]);
        $w = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
        $win = ['3d' => $w, 'prev' => $w, '7d' => $w, 'yesterday' => $w, 'max' => $w];
        $f['adsets'][$set] = ['meta_id' => $set, 'name' => "{$code} {$angle}", 'campaign_meta_id' => '900', 'active' => false, 'missing' => false, 'batch_code' => $code, 'angle_code' => $angle, 'concept' => null, 'stage' => null, 'windows' => $win];
        $f['ads']["a{$set}"] = ['id' => (int) $set, 'meta_id' => "a{$set}", 'name' => "C{$set}", 'campaign_meta_id' => '900', 'adset_meta_id' => $set, 'creative_id' => null, 'creative_type' => 'video', 'video_length' => null,
            'angle_code' => $angle, 'batch_code' => $code, 'active' => false, 'created_time' => "{$day} 10:00:00", 'windows' => $win];
    }

    function decisionesDescanso(array $f, array $opsExtra = []): array
    {
        $facts = Mockery::mock(AdFacts::class);
        $facts->allows('load')->andReturn([5 => $f]);
        $facts->allows('dailyHistory')->andReturn([]);
        $settings = Mockery::mock(DecisionSettings::class);
        $settings->allows('get')->andReturn(DecisionSettings::defaults());
        $empty = ['daily' => [], 'budget_events' => [], 'status_events' => [], 'ad_pauses' => [], 'new_ads' => [], 'mins' => 0.0];
        $ops = Mockery::mock(OpsFacts::class);
        $ops->allows('worth')->andReturn([]);
        $ops->allows('campaigns')->andReturn(['history_since' => null, 'today' => now()->toDateString(), 'yesterday' => now()->subDay()->toDateString(), 'campaigns' => ['900' => $empty]]);
        $ops->allows('products')->andReturn([5 => $opsExtra + ['price' => 30.0, 'price_change' => null, 'cities' => [3 => ['available' => true, 'stock' => 100, 'units_per_day' => 5.0, 'days_of_stock' => 20.0, 'sizes_out' => [], 'version' => null]], 'version' => null,
            'bank' => ['codes' => [], 'ready' => [], 'launched' => []]]]);

        return (new MetaDecisions($facts, $settings, $ops))->compute([5])[5];
    }

    beforeEach(function () {
        $t = MetaMetrics::sum([['spend' => 60, 'purchases' => 0, 'impressions' => 5000]]);
        $w = ['totals' => $t, 'metrics' => MetaMetrics::compute($t)];
        $this->meta = ['product' => ['id' => 5, 'name' => 'FlexiStep', 'mode' => 'normal'], 'creatives' => [], 'dates' => AdFacts::dates(), 'adsets' => [], 'ads' => [],
            'campaigns' => ['900' => ['meta_id' => '900', 'name' => 'FLEXISTEP | ARAGUA', 'city_id' => 3, 'city' => 'Aragua', 'active' => true, 'daily_budget' => 25.0, 'target' => 5.36, 'break_even' => 8.17,
                'target_source' => 'financial', 'target_no_cost' => false, 'windows' => ['3d' => $w, 'prev' => $w, '7d' => $w, 'yesterday' => $w, 'max' => $w]]]];
        $this->day = fn (int $ago) => now(config('app.timezone'))->subDays($ago)->toDateString();
        $this->profile = ['angles' => ['order' => ['POSP', 'NERV'], 'lost' => [], 'used' => ['POSP'], 'chosen' => null, 'floors' => []]];
    });

    it('3 batches perdidos seguidos: descansa, dice cuál sigue y el trabajo cambia de ángulo', function () {
        foreach ([[1, 'B0101', 6], [2, 'B0102', 4], [3, 'B0103', 2]] as [$set, $code, $ago]) {
            perdedorDescanso($this->meta, "1{$set}", $code, 'POSP', ($this->day)($ago));
        }
        $plan = decisionesDescanso($this->meta, $this->profile)['plan'];
        expect($plan['rests'])->toHaveCount(1)->and($plan['rests'][0]['angle'])->toBe('POSP')->and($plan['rests'][0]['codes'])->toBe(['B0101', 'B0102', 'B0103'])
            ->and($plan['next_angle'])->toBe('NERV')->and($plan['work']['rested'])->toBe(['POSP'])
            ->and($plan['next_work'][0])->toBe('Otro ángulo: POSP descansa porque perdieron sus últimos 3 batches.');
    });

    it('con 2 perdidos no descansa', function () {
        perdedorDescanso($this->meta, '11', 'B0101', 'POSP', ($this->day)(6));
        perdedorDescanso($this->meta, '12', 'B0102', 'POSP', ($this->day)(4));
        $plan = decisionesDescanso($this->meta, $this->profile)['plan'];
        expect($plan['rests'])->toBe([])->and($plan['work']['rested'])->toBe([]);
    });

    it('si el dueño lo eligió hoy, lo de antes no cuenta', function () {
        foreach ([[1, 'B0101', 6], [2, 'B0102', 4], [3, 'B0103', 2]] as [$set, $code, $ago]) {
            perdedorDescanso($this->meta, "1{$set}", $code, 'POSP', ($this->day)($ago));
        }
        $this->profile['angles']['floors'] = ['POSP' => ($this->day)(0)];
        expect(decisionesDescanso($this->meta, $this->profile)['plan']['rests'])->toBe([]);
    });
});
