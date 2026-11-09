<?php

use App\Http\Controllers\MetaQueueController;
use App\Models\CreativeBatch;
use App\Services\Meta\Queue\EditorWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// .128 (dueño, 2026-11-09: "el sistema no tiene que recomendarle absolutamente nada en cuanto a qué debe hacer él en cada
// variación"; "el editor líder tampoco debe ver las recomendaciones"): lo que redactó la IA no le llega al editor ni al líder.

beforeEach(function () {
    Schema::create('users', fn ($t) => [$t->id(), $t->string('names'), $t->string('surnames')->nullable(), $t->timestamps()]);
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->unsignedInteger('position')->nullable();
        $t->string('status');
        $t->string('kind')->nullable();
        $t->string('source')->nullable();
        $t->string('angle_code')->nullable();
        foreach (['concept', 'audience', 'format', 'style', 'offer', 'discard_reason'] as $c) {
            $t->string($c)->nullable();
        }
        foreach (['hypothesis', 'goal', 'test_idea', 'improve', 'keep', 'deliverables', 'learning'] as $c) {
            $t->text($c)->nullable();
        }
        foreach (['reference_creative_id', 'learning_city_id', 'persona_id', 'editor_id', 'suggested_reference_id', 'created_by', 'approved_by', 'assigned_by', 'status_by'] as $c) {
            $t->unsignedBigInteger($c)->nullable();
        }
        $t->unsignedInteger('urgency')->nullable();
        $t->unsignedInteger('testing_round')->nullable();
        $t->date('due_date')->nullable();
        $t->date('proposed_on')->nullable();
        foreach (['approved_at', 'assigned_at', 'status_at', 'started_at', 'delivered_at', 'testing_at', 'tested_at'] as $c) {
            $t->timestamp($c)->nullable();
        }
        $t->json('result')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_pieces', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->unsignedInteger('number')->nullable();
        $t->unsignedInteger('position')->default(0);
        foreach (['format', 'hook', 'test', 'style', 'source', 'result'] as $c) {
            $t->string($c)->nullable();
        }
        $t->string('link', 1000)->nullable();
        $t->text('change')->nullable();
        $t->text('hypothesis')->nullable();
        $t->text('reason')->nullable();
        $t->unsignedBigInteger('file_id')->nullable();
        $t->unsignedBigInteger('source_reference_id')->nullable();
        $t->timestamp('result_at')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_deliveries', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->unsignedSmallInteger('version');
        $t->unsignedBigInteger('editor_id')->nullable();
        $t->string('concept')->nullable();
        $t->string('angle')->nullable();
        $t->text('notes')->nullable();
        $t->json('pieces');
        $t->string('review')->default('pending');
        $t->unsignedBigInteger('reviewed_by')->nullable();
        $t->timestamp('reviewed_at')->nullable();
        $t->text('comment')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_references', function ($t) {
        $t->id();
        $t->unsignedInteger('number')->unique();
        $t->string('link', 1000)->nullable();
        $t->string('type')->nullable();
        $t->string('why', 500)->nullable();
        $t->unsignedBigInteger('file_id')->nullable();
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
    Schema::create('meta_ads', function ($t) {
        $t->id();
        $t->string('meta_id');
        $t->string('name')->nullable();
        $t->unsignedBigInteger('meta_creative_ref_id')->nullable();
        foreach (['video_id', 'thumbnail_url', 'image_url', 'ad_title'] as $c) {
            $t->string($c)->nullable();
        }
        $t->text('ad_body')->nullable();
        $t->timestamp('created_time')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_video_transcripts', fn ($t) => [$t->id(), $t->string('video_id'), $t->string('status'), $t->string('hook')->nullable(),
        $t->text('text')->nullable(), $t->timestamp('transcribed_at')->nullable()]);
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
    Schema::create('product_profiles', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id')->unique();
        foreach (['what_it_is', 'benefits', 'mechanism', 'not_this', 'key_info', 'photos_url'] as $c) {
            $t->text($c)->nullable();
        }
        $t->string('phase')->nullable();
        $t->timestamps();
    });
    Schema::create('product_personas', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->string('name');
        $t->unsignedInteger('position')->nullable();
        $t->timestamps();
    });
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
    Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->json('value')->nullable(), $t->timestamps()]);
    Schema::create('product_ad_settings', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id')->unique();
        $t->string('mode')->default('normal');
        $t->string('note')->nullable();
        $t->text('proof')->nullable();
        $t->unsignedBigInteger('updated_by')->nullable();
        $t->timestamps();
    });

    DB::table('users')->insert(['id' => 10, 'names' => 'Lara']);
    DB::table('products')->insert(['id' => 5, 'title' => 'FlexiStep']);
    DB::table('meta_creatives')->insert(['id' => 36, 'tracking_id' => '5:CRE005', 'product_id' => 5, 'label' => 'CRE005-FEX-FASP-REF005', 'status' => 'winner']);

    // Lo que escribió la IA en B0103 (el encargo que confundió al editor)
    $this->ai = ['test_idea' => 'Partimos de una situación reconocible, sin comenzar con el nombre de un diagnóstico.', 'goal' => 'Llevarlas desde una situación cotidiana.',
        'keep' => 'El cierre.', 'improve' => 'El medio.', 'concept' => 'Situación cotidiana', 'audience' => 'Quien no busca una tobillera', 'hypothesis' => 'Creemos que abrir con alguien dudando sube el CTR.'];
    $this->batch = function (array $over, array $pieces) {
        $id = DB::table('creative_batches')->insertGetId($over + ['product_id' => 5, 'number' => 103, 'status' => 'production', 'editor_id' => 10] + $this->ai);
        foreach ($pieces as $i => $p) {
            DB::table('creative_batch_pieces')->insert($p + ['creative_batch_id' => $id, 'number' => 109 + $i, 'position' => $i + 1]);
        }

        return $id;
    };
    $this->seen = function (int $id, string $role) {
        $b = CreativeBatch::with(EditorWork::WITH)->whereKey($id)->get();

        return EditorWork::present($b, ['id' => 10, 'role' => $role])[0];
    };
    $this->idea = 'Una versión que empiece con alguien dudando si podrá caminar durante un paseo.';
});

