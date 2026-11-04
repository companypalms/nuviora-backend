<?php

use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Meta\Products\TestingPhase;
use App\Services\Meta\Products\TestingRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .100 (dueño, 2026-11-04: "si tenemos uno en banca, ¿por qué se le está enviando para que haga 3 batch más?… nosotros
// lanzamos un batch a la vez"): el testeo sigue pidiendo sus 3 juntos, pero lo que ya está en la banca o haciéndose cuenta;
// y antes de poner en testeo un producto que ya vende, el sistema avisa. Lo de "un batch a la vez", en EditorDemandTest.

beforeEach(function () {
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
        $t->unsignedBigInteger('persona_id')->nullable();
        $t->unsignedInteger('position')->nullable();
        $t->unsignedBigInteger('alias_of')->nullable();
        $t->timestamps();
    });
    Schema::create('product_personas', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->string('name'), $t->timestamps()]);
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
        $t->string('source')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->string('effective_status')->nullable(), $t->timestamp('missing_since')->nullable()]);

    DB::table('products')->insert(['id' => 5, 'title' => 'FLEXISTEP RODILLERA', 'showable_name' => 'FlexiStep']);
    DB::table('product_profiles')->insert(['product_id' => 5, 'phase' => 'testing', 'testing_round' => 1]);
    foreach (['RODI', 'ESPA', 'CAMI', 'OFIC'] as $i => $code) {
        DB::table('ad_angles')->insert(['product_id' => 5, 'code' => $code, 'name' => ucfirst(strtolower($code)), 'position' => $i + 1]);
    }
});

/** Un batch del FlexiStep. */
function batchFlexi(int $number, string $status, ?string $angle, ?int $round = null): int
{
    return DB::table('creative_batches')->insertGetId(['product_id' => 5, 'number' => $number, 'status' => $status, 'angle_code' => $angle, 'testing_round' => $round, 'source' => 'imported']);
}

describe('.100: el testeo cuenta lo que ya está en la banca', function () {
    it('sin nada hecho, pide los 3 (uno por cada uno de los 3 ángulos más fuertes)', function () {
        expect(TestingPhase::adopt(5))->toBe(0)
            ->and(array_column(TestingPhase::round(5)['slots'], 'label'))->toBe(['Por pedir', 'Por pedir', 'Por pedir']);
    });

    it('uno en la banca de un ángulo del testeo ocupa su lugar: se piden 2', function () {
        $id = batchFlexi(103, 'bank', 'ESPA');
        expect(TestingPhase::adopt(5))->toBe(1);

        $st = TestingPhase::round(5);
        expect(array_column($st['slots'], 'angle'))->toBe(['RODI', 'ESPA', 'CAMI'])
            ->and(array_column($st['slots'], 'batch_id'))->toBe([null, $id, null])
            ->and($st['slots'][1]['label'])->toBe('En banca, listo para lanzar (B0103)')
            ->and(TestingRules::missing(array_column($st['slots'], 'angle'), ['ESPA']))->toHaveCount(2);
    });

    it('uno de otro ángulo (o sin ángulo) ocupa el último lugar libre: se prueba ese en vez del más débil', function () {
        batchFlexi(103, 'bank', null);
        batchFlexi(104, 'production', 'OFIC');
        expect(TestingPhase::adopt(5))->toBe(2);

        $st = TestingPhase::round(5);
        // el que está listo en la banca va primero: el último lugar; el que se está haciendo, el siguiente
        expect(array_column($st['slots'], 'angle'))->toBe(['RODI', 'OFIC', 'B0103'])
            ->and(array_column($st['slots'], 'label'))->toBe(['Por pedir', 'En producción (B0104)', 'En banca, listo para lanzar (B0103)']);
    });

    it('no entra lo de un ángulo que ya tiene su lugar, ni lo que ya salió en Meta, ni lo descartado; con los 3 lugares llenos, nada más', function () {
        batchFlexi(110, 'approved', 'RODI', 1);
        batchFlexi(111, 'approved', 'ESPA', 1);
        batchFlexi(103, 'bank', 'RODI');
        batchFlexi(104, 'testing', 'CAMI');
        batchFlexi(105, 'discarded', 'CAMI');
        expect(TestingPhase::adopt(5))->toBe(0)
            ->and(DB::table('creative_batches')->whereNull('testing_round')->count())->toBe(3);

        batchFlexi(112, 'approved', 'CAMI', 1);
        batchFlexi(106, 'bank', 'OFIC');
        expect(TestingPhase::adopt(5))->toBe(0);
    });

    it('su resultado cuenta: con los 3 testeados la ronda termina (el de sin ángulo, por su código)', function () {
        $won = ['verdicts' => [['key' => 'promising']]];
        $lost = ['verdicts' => [['key' => 'loser']]];
        DB::table('creative_batches')->insert([
            ['product_id' => 5, 'number' => 110, 'status' => 'tested', 'angle_code' => 'RODI', 'testing_round' => 1, 'result' => json_encode($lost)],
            ['product_id' => 5, 'number' => 111, 'status' => 'tested', 'angle_code' => 'ESPA', 'testing_round' => 1, 'result' => json_encode($lost)],
            ['product_id' => 5, 'number' => 103, 'status' => 'tested', 'angle_code' => null, 'testing_round' => 1, 'result' => json_encode($won)],
        ]);
        $st = TestingPhase::round(5);
        expect($st['outcome'])->toBe(['won' => ['B0103'], 'lost' => ['RODI', 'ESPA'], 'tie' => []]);
    });

    it('en una ronda de ángulos nuevos (la 2.ª), solo cuentan los de esos ángulos', function () {
        DB::table('product_profiles')->update(['testing_round' => 2]);
        batchFlexi(90, 'tested', 'RODI', 1);
        batchFlexi(91, 'tested', 'ESPA', 1);
        batchFlexi(92, 'tested', 'CAMI', 1);
        batchFlexi(103, 'bank', 'RODI');
        batchFlexi(104, 'bank', null);
        $id = batchFlexi(105, 'bank', 'OFIC');
        expect(TestingPhase::adopt(5))->toBe(1)
            ->and(array_column(TestingPhase::round(5)['slots'], 'batch_id'))->toBe([$id, null]); // OFIC (2 lugares: un solo ángulo nuevo)
    });

    it('en "Iterar lo que funciona" no toca nada', function () {
        DB::table('product_profiles')->update(['phase' => 'iterate']);
        batchFlexi(103, 'bank', 'ESPA');
        expect(TestingPhase::adopt(5))->toBe(0);
    });
});

