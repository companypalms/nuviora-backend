<?php

use App\Models\AssistantMessage;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\CreativeReference;
use App\Models\StoredFile;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\Tools\ProductModeTool;
use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Files\FileStore;
use App\Services\Files\R2Store;
use App\Services\Files\SigV4;
use App\Services\Files\StoredFileRules;
use App\Services\Files\Uploads;
use App\Services\Meta\Products\ProductModes;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Queue\BatchQueue;
use App\Services\Meta\Queue\EditorGame;
use App\Services\Meta\Queue\EditorWork;
use App\Services\Meta\Queue\LateBatches;
use App\Services\Meta\Queue\QueueRules;
use App\Services\Meta\Queue\ReferenceLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// .90 (dueño, 2026-11-03: "es más práctico que se suban todos los archivos en el sistema en vez de utilizar Google
// Drive"): los archivos de los creativos en el almacén (Cloudflare R2 en el servidor; la carpeta privada en la PC de
// pruebas). Sin las llaves de R2, todo sigue con Drive.

describe('La firma de R2 (SigV4)', function () {
    it('da la misma firma que el ejemplo de la documentación de AWS', function () {
        // Las llaves de ejemplo de la documentación de AWS ("Authenticating Requests: Using Query Parameters"), no reales
        $url = SigV4::presign('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 86400, new DateTimeImmutable('2013-05-24T00:00:00Z'));

        expect($url)->toEndWith('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404');
    });

    it('codifica la ruta y ordena los parámetros', function () {
        expect(SigV4::path('/nuviora-prod/creativos/p1/B0110 POSP/C0457.mp4'))->toBe('/nuviora-prod/creativos/p1/B0110%20POSP/C0457.mp4')
            ->and(SigV4::query(['response-content-type' => 'video/mp4', 'X-Amz-Date' => '1']))->toBe('X-Amz-Date=1&response-content-type=video%2Fmp4');
    });

    it('R2: sube y muestra con enlaces firmados de su cuenta y su bucket', function () {
        config(['files.r2' => ['account' => 'cuenta123', 'key' => 'llave', 'secret' => 'secreto', 'bucket' => 'nuviora-test']]);
        $file = new StoredFile(['path' => 'creativos/p1/B0110-POSP/C0457-POSP-v1-abc.mp4', 'name' => 'C0457 POSP.mp4', 'mime' => 'video/mp4']);
        $store = new R2Store();

        expect($store->uploadUrl($file)['url'])->toStartWith('https://cuenta123.r2.cloudflarestorage.com/nuviora-test/creativos/p1/B0110-POSP/')
            ->and($store->uploadUrl($file)['headers'])->toBe(['Content-Type' => 'video/mp4'])
            ->and(urldecode($store->viewUrl($file, true)))->toContain('attachment; filename="C0457 POSP.mp4"')
            ->and(urldecode($store->viewUrl($file, false)))->toContain('response-content-type=video/mp4');
    });

    it('R2: el tamaño de lo que llegó (o null si no está)', function () {
        config(['files.r2' => ['account' => 'cuenta123', 'key' => 'llave', 'secret' => 'secreto', 'bucket' => 'nuviora-test']]);
        Http::fake(fn ($request) => str_contains($request->url(), 'falta') ? Http::response('', 404) : Http::response('', 200, ['Content-Length' => '5242880']));

        expect((new R2Store())->size(new StoredFile(['path' => 'a/hay.mp4'])))->toBe(5242880)
            ->and((new R2Store())->size(new StoredFile(['path' => 'a/falta.mp4'])))->toBeNull();
    });
});

describe('Qué se puede subir', function () {
    it('una pieza es un video o una imagen; las fotos, imágenes; la estrategia, un documento', function () {
        expect(StoredFileRules::problem('piece', 'video/mp4', 80 * 1024 * 1024))->toBeNull()
            ->and(StoredFileRules::problem('piece', 'image/png', 1000))->toBeNull()
            ->and(StoredFileRules::problem('piece', 'application/pdf', 1000))->toContain('video')
            ->and(StoredFileRules::problem('piece', 'video/mp4', StoredFileRules::VIDEO_MAX + 1))->toContain('hasta 1 GB')
            ->and(StoredFileRules::problem('photo', 'video/mp4', 1000))->toContain('jpg, png o webp')
            ->and(StoredFileRules::problem('strategy', 'application/pdf', 1000))->toBeNull()
            ->and(StoredFileRules::problem('strategy', 'image/jpeg', 1000))->toContain('pdf');
    });

    it('si el navegador no dice el tipo, sale de la extensión (.mov en Windows)', function () {
        expect(StoredFileRules::mime('', 'final.MOV'))->toBe('video/quicktime')
            ->and(StoredFileRules::mime('application/octet-stream', 'foto.jpeg'))->toBe('image/jpeg')
            ->and(StoredFileRules::mime('video/mp4', 'x.bin'))->toBe('video/mp4');
    });

    it('cada pieza queda guardada con su batch, su anuncio y su versión, sin espacios ni acentos', function () {
        expect(StoredFileRules::path('piece', 12, 'B0110 POSP', 'C0457 POSP.mp4', 2, 'mp4'))->toMatch('#^creativos/p12/B0110-POSP/C0457-POSP-v2-[a-z0-9]{8}\.mp4$#')
            ->and(StoredFileRules::path('photo', 12, null, 'Frente.jpg', null, 'jpg'))->toMatch('#^productos/p12/fotos/[a-z0-9]{12}\.jpg$#')
            ->and(StoredFileRules::path('strategy', 12, null, 'Estrategia Termafit.pdf', null, 'pdf'))->toMatch('#^productos/p12/estrategia/Estrategia-Termafit-[a-z0-9]{8}\.pdf$#');
    });
});

describe('Dónde se guarda', function () {
    it('sin las llaves de R2 está apagado (las entregas siguen con Drive): nunca llena el disco del servidor sin querer', function () {
        config(['files.driver' => null, 'files.r2' => ['account' => null, 'key' => null, 'secret' => null, 'bucket' => null]]);
        expect(FileStore::driver())->toBe('off')->and(FileStore::on())->toBeFalse()->and(FileStore::current())->toBeNull();

        config(['files.r2' => ['account' => 'c', 'key' => 'k', 'secret' => 's', 'bucket' => 'b']]);
        expect(FileStore::driver())->toBe('r2');

        config(['files.driver' => 'local']);
        expect(FileStore::driver())->toBe('local');

        config(['files.driver' => 'r2', 'files.r2' => ['account' => null, 'key' => null, 'secret' => null, 'bucket' => null]]);
        expect(FileStore::driver())->toBe('off'); // pidió R2 pero faltan llaves
    });

    it('subir, confirmar y ver: solo las rutas de los editores y del dueño, y la de la PC de pruebas firmada', function () {
        $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri());
        expect($routes->get('POST api/editor/queue/{batch}/pieces/{piece}/upload')->gatherMiddleware())->toContain('role:Editor líder,Editor de video')
            ->and($routes->get('POST api/meta/product-profiles/{product}/files')->gatherMiddleware())->toContain('role:Admin')
            ->and($routes->get('DELETE api/meta/files/{file}')->gatherMiddleware())->toContain('role:Admin')
            ->and($routes->get('POST api/meta/files/check')->gatherMiddleware())->toContain('role:Admin')
            ->and($routes->first(fn ($r, $k) => str_ends_with($k, ' api/files/local/{file}'))->methods())->toContain('PUT')->toContain('GET')
            ->and($routes->first(fn ($r, $k) => str_ends_with($k, ' api/files/local/{file}'))->gatherMiddleware())->not->toContain('auth:sanctum');
    });
});

