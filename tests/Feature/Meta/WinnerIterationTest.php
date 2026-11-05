<?php

use App\Models\CreativeBatch;
use App\Models\MetaCreative;
use App\Models\StoredFile;
use App\Services\Meta\MetaCreativeLinker;
use App\Services\Meta\Queue\BatchTask;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// .103 (el editor, 2026-11-05: "de este no sé qué creativo es el ganador y no sale el video como tal"): el encargo de
// iterar un ganador apuntaba a un creativo viejo que se quedó sin anuncios (sin nombre y sin video).

beforeEach(function () {
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('meta_campaigns', fn ($t) => [$t->id(), $t->string('meta_id'), $t->unsignedBigInteger('meta_ad_account_id')->nullable(),
        $t->unsignedBigInteger('product_id')->nullable(), $t->string('name')->nullable(), $t->timestamps()]);
    Schema::create('meta_adsets', function ($t) {
        $t->id();
        $t->unsignedBigInteger('meta_ad_account_id')->nullable();
        $t->string('meta_id');
        $t->string('campaign_meta_id');
        $t->string('name')->nullable();
        $t->string('batch_code')->nullable();
        $t->string('angle_code')->nullable();
        $t->string('concept')->nullable();
        $t->string('stage')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_ads', function ($t) {
        $t->id();
        $t->unsignedBigInteger('meta_ad_account_id')->nullable();
        $t->string('meta_id');
        $t->string('campaign_meta_id');
        $t->string('adset_meta_id');
        $t->string('name')->nullable();
        $t->string('creative_type')->nullable();
        $t->string('creative_tracking_id')->nullable();
        $t->string('tracking_id_source')->nullable();
        $t->unsignedBigInteger('meta_creative_ref_id')->nullable();
        foreach (['creative_code', 'angle_code', 'batch_code', 'ref_code', 'iteration_of', 'video_id', 'thumbnail_url', 'image_url', 'ad_title'] as $c) {
            $t->string($c)->nullable();
        }
        $t->text('ad_body')->nullable();
        $t->timestamp('created_time')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_creatives', function ($t) {
        $t->id();
        $t->string('tracking_id');
        $t->unsignedBigInteger('product_id')->nullable();
        foreach (['label', 'creative_code', 'angle_code', 'batch_code', 'iteration_of', 'type', 'status'] as $c) {
            $t->string($c)->nullable();
        }
        $t->timestamps();
    });
    Schema::create('ad_angles', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->string('code'), $t->string('name')->nullable(),
        $t->unsignedBigInteger('alias_of')->nullable(), $t->timestamps()]);
    Schema::create('creative_batches', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id'), $t->unsignedInteger('number')->nullable(),
        $t->string('status'), $t->string('angle_code')->nullable(), $t->unsignedBigInteger('reference_creative_id')->nullable(), $t->timestamps()]);
    Schema::create('creative_batch_pieces', fn ($t) => [$t->id(), $t->unsignedBigInteger('creative_batch_id'), $t->unsignedInteger('number')->nullable(),
        $t->unsignedBigInteger('file_id')->nullable(), $t->timestamps()]);
    Schema::create('meta_video_transcripts', fn ($t) => [$t->id(), $t->string('video_id'), $t->string('status'), $t->string('hook')->nullable(),
        $t->text('text')->nullable(), $t->timestamp('transcribed_at')->nullable()]);
    Schema::create('stored_files', function ($t) {
        $t->id();
        $t->string('driver');
        $t->string('path')->unique();
        $t->string('name');
        $t->string('mime');
        $t->unsignedBigInteger('size');
        $t->string('status')->default('pending');
        $t->string('kind');
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('creative_batch_piece_id')->nullable();
        $t->unsignedSmallInteger('version')->nullable();
        $t->unsignedBigInteger('uploaded_by')->nullable();
        $t->timestamps();
    });
    DB::table('products')->insert(['id' => 5, 'title' => 'FlexiStep Tobillera']);
    DB::table('meta_campaigns')->insert(['meta_id' => 'c1', 'meta_ad_account_id' => 1, 'product_id' => 5, 'name' => 'FLEXISTEP | CBO']);
    DB::table('meta_adsets')->insert(['meta_id' => 's1', 'campaign_meta_id' => 'c1', 'name' => 'B0005 FAS']);
    $this->ad = fn (string $id, string $name, ?int $creative = null, ?string $video = null) => DB::table('meta_ads')->insert(['meta_id' => $id, 'meta_ad_account_id' => 1,
        'campaign_meta_id' => 'c1', 'adset_meta_id' => 's1', 'name' => $name, 'meta_creative_ref_id' => $creative, 'video_id' => $video,
        'creative_type' => $video ? 'video' : 'image', 'created_time' => '2026-11-01 10:00:00']);
});