describe('.100: el aviso antes de poner en testeo un producto que ya vende', function () {
    it('con campañas activas avisa (no lo bloquea); sin campañas, nada', function () {
        DB::table('meta_campaigns')->insert([
            ['product_id' => 5, 'effective_status' => 'ACTIVE', 'missing_since' => null],
            ['product_id' => 5, 'effective_status' => 'ACTIVE', 'missing_since' => null],
            ['product_id' => 5, 'effective_status' => 'PAUSED', 'missing_since' => null],
            ['product_id' => 5, 'effective_status' => 'ACTIVE', 'missing_since' => now()],
        ]);
        expect(TestingPhase::liveCampaigns(5))->toBe(2)
            ->and(TestingRules::sellingWarning('FlexiStep', 2))->toBe('FlexiStep ya tiene 2 campañas activas en Meta: el testeo pide hasta 3 batches juntos '
                . '(los que ya están en la banca cuentan). Si solo hacen falta creativos nuevos, déjalo en «Iterar lo que funciona».')
            ->and(TestingRules::sellingWarning('FlexiStep', 1))->toStartWith('FlexiStep ya tiene 1 campaña activa en Meta')
            ->and(TestingRules::sellingWarning('FlexiStep', 0))->toBeNull();
    });

    it('la tarjeta del asistente lo dice solo al pasarlo a testeo y si ya vende', function () {
        $ficha = ['what_it_is' => 'Rodillera', 'benefits' => 'Caminar sin dolor', 'mechanism' => 'Compresión', 'not_this' => null, 'key_info' => null, 'photos_url' => null];
        $persona = ['id' => 7, 'name' => 'Adulto mayor', 'who' => 'Mayor de 55', 'pain' => 'Rodilla', 'wants' => null, 'objections' => null];
        $angles = [['code' => 'RODI', 'name' => 'Rodilla', 'hypothesis' => null, 'persona' => null]];
        $args = ['producto' => 'FlexiStep', 'que_es' => null, 'para_que_sirve' => null, 'como_funciona' => null, 'lo_que_no_es' => null, 'info_clave' => null,
            'fotos' => null, 'buyer_personas' => [], 'quitar_buyer_personas' => [], 'angulos' => [], 'fase' => 'testeo'];
        $card = fn (string $before, int $campaigns) => ProductProfileTool::proposal(5, 'FlexiStep', $ficha, [$persona], $args, $angles, $before, [],
            ['create' => false, 'shopify' => null, 'campaigns' => $campaigns]);

        expect($card('iterate', 3)->warnings)->toContain(TestingRules::sellingWarning('FlexiStep', 3))
            ->and(implode(' ', $card('iterate', 0)->warnings))->not->toContain('ya tiene');
    });
});
