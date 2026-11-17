<?php

use App\Jobs\RunMetaUpload;
use App\Models\CreativeBatch;
use App\Models\MetaAnalysis;
use App\Models\MetaUpload;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaConnectionService;
use App\Services\Meta\Upload\MetaUploader;
use App\Services\Meta\Upload\MetaUploads;
use App\Services\Meta\Upload\MetaWriter;
use App\Services\Meta\Upload\UploadGateway;
use App\Services\Meta\Upload\UploadRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// .155 (dueño, 2026-11-17: "que me aparezcan en mis misiones diarias, yo le doy un botón de aprobar y él los suba
// automáticamente en meta ads"): Termafit, el batch B0123 FASP (3 videos) a la campaña de Caracas, que ya corre.

/** Un Meta simulado que anota todo lo que se le pide. */
function metaSimulado155(array $options = []): UploadGateway
{
    return new class($options) implements UploadGateway {
        public array $calls = [];
        private int $n = 0;
        private array $seen = [];

        public function __construct(public array $options)
        {
        }

        public function creativeStory(string $creativeId): array
        {
            $this->calls[] = ['creativeStory', $creativeId];

            return $this->options['story'] ?? ['object_story_spec' => ['page_id' => '111', 'instagram_user_id' => '222']];
        }

        public function videoFromUrl(string $act, string $url, string $name): string
        {
            $this->calls[] = ['videoFromUrl', $act, $name];

            return 'v' . ++$this->n;
        }

        public function videoFromFile(string $act, string $bytes, string $fileName, string $name): string
        {
            $this->calls[] = ['videoFromFile', $act, $name, $bytes];

            return 'v' . ++$this->n;
        }

        public function videoState(string $videoId): array
        {
            $this->calls[] = ['videoState', $videoId];
            $this->seen[$videoId] = ($this->seen[$videoId] ?? 0) + 1;
            if (isset($this->options['video_error'])) {
                return ['state' => 'error', 'note' => $this->options['video_error']];
            }

            return $this->seen[$videoId] > ($this->options['processing'] ?? 0) ? ['state' => 'ready', 'note' => null] : ['state' => 'processing', 'note' => null];
        }

        public function videoThumbnail(string $videoId): ?string
        {
            return "https://x/{$videoId}.jpg";
        }

        public function image(string $act, string $bytes, string $name): string
        {
            $this->calls[] = ['image', $act, $name];

            return 'hash' . ++$this->n;
        }

        public function adsetsNamed(string $campaignId, string $name): array
        {
            $this->calls[] = ['adsetsNamed', $campaignId, $name];

            return $this->options['existing'] ?? [];
        }

        public function copyAdset(string $adsetId, string $campaignId): string
        {
            $this->calls[] = ['copyAdset', $adsetId, $campaignId];

            return 'adset9';
        }

        public function updateAdset(string $adsetId, array $fields): void
        {
            $this->calls[] = ['updateAdset', $adsetId, $fields];
        }

        public function creative(string $act, array $spec): string
        {
            $this->calls[] = ['creative', $act, $spec['name']];

            return 'cr' . ++$this->n;
        }

        public function ad(string $act, array $fields): string
        {
            $this->calls[] = ['ad', $act, $fields['name'], $fields['adset_id'], $fields['status']];
            if (($this->options['fail_ad'] ?? null) === $fields['name']) {
                unset($this->options['fail_ad']);
                throw new MetaApiException(MetaApiException::META, 'Invalid parameter');
            }

            return 'ad' . ++$this->n;
        }

        public function did(string $what): array
        {
            return array_values(array_filter($this->calls, fn ($c) => $c[0] === $what));
        }
    };
}