describe('Las entregas con archivo', function () {
    beforeEach(function () {
        config(['files.driver' => 'local']);
        Storage::fake('local');
        Schema::create('roles', fn ($t) => [$t->id(), $t->string('description')]);
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('names');
            $t->string('surnames')->nullable();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->timestamp('deactivated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('products', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->default(0), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
        Schema::create('creative_batches', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedInteger('number')->nullable();
            $t->string('status');
            $t->string('kind')->nullable();
            $t->string('angle_code')->nullable();
            $t->unsignedBigInteger('reference_creative_id')->nullable();
            $t->unsignedBigInteger('editor_id')->nullable();
            $t->unsignedBigInteger('suggested_reference_id')->nullable();
            $t->string('source')->nullable();
            $t->string('concept')->nullable();
            $t->string('format')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->string('discard_reason')->nullable();
            $t->date('due_date')->nullable();
            $t->timestamp('assigned_at')->nullable();
            $t->unsignedBigInteger('assigned_by')->nullable();
            $t->timestamp('status_at')->nullable();
            $t->unsignedBigInteger('status_by')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('creative_batch_pieces', function ($t) {
            $t->id();
            $t->unsignedBigInteger('creative_batch_id');
            $t->unsignedInteger('number')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->string('format')->nullable();
            $t->string('link', 1000)->nullable();
            $t->unsignedBigInteger('file_id')->nullable();
            $t->string('source')->nullable();
            $t->unsignedBigInteger('source_reference_id')->nullable();
            $t->string('result')->nullable();
            $t->timestamp('result_at')->nullable();
            $t->string('hook')->nullable();
            $t->string('test')->nullable(); // .99: qué prueba (las variaciones de un creativo cargadas)
            $t->string('style')->nullable();
            $t->timestamps();
        });
        // .92: la biblioteca muestra el nombre del ángulo (TestingPhase::anglesMany)
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
        Schema::create('creative_references', function ($t) {
            $t->id();
            $t->unsignedInteger('number')->unique();
            $t->string('link', 1000)->nullable();
            $t->string('link_key', 64)->nullable();
            $t->string('type')->nullable();
            $t->string('why', 500)->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('angle_code')->nullable();
            $t->string('angle_other', 160)->nullable();
            $t->unsignedBigInteger('file_id')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by')->nullable();
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
        // .99: el estado del producto (en pausa, en liquidación o abandonado deja sus encargos en espera)
        Schema::create('product_ad_settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id')->unique();
            $t->string('mode')->default('normal');
            $t->string('note')->nullable();
            $t->text('proof')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        DB::table('roles')->insert([['id' => 1, 'description' => 'Editor de video'], ['id' => 2, 'description' => 'Admin']]);
        DB::table('users')->insert([['id' => 10, 'names' => 'Lara', 'role_id' => 1], ['id' => 11, 'names' => 'Otro', 'role_id' => 1], ['id' => 1, 'names' => 'Dueño', 'role_id' => 2]]);
        DB::table('products')->insert(['id' => 5, 'title' => 'Termafit']);
        DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 5, 'number' => 110, 'status' => 'production', 'angle_code' => 'POSP', 'editor_id' => 10]);
        DB::table('creative_batch_pieces')->insert([['id' => 21, 'creative_batch_id' => 1, 'number' => 457, 'position' => 1], ['id' => 22, 'creative_batch_id' => 1, 'number' => 458, 'position' => 2]]);

        // El navegador sube con el enlace firmado y avisa que terminó
        $this->upload = function (int $pieceId, string $content = 'video de prueba', ?int $userId = 10): StoredFile {
            $start = (new EditorWork())->uploadPiece(CreativeBatch::find(1), CreativeBatchPiece::find($pieceId), User::find($userId), 'final.mp4', 'video/mp4', strlen($content));
            $this->call('PUT', $start['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'video/mp4'], $content)->assertNoContent();

            return app(Uploads::class)->confirm(StoredFile::find($start['file']['id']), User::find($userId));
        };
        // .92: cada pieza dice de dónde salió (si la prueba no lo dice, "idea propia")
        $this->data = fn (array $pieces) => ['concept' => 'Antes y después', 'angle' => 'Postparto', 'pieces' => array_map(fn ($p) => $p + ['source' => 'own'], $pieces)];
    });

    it('la pieza se sube con el nombre de su anuncio, se confirma y se entrega sin Drive', function () {
        $a = ($this->upload)(21);
        $b = ($this->upload)(22, 'otro video');
        expect($a->status)->toBe('ready')->and($a->name)->toBe('C0457 POSP.mp4')->and($a->size)->toBe(strlen('video de prueba'))
            ->and($a->path)->toStartWith('creativos/p5/B0110-POSP/C0457-POSP-v1-');

        $delivery = (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10),
            ($this->data)([['id' => 21, 'file_id' => $a->id, 'hook' => 'Así quedé'], ['id' => 22, 'file_id' => $b->id, 'hook' => 'Mira esto']]));

        expect($delivery->pieces[0])->toMatchArray(['piece_id' => 21, 'file_id' => $a->id, 'link' => null])
            ->and(CreativeBatchPiece::find(21)->file_id)->toBe($a->id)
            ->and(CreativeBatch::find(1)->status)->toBe('review');
        $fields = EditorWork::fields(CreativeBatch::with('deliveries')->find(1), ['id' => 1, 'role' => 'Admin']);
        expect($fields['deliveries'][0]['pieces'][0]['file'])->toMatchArray(['name' => 'C0457 POSP.mp4', 'type' => 'video'])
            ->and($fields['deliveries'][0]['pieces'][0]['file']['url'])->toContain('/api/files/local/' . $a->id);
    });

    it('.102: los videos de un batch para la misión de lanzar: su nombre de Meta, ver y bajar; los de Drive, con su enlace', function () {
        $a = ($this->upload)(21);
        DB::table('creative_batch_pieces')->where('id', 21)->update(['file_id' => $a->id]);
        DB::table('creative_batch_pieces')->where('id', 22)->update(['link' => 'https://drive.google.com/x']);
        DB::table('creative_batches')->update(['status' => 'bank']);
        $videos = fn (array $codes) => (new \App\Http\Controllers\MetaAnalysisController())->videos(\Illuminate\Http\Request::create('/', 'GET', ['codes' => $codes]))->getData(true);

        $r = $videos(['B0110', 'B0999']);
        expect($r['batches'])->toHaveCount(1)
            ->and($r['batches'][0])->toMatchArray(['code' => 'B0110', 'adset' => 'B0110 POSP', 'status' => 'bank', 'status_label' => QueueRules::STATUSES['bank']])
            ->and($r['batches'][0]['pieces'][0]['name'])->toBe('C0457 POSP')
            ->and($r['batches'][0]['pieces'][0]['file']['name'])->toBe('C0457 POSP.mp4')
            ->and($r['batches'][0]['pieces'][0]['file']['download_url'])->toContain('/api/files/local/' . $a->id)->toContain('download=1')
            ->and($r['batches'][0]['pieces'][1])->toMatchArray(['name' => 'C0458 POSP', 'file' => null, 'link' => 'https://drive.google.com/x'])
            // lo que no es un código de batch no pasa
            ->and(fn () => $videos(['DROP TABLE']))->toThrow(ValidationException::class)
            ->and(fn () => $videos([]))->toThrow(ValidationException::class);
        // solo el dueño
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/meta/analysis/videos');
        expect($route->gatherMiddleware())->toContain('role:Admin');
    });

    it('.91: el primer archivo empieza el encargo solo (sin el botón "Empezar")', function () {
        DB::table('creative_batches')->update(['status' => 'approved', 'started_at' => null]);
        ($this->upload)(21);

        expect(CreativeBatch::find(1))->status->toBe('production')->and(CreativeBatch::find(1)->started_at)->not->toBeNull();
    });

    it('en una corrección, la pieza que no se volvió a subir sigue con su archivo', function () {
        $a = ($this->upload)(21);
        $b = ($this->upload)(22);
        (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), ($this->data)([['id' => 21, 'file_id' => $a->id, 'hook' => 'h'], ['id' => 22, 'file_id' => $b->id, 'hook' => 'h']]));
        DB::table('creative_batch_deliveries')->update(['review' => 'returned']);
        DB::table('creative_batches')->update(['status' => 'production']);

        $c = ($this->upload)(22, 'video corregido');
        expect($c->version)->toBe(2)->and($c->path)->toContain('-v2-');
        $v2 = (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), ($this->data)([['id' => 21, 'hook' => 'h'], ['id' => 22, 'file_id' => $c->id, 'hook' => 'h']]));

        expect(array_column($v2->pieces, 'file_id'))->toBe([$a->id, $c->id]);
    });

    it('sin archivo no se entrega, y el archivo de otra pieza no sirve', function () {
        $a = ($this->upload)(21);
        expect(fn () => (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10),
            ($this->data)([['id' => 21, 'file_id' => $a->id, 'hook' => 'h'], ['id' => 22, 'file_id' => $a->id, 'hook' => 'h']])))
            ->toThrow(ValidationException::class, 'C0458: sube el video o la imagen de esta pieza.');
    });

    it('lo que no llegó al almacén no queda listo, y solo el que subió lo confirma', function () {
        $start = (new EditorWork())->uploadPiece(CreativeBatch::find(1), CreativeBatchPiece::find(21), User::find(10), 'final.mp4', 'video/mp4', 100);
        $file = StoredFile::find($start['file']['id']);

        expect(fn () => app(Uploads::class)->confirm($file, User::find(10)))->toThrow(ValidationException::class, 'El archivo no llegó: vuelve a subirlo.')
            ->and(fn () => app(Uploads::class)->confirm($file, User::find(11)))->toThrow(HttpResponseException::class);
    });

    it('un editor no sube archivos a un encargo que no es suyo, ni un PDF como pieza', function () {
        expect(fn () => (new EditorWork())->uploadPiece(CreativeBatch::find(1), CreativeBatchPiece::find(21), User::find(11), 'x.mp4', 'video/mp4', 10))
            ->toThrow(HttpResponseException::class)
            ->and(fn () => (new EditorWork())->uploadPiece(CreativeBatch::find(1), CreativeBatchPiece::find(21), User::find(10), 'guion.pdf', 'application/pdf', 10))
            ->toThrow(ValidationException::class, 'Sube el video');
    });

    it('el enlace firmado no se puede usar dos veces ni sin firma', function () {
        $a = ($this->upload)(21);
        $this->call('PUT', '/api/files/local/' . $a->id, [], [], [], [], 'otra cosa')->assertForbidden();
        $this->get(StoredFile::find($a->id)->payload()['url'])->assertOk();
    });

    it('apagado, se entrega con el enlace de Drive como siempre', function () {
        config(['files.driver' => 'off']);
        $link = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/view';
        $d = (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), ($this->data)([['id' => 21, 'link' => $link, 'hook' => 'h'], ['id' => 22, 'link' => $link, 'hook' => 'h']]));

        expect($d->pieces[0])->toMatchArray(['link' => $link, 'file_id' => null]);
    });

    it('"Probar el almacén" guarda, lee y borra un archivo de prueba', function () {
        expect(app(Uploads::class)->check())->toMatchArray(['ok' => true, 'driver' => 'local']);
        config(['files.driver' => 'off']);
        expect(app(Uploads::class)->check())->toMatchArray(['ok' => false])->and(app(Uploads::class)->check()['message'])->toContain('Drive');
    });

    it('.92: un editor sube una referencia (video, link, tipo y por qué) y queda REF0001; el mismo link avisa', function () {
        $start = app(Uploads::class)->start(['kind' => 'reference', 'name' => 'ref.mp4'], 'video/mp4', 9, User::find(10));
        $this->call('PUT', $start['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'video/mp4'], 'referenci')->assertNoContent();
        app(Uploads::class)->confirm(StoredFile::find($start['file']['id']), User::find(10));
        $data = ['file_id' => $start['file']['id'], 'link' => 'https://www.facebook.com/ads/library/?id=777', 'type' => 'testimonial',
            'why' => 'El hook de la balanza engancha en 2 segundos', 'product_id' => 5, 'angle_code' => 'posp'];
        DB::table('ad_angles')->insert(['product_id' => 5, 'code' => 'POSP', 'name' => 'Posparto', 'position' => 1]);
        $ref = app(ReferenceLibrary::class)->create($data, User::find(10));
        $again = app(Uploads::class)->start(['kind' => 'reference', 'name' => 'ref2.mp4'], 'video/mp4', 9, User::find(10));
        $this->call('PUT', $again['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'video/mp4'], 'referenci')->assertNoContent();
        $second = app(Uploads::class)->confirm(StoredFile::find($again['file']['id']), User::find(10))->id;

        expect($ref->code())->toBe('REF0001')->and($ref->angle_code)->toBe('POSP')->and(StoredFile::find($start['file']['id'])->name)->toBe('REF0001.mp4')
            // .97: otro archivo con el mismo link (el mismo archivo ya avisa que es REF0001)
            ->and(fn () => app(ReferenceLibrary::class)->create(['link' => 'https://facebook.com/ads/library?id=777', 'file_id' => $second] + $data, User::find(10)))
            ->toThrow(ValidationException::class, 'Esa referencia ya está en la biblioteca: REF0001.');
    });

    /** .97: un archivo de referencia ya subido y confirmado (video o imagen). */
    $refFile = function (string $name, string $mime, int $userId = 10): StoredFile {
        $start = app(Uploads::class)->start(['kind' => 'reference', 'name' => $name], $mime, 9, User::find($userId));
        test()->call('PUT', $start['upload']['url'], [], [], [], ['CONTENT_TYPE' => $mime], 'contenido')->assertNoContent();

        return app(Uploads::class)->confirm(StoredFile::find($start['file']['id']), User::find($userId));
    };
    /** Los errores de una ValidationException, por casilla. */
    $errorsOf = function (callable $fn): array {
        try {
            $fn();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }

        return [];
    };

    it('.97: el producto y el ángulo son obligatorios; el ángulo es de la ficha o «Otro ángulo» con su frase', function () use ($refFile, $errorsOf) {
        DB::table('ad_angles')->insert(['product_id' => 5, 'code' => 'POSP', 'name' => 'Posparto', 'position' => 1]);
        $base = ['link' => 'https://www.tiktok.com/@marca/video/1', 'type' => 'ugc', 'why' => 'El ritmo'];
        $lib = app(ReferenceLibrary::class);

        $none = $errorsOf(fn () => $lib->create($base + ['file_id' => $refFile('a.mp4', 'video/mp4')->id], User::find(10)));
        $noAngle = $errorsOf(fn () => $lib->create($base + ['file_id' => $refFile('b.mp4', 'video/mp4')->id, 'product_id' => 5], User::find(10)));
        $foreign = $errorsOf(fn () => $lib->create($base + ['file_id' => $refFile('c.mp4', 'video/mp4')->id, 'product_id' => 5, 'angle_code' => 'GYMF'], User::find(10)));
        $otherEmpty = $errorsOf(fn () => $lib->create($base + ['file_id' => $refFile('d.mp4', 'video/mp4')->id, 'product_id' => 5, 'angle_code' => 'other'], User::find(10)));
        expect($none['product_id'])->toBe('Elige el producto.')
            ->and($noAngle['angle_code'])->toContain('Elige el ángulo')
            ->and($foreign['angle_code'])->toContain('no es de este producto')
            ->and($otherEmpty['angle_other'])->toContain('cuál es el otro ángulo');

        $ref = $lib->create($base + ['file_id' => $refFile('e.png', 'image/png')->id, 'product_id' => 5, 'angle_code' => 'other',
            'angle_other' => 'Dolor de espalda en la oficina'], User::find(10));
        $shown = ReferenceLibrary::present(collect([$ref->fresh(['file', 'product', 'uploader'])]), ['id' => 10, 'role' => 'Editor de video'])[0];
        expect($ref->angle_code)->toBeNull()->and($ref->angle_other)->toBe('Dolor de espalda en la oficina')
            ->and($shown)->toMatchArray(['format' => 'image', 'angle_other' => 'Dolor de espalda en la oficina', 'type_label' => 'UGC'])
            ->and($shown['file']['name'])->toBe('REF0001.png');
    });

    it('.97: subir varias: el producto y el ángulo una vez, el link opcional; quedan REF seguidas con su nombre', function () use ($refFile, $errorsOf) {
        DB::table('ad_angles')->insert(['product_id' => 5, 'code' => 'POSP', 'name' => 'Posparto', 'position' => 1]);
        CreativeReference::create(['number' => 11, 'link' => 'https://x.com/viejo', 'link_key' => ReferenceLibrary::linkKey('https://x.com/viejo'), 'type' => 'ugc', 'why' => 'w']);
        $common = ['product_id' => 5, 'angle_code' => 'POSP'];
        $lib = app(ReferenceLibrary::class);
        [$a, $b, $c] = [$refFile('1.mp4', 'video/mp4'), $refFile('2.mp4', 'video/mp4'), $refFile('3.jpg', 'image/jpeg')];

        // un link que ya está y uno repetido en la misma carga: error en su fila y no se guarda ninguna
        $errors = $errorsOf(fn () => $lib->createMany($common, [['file_id' => $a->id, 'link' => 'https://x.com/nuevo'],
            ['file_id' => $b->id, 'link' => 'https://www.x.com/nuevo/'], ['file_id' => $c->id, 'link' => 'https://x.com/viejo']], User::find(10)));
        expect($errors)->toBe(['items.1.link' => 'Ese link está repetido en esta carga.', 'items.2.link' => 'Ya está en la biblioteca: REF0011.'])
            ->and(CreativeReference::count())->toBe(1);

        // un archivo de otra persona tampoco
        $other = $refFile('4.mp4', 'video/mp4', 11);
        expect($errorsOf(fn () => $lib->createMany($common, [['file_id' => $other->id]], User::find(10))))->toHaveKey('items.0.file');

        $refs = $lib->createMany($common + ['type' => 'demo'], [['file_id' => $a->id, 'link' => 'https://x.com/nuevo'], ['file_id' => $b->id], ['file_id' => $c->id, 'link' => '']], User::find(10));
        expect(array_map(fn ($r) => $r->code(), $refs))->toBe(['REF0012', 'REF0013', 'REF0014'])
            ->and($refs[1]->link)->toBeNull()->and($refs[1]->why)->toBeNull()->and($refs[1]->type)->toBe('demo')->and($refs[2]->angle_code)->toBe('POSP')
            ->and(StoredFile::find($c->id)->name)->toBe('REF0014.jpg')
            // el mismo archivo no entra dos veces (por ejemplo, si se reintenta)
            ->and($errorsOf(fn () => $lib->createMany($common, [['file_id' => $a->id]], User::find(10)))['items.0.file'])->toBe('Ese archivo ya está en la biblioteca: REF0012.');

        // el filtro de formato
        $who = ['id' => 10, 'role' => 'Editor de video'];
        expect(array_column(ReferenceLibrary::list($who, null, null, 'image'), 'code'))->toBe(['REF0014'])
            ->and(array_column(ReferenceLibrary::list($who, null, null, 'video'), 'code'))->toBe(['REF0013', 'REF0012']);
    });

    it('.97: subir varias, hasta 20', function () use ($errorsOf) {
        $items = array_map(fn ($i) => ['file_id' => $i], range(1, 21));
        expect($errorsOf(fn () => app(ReferenceLibrary::class)->createMany(['product_id' => 5, 'angle_code' => 'other', 'angle_other' => 'x'], $items, User::find(10)))['items'])
            ->toBe('Puedes subir hasta 20 referencias a la vez.');
    });

    it('.92: cada pieza dice de dónde salió; sin decirlo no se entrega; la referencia cuenta sus usos y sus resultados', function () {
        $ref = CreativeReference::create(['number' => 3, 'link' => 'https://x.com/a', 'link_key' => 'k', 'type' => 'ugc', 'why' => 'Buen hook', 'product_id' => null]);
        $a = ($this->upload)(21);
        $b = ($this->upload)(22);
        $base = ['concept' => 'C', 'angle' => 'A'];
        expect(fn () => (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), $base + ['pieces' => [
            ['id' => 21, 'file_id' => $a->id, 'hook' => 'h', 'source' => 'reference', 'reference_id' => $ref->id],
            ['id' => 22, 'file_id' => $b->id, 'hook' => 'h']]]))
            ->toThrow(ValidationException::class, 'C0458: di de dónde salió (una referencia de la biblioteca o idea propia).');

        (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), $base + ['pieces' => [
            ['id' => 21, 'file_id' => $a->id, 'hook' => 'h', 'source' => 'reference', 'reference_id' => $ref->id],
            ['id' => 22, 'file_id' => $b->id, 'hook' => 'h', 'source' => 'own']]]);
        DB::table('creative_batch_pieces')->where('id', 21)->update(['result' => 'Ganó']);

        $editor = ReferenceLibrary::list(['id' => 10, 'role' => 'Editor de video'])[0];
        $leader = ReferenceLibrary::list(['id' => 1, 'role' => 'Admin'])[0];
        expect($editor)->toMatchArray(['code' => 'REF0003', 'uses' => 1])->not->toHaveKey('results')
            ->and($leader['results'])->toBe(['won' => 1, 'lost' => 0, 'tie' => 0])
            ->and(CreativeBatchPiece::find(21))->source->toBe('reference')->source_reference_id->toBe($ref->id);

        $uses = ReferenceLibrary::uses($ref, ['id' => 10, 'role' => 'Editor de video']);
        expect($uses)->toHaveCount(1)->and($uses[0])->toMatchArray(['code' => 'C0457', 'ad_name' => 'C0457 POSP', 'batch' => 'B0110'])->not->toHaveKey('result')
            ->and(ReferenceLibrary::uses($ref, ['id' => 1, 'role' => 'Admin'])[0]['result'])->toBe('Ganó');
    });

    it('.92: solo el líder o el dueño quitan una referencia; la iteración solo vale en el encargo de un ganador', function () {
        $ref = CreativeReference::create(['number' => 1, 'link' => 'https://x.com/a', 'link_key' => 'k', 'type' => 'ugc', 'why' => 'w']);
        expect(fn () => app(ReferenceLibrary::class)->archive($ref, User::find(10)))->toThrow(HttpResponseException::class);
        app(ReferenceLibrary::class)->archive($ref, User::find(1));
        expect(ReferenceLibrary::list(['id' => 1, 'role' => 'Admin']))->toBe([]);

        $a = ($this->upload)(21);
        $b = ($this->upload)(22);
        expect(fn () => (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), ['concept' => 'C', 'angle' => 'A', 'pieces' => [
            ['id' => 21, 'file_id' => $a->id, 'hook' => 'h', 'source' => 'iteration'], ['id' => 22, 'file_id' => $b->id, 'hook' => 'h', 'source' => 'own']]]))
            ->toThrow(ValidationException::class, 'este encargo no es la iteración de un ganador');
    });

    it('.98: en el encargo de iterar un ganador, cada pieza queda sola como iteración (no la elige el editor)', function () {
        DB::table('creative_batches')->where('id', 1)->update(['kind' => 'iteration', 'reference_creative_id' => 7]);
        $ref = CreativeReference::create(['number' => 1, 'link' => 'https://x.com/a', 'link_key' => 'k', 'type' => 'ugc', 'why' => 'w']);
        $a = ($this->upload)(21);
        $b = ($this->upload)(22);

        (new EditorWork())->deliver(CreativeBatch::find(1), User::find(10), ['concept' => 'C', 'angle' => 'A', 'pieces' => [
            ['id' => 21, 'file_id' => $a->id, 'hook' => 'h', 'source' => 'reference', 'reference_id' => $ref->id],
            ['id' => 22, 'file_id' => $b->id, 'hook' => 'h']]]);

        expect(CreativeBatchPiece::find(21))->source->toBe('iteration')->source_reference_id->toBeNull()
            ->and(CreativeBatchPiece::find(22))->source->toBe('iteration');
    });

    it('.93: al revisar una iteración, el video del ganador (C0457 es la pieza 457) o, si es viejo, su miniatura y sus textos sin precios', function () {
        Schema::create('meta_creatives', fn ($t) => [$t->id(), $t->string('label')->nullable(), $t->string('creative_code')->nullable(), $t->timestamps()]);
        Schema::create('meta_ads', function ($t) {
            $t->id();
            $t->unsignedBigInteger('meta_creative_ref_id')->nullable();
            $t->string('thumbnail_url')->nullable();
            $t->string('image_url')->nullable();
            $t->string('ad_title')->nullable();
            $t->text('ad_body')->nullable();
            $t->string('video_id')->nullable();
            $t->timestamp('created_time')->nullable();
        });
        Schema::create('meta_video_transcripts', fn ($t) => [$t->id(), $t->string('video_id'), $t->string('status'), $t->string('hook')->nullable(), $t->text('text')->nullable(),
            $t->timestamp('transcribed_at')->nullable()]);
        // el ganador ya se entregó con su archivo
        DB::table('creative_batch_pieces')->where('id', 21)->update(['file_id' => ($this->upload)(21)->id]);
        DB::table('meta_creatives')->insert([['id' => 7, 'label' => 'C0457 POSP', 'creative_code' => 'C0457'], ['id' => 8, 'label' => 'Viejo', 'creative_code' => null]]);
        DB::table('meta_ads')->insert(['meta_creative_ref_id' => 8, 'thumbnail_url' => 'https://x.test/mini.jpg', 'ad_title' => null, 'ad_body' => 'Quema grasa sin dieta. Hoy a $20.']);
        DB::table('creative_batches')->insert([
            ['id' => 2, 'product_id' => 5, 'number' => 111, 'status' => 'review', 'reference_creative_id' => 7],
            ['id' => 3, 'product_id' => 5, 'number' => 112, 'status' => 'production', 'reference_creative_id' => 8],
            ['id' => 4, 'product_id' => 5, 'number' => 113, 'status' => 'bank', 'reference_creative_id' => 7],
        ]);

        $winner = EditorWork::iteration(CreativeBatch::find(2));
        $old = EditorWork::iteration(CreativeBatch::find(3));
        expect($winner['code'])->toBe('C0457')->and($winner['file'])->toMatchArray(['name' => 'C0457 POSP.mp4', 'type' => 'video'])
            ->and($old)->toMatchArray(['code' => 'Viejo', 'file' => null, 'thumbnail' => 'https://x.test/mini.jpg'])
            ->and($old['texts'])->toBe(['Quema grasa sin dieta.'])
            // ya en la banca (o sin iteración) no se busca
            ->and(EditorWork::iteration(CreativeBatch::find(4)))->toBeNull()
            ->and(EditorWork::iteration(CreativeBatch::find(1)))->toBeNull();
    });

    it('.93: al revisar, la referencia de cada pieza dice por qué la eligieron', function () {
        $ref = CreativeReference::create(['number' => 2, 'link' => 'https://x.com/a', 'link_key' => 'k', 'type' => 'testimonial', 'why' => 'El hook de la balanza']);
        expect(ReferenceLibrary::brief([$ref->id])[$ref->id])->toMatchArray(['code' => 'REF0002', 'type_label' => 'Testimonio', 'why' => 'El hook de la balanza']);
    });

    it('.94: el juego del editor: racha a tiempo, aprobados a la primera y ganadores del mes', function () {
        $this->travelTo('2026-11-20 12:00:00');
        DB::table('creative_batches')->insert([
            ['id' => 2, 'product_id' => 5, 'number' => 111, 'status' => 'bank', 'editor_id' => 10, 'due_date' => '2026-11-19', 'delivered_at' => '2026-11-19 15:00:00'],
            ['id' => 3, 'product_id' => 5, 'number' => 112, 'status' => 'bank', 'editor_id' => 10, 'due_date' => '2026-11-18', 'delivered_at' => '2026-11-18 09:00:00'],
            ['id' => 4, 'product_id' => 5, 'number' => 113, 'status' => 'bank', 'editor_id' => 11, 'due_date' => '2026-11-18', 'delivered_at' => '2026-11-19 09:00:00'],
        ]);
        DB::table('creative_batch_deliveries')->insert([
            ['creative_batch_id' => 2, 'version' => 1, 'editor_id' => 10, 'pieces' => '[]', 'review' => 'approved', 'reviewed_by' => 1, 'reviewed_at' => '2026-11-19 18:00:00'],
            ['creative_batch_id' => 3, 'version' => 1, 'editor_id' => 10, 'pieces' => '[]', 'review' => 'returned', 'reviewed_by' => 1, 'reviewed_at' => '2026-11-18 18:00:00'],
            ['creative_batch_id' => 3, 'version' => 2, 'editor_id' => 10, 'pieces' => '[]', 'review' => 'approved', 'reviewed_by' => 1, 'reviewed_at' => '2026-11-19 10:00:00'],
        ]);
        DB::table('creative_batch_pieces')->where('id', 21)->update(['result' => 'Ganó', 'result_at' => '2026-11-19 08:00:00']);
        DB::table('creative_batch_pieces')->insert(['id' => 23, 'creative_batch_id' => 4, 'number' => 460, 'position' => 1, 'result' => 'Ganó', 'result_at' => '2026-10-30 08:00:00']);

        $game = EditorGame::for(['id' => 10, 'role' => 'Editor de video'], '2026-11-20', 1);
        expect($game['team'])->toBeNull()
            ->and($game['me'])->toMatchArray(['streak' => 2, 'first' => ['ok' => 1, 'total' => 2], 'winners' => 1])
            ->and($game['me']['recent_wins'][0]['code'])->toBe('C0457')
            // el otro editor entregó tarde y su ganador es del mes pasado
            ->and(EditorGame::for(['id' => 11, 'role' => 'Editor de video'], '2026-11-20', 1)['me'])->toMatchArray(['streak' => 0, 'winners' => 0]);
    });

    it('.94: asignar sabiendo quién está más libre, con una referencia sugerida que el editor ve', function () {
        DB::table('creative_batches')->insert(['id' => 4, 'product_id' => 5, 'number' => 113, 'status' => 'approved', 'angle_code' => 'POSP']);
        $editors = collect(EditorWork::editorsWithLoad())->keyBy('id');
        expect($editors[10])->toMatchArray(['open' => 1, 'suggested' => false])->and($editors[11])->toMatchArray(['open' => 0, 'suggested' => true]);

        $ref = CreativeReference::create(['number' => 3, 'link' => 'https://x.com/a', 'link_key' => 'k', 'type' => 'ugc', 'why' => 'Buen hook']);
        (new EditorWork())->assign(CreativeBatch::find(4), 11, User::find(1), ['reference_id' => $ref->id]);
        expect(CreativeBatch::find(4))->editor_id->toBe(11)->suggested_reference_id->toBe($ref->id)
            ->and(EditorWork::fields(CreativeBatch::with('deliveries')->find(4), ['id' => 11, 'role' => 'Editor de video'])['suggested_reference'])
            ->toMatchArray(['code' => 'REF0003', 'why' => 'Buen hook']);

        // cambiar de editor sin decir nada de la referencia la deja como estaba; una archivada no se puede sugerir
        (new EditorWork())->assign(CreativeBatch::find(4), 10, User::find(1));
        expect(CreativeBatch::find(4)->suggested_reference_id)->toBe($ref->id);
        $ref->forceFill(['archived_at' => now()])->save();
        expect(fn () => (new EditorWork())->assign(CreativeBatch::find(4), 10, User::find(1), ['reference_id' => $ref->id]))
            ->toThrow(ValidationException::class, 'Esa referencia ya no está en la biblioteca');
    });

    it('.94: cuando un creativo gana, le llega el aviso solo al editor que lo hizo', function () {
        Notification::fake();
        \App\Services\Meta\Queue\BatchTracker::tellWinner(CreativeBatch::find(1), CreativeBatchPiece::find(21));
        Notification::assertSentTo(User::find(10), MetaWorkNotification::class,
            fn ($n) => $n->toDatabase(User::find(10)) === ['type' => 'creative_won', 'message' => 'Tu C0457 ganó en Meta: es un ganador.', 'url' => '/editor/resultados']);
        Notification::assertNotSentTo(User::find(11), MetaWorkNotification::class);
    });

    it('.95: el líder carga un batch ya hecho: recibe sus números, sus videos con su nombre y entra a la banca', function () {
        Schema::create('meta_adsets', fn ($t) => [$t->id(), $t->string('batch_code')->nullable()]);
        Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('creative_code')->nullable()]);
        DB::table('meta_adsets')->insert(['batch_code' => 'B0115']);
        DB::table('meta_ads')->insert(['creative_code' => 'C0500']);
        DB::table('roles')->insert(['id' => 3, 'description' => 'Editor líder']);
        DB::table('users')->insert(['id' => 12, 'names' => 'Líder', 'role_id' => 3]);

        $batch = app(BatchQueue::class)->import(['product_id' => 5, 'angle_code' => 'POSP', 'editor_id' => 12, 'concept' => null, 'pieces' => 2], 12);
        expect($batch->code())->toBe('B0116')->and($batch)->status->toBe('production')->source->toBe('imported')->due_date->toBeNull()
            ->and($batch->pieces->map->code()->all())->toBe(['C0501', 'C0502']);

        // mientras se suben los videos no es un encargo de nadie
        $up = function (CreativeBatchPiece $piece) use ($batch): StoredFile {
            $start = (new EditorWork())->uploadImport($batch, $piece, User::find(12), 'video de drive.mp4', 'video/mp4', 5);
            $this->call('PUT', $start['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'video/mp4'], 'video')->assertNoContent();

            return app(Uploads::class)->confirm(StoredFile::find($start['file']['id']), User::find(12));
        };
        [$a, $b] = [$up($batch->pieces[0]), $up($batch->pieces[1])];
        expect($a->name)->toBe('C0501 POSP.mp4')
            ->and(fn () => (new EditorWork())->finishImport($batch, User::find(12), [['id' => $batch->pieces[0]->id, 'file_id' => $a->id]]))
            ->toThrow(ValidationException::class, 'C0502: falta su video');

        $done = (new EditorWork())->finishImport($batch, User::find(12), [
            ['id' => $batch->pieces[0]->id, 'file_id' => $a->id, 'hook' => '¿Todavía no te cierra?'], ['id' => $batch->pieces[1]->id, 'file_id' => $b->id]]);
        expect($done->status)->toBe('bank')->and($done->pieces[0])->file_id->toBe($a->id)->hook->toBe('¿Todavía no te cierra?')
            ->and($done->pieces[1]->hook)->toBeNull()
            // sin plazo ni revisión: no cuenta para la racha ni "a la primera"
            ->and(EditorGame::for(['id' => 12, 'role' => 'Editor líder'], now()->toDateString(), 1)['me'])->toMatchArray(['streak' => 0, 'first' => ['ok' => 0, 'total' => 0]]);
    });

    it('.99: un producto en pausa: sus encargos sin terminar salen de todas las listas y vuelven solos al reactivarlo', function () {
        $this->travelTo('2026-11-04 12:00:00');
        Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->text('value')->nullable(), $t->timestamps()]);
        DB::table('products')->insert(['id' => 6, 'title' => 'FlexiStep']);
        // Termafit (5): B0110 lo tiene Lara (del beforeEach), B0111 sin asignar y atrasado, B0112 en la banca; FlexiStep (6): B0113 de Lara, atrasado
        DB::table('creative_batches')->where('id', 1)->update(['due_date' => '2026-11-03']);
        DB::table('creative_batches')->insert([
            ['id' => 2, 'product_id' => 5, 'number' => 111, 'status' => 'approved', 'editor_id' => null, 'due_date' => '2026-11-02'],
            ['id' => 3, 'product_id' => 5, 'number' => 112, 'status' => 'bank', 'editor_id' => 10, 'due_date' => null],
            ['id' => 4, 'product_id' => 6, 'number' => 113, 'status' => 'production', 'editor_id' => 10, 'due_date' => '2026-11-03'],
        ]);
        $ids = fn () => ProductModes::withoutHeld(CreativeBatch::query())->orderBy('id')->pluck('id')->all();
        $late = fn () => array_column(LateBatches::find([], '2026-11-04'), 'code');
        expect($ids())->toBe([1, 2, 3, 4])->and($late())->toBe(['B0111', 'B0110', 'B0113']);

        $r = ProductModes::set(5, 'paused', 1, ['note' => 'Sin stock del proveedor']);
        expect($r)->toMatchArray(['from' => 'normal', 'to' => 'paused', 'returned' => 0])
            ->and(ProductModes::effect($r['from'], $r['to'], $r['tasks']))
            ->toBe('Deja de pedir encargos para este producto. Quedan en espera (salen de las listas de los editores): B0110 (lo tiene Lara) y B0111 (sin asignar).')
            // ni en las listas, ni en la carga de Lara, ni atrasados; lo de la banca se queda
            ->and($ids())->toBe([3, 4])
            ->and(collect(EditorWork::editorsWithLoad())->firstWhere('id', 10))->toMatchArray(['open' => 1, 'late' => 1])
            ->and($late())->toBe(['B0113'])
            ->and(DB::table('product_ad_settings')->where('product_id', 5)->first())->mode->toBe('paused')->note->toBe('Sin stock del proveedor');

        // reactivado: vuelven con la fecha de la reserva (hoy + 2 días); la nota queda como estaba
        $r = ProductModes::set(5, 'normal', 1);
        expect($r['returned'])->toBe(2)
            ->and($ids())->toBe([1, 2, 3, 4])
            ->and(CreativeBatch::find(1)->due_date->toDateString())->toBe('2026-11-06')
            ->and(CreativeBatch::find(2)->due_date->toDateString())->toBe('2026-11-06')
            ->and($late())->toBe(['B0113'])
            ->and(DB::table('product_ad_settings')->where('product_id', 5)->value('note'))->toBe('Sin stock del proveedor');

        // en liquidación también queda en espera
        ProductModes::set(5, 'liquidation', 1);
        expect($ids())->toBe([3, 4]);
    });

    it('.99: el asistente pausa y reactiva un producto con su tarjeta', function () {
        Schema::create('settings', fn ($t) => [$t->id(), $t->string('key')->unique(), $t->text('value')->nullable(), $t->timestamps()]);
        $tool = app(ProductModeTool::class);
        $p = $tool->propose(['producto' => 'Termafit', 'estado' => 'pausado', 'motivo' => 'sin stock del proveedor'], new ActionContext(User::find(1), null, [], CarbonImmutable::now()));
        expect($p->title)->toBe('Pausar Termafit')
            ->and(array_column($p->lines, 'value', 'label'))->toMatchArray(['Producto' => 'Termafit', 'Ahora' => 'Activo', 'Motivo' => 'sin stock del proveedor'])
            ->and(array_column($p->lines, 'value', 'label')['Qué pasa'])->toContain('B0110 (lo tiene Lara)')
            ->and($p->payload)->toBe(['product_id' => 5, 'mode' => 'paused', 'note' => 'sin stock del proveedor']);

        $out = $tool->execute($p->payload, new ActionContext(User::find(1), null, [], CarbonImmutable::now()));
        expect($out->text)->toStartWith('En pausa. Deja de pedir encargos')->and(ProductModes::modeOf(5))->toBe('paused')
            ->and(fn () => $tool->propose(['producto' => 'Termafit', 'estado' => 'pausado', 'motivo' => null], new ActionContext(User::find(1), null, [], CarbonImmutable::now())))
            ->toThrow(ToolProblem::class, 'ya está así');

        $back = $tool->propose(['producto' => 'Termafit', 'estado' => 'activo', 'motivo' => null], new ActionContext(User::find(1), null, [], CarbonImmutable::now()));
        expect($back->title)->toBe('Reactivar Termafit')->and(array_column($back->lines, 'value', 'label')['Qué pasa'])->toContain('Vuelven a las listas');
    });

    it('.99: un batch ya hecho que son variaciones de un creativo que estuvo en Meta queda como su iteración', function () {
        Schema::create('meta_adsets', fn ($t) => [$t->id(), $t->string('batch_code')->nullable()]);
        Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('creative_code')->nullable()]);
        Schema::create('meta_creatives', fn ($t) => [$t->id(), $t->unsignedBigInteger('product_id')->nullable(), $t->string('tracking_id')->nullable(),
            $t->string('label')->nullable(), $t->string('creative_code')->nullable(), $t->string('angle_code')->nullable(), $t->timestamps()]);
        DB::table('roles')->insert(['id' => 3, 'description' => 'Editor líder']);
        DB::table('users')->insert(['id' => 12, 'names' => 'Líder', 'role_id' => 3]);
        DB::table('products')->insert(['id' => 6, 'title' => 'FlexiStep']);
        DB::table('ad_angles')->insert(['product_id' => 5, 'code' => 'POSP', 'name' => 'Posparto', 'position' => 1]);
        DB::table('meta_creatives')->insert([['id' => 30, 'product_id' => 5, 'tracking_id' => '5:CRE002', 'label' => 'CRE002-TERMA-POSP-BATCH001', 'angle_code' => 'POSP'],
            ['id' => 31, 'product_id' => 6, 'tracking_id' => '6:CRE001', 'label' => 'CRE001-FLEX', 'angle_code' => null]]);
        $queue = app(BatchQueue::class);
        $base = ['product_id' => 5, 'angle_code' => null, 'editor_id' => 12, 'concept' => null, 'pieces' => 2];

        // el creativo tiene que ser de ese producto, y hay que decir qué cambia
        expect(fn () => $queue->import($base + ['iterates_id' => 31, 'changes' => 'hook'], 12))->toThrow(ValidationException::class, 'no es de este producto')
            ->and(fn () => $queue->import($base + ['iterates_id' => 30, 'changes' => null], 12))->toThrow(ValidationException::class, 'qué cambia');

        $batch = $queue->import($base + ['iterates_id' => 30, 'changes' => 'hook'], 12);
        // sin ángulo elegido toma el del creativo (está en la ficha)
        expect($batch)->kind->toBe('iteration')->reference_creative_id->toBe(30)->angle_code->toBe('POSP')
            ->and($batch->pieces->pluck('test')->all())->toBe(['winner_hook', 'winner_hook'])
            ->and(EditorWork::task($batch->load('reference'), 'Posparto'))
            ->toBe(['title' => '2 iteraciones del hook de CRE002-TERMA-POSP-BATCH001', 'detail' => 'Solo cambia el hook (los primeros 3 segundos): el resto del video queda igual.']);

        // al terminar de subir, cada pieza salió de la iteración de ese creativo
        $files = $batch->pieces->map(function (CreativeBatchPiece $piece) use ($batch) {
            $start = (new EditorWork())->uploadImport($batch, $piece, User::find(12), 'v.mp4', 'video/mp4', 5);
            $this->call('PUT', $start['upload']['url'], [], [], [], ['CONTENT_TYPE' => 'video/mp4'], 'video')->assertNoContent();

            return app(Uploads::class)->confirm(StoredFile::find($start['file']['id']), User::find(12));
        });
        $done = (new EditorWork())->finishImport($batch, User::find(12), $batch->pieces->values()->map(fn ($p, $i) => ['id' => $p->id, 'file_id' => $files[$i]->id])->all());
        expect($done->status)->toBe('bank')->and(DB::table('creative_batch_pieces')->where('creative_batch_id', $batch->id)->pluck('source')->unique()->all())->toBe(['iteration'])
            // uno normal sigue igual
            ->and($queue->import($base, 12))->kind->toBe('new_concept')->reference_creative_id->toBeNull();
    });

    it('.95: solo el líder carga; una carga a medias se descarta', function () {
        Schema::create('meta_adsets', fn ($t) => [$t->id(), $t->string('batch_code')->nullable()]);
        Schema::create('meta_ads', fn ($t) => [$t->id(), $t->string('creative_code')->nullable()]);
        DB::table('roles')->insert(['id' => 3, 'description' => 'Editor líder']);
        DB::table('users')->insert(['id' => 12, 'names' => 'Líder', 'role_id' => 3]);
        $batch = app(BatchQueue::class)->import(['product_id' => 5, 'angle_code' => null, 'editor_id' => 10, 'concept' => 'Rutina', 'pieces' => 1], 12);
        expect($batch->code())->toBe('B0111')->and(QueueRules::metaNames($batch->number, null, [$batch->pieces[0]->number])['adset'])->toBe('B0111')
            ->and(fn () => (new EditorWork())->uploadImport($batch, $batch->pieces[0], User::find(10), 'v.mp4', 'video/mp4', 5))->toThrow(HttpResponseException::class);

        (new EditorWork())->cancelImport($batch, User::find(12));
        expect(CreativeBatch::find($batch->id))->status->toBe('discarded')->discard_reason->toBe('Carga sin terminar')
            ->and(fn () => (new EditorWork())->finishImport($batch, User::find(12), []))->toThrow(ValidationException::class, 'ya no se está cargando');
    });

    it('el asistente guarda en el producto las fotos y el PDF que el dueño adjuntó en la última hora', function () {
        Schema::create('assistant_messages', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('role');
            $t->text('text')->nullable();
            $t->string('status')->nullable();
            $t->json('attachments')->nullable();
            $t->timestamps();
        });
        Storage::disk('local')->put('assistant/1/a.jpg', 'foto');
        Storage::disk('local')->put('assistant/1/b.pdf', 'estrategia');
        Storage::disk('local')->put('assistant/1/c.xlsx', 'hoja');
        $now = CarbonImmutable::now();
        DB::table('assistant_messages')->insert([
            ['user_id' => 1, 'role' => 'user', 'created_at' => $now->subMinutes(10), 'attachments' => json_encode([
                ['path' => 'assistant/1/a.jpg', 'name' => 'frente.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'kind' => 'image'],
                ['path' => 'assistant/1/c.xlsx', 'name' => 'precios.xlsx', 'mime' => 'application/vnd.ms-excel', 'size' => 4, 'kind' => 'sheet']])],
            ['user_id' => 1, 'role' => 'user', 'created_at' => $now->subMinutes(2), 'attachments' => json_encode([
                ['path' => 'assistant/1/b.pdf', 'name' => 'estrategia.pdf', 'mime' => 'application/pdf', 'size' => 10, 'kind' => 'pdf']])],
            ['user_id' => 1, 'role' => 'user', 'created_at' => $now->subHours(3), 'attachments' => json_encode([
                ['path' => 'assistant/1/a.jpg', 'name' => 'vieja.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'kind' => 'image']])],
        ]);
        $files = ProductProfileTool::attachments(new ActionContext(User::find(1), null, [], $now));
        expect(array_column($files, 'name'))->toBe(['frente.jpg', 'estrategia.pdf'])->and(array_column($files, 'kind'))->toBe(['photo', 'strategy']);

        $saved = ProductProfileTool::saveFiles($files, 5, 1);
        expect($saved)->toMatchArray(['photos' => 1, 'strategy' => 1, 'problems' => []])
            ->and(StoredFile::where('product_id', 5)->where('kind', 'photo')->value('status'))->toBe('ready')
            ->and(ProductProfileTool::saveFiles($files, 5, 1))->toMatchArray(['photos' => 0, 'strategy' => 0]); // no se repite

        $card = ProductProfileTool::proposal(0, 'Termafit', null, [], ['que_es' => 'Faja térmica', 'para_que_sirve' => 'Reducir', 'como_funciona' => 'Calor',
            'buyer_personas' => [['nombre' => 'Mamá', 'quien_es' => 'Mujer', 'que_le_duele' => 'La barriga', 'que_quiere' => null, 'que_lo_frena' => null]],
            'angulos' => [], 'fase' => 'testeo'], [], 'iterate', [], ['create' => true, 'shopify' => null], $files);
        expect(collect($card->lines)->firstWhere('label', 'Se guarda en el producto')['value'])
            ->toBe("1 foto (las ven los editores): frente.jpg\nDocumento de estrategia (solo tú): estrategia.pdf")
            ->and($card->payload['files'])->toBe($files);
    });
});

describe('.92: la biblioteca de referencias (sin base)', function () {
    it('el mismo anuncio con otro link (www, la barra final, utm) se reconoce como el mismo', function () {
        $a = ReferenceLibrary::linkKey('https://www.facebook.com/ads/library/?id=123456&utm_source=wa');
        expect(ReferenceLibrary::linkKey('https://facebook.com/ads/library?id=123456'))->toBe($a)
            ->and(ReferenceLibrary::linkKey('https://facebook.com/ads/library/?id=999'))->not->toBe($a)
            ->and(ReferenceLibrary::validLink('https://www.tiktok.com/@marca/video/1'))->toBeTrue()
            ->and(ReferenceLibrary::validLink('no es un link'))->toBeFalse();
    });

    it('el líder y el dueño ven si ganaron; los editores no', function () {
        expect(ReferenceLibrary::seesResults(['id' => 1, 'role' => 'Editor líder']))->toBeTrue()
            ->and(ReferenceLibrary::seesResults(['id' => 1, 'role' => 'Admin']))->toBeTrue()
            ->and(ReferenceLibrary::seesResults(['id' => 1, 'role' => 'Editor de video']))->toBeFalse()
            ->and(CreativeReference::codeOf(7))->toBe('REF0007');
    });
});

describe('.93: lo que ve el líder al revisar (sin base)', function () {
    it('la hipótesis del encargo la ve solo el líder, sin precios; el editor de video y el dueño por esta vía no', function () {
        expect(EditorWork::hypothesisFor(['id' => 3, 'role' => 'Editor líder'], 'Ángulo nuevo POSP. Con el 2x1 a $35.'))->toBe(['hypothesis' => 'Ángulo nuevo POSP.'])
            ->and(EditorWork::hypothesisFor(['id' => 4, 'role' => 'Editor de video'], 'Ángulo nuevo POSP'))->toBe([]);
    });
});

describe('La ficha completa para el editor', function () {
    it('ve todos los ángulos, de qué trata cada uno y a quién le habla; por código y sin precios', function () {
        $angles = ProductProfileRules::anglesForEditor([
            ['code' => 'POSP', 'name' => 'Postparto', 'hypothesis' => 'Mamás que quieren volver a su talla; a $30 es barato.', 'persona' => 'Mamá reciente'],
            ['code' => 'ABDO', 'name' => 'Abdomen', 'hypothesis' => null, 'persona' => null],
        ]);

        expect(array_column($angles, 'code'))->toBe(['ABDO', 'POSP'])
            ->and($angles[1])->toMatchArray(['name' => 'Postparto', 'persona' => 'Mamá reciente'])
            ->and($angles[1]['about'])->toContain('Mamás que quieren volver a su talla')->not->toContain('$30');
    });
});