describe('El encargo sigue a su creativo', function () {
    it('cuando los anuncios de un creativo pasan a otro, los encargos que lo iteran pasan con ellos', function () {
        // el creativo viejo (de antes de la identidad producto + código) y su anuncio; su código no es el nombre del anuncio,
        // así que solo lo encuentra porque el anuncio se movió
        DB::table('meta_creatives')->insert(['id' => 96, 'tracking_id' => 'CRE002', 'status' => 'winner']);
        ($this->ad)('a1', 'CRE002-FLEX-FAS-BATCH005', 96, 'v1');
        DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 5, 'number' => 101, 'status' => 'approved', 'reference_creative_id' => 96]);

        $stats = (new MetaCreativeLinker())->run();

        $new = (int) DB::table('meta_ads')->where('meta_id', 'a1')->value('meta_creative_ref_id');
        expect($new)->not->toBe(96)
            ->and(CreativeBatch::find(1)->reference_creative_id)->toBe($new)
            ->and(MetaCreative::find($new)->label)->toBe('CRE002-FLEX-FAS-BATCH005')
            ->and($stats['references_moved'])->toBe(1);
    });

    it('si el creativo viejo todavía tiene anuncios, o se repartieron en dos, el encargo no se mueve', function () {
        DB::table('meta_creatives')->insert(['id' => 96, 'tracking_id' => '5:CRE009-FAS-B005']);
        ($this->ad)('a1', 'CRE009-FLEX-FAS-BATCH005', 96);
        ($this->ad)('a2', 'CRE010-FLEX-FAS-BATCH005', 96);
        DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 5, 'number' => 101, 'status' => 'approved', 'reference_creative_id' => 96]);

        (new MetaCreativeLinker())->run();

        expect(CreativeBatch::find(1)->reference_creative_id)->toBe(96);
    });

    it('el que ya apuntaba a un creativo vacío busca el de su producto con anuncios que se llaman como el código viejo', function () {
        DB::table('meta_creatives')->insert(['id' => 36, 'tracking_id' => 'CRE005-FEX-FASP-REF005', 'status' => 'winner']);
        ($this->ad)('a1', 'CRE005-FEX-FASP-REF005', null, 'v1');
        ($this->ad)('a2', 'CRE005 - FEX - FASP - REF005 - Copia', null, 'v2'); // la copia de Meta, en otra ciudad
        DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 5, 'number' => 103, 'status' => 'production', 'reference_creative_id' => 36]);

        (new MetaCreativeLinker())->run();

        $new = (int) DB::table('meta_ads')->where('meta_id', 'a1')->value('meta_creative_ref_id');
        expect(CreativeBatch::find(1)->reference_creative_id)->toBe($new)->and($new)->not->toBe(36);
    });

    it('si hay más de uno (o ninguno se llama igual), no adivina: lo elige el dueño', function () {
        DB::table('meta_creatives')->insert(['id' => 64, 'tracking_id' => 'CRE001', 'status' => 'winner']);
        ($this->ad)('a1', 'CRE001 - COM - GORD - REF001');
        ($this->ad)('a2', 'CRE001-CERV-GINE-BATCH1');
        DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 5, 'number' => 100, 'status' => 'approved', 'reference_creative_id' => 64]);

        (new MetaCreativeLinker())->run();

        expect(CreativeBatch::find(1)->reference_creative_id)->toBe(64)
            // y mientras tanto el encargo dice su código, no "el ganador"
            ->and(EditorWork::referenceName(MetaCreative::find(64)))->toBe('CRE001');
    });
});