$hookHow = 'Busca hooks ya validados de este mismo ángulo, en la biblioteca de anuncios o en redes sociales, y conéctalos a este creativo. Cambia solo los primeros 3 segundos.';

describe('Una variación que pidieron las reglas', function () use ($hookHow) {
    it('el encargo viejo (B0103) se limpia solo: el editor y el líder leen solo los textos fijos', function () use ($hookHow) {
        $id = ($this->batch)(['kind' => 'iteration', 'source' => 'rules', 'reference_creative_id' => 36],
            array_fill(0, 3, ['test' => 'iteration', 'change' => $this->idea]));
        $why = 'Queremos probar si variando el hook aumentamos la efectividad de este creativo.';

        foreach (['Editor de video', 'Editor líder'] as $role) {
            $b = ($this->seen)($id, $role);
            expect($b['task'])->toBe(['title' => '3 variaciones de hook de CRE005-FEX-FASP-REF005', 'detail' => $hookHow, 'hypothesis' => $why])
                ->and($b['hypothesis'])->toBe($why)
                ->and([$b['test_idea'], $b['goal'], $b['keep'], $b['improve'], $b['concept'], $b['audience']])->each->toBeNull()
                ->and(array_column($b['pieces'], 'change'))->each->toBeNull();
            // ni en el texto de "Copiar encargo"
            expect($b['brief'])->toContain("La hipótesis: {$why}")->toContain("Cómo hacerlo: {$hookHow}")
                ->not->toContain('dudando')->not->toContain('situación')->not->toContain('Ideas para')->not->toContain('Qué lleva cada pieza');
        }
    });

    it('la mezcla de un ganador sano dice qué lleva cada pieza, sin escribir qué hacer', function () {
        $id = ($this->batch)(['kind' => 'iteration', 'source' => 'rules', 'reference_creative_id' => 36], [['test' => 'winner_hook'],
            ['test' => 'format', 'style' => 'ugc', 'hypothesis' => 'Probamos si el mismo mensaje en formato UGC también vende.']]);
        $b = ($this->seen)($id, 'Editor de video');

        expect($b['task']['title'])->toBe('2 variaciones de CRE005-FEX-FASP-REF005: 1 de hook y 1 de formato')
            ->and($b['pieces'][1])->toMatchArray(['test_label' => 'Otro formato', 'style_label' => 'UGC', 'change' => null, 'hypothesis' => null])
            ->and($b['brief'])->toContain("Qué lleva cada pieza:\n1. C0109 — Hook nuevo en un ganador\n2. C0110 — Otro formato (UGC)");
    });
});

