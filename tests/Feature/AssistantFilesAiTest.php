<?php

use App\Models\User;
use App\Services\Assistant\Ai\OpenAiModel;
use App\Services\Assistant\Files\Attachments;
use App\Services\Assistant\Turn;
use App\Services\Assistant\Voice\OpenAiTranscriber;
use App\Services\Reconciliation\StatementReader;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// El asistente del dueño (Etapa 4, paso 4): fotos, PDF, Excel y voz, con una carpeta de mentira y respuestas falsas de
// OpenAI (nunca se llama a OpenAI de verdad). No toca la base de datos.

const LLAVE_FALSA_ARCHIVOS = 'sk-llave-falsa-de-archivos';

/** Un PDF de una página, con ese texto o sin texto (como uno escaneado). */
function pdfDePrueba(?string $text): string
{
    $stream = $text === null ? '' : "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
}

function duenoDePrueba(): User
{
    $user = new User();
    $user->id = 7;

    return $user;
}

describe('Los archivos del chat', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('se guardan en la carpeta privada del dueño y se leen: foto, PDF con texto, PDF escaneado y CSV', function () {
        $files = new Attachments(new StatementReader());
        $stored = $files->store(duenoDePrueba(), [
            UploadedFile::fake()->image('factura.jpg', 20, 20),
            UploadedFile::fake()->createWithContent('Factura Hostinger.pdf', pdfDePrueba('Hostinger factura total 79,00 USD del 01/10/2026')),
            UploadedFile::fake()->createWithContent('escaneado.pdf', pdfDePrueba(null)),
            UploadedFile::fake()->createWithContent('banco.csv', "Fecha;Concepto;Monto\n01/10/2026;Pago movil;25,00\n02/10/2026;Zelle;30,00\n"),
        ]);

        expect(array_column($stored, 'kind'))->toBe(['image', 'pdf', 'pdf', 'sheet']);
        expect($stored[1]['name'])->toBe('Factura Hostinger.pdf');
        expect($stored[0]['path'])->toStartWith('assistant/7/')->toEndWith('.jpg');
        Storage::disk('local')->assertExists(array_column($stored, 'path'));

        $progress = [];
        $read = $files->read($stored, function (string $p) use (&$progress) {
            $progress[] = $p;
        });
        expect($progress)->toBe(['Leyendo Factura Hostinger.pdf…', 'Leyendo escaneado.pdf…', 'Leyendo banco.csv…']);
        expect($read[1])->toMatchArray(['pages' => 1, 'read' => true, 'clipped' => false]);
        expect($read[1])->not->toHaveKey('scanned');
        expect($files->text($read[1]))->toContain('Hostinger factura total 79,00');
        expect($read[2])->toMatchArray(['pages' => 1, 'scanned' => true]);
        expect($read[3])->toMatchArray(['rows' => 3]);
        expect($files->text($read[3]))->toBe("Fila 1: Fecha | Concepto | Monto\nFila 2: 01/10/2026 | Pago movil | 25,00\nFila 3: 02/10/2026 | Zelle | 30,00");

        // Lo que ve la IA
        $parts = $files->parts($read);
        expect(array_column($parts, 'type'))->toBe(['image', 'text', 'file', 'text']);
        expect($parts[0]['data'])->toBe(base64_encode(Storage::disk('local')->get($stored[0]['path'])));
        expect($parts[1]['text'])->toStartWith('<archivo nombre="Factura Hostinger.pdf" tipo="PDF de 1 página">');
        expect($parts[2])->toMatchArray(['name' => 'escaneado.pdf', 'mime' => 'application/pdf', 'label' => 'PDF escaneado de 1 página']);
        expect($parts[3]['text'])->toContain('Fila 3: 02/10/2026 | Zelle | 30,00');

        // Lo que recuerda en la pregunta siguiente
        $budget = 20000;
        $remembered = $files->remembered($read, $budget);
        expect($remembered)->toContain('[Foto factura.jpg: ya no la ves')
            ->toContain('Hostinger factura total')
            ->toContain('[PDF escaneado de 1 página escaneado.pdf: ya no lo ves')
            ->toContain('Fila 2: 01/10/2026');
        expect($budget)->toBeLessThan(20000);

        // Ya leídos, no se vuelven a leer; al borrar no queda nada
        expect($files->read($read))->toBe($read);
        $files->delete($read);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('un archivo que no se puede leer queda con su motivo y la IA lo sabe', function () {
        $files = new Attachments(new StatementReader());
        $stored = $files->store(duenoDePrueba(), [UploadedFile::fake()->createWithContent('roto.pdf', '%PDF-1.4 esto no es un pdf')]);
        $read = $files->read($stored);
        expect($read[0]['problem'])->toContain('No se pudo abrir el PDF');
        expect($files->parts($read)[0]['text'])->toContain('No se pudo leer: No se pudo abrir el PDF');
    });

    it('no acepta HEIC, ejecutables ni más de 5 archivos', function () {
        $files = new Attachments(new StatementReader());
        $heic = UploadedFile::fake()->create('foto.heic', 10, 'image/heic');
        expect(fn () => $files->validate([$heic]))->toThrow(ValidationException::class, 'HEIC del iPhone');
        expect(fn () => $files->validate([UploadedFile::fake()->create('a.exe', 10, 'application/x-dosexec')]))->toThrow(ValidationException::class, 'se aceptan fotos');
        $six = array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 10, 'application/pdf'), range(1, 6));
        expect(fn () => $files->validate($six))->toThrow(ValidationException::class, 'hasta 5 archivos');
        $files->validate([UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('ok.png')]);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('Los archivos con OpenAI', function () {
    beforeEach(fn () => config(['services.openai.key' => LLAVE_FALSA_ARCHIVOS, 'services.openai.assistant_model' => 'modelo-de-prueba', 'services.openai.transcribe_model' => 'voz-de-prueba']));

    it('manda la foto y el PDF dentro de la pregunta, sin guardar nada en OpenAI', function () {
        Http::fake(['api.openai.com/*' => Http::response(['model' => 'm', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Es una factura de $79.']]]]])]);
        $reply = (new OpenAiModel())->next(new Turn('i', [], '¿Qué es esto?', [], files: [
            ['type' => 'image', 'name' => 'f.jpg', 'label' => 'foto', 'mime' => 'image/jpeg', 'data' => 'QUJD'],
            ['type' => 'file', 'name' => 'c.pdf', 'label' => 'PDF escaneado', 'mime' => 'application/pdf', 'data' => 'UERG'],
        ]));

        expect($reply->text)->toBe('Es una factura de $79.');
        Http::assertSent(fn (Request $r) => $r['store'] === false
            && $r['input'][0]['content'][1] === ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,QUJD', 'detail' => 'high']
            && $r['input'][0]['content'][2]['type'] === 'input_file');
    });

    it('la voz pide español y el vocabulario del negocio; sin pistas, solo el modelo', function () {
        $fields = OpenAiTranscriber::fields(true);
        expect($fields[0])->toBe(['name' => 'model', 'contents' => 'voz-de-prueba']);
        expect($fields)->toContain(['name' => 'languages[]', 'contents' => 'es']);
        expect($fields)->toContain(['name' => 'keywords[]', 'contents' => 'Binance']);
        expect(collect($fields)->firstWhere('name', 'prompt')['contents'])->toContain('español');
        expect(OpenAiTranscriber::fields(false))->toBe([['name' => 'model', 'contents' => 'voz-de-prueba']]);
    });

    it('la voz: manda el audio con el modelo, español y las palabras del negocio; la llave solo en la cabecera', function () {
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' ¿Cuánto vendió Fabiola ayer? '])]);
        $audio = tempnam(sys_get_temp_dir(), 'voz');
        file_put_contents($audio, 'audio-de-mentira');

        expect((new OpenAiTranscriber())->transcribe($audio, 'webm'))->toBe('¿Cuánto vendió Fabiola ayer?');
        Http::assertSent(function (Request $r) {
            $parts = collect($r->data());

            return $r->url() === OpenAiTranscriber::URL
                && $r->isMultipart()
                && $r->hasHeader('Authorization', 'Bearer ' . LLAVE_FALSA_ARCHIVOS)
                && $parts->firstWhere('name', 'model')['contents'] === 'voz-de-prueba'
                && $parts->firstWhere('name', 'languages[]')['contents'] === 'es'
                && $parts->where('name', 'keywords[]')->pluck('contents')->contains('Cashea')
                && $parts->firstWhere('name', 'file')['filename'] === 'voz.webm'
                && !str_contains(json_encode($parts->where('name', '!=', 'file')->values()), LLAVE_FALSA_ARCHIVOS);
        });
        unlink($audio);
    });

    it('si OpenAI no acepta las pistas (400), lo intenta una vez sin ellas; si falla, lo dice', function () {
        Http::fakeSequence('api.openai.com/*')
            ->push(['error' => ['message' => 'Unknown parameter: keywords']], 400)
            ->push(['text' => 'Hola']);
        $audio = tempnam(sys_get_temp_dir(), 'voz');
        file_put_contents($audio, 'audio');

        expect((new OpenAiTranscriber())->transcribe($audio, 'mp4'))->toBe('Hola');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => collect($r->data())->where('name', 'keywords[]')->isEmpty() && collect($r->data())->firstWhere('name', 'file')['filename'] === 'voz.mp4');
        unlink($audio);
    });

    it('si OpenAI falla, avisa con el motivo', function () {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit']], 429)]);
        $audio = tempnam(sys_get_temp_dir(), 'voz');
        file_put_contents($audio, 'audio');

        expect(fn () => (new OpenAiTranscriber())->transcribe($audio, 'webm'))->toThrow(RuntimeException::class, 'OpenAI respondió 429: Rate limit');
        unlink($audio);
    });
});