describe('Lo que dice el encargo', function () {
    it('"3 iteraciones del ganador" (no "de el ganador") cuando no se sabe su nombre', function () {
        $hooks = array_fill(0, 3, ['test' => 'hook', 'style' => null, 'format' => null]);
        $free = array_fill(0, 3, ['test' => null, 'style' => null, 'format' => null]);

        expect(BatchTask::of(['kind' => 'iteration', 'angle_code' => null, 'angle_name' => null, 'iterates' => 'el ganador', 'pieces' => $free])['title'])
            ->toBe('3 iteraciones del ganador')
            ->and(BatchTask::of(['kind' => 'iteration', 'angle_code' => null, 'angle_name' => null, 'iterates' => 'el ganador', 'pieces' => $hooks])['title'])
            ->toBe('3 iteraciones del hook del ganador')
            ->and(BatchTask::of(['kind' => 'iteration', 'angle_code' => null, 'angle_name' => null, 'iterates' => 'CRE002-FLEX-FAS-BATCH005', 'pieces' => $free])['title'])
            ->toBe('3 iteraciones de CRE002-FLEX-FAS-BATCH005');
    });
});

describe('El ganador de antes del sistema', function () {
    // .104 (dueño, 2026-11-05: "con el nombre del anuncio y el ángulo es suficiente… lo busque en nuestro drive antiguo"):
    // Meta no deja bajar los videos (#10), así que no se le pide nada: el encargo dice dónde buscarlo
    beforeEach(function () {
        config(['files.driver' => 'local']);
        Storage::fake('local');
        DB::table('meta_creatives')->insert(['id' => 642, 'tracking_id' => '5:CRE002-FAS-B005', 'product_id' => 5, 'label' => 'CRE002-FLEX-FAS-BATCH005',
            'creative_code' => 'CRE002', 'angle_code' => 'FASP', 'status' => 'winner']);
        ($this->ad)('a1', 'CRE002-FLEX-FAS-BATCH005', 642, '1203');
        DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 5, 'number' => 101, 'status' => 'approved', 'reference_creative_id' => 642]);
    });

    it('dice su nombre completo y su ángulo para buscarlo en el Drive antiguo, sin llamar a Meta', function () {
        Http::fake();

        expect(EditorWork::iteration(CreativeBatch::find(1)))->toMatchArray(['code' => 'CRE002-FLEX-FAS-BATCH005', 'angle' => 'FASP', 'file' => null,
            'video_note' => 'Su video no está en el sistema: búscalo en el Drive antiguo como «CRE002-FLEX-FAS-BATCH005» (ángulo FASP).']);
        Http::assertNothingSent();
    });

    it('sin ángulo propio usa el del encargo; el ganador hecho en el sistema (C0457) trae su video y no dice nada', function () {
        DB::table('meta_creatives')->where('id', 642)->update(['angle_code' => null]);
        DB::table('creative_batches')->where('id', 1)->update(['angle_code' => 'FAS']);
        expect(EditorWork::iteration(CreativeBatch::find(1))['video_note'])->toEndWith('(ángulo FAS).');

        $file = StoredFile::create(['driver' => 'local', 'path' => 'creativos/p5/B0100/C0457.mp4', 'name' => 'C0457 FASP.mp4', 'mime' => 'video/mp4',
            'size' => 10, 'status' => 'ready', 'kind' => 'piece']);
        DB::table('creative_batch_pieces')->insert(['creative_batch_id' => 9, 'number' => 457, 'file_id' => $file->id]);
        DB::table('meta_creatives')->where('id', 642)->update(['creative_code' => 'C0457']);
        $it = EditorWork::iteration(CreativeBatch::find(1));
        expect($it['code'])->toBe('C0457')->and($it['file'])->toMatchArray(['name' => 'C0457 FASP.mp4', 'type' => 'video'])->and($it['video_note'])->toBeNull();
    });

    it('ya no existe la tarea que bajaba los videos de Meta', function () {
        expect(array_keys(Artisan::all()))->not->toContain('meta:winner-videos');
    });
});

describe('La transcripción de los videos', function () {
    // .105 (dueño, 2026-11-05: "apágala también por el momento"): Meta responde #10 a todos los videos
    it('la vuelta de las 07:30 está apagada; lo demás del programador sigue', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('meta:sync')->doesntExpectOutputToContain('meta:transcribe')->assertSuccessful();
    });
});