beforeEach(function () {
    Schema::create('settings', function ($t) {
        $t->id();
        $t->string('key')->unique();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    Schema::create('roles', fn ($t) => [$t->id(), $t->string('description')]);
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('names');
        $t->unsignedBigInteger('role_id')->nullable();
        $t->timestamp('deactivated_at')->nullable();
        $t->timestamps();
    });
    Schema::create('products', fn ($t) => [$t->id(), $t->string('title'), $t->string('showable_name')->nullable(), $t->timestamps()]);
    Schema::create('cities', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('meta_creatives', fn ($t) => [$t->id(), $t->string('label')->nullable(), $t->timestamps()]);
    Schema::create('creative_batches', function ($t) {
        $t->id();
        $t->unsignedBigInteger('product_id');
        $t->unsignedInteger('number')->nullable();
        $t->string('status');
        $t->string('angle_code')->nullable();
        $t->string('format')->nullable();
        $t->unsignedBigInteger('reference_creative_id')->nullable();
        $t->unsignedBigInteger('learning_city_id')->nullable();
        $t->timestamp('testing_at')->nullable();
        $t->timestamp('status_at')->nullable();
        $t->unsignedBigInteger('status_by')->nullable();
        $t->timestamps();
    });
    Schema::create('creative_batch_pieces', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->unsignedInteger('number')->nullable();
        $t->unsignedInteger('position')->default(0);
        $t->string('format')->nullable();
        $t->string('hook')->nullable();
        $t->string('link', 1000)->nullable();
        $t->unsignedBigInteger('file_id')->nullable();
        $t->timestamps();
    });
    Schema::create('stored_files', function ($t) {
        $t->id();
        $t->string('driver');
        $t->string('path');
        $t->string('name');
        $t->string('mime');
        $t->unsignedBigInteger('size')->default(0);
        $t->string('status')->default('ready');
        $t->string('kind')->nullable();
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('creative_batch_piece_id')->nullable();
        $t->unsignedInteger('version')->nullable();
        $t->unsignedBigInteger('uploaded_by')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_connections', function ($t) {
        $t->id();
        $t->string('name');
        $t->text('access_token')->nullable();
        $t->text('app_secret')->nullable();
        $t->json('scopes')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_ad_accounts', function ($t) {
        $t->id();
        $t->unsignedBigInteger('meta_connection_id');
        $t->string('meta_id');
        $t->string('name')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_campaigns', function ($t) {
        $t->id();
        $t->unsignedBigInteger('meta_ad_account_id');
        $t->string('meta_id');
        $t->string('name')->nullable();
        $t->string('effective_status')->nullable();
        $t->unsignedBigInteger('product_id')->nullable();
        $t->unsignedBigInteger('city_id')->nullable();
        $t->timestamp('missing_since')->nullable();
        $t->json('meta')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_adsets', function ($t) {
        $t->id();
        $t->string('campaign_meta_id');
        $t->string('meta_id');
        $t->string('name')->nullable();
        $t->string('effective_status')->nullable();
        $t->string('optimization_goal')->nullable();
        $t->timestamp('created_time')->nullable();
        $t->timestamp('missing_since')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_ads', function ($t) {
        $t->id();
        $t->string('adset_meta_id');
        $t->string('meta_id');
        $t->unsignedBigInteger('meta_creative_ref_id')->nullable();
        $t->string('meta_creative_id')->nullable();
        $t->text('ad_body')->nullable();
        $t->string('ad_title')->nullable();
        $t->text('landing_url')->nullable();
        $t->text('url_tags')->nullable();
        $t->timestamp('created_time')->nullable();
        $t->timestamp('missing_since')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_analyses', function ($t) {
        $t->id();
        $t->date('date');
        $t->unsignedSmallInteger('version')->default(1);
        $t->string('status', 12);
        $t->json('facts');
        $t->json('urgent');
        $t->json('marks')->nullable();
        $t->timestamps();
    });
    Schema::create('meta_uploads', function ($t) {
        $t->id();
        $t->unsignedBigInteger('creative_batch_id');
        $t->string('campaign_meta_id', 32);
        $t->unsignedBigInteger('meta_ad_account_id')->nullable();
        $t->unsignedBigInteger('meta_analysis_id')->nullable();
        $t->string('mission_key', 120)->nullable();
        $t->string('status', 12)->default('queued');
        $t->string('step', 16)->nullable();
        $t->boolean('paused')->default(false);
        $t->json('plan');
        $t->json('created')->nullable();
        $t->text('error')->nullable();
        $t->unsignedSmallInteger('attempts')->default(0);
        $t->unsignedBigInteger('requested_by')->nullable();
        $t->timestamp('started_at')->nullable();
        $t->timestamp('waiting_since')->nullable();
        $t->timestamp('finished_at')->nullable();
        $t->timestamps();
        $t->unique(['creative_batch_id', 'campaign_meta_id']);
    });

    DB::table('roles')->insert([['id' => 1, 'description' => 'Admin']]);
    DB::table('users')->insert([['id' => 1, 'names' => 'Jose', 'role_id' => 1]]);
    DB::table('products')->insert(['id' => 7, 'title' => 'Termafit faja térmica', 'showable_name' => 'Termafit']);
    DB::table('cities')->insert(['id' => 3, 'name' => 'Distrito Capital']);
    DB::table('meta_connections')->insert(['id' => 1, 'name' => 'BM Meloon', 'scopes' => json_encode(['ads_read', 'ads_management'])]);
    DB::table('meta_ad_accounts')->insert(['id' => 5, 'meta_connection_id' => 1, 'meta_id' => '777', 'name' => 'Meloon 2']);
    DB::table('meta_campaigns')->insert(['id' => 1, 'meta_ad_account_id' => 5, 'meta_id' => '120200000000001', 'name' => 'TERMAFIT | CCS | CBO | CPA 4.5',
        'effective_status' => 'ACTIVE', 'product_id' => 7, 'city_id' => 3, 'meta' => json_encode(['daily_budget' => '6000'])]);
    DB::table('meta_adsets')->insert(['campaign_meta_id' => '120200000000001', 'meta_id' => '230000000000001', 'name' => 'B0110 POSP',
        'effective_status' => 'ACTIVE', 'optimization_goal' => 'OFFSITE_CONVERSIONS', 'created_time' => '2026-11-10 10:00:00']);
    DB::table('meta_ads')->insert(['adset_meta_id' => '230000000000001', 'meta_id' => '240000000000001', 'meta_creative_id' => '250000000000001',
        'ad_body' => 'Reduce tu cintura sin dietas', 'ad_title' => 'Termafit', 'landing_url' => 'https://meloon.com/termafit',
        'url_tags' => 'utm_source=facebook&utm_medium=paid', 'created_time' => '2026-11-10 10:00:00']);
    DB::table('creative_batches')->insert(['id' => 1, 'product_id' => 7, 'number' => 123, 'status' => 'bank', 'angle_code' => 'FASP', 'format' => 'video']);
    Storage::fake('local');
    foreach ([[21, 457], [22, 458], [23, 459]] as $i => [$piece, $num]) {
        DB::table('creative_batch_pieces')->insert(['id' => $piece, 'creative_batch_id' => 1, 'number' => $num, 'position' => $i + 1, 'file_id' => 100 + $i]);
        DB::table('stored_files')->insert(['id' => 100 + $i, 'driver' => 'local', 'path' => "v{$num}.mp4", 'name' => "C0{$num}.mp4", 'mime' => 'video/mp4', 'size' => 10]);
        Storage::disk('local')->put("files/v{$num}.mp4", "video-{$num}");
    }
    MetaAnalysis::create(['date' => '2026-11-17', 'version' => 1, 'status' => 'ok', 'facts' => [], 'urgent' => [
        ['key' => '7:120200000000001:few_batches', 'kind' => 'few_batches', 'batches' => ['B0123'],
            'upload' => [['code' => 'B0123', 'campaign' => '120200000000001', 'min' => 4.5]]],
        ['key' => '7:120200000000001:scale', 'kind' => 'scale', 'batches' => []],
    ]]);
    MetaUploads::setOn(true);
    Notification::fake();
    Queue::fake();
});

