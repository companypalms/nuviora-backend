<?php

use App\Models\AssistantMessage;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\Tools\ProductProfileTool;
use App\Services\Files\FileStore;
use App\Services\Files\R2Store;
use App\Services\Files\SigV4;
use App\Services\Files\StoredFileRules;
use App\Services\Files\Uploads;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Queue\EditorWork;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            $t->unsignedBigInteger('editor_id')->nullable();
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
            $t->string('hook')->nullable();
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
        $this->data = fn (array $pieces) => ['concept' => 'Antes y después', 'angle' => 'Postparto', 'pieces' => $pieces];
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
