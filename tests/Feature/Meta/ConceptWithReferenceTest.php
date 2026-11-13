<?php

use App\Http\Controllers\MetaAnalysisController;
use App\Models\CreativeBatch;
use App\Services\Meta\Queue\EditorWork;
use App\Services\Meta\Queue\IterationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .141 (el editor de Releev, 2026-11-13: "este es el que está pidiendo, revisa pues es el mismo no?"). B0123 (3 variaciones
// de hook de "creativo 2 07/11/2026") entró a Meta el 12/11 a las 10:31; 33 segundos después las reglas pidieron B0136. Como
// creativo 2 ya tenía una variación sin resultado (.133), no pidieron otra: pidieron un concepto nuevo con creativo 2 de
// referencia (lo que ya funciona en ese ángulo). Pero todo lo que tenía un creativo adjunto se leía como "variación de ese
// creativo": la tarjeta decía "3 variaciones de hook de creativo 2", al entregar cada pieza quedaba como su iteración y
// creativo 2 quedaba "ocupado" por B0136.

beforeEach(function () {
    Schema::create('users', fn ($t) => [$t->id(), $t->string('names')->nullable(), $t->string('surnames')->nullable()]);
    Schema::create('meta_creatives', fn ($t) => [$t->id(), $t->string('label')->nullable(), $t->string('creative_code')->nullable(), $t->string('tracking_id')->nullable()]);
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->string('status');
        $t->string('kind', 16)->default('iteration');
        $t->string('source')->nullable();
        $t->string('angle_code')->nullable();
        $t->unsignedInteger('testing_round')->nullable();
        $t->unsignedBigInteger('reference_creative_id')->nullable();
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_pieces', fn ($t) => [$t->id(), $t->unsignedBigInteger('creative_batch_id'), $t->unsignedInteger('position')->nullable(),
        $t->string('test')->nullable(), $t->string('style')->nullable(), $t->string('format')->nullable()]);

    DB::table('meta_creatives')->insert(['id' => 863, 'label' => 'creativo 2 07/11/2026', 'creative_code' => 'CREATIVO2']);
    $batch = function (int $id, int $number, string $status, string $kind, string $test) {
        DB::table('creative_batches')->insert(['id' => $id, 'product_id' => 10, 'number' => $number, 'status' => $status, 'kind' => $kind, 'source' => 'rules',
            'angle_code' => 'ARTR', 'reference_creative_id' => 863]);
        foreach ([1, 2, 3] as $i) {
            DB::table('creative_batch_pieces')->insert(['creative_batch_id' => $id, 'position' => $i, 'test' => $test]);
        }
    };
    $batch(33, 123, 'testing', 'iteration', 'hook');
    $batch(46, 136, 'approved', 'new_concept', 'new_concept');
});

it('B0136 es un concepto nuevo con creativo 2 de referencia: "3 piezas nuevas del ángulo", no "3 variaciones de hook"', function () {
    $task = fn (int $id) => EditorWork::task(CreativeBatch::with('pieces', 'reference')->find($id), 'Artritis');

    expect($task(46))->toBe(['title' => '3 piezas nuevas del ángulo ARTR · Artritis', 'detail' => 'Un concepto nuevo: una idea distinta a lo que ya hay de este ángulo.'])
        // la variación de verdad se ve igual que antes
        ->and($task(33)['title'])->toBe('3 variaciones de hook de creativo 2 07/11/2026')
        ->and(CreativeBatch::find(46)->iteratesId())->toBeNull()
        ->and(CreativeBatch::find(33)->iteratesId())->toBe(863);
});

it('una variación a la vez (.133) solo cuenta las variaciones de creativo 2, no el concepto nuevo que lo tiene de referencia', function () {
    // B0123 en Meta: creativo 2 está ocupado, y su última variación fue de hook (B0136 es más nuevo, pero no es una variación)
    expect(IterationRules::state([863]))->toBe(['busy' => [863], 'prev' => [863 => ['hook']]]);

    // Con B0123 ya medido, B0136 en producción no bloquea otra variación de creativo 2
    DB::table('creative_batches')->where('id', 33)->update(['status' => 'tested']);
    expect(IterationRules::state([863]))->toBe(['busy' => [], 'prev' => [863 => ['hook']]]);
});

it('en el análisis, la variación pedida de creativo 2 es B0123 (no B0136)', function () {
    $sent = (new ReflectionMethod(MetaAnalysisController::class, 'sent'))->invoke(null, [['creatives' => [['id' => 863]]]]);

    expect($sent)->toHaveKey(863)->and($sent[863]['code'])->toBe('B0123');
});
