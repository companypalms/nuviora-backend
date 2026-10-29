<?php

namespace App\Services\Assistant\Files;

use App\Models\InternalMessage;
use App\Models\User;
use App\Services\Reconciliation\StatementReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Los archivos que el dueño le manda al asistente (Etapa 4, paso 4): se guardan en la carpeta privada del servidor
 * (assistant/{usuario}/…), se leen al armar la respuesta y se arma lo que ve la IA. Las reglas están en FileRules.
 *
 * Cada archivo queda en assistant_messages.attachments, en la pregunta: path, name, mime, size y kind (image | pdf |
 * sheet). Al leerlo se agrega read, y según el caso pages, scanned, rows, sheets, sheet, text_path (el texto leído, en un
 * .txt al lado) y clipped; o problem si no se pudo leer.
 */
class Attachments
{
    public const DISK = 'local';

    public function __construct(private StatementReader $sheets)
    {
    }

    /**
     * Si se pueden mandar (tipo, tamaño, cantidad). El tipo sale del contenido del archivo, no del nombre.
     * @param array<int, UploadedFile> $files
     */
    public function validate(array $files): void
    {
        $errors = [];
        foreach (array_values($files) as $i => $file) {
            $problem = FileRules::problem($this->name($file), (string) $file->getMimeType(), $file->getClientOriginalExtension(), (int) $file->getSize());
            if ($problem !== null) {
                $errors["files.{$i}"] = $problem;
            }
        }
        if ($errors === [] && ($total = FileRules::totalProblem(array_map(fn (UploadedFile $f) => (int) $f->getSize(), array_values($files))))) {
            $errors['files'] = $total;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Los guarda en la carpeta privada. Si uno falla, no queda ninguno.
     * @param array<int, UploadedFile> $files
     * @return array<int, array{path: string, name: string, mime: string, size: int, kind: string}>
     */
    public function store(User $user, array $files): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $mime = (string) $file->getMimeType();
                $kind = FileRules::kind($mime, $file->getClientOriginalExtension());
                $extension = FileRules::IMAGE_MIMES[$mime] ?? ($kind === 'pdf' ? 'pdf' : strtolower($file->getClientOriginalExtension()));
                $path = $file->storeAs("assistant/{$user->id}", Str::uuid() . ".{$extension}", self::DISK);
                if (!$path) {
                    throw new RuntimeException('No se pudo guardar el archivo. Intenta de nuevo.');
                }
                $stored[] = ['path' => $path, 'name' => $this->name($file), 'mime' => $mime, 'size' => (int) $file->getSize(), 'kind' => $kind];
            }
        } catch (Throwable $e) {
            $this->delete($stored);
            throw $e;
        }