$aprobar = fn () => (new MetaUploads())->approve(MetaAnalysis::first(), '7:120200000000001:few_batches', User::find(1));

describe('Antes de aprobar: lo que se va a crear', function () {
    it('B0123 FASP en la campaña de Caracas: copia el ad set más nuevo, gasto mínimo $4,50, 3 anuncios con el texto de la campaña, en pausa', function () {
        $p = (new MetaUploads())->plans(MetaAnalysis::first(), '7:120200000000001:few_batches')['plans'][0];

        expect($p['ready'])->toBeTrue()
            ->and($p['campaign']['name'])->toBe('TERMAFIT | CCS | CBO | CPA 4.5')
            ->and($p['template_adset']['name'])->toBe('B0110 POSP')
            ->and($p['adset_name'])->toBe('B0123 FASP')
            ->and($p['min_spend'])->toBe(4.5)
            ->and($p['paused'])->toBeTrue()
            ->and($p['account'])->toBe(['id' => 5, 'meta_id' => '777', 'name' => 'Meloon 2', 'connection' => 'BM Meloon', 'can_upload' => true])
            ->and(array_column($p['ads'], 'name'))->toBe(['C0457 FASP', 'C0458 FASP', 'C0459 FASP'])
            ->and(array_column($p['ads'], 'type'))->toBe(['video', 'video', 'video'])
            ->and($p['texts']['body'])->toBe('Reduce tu cintura sin dietas')
            ->and($p['texts']['creative_id'])->toBe('250000000000001')
            ->and($p['warnings'])->toContain('Se crea EN PAUSA (una de tus primeras 5 subidas): revísalo en el Administrador de anuncios y préndelo tú.');
    });

    it('si el token solo lee, lo dice y no deja subir', function () {
        DB::table('meta_connections')->update(['scopes' => json_encode(['ads_read'])]);
        $p = (new MetaUploads())->plans(MetaAnalysis::first(), '7:120200000000001:few_batches')['plans'][0];

        expect($p['ready'])->toBeFalse()
            ->and($p['problems'][0])->toContain('solo puede leer: su token no tiene el permiso para crear anuncios (ads_management)');
    });

    it('sin gasto mínimo, con la campaña apagada o ya subido: no deja subir', function () {
        $sinMinimo = MetaAnalysis::first();
        $sinMinimo->forceFill(['urgent' => [['key' => 'k', 'upload' => [['code' => 'B0123', 'campaign' => '120200000000001', 'min' => null]]]]])->save();
        expect((new MetaUploads())->plans($sinMinimo, 'k')['plans'][0]['problems'])->toContain('No sé el gasto mínimo del ad set nuevo: pon el CPA en el nombre de la campaña o tu C de esa ciudad en Productos y objetivos.');

        DB::table('meta_campaigns')->update(['effective_status' => 'PAUSED']);
        expect((new MetaUploads())->plans($sinMinimo, 'k')['plans'][0]['problems'])->toContain('La campaña TERMAFIT | CCS | CBO | CPA 4.5 está apagada: los batches nuevos van en una campaña prendida.');
    });

    it('una misión sin batches para subir solos no se aprueba', function () {
        expect(fn () => (new MetaUploads())->plans(MetaAnalysis::first(), '7:120200000000001:scale'))->toThrow(ValidationException::class);
    });
});