describe('Las piezas nuevas que pidieron las reglas', function () {
    it('queda una línea general; sin ideas por pieza. La hipótesis de la IA la sigue leyendo solo el líder', function () {
        $id = ($this->batch)(['kind' => 'new_concept', 'source' => 'rules', 'angle_code' => 'FASP'], array_fill(0, 2, ['test' => 'new_concept', 'change' => $this->idea]));
        $editor = ($this->seen)($id, 'Editor de video');

        expect($editor['test_idea'])->toBe($this->ai['test_idea'])
            ->and([$editor['goal'], $editor['keep'], $editor['improve'], $editor['concept'], $editor['audience']])->each->toBeNull()
            ->and(array_column($editor['pieces'], 'change'))->each->toBeNull()
            ->and($editor)->not->toHaveKey('hypothesis')
            ->and($editor['brief'])->not->toContain('dudando')->not->toContain('Qué lleva cada pieza');
        expect(($this->seen)($id, 'Editor líder')['hypothesis'])->toBe($this->ai['hypothesis']);
    });
});

describe('Lo que escribe el dueño', function () {
    it('un encargo hecho a mano se ve tal cual', function () {
        $id = ($this->batch)(['kind' => 'iteration', 'source' => 'owner', 'reference_creative_id' => 36], array_fill(0, 2, ['change' => 'Con la toma del parque']));
        $b = ($this->seen)($id, 'Editor de video');

        expect($b['goal'])->toBe($this->ai['goal'])->and($b['test_idea'])->toBe($this->ai['test_idea'])
            ->and(array_column($b['pieces'], 'change'))->toBe(['Con la toma del parque', 'Con la toma del parque'])
            ->and($b['brief'])->toContain("Qué lleva cada pieza:\n1. C0109 — Con la toma del parque");
    });

    it('si edita un texto de un encargo de las reglas, el editor lo ve; si solo cambia la fecha, no se destapa lo de la IA', function () {
        $id = ($this->batch)(['kind' => 'iteration', 'source' => 'rules', 'reference_creative_id' => 36], [['test' => 'hook', 'change' => $this->idea]]);
        $piece = DB::table('creative_batch_pieces')->where('creative_batch_id', $id)->value('id');
        $form = ['goal' => $this->ai['goal'], 'pieces' => [['id' => $piece, 'change' => $this->idea, 'format' => null]]];
        $update = fn (array $data) => app(MetaQueueController::class)->update(Request::create('/', 'PUT', $data), CreativeBatch::find($id));

        $shown = $update($form + ['due_date' => '2026-11-20'])->getData(true)['batch'];
        expect(CreativeBatch::find($id))->source->toBe('rules')->due_date->toDateString()->toBe('2026-11-20');
        // el "Copiar encargo" del dueño dice lo mismo que lee el editor
        expect($shown['brief'])->toContain('La hipótesis: Este creativo tuvo un hook rate bajo.')->not->toContain('dudando')->not->toContain('situación');

        $update(['goal' => 'Que dure más.'] + $form);
        expect(CreativeBatch::find($id)->source)->toBe('owner');
        expect(($this->seen)($id, 'Editor de video')['goal'])->toBe('Que dure más.');
    });

    it('también si cambia el texto de una pieza', function () {
        $id = ($this->batch)(['kind' => 'new_concept', 'source' => 'rules'], [['test' => 'new_concept']]);
        $piece = DB::table('creative_batch_pieces')->where('creative_batch_id', $id)->value('id');

        app(MetaQueueController::class)->update(Request::create('/', 'PUT', ['pieces' => [['id' => $piece, 'change' => 'Con la toma del parque', 'format' => null]]]), CreativeBatch::find($id));

        expect(CreativeBatch::find($id)->source)->toBe('owner')
            ->and(($this->seen)($id, 'Editor de video')['pieces'][0]['change'])->toBe('Con la toma del parque');
    });
});