        return $stored;
    }

    public function delete(array $files): void
    {
        $disk = Storage::disk(self::DISK);
        foreach ($files as $file) {
            $disk->delete(array_filter([$file['path'] ?? null, $file['text_path'] ?? null]));
        }
    }

    /**
     * Lee los que todavía no se leyeron: el texto del PDF o la tabla del Excel. Un archivo que no se puede leer queda con
     * su motivo (la IA lo cuenta), sin cortar la respuesta.
     * @param callable(string): void|null $progress "Leyendo ventas.xlsx…"
     */
    public function read(array $files, ?callable $progress = null): array
    {
        foreach ($files as $i => $file) {
            if (!empty($file['read'])) {
                continue;
            }
            if ($progress && $file['kind'] !== 'image') {
                $progress('Leyendo ' . mb_strimwidth((string) $file['name'], 0, 80, '…') . '…');
            }
            try {
                $files[$i] = $this->readOne($file);
            } catch (RuntimeException $e) {
                $files[$i] = $file + ['problem' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $files[$i] = $file + ['problem' => 'No se pudo leer el archivo.'];
            }
            $files[$i]['read'] = true;
        }

        return $files;
    }

    /**
     * Lo que ve la IA en la pregunta donde se mandaron (Turn::$files): las fotos y los PDF escaneados como archivo; los
     * PDF con texto y los Excel, como texto.
     * @return array<int, array{type: string, name: string, label: string, mime?: string, data?: string, text?: string}>
     */
    public function parts(array $files): array
    {
        $disk = Storage::disk(self::DISK);
        $parts = [];
        foreach ($files as $file) {
            $base = ['name' => (string) $file['name'], 'label' => FileRules::label($file)];
            $visual = $file['kind'] === 'image' || !empty($file['scanned']);
            if (empty($file['problem']) && $visual && !$disk->exists($file['path'])) {
                $file['problem'] = 'El archivo ya no está.';
            }
            if (!empty($file['problem'])) {
                $parts[] = $base + ['type' => 'text', 'text' => FileRules::block($file, 'No se pudo leer: ' . $file['problem'])];
            } elseif ($visual) {
                $parts[] = [
                    'type' => $file['kind'] === 'image' ? 'image' : 'file',
                    // OpenAI reconoce el PDF por el nombre
                    'name' => $file['kind'] === 'image' || str_ends_with(strtolower($base['name']), '.pdf') ? $base['name'] : "{$base['name']}.pdf",
                    'label' => $base['label'],
                    'mime' => $file['kind'] === 'image' ? (string) $file['mime'] : 'application/pdf',
                    'data' => base64_encode((string) $disk->get($file['path'])),
                ];
            } else {
                $parts[] = $base + ['type' => 'text', 'text' => FileRules::block($file, $this->text($file) ?? '', FileRules::readNote($file, !empty($file['clipped'])))];
            }
        }

        return $parts;
    }

    /** Cómo se recuerdan en las preguntas siguientes (se descuenta del espacio que queda, $budget). */
    public function remembered(array $files, int &$budget): string
    {
        $lines = [];
        foreach ($files as $file) {
            $text = $file['kind'] === 'image' || !empty($file['scanned']) || !empty($file['problem']) ? null : $this->text($file);
            $lines[] = FileRules::remembered($file, $text, $budget);
        }

        return implode("\n", $lines);
    }

    /** El texto que se leyó del archivo, o null. */
    public function text(array $file): ?string
    {
        $disk = Storage::disk(self::DISK);

        return !empty($file['text_path']) && $disk->exists($file['text_path']) ? (string) $disk->get($file['text_path']) : null;
    }

    /** El archivo para el navegador: la foto y el PDF se abren ahí; el Excel se descarga. */
    public function response(array $file, bool $download): BinaryFileResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless(!empty($file['path']) && $disk->exists($file['path']), 404, 'El archivo ya no está.');
        $inline = !$download && in_array($file['kind'], ['image', 'pdf'], true);
        $name = (string) ($file['name'] ?: basename($file['path']));
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($name)) ?: 'archivo';

        return response()->file($disk->path($file['path']), [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=43200',
        ])->setContentDisposition($inline ? 'inline' : 'attachment', $name, $fallback);
    }

    private function readOne(array $file): array
    {
        $disk = Storage::disk(self::DISK);
        if (!$disk->exists($file['path'])) {
            throw new RuntimeException('El archivo ya no está.');
        }
        $path = $disk->path($file['path']);

        if ($file['kind'] === 'pdf') {
            try {
                $pdf = (new PdfParser())->parseFile($path);
                $pages = count($pdf->getPages());
                $text = FileRules::pdfText((string) $pdf->getText());
            } catch (Throwable $e) {
                throw new RuntimeException('No se pudo abrir el PDF (puede estar dañado o tener contraseña).');
            }
            $file['pages'] = $pages;
            if (FileRules::scanned($text, $pages)) {
                $file['scanned'] = true; // la IA ve el archivo, no el texto

                return $file;
            }

            return $this->keepText($file, $text);
        }

        if ($file['kind'] === 'sheet') {
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            $cells = $this->sheets->cells($path, $extension, FileRules::SHEET_ROWS);
            $text = FileRules::sheetText($cells);
            if (trim($text) === '') {
                throw new RuntimeException('La hoja está vacía.');
            }

            return $this->keepText($file + $this->sheetInfo($path, $extension, count($cells)), $text);
        }

        return $file;
    }

    /** Cuántas filas y hojas tiene el archivo entero (sin cargarlo). */
    private function sheetInfo(string $path, string $extension, int $read): array
    {
        if ($extension === 'csv') {
            $rows = 0;
            $handle = fopen($path, 'rb');
            while ($handle && ($line = fgets($handle)) !== false) {
                $rows += trim($line) !== '' ? 1 : 0;
            }
            if ($handle) {
                fclose($handle);
            }

            return ['rows' => max($rows, $read)];
        }
        try {
            $info = IOFactory::createReaderForFile($path)->listWorksheetInfo($path);

            return ['rows' => max((int) ($info[0]['totalRows'] ?? 0), $read), 'sheets' => count($info), 'sheet' => (string) ($info[0]['worksheetName'] ?? '')];
        } catch (Throwable $e) {
            return ['rows' => $read];
        }
    }

    /** Guarda el texto leído al lado del archivo (no en la base: puede ser largo). */
    private function keepText(array $file, string $text): array
    {
        [$text, $clipped] = FileRules::clip($text, FileRules::TEXT_MAX_CHARS);
        $textPath = preg_replace('/\.[A-Za-z0-9]+$/', '', $file['path']) . '.txt';
        Storage::disk(self::DISK)->put($textPath, $text);

        return $file + ['text_path' => $textPath, 'clipped' => $clipped];
    }

    private function name(UploadedFile $file): string
    {
        return InternalMessage::cleanName($file->getClientOriginalName(), $file->getClientOriginalExtension() ?: null);
    }
}