describe('Aprobar', function () use ($aprobar) {
    it('crea la subida (en pausa: es de las primeras 5) y la manda a la cola de Meta', function () use ($aprobar) {
        $aprobar();
        $u = MetaUpload::first();

        expect($u->status)->toBe('queued')->and($u->paused)->toBeTrue()->and($u->meta_ad_account_id)->toBe(5)
            ->and($u->mission_key)->toBe('7:120200000000001:few_batches');
        Queue::assertPushed(RunMetaUpload::class, fn ($job) => $job->uploadId === $u->id && $job->queue === 'meta');
    });

    it('con el interruptor apagado no sube nada', function () use ($aprobar) {
        MetaUploads::setOn(false);

        expect($aprobar)->toThrow(ValidationException::class);
        expect(MetaUpload::count())->toBe(0);
    });

    it('no se aprueba dos veces la misma subida', function () use ($aprobar) {
        $aprobar();

        expect($aprobar)->toThrow(ValidationException::class);
        expect(MetaUpload::count())->toBe(1);
    });

    it('después de 5 en pausa, se crean prendidas', function () use ($aprobar) {
        foreach (range(1, 5) as $i) {
            DB::table('meta_uploads')->insert(['creative_batch_id' => 90 + $i, 'campaign_meta_id' => '1', 'status' => 'done', 'paused' => true, 'plan' => '{}']);
        }
        $aprobar();

        expect(MetaUpload::latest('id')->first()->paused)->toBeFalse();
    });

    it('las que fallaron no cuentan entre las 5 en pausa', function () {
        foreach (range(1, 5) as $i) {
            DB::table('meta_uploads')->insert(['creative_batch_id' => 90 + $i, 'campaign_meta_id' => '1', 'status' => $i === 1 ? 'failed' : 'done', 'paused' => true, 'plan' => '{}']);
        }

        expect(MetaUploads::pausedNext())->toBeTrue();
    });
});

describe('La subida', function () use ($aprobar) {
    it('sube los videos, espera a Meta, copia el ad set apagado con su nombre y su mínimo, y crea los 3 anuncios; en pausa no lo prende', function () use ($aprobar) {
        $aprobar();
        $meta = metaSimulado155(['processing' => 1]);
        $uploader = new MetaUploader(fn () => $meta);
        $u = MetaUpload::first();

        expect($uploader->advance($u))->toBe('wait')
            ->and($u->refresh()->status)->toBe('waiting');
        expect($uploader->advance($u->refresh()))->toBe('done');

        expect(array_map(fn ($c) => [$c[2], $c[3]], $meta->did('videoFromFile')))->toBe([['C0457 FASP', 'video-457'], ['C0458 FASP', 'video-458'], ['C0459 FASP', 'video-459']])
            ->and($meta->did('creativeStory'))->toBe([['creativeStory', '250000000000001']])
            ->and($meta->did('copyAdset'))->toBe([['copyAdset', '230000000000001', '120200000000001']])
            ->and($meta->did('updateAdset'))->toBe([['updateAdset', 'adset9', ['name' => 'B0123 FASP', 'daily_min_spend_target' => 450]]])
            ->and(array_map(fn ($c) => [$c[2], $c[3], $c[4]], $meta->did('ad')))->toBe([['C0457 FASP', 'adset9', 'ACTIVE'], ['C0458 FASP', 'adset9', 'ACTIVE'], ['C0459 FASP', 'adset9', 'ACTIVE']]);

        $u->refresh();
        expect($u->status)->toBe('done')->and($u->created['adset_id'])->toBe('adset9')->and(count($u->created['ads']))->toBe(3)
            ->and(CreativeBatch::find(1)->status)->toBe('testing')
            // en pausa, la misión la marca el dueño cuando lo prende
            ->and(MetaAnalysis::first()->marks)->toBeNull()
            ->and(MetaUploads::present($u)['text'])->toStartWith('Listo, EN PAUSA');
        Notification::assertSentTo(User::find(1), MetaWorkNotification::class,
            fn ($n) => $n->toDatabase(User::find(1))['message'] === 'B0123 quedó subido EN PAUSA en TERMAFIT | CCS | CBO | CPA 4.5: revísalo y préndelo en el Administrador.');
    });

    it('ya pasadas las 5 en pausa: al final prende el ad set y la misión se marca sola', function () use ($aprobar) {
        foreach (range(1, 5) as $i) {
            DB::table('meta_uploads')->insert(['creative_batch_id' => 90 + $i, 'campaign_meta_id' => '1', 'status' => 'done', 'paused' => true, 'plan' => '{}']);
        }
        $aprobar();
        $meta = metaSimulado155();
        $u = MetaUpload::latest('id')->first();

        expect((new MetaUploader(fn () => $meta))->advance($u))->toBe('done');
        expect(end($meta->calls))->toBe(['updateAdset', 'adset9', ['status' => 'ACTIVE']])
            ->and(MetaAnalysis::first()->marks['7:120200000000001:few_batches'])->toMatchArray(['status' => 'done', 'by' => 'El sistema']);
    });

    it('si Meta falla en un anuncio, queda fallida con el porqué y Reintentar sigue sin repetir nada', function () use ($aprobar) {
        $aprobar();
        $meta = metaSimulado155(['fail_ad' => 'C0458 FASP']);
        $uploader = new MetaUploader(fn () => $meta);
        $u = MetaUpload::first();

        expect($uploader->advance($u))->toBe('failed');
        $u->refresh();
        expect($u->status)->toBe('failed')->and($u->error)->toBe('Meta respondió: Invalid parameter')
            ->and(count($u->created['ads']))->toBe(1)
            ->and(CreativeBatch::find(1)->status)->toBe('bank');

        (new MetaUploads())->retry($u);
        expect($uploader->advance($u->refresh()))->toBe('done');
        // un solo ad set y un solo creativo por pieza; los videos no se volvieron a subir
        expect($meta->did('copyAdset'))->toHaveCount(1)
            ->and($meta->did('videoFromFile'))->toHaveCount(3)
            ->and($meta->did('creative'))->toHaveCount(3)
            ->and(array_column($meta->did('ad'), 2))->toBe(['C0457 FASP', 'C0458 FASP', 'C0458 FASP', 'C0459 FASP']);
    });

    it('si en Meta ya hay un ad set B0123, no crea otro', function () use ($aprobar) {
        $aprobar();
        $meta = metaSimulado155(['existing' => [['id' => '1', 'name' => 'B0123 FASP']]]);

        expect((new MetaUploader(fn () => $meta))->advance(MetaUpload::first()))->toBe('failed')
            ->and(MetaUpload::first()->error)->toBe('ya hay un ad set «B0123 FASP» en la campaña TERMAFIT | CCS | CBO | CPA 4.5: no se crea otro')
            ->and($meta->did('copyAdset'))->toBe([]);
    });

    it('si Meta no puede procesar un video, lo dice y no crea el ad set', function () use ($aprobar) {
        $aprobar();
        $meta = metaSimulado155(['video_error' => 'Formato no soportado']);

        expect((new MetaUploader(fn () => $meta))->advance(MetaUpload::first()))->toBe('failed')
            ->and(MetaUpload::first()->error)->toBe('Meta no pudo procesar el video de C0457: Formato no soportado')
            ->and($meta->did('copyAdset'))->toBe([]);
    });

    it('si Meta tarda más de 30 minutos procesando, avisa', function () use ($aprobar) {
        $aprobar();
        $meta = metaSimulado155(['processing' => 99]);
        $u = MetaUpload::first();
        (new MetaUploader(fn () => $meta))->advance($u);
        $u->forceFill(['waiting_since' => now()->subMinutes(31)])->save();

        expect((new MetaUploader(fn () => $meta))->advance($u->refresh()))->toBe('failed')
            ->and($u->refresh()->error)->toBe('Meta lleva más de 30 minutos procesando el video de C0457');
    });
});

describe('El escritor de Meta', function () {
    it('solo crea: videos, imágenes, creativos, anuncios y la copia de un ad set; nunca borra', function () {
        $publicos = collect((new ReflectionClass(MetaWriter::class))->getMethods(ReflectionMethod::IS_PUBLIC))->map->getName()->sort()->values()->all();

        expect($publicos)->toBe(['__construct', 'ad', 'copyAdset', 'creative', 'for', 'image', 'updateAdset', 'videoFromFile', 'videoFromUrl']);
    });

    it('al ad set solo le cambia el nombre, el gasto mínimo o el estado', function () {
        Http::fake();

        expect(fn () => (new MetaWriter('EAAtokenFalso155'))->updateAdset('123', ['daily_budget' => 9000]))->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    });

    it('copia el ad set apagado y sin anuncios, con el token solo en la cabecera', function () {
        config(['services.meta.graph_version' => 'v26.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['copied_adset_id' => '999'])]);

        expect((new MetaWriter('EAAtokenFalso155'))->copyAdset('230000000000001', '120200000000001'))->toBe('999');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://graph.facebook.com/v26.0/230000000000001/copies'
            && $r['campaign_id'] === '120200000000001' && $r['deep_copy'] === 'false' && $r['status_option'] === 'PAUSED'
            && $r->header('Authorization')[0] === 'Bearer EAAtokenFalso155' && !str_contains($r->body(), 'EAAtokenFalso155'));
    });

    it('un error de Meta llega sin el token', function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token EAAtokenFalso155', 'code' => 190]], 400)]);

        try {
            (new MetaWriter('EAAtokenFalso155'))->ad('act_777', ['name' => 'C0457 FASP']);
            $this->fail('debía fallar');
        } catch (MetaApiException $e) {
            expect($e->kind)->toBe(MetaApiException::TOKEN)->and($e->getMessage())->not->toContain('EAAtokenFalso155');
        }
    });
});

describe('Conexiones', function () {
    it('el permiso de crear anuncios ya no es "de más"', function () {
        expect(MetaConnectionService::extraScopes(['ads_read', 'ads_management', 'pages_show_list']))->toBe(['pages_show_list'])
            ->and(UploadRules::canUpload(['ads_read', 'ads_management']))->toBeTrue();
    });
});
